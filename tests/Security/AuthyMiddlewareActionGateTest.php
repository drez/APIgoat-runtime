<?php
// Two gaps in AuthyMiddleware:
//  - checkMutatingGet() judged only the parsed PATH action; a query-style
//    GET /Model?a=delete parses as 'list' and passed.
//  - checkPrivileges() inferred 'w' for every POST custom action, locking the
//    emitter's read-only POST actions (chat, selectbox) away from 'r' users.
namespace ApiGoat\Tests\Security;

use ApiGoat\Middlewares\AuthyMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ApiGoat\\')) {
        $f = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
}, true, true);

if (!\defined('_AUTH_VAR')) {
    \define('_AUTH_VAR', 'amg_auth');
}

final class AmgSession
{
    public function get($k) { return $k === 'connected' ? 'YES' : 7; }
}

final class AuthyMiddlewareActionGateTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION[\_AUTH_VAR]);
    }

    private function mutatingGet(string $method, string $uri, array $args): ?int
    {
        $_SESSION[\_AUTH_VAR] = new AmgSession();
        $ref = new \ReflectionClass(AuthyMiddleware::class);
        $mw = $ref->newInstanceWithoutConstructor();
        foreach (['privilegeMap' => ['exclude' => [], 'action' => []], 'args' => $args + ['route' => 'Contact', 'is_api' => false]] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($mw, $value);
        }
        $m = $ref->getMethod('checkMutatingGet');
        $m->setAccessible(true);
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://app.test' . $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $response = @$m->invoke($mw, $request->withQueryParams($query));
        return $response === null ? null : $response->getStatusCode();
    }

    public function test_a_query_style_mutating_get_is_refused(): void
    {
        $this->assertSame(405, $this->mutatingGet('GET', '/Contact?a=delete&i=5', ['action' => 'list']));
        $this->assertSame(405, $this->mutatingGet('GET', '/Contact?a=DELETE', ['action' => 'list']));
        $this->assertSame(405, $this->mutatingGet('HEAD', '/Contact?a=update', ['action' => 'list']));
        $this->assertSame(405, $this->mutatingGet('GET', '/Contact/delete/5', ['action' => 'delete']), 'path form still refused');
    }

    public function test_reads_and_posts_are_untouched(): void
    {
        $this->assertNull($this->mutatingGet('GET', '/Contact?a=list', ['action' => 'list']));
        $this->assertNull($this->mutatingGet('GET', '/Contact?a[]=delete', ['action' => 'list']), 'non-string a is not an action');
        $this->assertNull($this->mutatingGet('GET', '/GuiManager?a=alive', ['action' => 'list']));
        $this->assertNull($this->mutatingGet('POST', '/Contact?a=delete', ['action' => 'list']));
        $this->assertNull($this->mutatingGet('GET', '/api/v1/Contact?a=delete', ['action' => 'list', 'is_api' => true]));
    }

    public function test_read_only_post_actions_need_r_not_w(): void
    {
        $this->assertSame(['chat', 'selectbox'], AuthyMiddleware::READ_ONLY_POST_ACTIONS);
        $this->assertSame('r', AuthyMiddleware::inferredPrivilege('POST', 'chat'));
        $this->assertSame('r', AuthyMiddleware::inferredPrivilege('POST', 'SelectBox'));
        // everything else keeps the method inference (review R3)
        $this->assertSame('w', AuthyMiddleware::inferredPrivilege('POST', 'recalculate'));
        $this->assertSame('w', AuthyMiddleware::inferredPrivilege('DELETE', 'purge'));
        $this->assertSame('w', AuthyMiddleware::inferredPrivilege('post', 'chatter'));
        $this->assertSame('r', AuthyMiddleware::inferredPrivilege('GET', 'summarycards'));
    }
}
