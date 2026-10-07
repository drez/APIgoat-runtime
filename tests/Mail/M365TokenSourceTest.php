<?php
namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\Token\M365AppTokenSource;
use ApiGoat\Mail\Token\M365OauthTokenSource;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;
use PHPUnit\Framework\TestCase;

final class M365TokenSourceTest extends TestCase
{
    private array $calls = [];

    private function http(array ...$responses): callable
    {
        return function (string $method, string $url, array $headers, ?string $body) use (&$responses) {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            $r = array_shift($responses) ?? ['status' => 500, 'body' => 'none'];
            return $r + ['headers' => ''];
        };
    }

    public function test_app_token_uses_client_credentials_for_the_tenant_and_caches(): void
    {
        $src = new M365AppTokenSource('tid-1', 'cid', 'sec', $this->http(
            ['status' => 200, 'body' => '{"access_token":"at1","expires_in":3600}']
        ));
        $this->assertSame('at1', $src->accessToken());
        $this->assertSame('at1', $src->accessToken());
        $this->assertCount(1, $this->calls);
        $this->assertSame('https://login.microsoftonline.com/tid-1/oauth2/v2.0/token', $this->calls[0]['url']);
        parse_str((string) $this->calls[0]['body'], $form);
        $this->assertSame(['grant_type' => 'client_credentials', 'client_id' => 'cid', 'client_secret' => 'sec',
            'scope' => 'https://graph.microsoft.com/.default'], $form);
        $this->assertSame('m365app:tid-1', $src->describe());
    }

    public function test_app_token_without_consent_is_auth_failed(): void
    {
        $src = new M365AppTokenSource('tid-1', 'cid', 'sec', $this->http(
            ['status' => 400, 'body' => '{"error":"invalid_client","error_description":"AADSTS7000229: missing service principal"}']
        ));
        $this->expectException(AuthFailed::class);
        $this->expectExceptionMessage('admin consent missing');
        $src->accessToken();
    }

    public function test_app_token_server_error_is_transient(): void
    {
        $src = new M365AppTokenSource('tid-1', 'cid', 'sec', $this->http(['status' => 503, 'body' => '']));
        $this->expectException(TransientError::class);
        $src->accessToken();
    }

    public function test_oauth_refresh_persists_a_rotated_refresh_token_before_returning(): void
    {
        $rotated = [];
        $src = new M365OauthTokenSource('cid', 'sec', 'rt-old', 'u@x.com', $this->http(
            ['status' => 200, 'body' => '{"access_token":"at","expires_in":3600,"refresh_token":"rt-new"}']
        ), function (string $rt) use (&$rotated) { $rotated[] = $rt; });
        $this->assertSame('at', $src->accessToken());
        $this->assertSame(['rt-new'], $rotated);
        parse_str((string) $this->calls[0]['body'], $form);
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('rt-old', $form['refresh_token']);
        $this->assertStringContainsString('offline_access', $form['scope']);
        $this->assertSame('https://login.microsoftonline.com/organizations/oauth2/v2.0/token', $this->calls[0]['url']);
        $this->assertSame('m365oauth:u@x.com', $src->describe());
    }

    public function test_oauth_uses_the_rotated_token_on_the_next_refresh(): void
    {
        $src = new M365OauthTokenSource('cid', 'sec', 'rt-old', 'u@x.com', $this->http(
            ['status' => 200, 'body' => '{"access_token":"a1","expires_in":3600,"refresh_token":"rt-2"}'],
            ['status' => 200, 'body' => '{"access_token":"a2","expires_in":3600}']
        ));
        $src->accessToken();
        $src->invalidate();
        $this->assertSame('a2', $src->accessToken());
        parse_str((string) $this->calls[1]['body'], $form);
        $this->assertSame('rt-2', $form['refresh_token']);
    }

    public function test_oauth_invalid_grant_says_sign_in_again(): void
    {
        $src = new M365OauthTokenSource('cid', 'sec', 'rt', 'u@x.com', $this->http(
            ['status' => 400, 'body' => '{"error":"invalid_grant","error_description":"AADSTS70008: expired"}']
        ));
        $this->expectException(AuthFailed::class);
        $this->expectExceptionMessage('sign in again');
        $src->accessToken();
    }

    public function test_app_token_429_is_rate_limited_with_retry_after(): void
    {
        $src = new M365AppTokenSource('tid-1', 'cid', 'sec', $this->http(['status' => 429, 'headers' => "Retry-After: 12\r\n", 'body' => '{}']));
        try { $src->accessToken(); $this->fail('no throw'); } catch (RateLimited $e) { $this->assertSame(12, $e->getCode()); }
    }

    public function test_oauth_429_is_rate_limited_default_30(): void
    {
        $src = new M365OauthTokenSource('cid', 'sec', 'rt', 'u@x.com', $this->http(['status' => 429, 'body' => '{}']));
        try { $src->accessToken(); $this->fail('no throw'); } catch (RateLimited $e) { $this->assertSame(30, $e->getCode()); }
    }

    public function test_oauth_onrotate_failure_propagates_and_caches_nothing(): void
    {
        $src = new M365OauthTokenSource('cid', 'sec', 'rt-old', 'u@x.com', $this->http(
            ['status' => 200, 'body' => '{"access_token":"a1","expires_in":3600,"refresh_token":"rt-new"}'],
            ['status' => 200, 'body' => '{"access_token":"a2","expires_in":3600}']
        ), function (string $rt) { throw new \RuntimeException('db down'); });
        try { $src->accessToken(); $this->fail('no throw'); } catch (\RuntimeException $e) { $this->assertSame('db down', $e->getMessage()); }
        $this->assertSame('a2', $src->accessToken());
        $this->assertCount(2, $this->calls);
        parse_str((string) $this->calls[1]['body'], $form);
        $this->assertSame('rt-old', $form['refresh_token']);
    }
}
