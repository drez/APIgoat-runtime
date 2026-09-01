<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Google\ClientFactory;
use ApiGoat\Google\JwtSigner;
use ApiGoat\Mail\Token\DwdTokenSource;
use ApiGoat\Mail\Token\OauthTokenSource;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\TransientError;
use PHPUnit\Framework\TestCase;

final class TokenSourceTest extends TestCase
{
    public function testOauthRefreshesOnceCachesAndReportsPersistHook(): void
    {
        $calls = [];
        $persisted = null;
        $http = function (string $m, string $url, array $h, ?string $body) use (&$calls) {
            $calls[] = compact('m', 'url', 'body');
            return ['status' => 200, 'headers' => '', 'body' => json_encode(['access_token' => 'at-' . count($calls), 'expires_in' => 3600, 'token_type' => 'Bearer'])];
        };
        $src = new OauthTokenSource('cid', 'sec', 'rt', 'u@x.com', $http, function (string $t, int $exp) use (&$persisted) { $persisted = [$t, $exp]; });
        $this->assertSame('at-1', $src->accessToken());
        $this->assertSame('at-1', $src->accessToken(), 'cached');
        $this->assertCount(1, $calls);
        $this->assertSame(OauthTokenSource::TOKEN_URL, $calls[0]['url']);
        parse_str($calls[0]['body'], $form);
        $this->assertSame(['grant_type' => 'refresh_token', 'client_id' => 'cid', 'client_secret' => 'sec', 'refresh_token' => 'rt'], $form);
        $this->assertSame('at-1', $persisted[0]);
        $this->assertGreaterThan(time() + 3000, $persisted[1]);
        $this->assertSame('oauth:u@x.com', $src->describe());

        $src->invalidate();
        $this->assertSame('at-2', $src->accessToken());
    }

    public function testOauthAcceptsAPreCachedAccessToken(): void
    {
        $http = fn () => throw new \LogicException('must not hit the network');
        $src = new OauthTokenSource('cid', 'sec', 'rt', '', $http, null, 'cached', time() + 3600);
        $this->assertSame('cached', $src->accessToken());
        $stale = new OauthTokenSource('cid', 'sec', 'rt', '', fn () => ['status' => 200, 'headers' => '', 'body' => '{"access_token":"fresh","expires_in":10}'], null, 'cached', time() + 30);
        $this->assertSame('fresh', $stale->accessToken(), 'within 60 s of expiry ⇒ refresh');
    }

    public function testOauthInvalidGrantIsAuthFailedAnd5xxIsTransient(): void
    {
        $src = new OauthTokenSource('cid', 'sec', 'rt', 'u@x', fn () => ['status' => 400, 'headers' => '', 'body' => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}']);
        try {
            $src->accessToken();
            $this->fail('should throw');
        } catch (AuthFailed $e) {
            $this->assertStringContainsString('Token has been expired or revoked.', $e->getMessage());
            $this->assertStringContainsString('oauth:u@x', $e->getMessage());
        }
        $this->expectException(TransientError::class);
        (new OauthTokenSource('cid', 'sec', 'rt', '', fn () => ['status' => 502, 'headers' => '', 'body' => '']))->accessToken();
    }

    public function testOauthRequiresAllThreeSecrets(): void
    {
        $this->expectException(AuthFailed::class);
        new OauthTokenSource('cid', '', 'rt');
    }

    public function testDwdDelegatesToClientFactoryPerSubjectAndScopes(): void
    {
        $signer = new class ('sa@x', 'k') extends JwtSigner {
            public array $mints = [];
            public function mintAccessToken(array $scopes, ?string $subject = null): array
            {
                $this->mints[] = [$scopes, $subject];
                return ['access_token' => 'dwd-' . count($this->mints), 'expires_in' => 3600];
            }
        };
        $google = new ClientFactory($signer, fn () => ['status' => 200, 'headers' => '', 'body' => '{}']);
        $src = new DwdTokenSource($google, 'ada@corp.com', [ClientFactory::SCOPE_GMAIL_MODIFY]);
        $this->assertSame('dwd-1', $src->accessToken());
        $this->assertSame('dwd-1', $src->accessToken());
        $this->assertSame([[[ClientFactory::SCOPE_GMAIL_MODIFY], 'ada@corp.com']], $signer->mints);
        $src->invalidate();
        $this->assertSame('dwd-2', $src->accessToken());
        $this->assertSame('dwd:ada@corp.com', $src->describe());
        $this->assertSame('ada@corp.com', $src->subject());
        $this->expectException(\InvalidArgumentException::class);
        new DwdTokenSource($google, ' ');
    }
}
