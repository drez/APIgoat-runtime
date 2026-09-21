<?php
// OAuth scopes were issued and shown on the consent page but read nowhere: a
// token granted crm:read alone could write. Enforcement is deliberately
// narrow — ONLY "has crm:read, lacks crm:write" restricts; no scopes / legacy
// scope names / non-bearer requests behave exactly as before.
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

    public function test_only_read_without_write_is_read_only(): void
    {
        $cases = [
            [null, false],                                           // not a bearer request
            [[], false],                                             // client requested no scope
            [['read'], false],                                       // pre-"crm:" legacy tokens
            [['read', 'write'], false],
            [['offline_access'], false],
            [['crm:read', 'crm:write', 'offline_access'], false],    // every first-party client
            [['crm:write'], false],
            [['crm:read'], true],
            [['crm:read', 'offline_access'], true],
        ];
        foreach ($cases as [$scopes, $expected]) {
            TokenScopes::set($scopes);
            $this->assertSame($expected, TokenScopes::readOnly(), json_encode($scopes));
        }
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

        foreach ([null, [], ['crm:read', 'crm:write'], ['read']] as $scopes) {
            TokenScopes::set($scopes);
            $this->assertTrue($registry->granted($write, $admin), json_encode($scopes));
            $this->assertTrue($registry->granted($ungated, $admin), json_encode($scopes));
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
}
}
