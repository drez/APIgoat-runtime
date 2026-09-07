<?php

namespace ApiGoat\Tests\Google;

use ApiGoat\Google\ClientFactory;
use ApiGoat\Google\OauthConsent;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\TransientError;
use PHPUnit\Framework\TestCase;

/**
 * The consent half of per-user OAuth: build the Google authorization URL,
 * then turn the code Google hands back into a REFRESH token. Every test runs
 * on a fake transport — nothing here may reach Google.
 */
final class OauthConsentTest extends TestCase
{
    /** @var array<int,array{method:string,url:string,headers:string[],body:?string}> */
    private array $calls = [];
    /** @var list<array{status:int, body:mixed, headers?:string}> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->calls     = [];
        $this->responses = [];
    }

    private function transport(): callable
    {
        return function (string $method, string $url, array $headers, ?string $body) {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            $r = array_shift($this->responses) ?? ['status' => 500, 'body' => 'no canned response for ' . $url];
            if (!is_string($r['body'])) {
                $r['body'] = (string) json_encode($r['body']);
            }
            return $r + ['headers' => ''];
        };
    }

    // ------------------------------------------------------------- authUrl()

    public function test_auth_url_carries_every_parameter_the_offline_flow_needs(): void
    {
        $url = OauthConsent::authUrl(
            'cid.apps.googleusercontent.com',
            'https://mail.example.com/Google/callback',
            [ClientFactory::SCOPE_GMAIL_READONLY, ClientFactory::SCOPE_CONTACTS_READONLY],
            'st/ate+with=specials'
        );

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertSame('cid.apps.googleusercontent.com', $q['client_id']);
        $this->assertSame('https://mail.example.com/Google/callback', $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('offline', $q['access_type'], 'without offline access Google never issues a refresh token');
        $this->assertSame('consent', $q['prompt'], 'without prompt=consent a second grant silently omits the refresh token');
        $this->assertSame('true', $q['include_granted_scopes']);
        $this->assertSame(
            ClientFactory::SCOPE_GMAIL_READONLY . ' ' . ClientFactory::SCOPE_CONTACTS_READONLY,
            $q['scope'],
            'scopes are space-joined'
        );
        $this->assertSame('st/ate+with=specials', $q['state'], 'the state survives url-encoding intact');
        $this->assertStringContainsString('state=st%2Fate%2Bwith%3Dspecials', $url);
    }

    // ------------------------------------------------------------ exchange()

    public function test_exchange_posts_the_authorization_code_and_returns_the_token_tuple(): void
    {
        $this->responses[] = ['status' => 200, 'body' => [
            'access_token'  => 'at-1',
            'refresh_token' => 'rt-1',
            'expires_in'    => 3599,
            'scope'         => 'https://www.googleapis.com/auth/gmail.readonly openid email',
            'token_type'    => 'Bearer',
        ]];
        $this->responses[] = ['status' => 200, 'body' => ['email' => 'Fred@Example.com', 'sub' => '123']];

        $out = OauthConsent::exchange('cid', 'csecret', 'https://x.example/Google/callback', 'the-code', $this->transport());

        $this->assertSame('POST', $this->calls[0]['method']);
        $this->assertSame('https://oauth2.googleapis.com/token', $this->calls[0]['url']);
        parse_str((string) $this->calls[0]['body'], $form);
        $this->assertSame('authorization_code', $form['grant_type']);
        $this->assertSame('the-code', $form['code']);
        $this->assertSame('cid', $form['client_id']);
        $this->assertSame('csecret', $form['client_secret']);
        $this->assertSame('https://x.example/Google/callback', $form['redirect_uri']);
        $this->assertContains('Content-Type: application/x-www-form-urlencoded', $this->calls[0]['headers']);

        $this->assertSame('rt-1', $out['refresh_token']);
        $this->assertSame('at-1', $out['access_token']);
        $this->assertSame(3599, $out['expires_in']);
        $this->assertSame('https://www.googleapis.com/auth/gmail.readonly openid email', $out['scope']);
        $this->assertSame('fred@example.com', $out['email'], 'the identity is lower-cased for comparison');
    }

    public function test_userinfo_is_fetched_with_the_access_token(): void
    {
        $this->responses[] = ['status' => 200, 'body' => ['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3599, 'scope' => 'openid email']];
        $this->responses[] = ['status' => 200, 'body' => ['email' => 'fred@example.com']];

        OauthConsent::exchange('cid', 'csecret', 'https://x.example/cb', 'code', $this->transport());

        $this->assertCount(2, $this->calls);
        $this->assertSame('GET', $this->calls[1]['method']);
        $this->assertSame('https://www.googleapis.com/oauth2/v3/userinfo', $this->calls[1]['url']);
        $this->assertContains('Authorization: Bearer at-1', $this->calls[1]['headers']);
    }

    public function test_a_missing_refresh_token_throws_and_says_how_to_recover(): void
    {
        // Google withholds refresh_token when this client already has a live
        // grant for the account — the only fix is to revoke and consent again.
        $this->responses[] = ['status' => 200, 'body' => ['access_token' => 'at-1', 'expires_in' => 3599, 'scope' => 'openid email']];

        try {
            OauthConsent::exchange('cid', 'csecret', 'https://x.example/cb', 'code', $this->transport());
            $this->fail('expected AuthFailed');
        } catch (AuthFailed $e) {
            $this->assertStringContainsString('refresh token', strtolower($e->getMessage()));
            $this->assertStringContainsString('myaccount.google.com', $e->getMessage());
            $this->assertStringContainsString('revoke', strtolower($e->getMessage()));
        }
        $this->assertCount(1, $this->calls, 'no userinfo call once the exchange is already lost');
    }

    public function test_a_userinfo_failure_leaves_the_email_null_and_does_not_lose_the_grant(): void
    {
        $this->responses[] = ['status' => 200, 'body' => ['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3599, 'scope' => 'openid email']];
        $this->responses[] = ['status' => 403, 'body' => ['error' => ['message' => 'nope']]];

        $out = OauthConsent::exchange('cid', 'csecret', 'https://x.example/cb', 'code', $this->transport());

        $this->assertSame('rt-1', $out['refresh_token']);
        $this->assertNull($out['email']);
    }

    public function test_userinfo_is_skipped_when_the_granted_scope_has_no_identity(): void
    {
        $this->responses[] = ['status' => 200, 'body' => [
            'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3599,
            'scope' => 'https://www.googleapis.com/auth/contacts.readonly',
        ]];

        $out = OauthConsent::exchange('cid', 'csecret', 'https://x.example/cb', 'code', $this->transport());

        $this->assertCount(1, $this->calls);
        $this->assertNull($out['email']);
    }

    public function test_a_rejected_code_is_auth_failed_with_googles_own_reason(): void
    {
        $this->responses[] = ['status' => 400, 'body' => ['error' => 'invalid_grant', 'error_description' => 'Bad Request']];

        try {
            OauthConsent::exchange('cid', 'csecret', 'https://x.example/cb', 'used-code', $this->transport());
            $this->fail('expected AuthFailed');
        } catch (AuthFailed $e) {
            $this->assertStringContainsString('Bad Request', $e->getMessage());
        }
    }

    public function test_a_token_endpoint_outage_is_transient(): void
    {
        $this->responses[] = ['status' => 503, 'body' => 'upstream down'];

        $this->expectException(TransientError::class);
        OauthConsent::exchange('cid', 'csecret', 'https://x.example/cb', 'code', $this->transport());
    }

    public function test_it_refuses_to_build_a_url_without_a_client_or_a_redirect(): void
    {
        $this->expectException(AuthFailed::class);
        OauthConsent::authUrl('', 'https://x.example/cb', ['openid'], 'st');
    }
}
