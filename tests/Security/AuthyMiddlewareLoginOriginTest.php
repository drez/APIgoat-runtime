<?php
// Anonymous POSTs skip the session-token CSRF gate (there is no signed-in
// session to protect), which also left the web sign-in family open: a foreign
// page could log a victim into the attacker's account (login CSRF) or fire
// password-reset mails. Those four routes now require a same-site Origin
// (Referer as fallback) — not the session token, which a re-auth after an
// expired session cannot match.
namespace ApiGoat\Tests\Security;

use ApiGoat\Middlewares\AuthyMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
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

final class LoginOriginAnonSession
{
    public function get($k) { return $k === 'connected' ? 'NO' : null; }
    public function getCsrf() { return ''; }
}

final class AuthyMiddlewareLoginOriginTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION[\_AUTH_VAR]);
    }

    public function test_same_origin_matches_host_only_exactly(): void
    {
        $hosts = ['app.test'];
        $this->assertTrue(AuthyMiddleware::sameOrigin('https://app.test', '', $hosts));
        $this->assertTrue(AuthyMiddleware::sameOrigin('https://APP.test:443', '', $hosts));
        $this->assertTrue(AuthyMiddleware::sameOrigin('', 'https://app.test/Authy/login', $hosts), 'Referer fallback');
        $this->assertFalse(AuthyMiddleware::sameOrigin('https://evil.test', 'https://app.test/', $hosts), 'Origin wins over Referer');
        $this->assertFalse(AuthyMiddleware::sameOrigin('https://app.test.evil.test', '', $hosts), 'look-alike suffix');
        $this->assertFalse(AuthyMiddleware::sameOrigin('https://evilapp.test', '', $hosts));
        $this->assertFalse(AuthyMiddleware::sameOrigin('null', '', $hosts), 'opaque origin (sandboxed iframe, file:)');
        $this->assertFalse(AuthyMiddleware::sameOrigin('', '', $hosts), 'no evidence at all');
        $this->assertFalse(AuthyMiddleware::sameOrigin('not a url', '', $hosts));
    }

    /** @return int|null status of the refusal, null when the gate passed */
    private function csrf(string $route, array $headers, array $args = []): ?int
    {
        $_SESSION[\_AUTH_VAR] = new LoginOriginAnonSession();
        $ref = new \ReflectionClass(AuthyMiddleware::class);
        $mw = $ref->newInstanceWithoutConstructor();
        $values = [
            'privilegeMap' => ['exclude' => [], 'action' => []],
            'args' => $args + ['route' => $route, 'is_api' => false],
            'response' => (new ResponseFactory())->createResponse(),
        ];
        foreach ($values as $prop => $value) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue($mw, $value);
            }
        }
        $m = $ref->getMethod('checkCsrf');
        $m->setAccessible(true);
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://app.test/' . $route);
        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }
        $response = @$m->invoke($mw, $request);
        return $response === null ? null : $response->getStatusCode();
    }

    public function test_sign_in_routes_need_a_same_site_origin(): void
    {
        foreach (['Authy/auth', 'Authy/google', 'Authy/reset', 'Authy/register'] as $route) {
            $this->assertNull($this->csrf($route, ['Origin' => 'https://app.test']), "$route same-site");
            $this->assertSame(403, $this->csrf($route, ['Origin' => 'https://evil.test']), "$route foreign origin");
            $this->assertSame(403, $this->csrf($route, []), "$route no Origin/Referer");
        }
    }

    public function test_other_anonymous_posts_and_the_api_exchange_are_untouched(): void
    {
        // Public cross-site endpoints (analytics collect, webhooks) stay open.
        $this->assertNull($this->csrf('public/api/collect', ['Origin' => 'https://customer-site.test']));
        // The mobile/API credential exchange has no browser Origin at all.
        $this->assertNull($this->csrf('Authy/auth', [], ['is_api' => true]));
    }
}
