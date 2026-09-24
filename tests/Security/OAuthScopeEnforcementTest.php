<?php
// OAuth scopes were issued and shown on the consent page but read nowhere: a
// token granted crm:read alone could write. Since 2026-09-23 bearer tokens
// are default-deny: writes need crm:write, reads crm:read or crm:write; a
// token with no crm:* scope (none, offline_access only, legacy "read write")
// is refused. Non-bearer requests (granted() null) are untouched, and
// ScopeRepository::finalizeScopes gives a client that requests no crm:* scope
// its registered ones.
namespace ApiGoat\Sessions {
    if (!class_exists(AuthySession::class, false)) {
        class AuthySession
        {
            public function isAdmin() { return true; }
            public function hasRights($m = '', $r = '') { return true; }
        }
    }
}

namespace ApiGoat\Tests\Security {

use ApiGoat\Mcp\McpTool;
use ApiGoat\Mcp\ToolRegistry;
use ApiGoat\Middlewares\OAuthResourceMiddleware;
use ApiGoat\OAuth\TokenScopes;
use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;

// PSR-4 for THIS checkout, prepended (see tests/Mcp/ToolCallAuthorizationTest.php).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ApiGoat\\')) {
        $f = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
}, true, true);

class ScopeAdminSession extends AuthySession
{
    public function __construct() {}
    public function isAdmin() { return true; }
    public function hasRights($model = '', $needeRight = '') { return true; }
}

class ScopeTool implements McpTool
{
    public function __construct(private ?array $right) {}
    public function name(): string { return 'scope_tool'; }
    public function description(): string { return 't'; }
    public function inputSchema(): array { return ['type' => 'object']; }
    public function requiredRight(): ?array { return $this->right; }
    public function handle(array $args, AuthySession $session): array { return ['content' => []]; }
}

final class ScopeSelfDeclaredReadTool extends ScopeTool
{
    public function readOnly(): bool { return true; }
}

final class OAuthScopeEnforcementTest extends TestCase
{
    protected function tearDown(): void
    {
        TokenScopes::set(null);
    }

    public function test_default_deny_read_and_write(): void
    {
        // [scopes, allowsRead, allowsWrite, readOnly]
        $cases = [
            [null, true, true, false],                                          // not a bearer request
            [[], false, false, false],                                          // no scope: denied (was unrestricted)
            [['read'], false, false, false],                                    // pre-"crm:" legacy tokens
            [['read', 'write'], false, false, false],
            [['offline_access'], false, false, false],
            [['crm:read', 'crm:write', 'offline_access'], true, true, false],   // every first-party client
            [['crm:write'], true, true, false],                                 // write implies read
            [['crm:read'], true, false, true],
            [['crm:read', 'offline_access'], true, false, true],
        ];
        foreach ($cases as [$scopes, $read, $write, $ro]) {
            TokenScopes::set($scopes);
            $this->assertSame($read, TokenScopes::allowsRead(), 'read ' . json_encode($scopes));
            $this->assertSame($write, TokenScopes::allowsWrite(), 'write ' . json_encode($scopes));
            $this->assertSame($ro, TokenScopes::readOnly(), 'ro ' . json_encode($scopes));
            $this->assertSame($write ? null : 'crm:write', TokenScopes::missingFor(true));
            $this->assertSame($read ? null : 'crm:read', TokenScopes::missingFor(false));
        }
    }

    public function test_finalize_scopes_defaults_and_clamps(): void
    {
        $full = ['crm:read', 'crm:write', 'offline_access'];
        $f = [\ApiGoat\OAuth\ScopeRepository::class, 'finalIdentifiers'];
        // real clients: request == registered → unchanged
        $this->assertSame($full, $f($full, $full));
        // a client requesting no scope gets its registered crm:* scopes
        $this->assertSame(['crm:read', 'crm:write'], $f([], $full));
        $this->assertSame(['offline_access', 'crm:read', 'crm:write'], $f(['offline_access'], $full));
        // an explicitly narrow request stays narrow
        $this->assertSame(['crm:read', 'offline_access'], $f(['crm:read', 'offline_access'], $full));
        // never more than registered
        $this->assertSame(['crm:read'], $f(['crm:read', 'crm:write'], ['crm:read']));
        // unknown identifiers dropped
        $this->assertSame(['crm:read'], $f(['crm:read', 'admin'], $full));
        // legacy client row without registered scopes → DEFAULT
        $this->assertSame(['crm:read', 'crm:write'], $f([], null));
        $this->assertSame(['crm:read'], $f(['crm:read'], null));
    }

    public function test_finalize_scopes_reads_the_client_registration(): void
    {
        $client = new \ApiGoat\OAuth\Entities\ClientEntity();
        $client->setIdentifier('c1');
        $client->setRegisteredScopes('crm:read  offline_access');
        $repo = new \ApiGoat\OAuth\ScopeRepository();
        $out = $repo->finalizeScopes([], 'authorization_code', $client);
        $this->assertSame(['crm:read'], array_map(fn ($s) => $s->getIdentifier(), $out));
    }

    public function test_scopes_are_read_from_a_league_jwt_payload(): void
    {
        $b64 = static fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
        $jwt = $b64(['alg' => 'RS256']) . '.' . $b64(['sub' => '7', 'scopes' => ['crm:read', 'offline_access']]) . '.sig';
        $this->assertSame(['crm:read', 'offline_access'], TokenScopes::fromJwt($jwt));
        $this->assertSame([], TokenScopes::fromJwt($b64(['alg' => 'RS256']) . '.' . $b64(['sub' => '7']) . '.sig'));
        $this->assertSame([], TokenScopes::fromJwt('opaque'));
    }

    public function test_a_read_only_token_reaches_read_tools_only(): void
    {
        $registry = new ToolRegistry([]);
        $admin = new ScopeAdminSession();
        $write = new ScopeTool(['Quote', 'w']);
        $ungated = new ScopeTool(null);
        $read = new ScopeTool(['Quote', 'r']);
        $declared = new ScopeSelfDeclaredReadTool(null);

        TokenScopes::set(['crm:read']);
        $this->assertFalse($registry->granted($write, $admin));
        $this->assertFalse($registry->granted($ungated, $admin), 'unknown tools fail closed');
        $this->assertTrue($registry->granted($read, $admin));
        $this->assertTrue($registry->granted($declared, $admin));
        $this->assertTrue(ToolRegistry::isReadTool(new \ApiGoat\Mcp\Tools\CrmList()));
        $this->assertFalse(ToolRegistry::isReadTool(new \ApiGoat\Mcp\Tools\CrmUpdate()));
        $this->assertFalse(ToolRegistry::isReadTool(new \ApiGoat\Mcp\Tools\CrmDelete()));

        foreach ([null, ['crm:read', 'crm:write'], ['crm:write']] as $scopes) {
            TokenScopes::set($scopes);
            $this->assertTrue($registry->granted($write, $admin), json_encode($scopes));
            $this->assertTrue($registry->granted($ungated, $admin), json_encode($scopes));
        }
        // default deny: no crm:* scope reaches nothing, not even read tools
        foreach ([[], ['read'], ['offline_access']] as $scopes) {
            TokenScopes::set($scopes);
            $this->assertFalse($registry->granted($write, $admin), json_encode($scopes));
            $this->assertFalse($registry->granted($read, $admin), json_encode($scopes));
        }
    }

    public function test_a_read_only_token_is_refused_on_non_safe_http_methods(): void
    {
        foreach (['POST', 'put', 'PATCH', 'DELETE'] as $m) {
            $this->assertTrue(OAuthResourceMiddleware::refusedByScope(true, $m), $m);
            $this->assertFalse(OAuthResourceMiddleware::refusedByScope(false, $m), $m);
        }
        foreach (['GET', 'HEAD', 'OPTIONS'] as $m) {
            $this->assertFalse(OAuthResourceMiddleware::refusedByScope(true, $m), $m);
        }
    }

    public function test_rest_missing_scope_per_method(): void
    {
        TokenScopes::set(['crm:read']);
        $this->assertSame('crm:write', OAuthResourceMiddleware::missingScope('POST'));
        $this->assertNull(OAuthResourceMiddleware::missingScope('GET'));
        TokenScopes::set([]);
        $this->assertSame('crm:read', OAuthResourceMiddleware::missingScope('GET'));
        $this->assertSame('crm:write', OAuthResourceMiddleware::missingScope('DELETE'));
        TokenScopes::set(null);
        $this->assertNull(OAuthResourceMiddleware::missingScope('DELETE'), 'non-bearer untouched');
    }

    public function test_mutating_actions_over_get_need_write(): void
    {
        // Legacy bearer actions write over GET: mass, upload, project ones.
        TokenScopes::set(['crm:read']);
        $this->assertSame('crm:write', OAuthResourceMiddleware::missingScope('GET', 'mass', true));
        $this->assertSame('crm:write', OAuthResourceMiddleware::missingScope('GET', 'scanAndCreateClient', true));
        $this->assertSame('crm:write', OAuthResourceMiddleware::missingScope('GET', 'delete'));
        $this->assertNull(OAuthResourceMiddleware::missingScope('GET', 'file', true));
        $this->assertNull(OAuthResourceMiddleware::missingScope('GET', 'open', true));
        $this->assertNull(OAuthResourceMiddleware::missingScope('GET', 'list'));
        TokenScopes::set(['crm:write']);
        $this->assertNull(OAuthResourceMiddleware::missingScope('GET', 'mass', true));
        $this->assertTrue(OAuthResourceMiddleware::requiresWriteScope('POST', 'file', true));
    }

    public function test_bearer_extraction_matches_detection(): void
    {
        $this->assertSame('abc.def', OAuthResourceMiddleware::bearerToken('Bearer abc.def'));
        $this->assertSame('abc.def', OAuthResourceMiddleware::bearerToken("bearer\t abc.def "));
        $this->assertNull(OAuthResourceMiddleware::bearerToken('Bearer '));
        $this->assertNull(OAuthResourceMiddleware::bearerToken('Basic dXNlcjpwYXNz'));
    }
}
}
