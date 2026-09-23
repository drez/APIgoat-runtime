<?php
// Review 2026-09-23 Wave 4 (goatcheese 69de5f6): the emitted Authy logout takes
// a POST carrying the session csrf token and honours a GET only for a
// same-origin navigation. The runtime's own sign-out controls — the drawer
// footer (BuilderLayout) and the backend_admin_only 403 page — are POST forms
// with the token, never a bare GET link, and carry no inline handler.
namespace ApiGoat\Tests\Security;

use ApiGoat\Middlewares\AuthyMiddleware;
use ApiGoat\Utility\BuilderLayout;
use PHPUnit\Framework\TestCase;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ApiGoat\\')) {
        $f = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
}, true, true);

if (! \defined('_SUB_DIR_URL')) {
    \define('_SUB_DIR_URL', '/app/');
}
if (! \defined('_SITE_URL')) {
    \define('_SITE_URL', '/app/');
}
if (! \defined('_AUTH_VAR')) {
    \define('_AUTH_VAR', 'gcTestAuth');
}

final class LpfSession
{
    public function __construct(private string $csrf) {}
    public function getCsrf() { return $this->csrf; }
}

final class LogoutPostFormTest extends TestCase
{
    private function assertLogoutForm(string $html, string $escapedToken): void
    {
        $this->assertDoesNotMatchRegularExpression('#<a [^>]*href=["\'][^"\']*Authy/logout#', $html, 'no GET logout link');
        $this->assertMatchesRegularExpression('#<form method=["\']post["\'] action=["\'][^"\']*/Authy/logout["\']#', $html);
        $this->assertMatchesRegularExpression('#<input type=["\']hidden["\'] name=["\']csrf["\'] value=["\']' . preg_quote($escapedToken, '#') . '["\']>#', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(alert|confirm|prompt)\s*\(/', $html);
    }

    public function test_backend_denied_page_logs_out_by_post_with_the_csrf_token(): void
    {
        $plain = AuthyMiddleware::backendDeniedPage('m', 'u', null, 'se<c"');
        $this->assertLogoutForm($plain, 'se&lt;c&quot;');

        $imp = AuthyMiddleware::backendDeniedPage('m', 'u', ['action' => '/app/GuiManager', 'land' => '/app/admin', 'iarc' => 3, 'csrf' => 'i'], 'tok');
        $this->assertLogoutForm($imp, 'tok');
        $this->assertSame(1, substr_count($imp, 'Authy/logout'));
    }

    public function test_drawer_sign_out_is_a_post_form(): void
    {
        $prev = $_SESSION[_AUTH_VAR] ?? null;
        $_SESSION[_AUTH_VAR] = new LpfSession("ab'cd");
        try {
            $layout = (new \ReflectionClass(BuilderLayout::class))->newInstanceWithoutConstructor();
            $m = new \ReflectionMethod(BuilderLayout::class, 'signOutForm');
            $html = $m->invoke($layout);
        } finally {
            $_SESSION[_AUTH_VAR] = $prev;
        }
        $this->assertLogoutForm($html, 'ab&#039;cd');
        $this->assertStringContainsString("class='dr-signout'", $html, 'keeps the styled class');
        $this->assertStringContainsString("<button type='submit'", $html);
    }
}
