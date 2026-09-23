<?php
// Review 2026-09-23 Wave 4: the impersonation switch token (IarcCsrf) must not
// travel in a URL. The backend_admin_only "Stop impersonating" control is a POST
// form (no inline handler — CSP is nonce-only), and the Iarc autocomplete reads
// the token from the X-Iarc-Csrf header before the legacy query field.
namespace ApiGoat\Tests\Security;

use ApiGoat\Middlewares\AuthyMiddleware;
use ApiGoat\Services\IarcAutoc;
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

final class IstSession
{
    public function __construct(private int $id, private string $connected = 'YES') {}
    public function get($k) { return $k === 'connected' ? $this->connected : null; }
    public function getIdAuthy() { return $this->id; }
}

final class IarcSwitchTokenTransportTest extends TestCase
{
    private function page(?array $switchBack): string
    {
        return AuthyMiddleware::backendDeniedPage('Reserved <for> admins', 'bob"x', $switchBack);
    }

    public function test_switch_back_is_a_post_form_not_a_token_url(): void
    {
        $html = $this->page(['action' => '/app/GuiManager', 'land' => '/app/admin', 'iarc' => 7, 'csrf' => 'tok"en<']);
        $this->assertStringNotContainsString('iarc_csrf=', $html, 'token must not appear in a URL');
        $this->assertStringNotContainsString('?iarc=', $html);
        $this->assertMatchesRegularExpression('#<form id="gc-iarc-back" method="post" action="/app/GuiManager" data-land="/app/admin"#', $html);
        $this->assertStringContainsString('<input type="hidden" name="a" value="alive">', $html);
        $this->assertStringContainsString('<input type="hidden" name="iarc" value="7">', $html);
        $this->assertStringContainsString('name="iarc_csrf" value="tok&quot;en&lt;"', $html, 'token is escaped');
        $this->assertStringContainsString('Stop impersonating', $html);
    }

    public function test_no_inline_handlers_and_no_native_dialogs(): void
    {
        $html = $this->page(['action' => '/app/GuiManager', 'land' => '/app/admin', 'iarc' => 7, 'csrf' => 't']);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(alert|confirm|prompt)\s*\(/', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_script_carries_the_csp_nonce_when_one_is_minted(): void
    {
        if (! \function_exists('gcNonceAttr')) {
            require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php';
        }
        $GLOBALS['__gc_csp_nonce'] = 'N0nce123';
        try {
            $html = $this->page(['action' => '/app/GuiManager', 'land' => '/app/admin', 'iarc' => 7, 'csrf' => 't']);
        } finally {
            unset($GLOBALS['__gc_csp_nonce']);
        }
        $this->assertStringContainsString('<script nonce="N0nce123">', $html);
    }

    public function test_message_and_username_are_escaped_and_plain_page_has_no_form(): void
    {
        $html = $this->page(null);
        $this->assertStringContainsString('Reserved &lt;for&gt; admins', $html);
        $this->assertStringContainsString('bob&quot;x', $html);
        $this->assertStringNotContainsString('gc-iarc-back', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('/app/Authy/logout', $html);
    }

    public function test_autoc_prefers_the_header_then_falls_back_to_the_query(): void
    {
        $this->assertSame('hdr', IarcAutoc::submittedCsrf(['iarc_csrf' => 'q'], 'hdr'));
        $this->assertSame('q', IarcAutoc::submittedCsrf(['iarc_csrf' => 'q'], ''));
        $this->assertSame('b', IarcAutoc::submittedCsrf(['data' => ['iarc_csrf' => 'b']], ''));
        $this->assertSame('', IarcAutoc::submittedCsrf([], ''));

        $_SERVER['HTTP_X_IARC_CSRF'] = 'srv';
        try {
            $this->assertSame('srv', IarcAutoc::submittedCsrf(['iarc_csrf' => 'q']));
        } finally {
            unset($_SERVER['HTTP_X_IARC_CSRF']);
        }
        $this->assertSame('q', IarcAutoc::submittedCsrf(['iarc_csrf' => 'q']));
    }

    public function test_user_key_is_opaque_stable_and_per_user(): void
    {
        $k7 = BuilderLayout::userKey(new IstSession(7), 'secret');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $k7);
        $this->assertSame($k7, BuilderLayout::userKey(new IstSession(7), 'secret'), 'stable');
        $this->assertNotSame($k7, BuilderLayout::userKey(new IstSession(8), 'secret'), 'per user');
        $this->assertNotSame($k7, BuilderLayout::userKey(new IstSession(7), 'other'), 'per project secret');
        $this->assertSame(substr(hash_hmac('sha256', 'gc-user-key|7', 'secret'), 0, 20), $k7);

        $this->assertSame('', BuilderLayout::userKey(new IstSession(7), ''), 'no secret, no key');
        $this->assertSame('', BuilderLayout::userKey(new IstSession(0), 'secret'));
        $this->assertSame('', BuilderLayout::userKey(new IstSession(7, 'NO'), 'secret'), 'signed out');
        $this->assertSame('', BuilderLayout::userKey(null, 'secret'));
    }
}
