<?php
// (1) prompt=login used to call forgetCrmSession() on a plain GET: one
// cross-site link logged the user out of the CRM (logout CSRF).
// (2) The consent page named the client only by its self-chosen name; it now
// says where the authorization code will be sent.
namespace ApiGoat\Tests\Security;

use ApiGoat\Services\OAuthAuthorizeService;
use PHPUnit\Framework\TestCase;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ApiGoat\\')) {
        $f = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
}, true, true);

final class OAuthAuthorizeGetSafetyTest extends TestCase
{
    public function test_the_session_is_only_forgotten_behind_a_csrf_check(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Services/OAuthAuthorizeService.php');
        $start = strpos($src, 'public function getApiResponse()');
        $end = strpos($src, '--- GET: never issues a code', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $body = substr($src, $start, $end - $start);

        $calls = preg_match_all('/\$this->forgetCrmSession\(\)/', $body, $m, PREG_OFFSET_CAPTURE);
        $this->assertGreaterThan(0, $calls);
        $postAt = strpos($body, 'if ($isPost) {');
        foreach ($m[0] as [, $at]) {
            $this->assertGreaterThan($postAt, $at, 'forgetCrmSession() outside the POST branch');
            $before = substr($body, 0, $at);
            $this->assertGreaterThan(
                (int) strrpos($before, 'if (self::is'),
                (int) strrpos($before, 'csrfOk('),
                'forgetCrmSession() must follow the csrfOk() gate of its own branch'
            );
        }
        // and the GET tail mutates nothing
        $tail = substr($src, $end, 400);
        $this->assertStringNotContainsString('forgetCrmSession', $tail);
    }

    public function test_consent_names_the_redirect_destination(): void
    {
        $this->assertStringContainsString('<strong>claude.ai</strong>', OAuthAuthorizeService::redirectHostHtml('https://claude.ai/api/mcp/auth_callback'));
        // falls back to the registered URI (string or list)
        $this->assertStringContainsString('<strong>evil.example</strong>', OAuthAuthorizeService::redirectHostHtml('', ['https://evil.example/cb']));
        $this->assertStringContainsString('<strong>app.example</strong>', OAuthAuthorizeService::redirectHostHtml('', 'https://app.example/cb'));
        // non-https destinations keep their scheme so they cannot pass for a site
        $this->assertStringContainsString('<strong>http://127.0.0.1</strong>', OAuthAuthorizeService::redirectHostHtml('http://127.0.0.1:8123/cb'));
        $this->assertStringContainsString('<strong>com.app.kid://oauth</strong>', OAuthAuthorizeService::redirectHostHtml('com.app.kid://oauth/cb'));
        // escaped, and silent when there is nothing to name
        $this->assertStringNotContainsString('<script', OAuthAuthorizeService::redirectHostHtml('x"><script>://h'));
        $this->assertSame('', OAuthAuthorizeService::redirectHostHtml('', null));
        $this->assertSame('', OAuthAuthorizeService::redirectHostHtml('not a url'));
    }
}
