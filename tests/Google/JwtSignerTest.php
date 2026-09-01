<?php

namespace ApiGoat\Tests\Google;

use ApiGoat\Google\JwtSigner;
use ApiGoat\Sync\Exceptions\AuthFailed;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

final class JwtSignerTest extends TestCase
{
    private static string $priv;
    private static string $pub;

    public static function setUpBeforeClass(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $priv);
        self::$priv = $priv;
        self::$pub  = openssl_pkey_get_details($res)['key'];
    }

    private function saJson(): string
    {
        return json_encode(['type' => 'service_account', 'client_email' => 'sa@proj.iam.gserviceaccount.com', 'private_key' => self::$priv]);
    }

    public function testAssertionCarriesIssScopeAudAndSub(): void
    {
        $signer = JwtSigner::fromServiceAccount($this->saJson());
        $now    = time();
        $jwt    = $signer->assertion(['https://www.googleapis.com/auth/gmail.readonly'], 'ada@example.com', $now);
        $claims = (array) JWT::decode($jwt, new Key(self::$pub, 'RS256'));
        $this->assertSame('sa@proj.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('https://www.googleapis.com/auth/gmail.readonly', $claims['scope']);
        $this->assertSame(JwtSigner::TOKEN_URL, $claims['aud']);
        $this->assertSame('ada@example.com', $claims['sub']);
        $this->assertSame($now + 3600, $claims['exp']);
        $this->assertSame($now, $claims['iat']);
    }

    public function testNoSubjectMeansNoSubClaim(): void
    {
        $signer = JwtSigner::fromServiceAccount(json_decode($this->saJson(), true));
        $claims = (array) JWT::decode($signer->assertion(['s'], null, time()), new Key(self::$pub, 'RS256'));
        $this->assertArrayNotHasKey('sub', $claims);
    }

    public function testMintPostsJwtBearerGrantAndReturnsToken(): void
    {
        $seen = [];
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$seen) {
            $seen = compact('method', 'url', 'headers', 'body');
            return ['status' => 200, 'headers' => '', 'body' => json_encode(['access_token' => 'ya29.x', 'expires_in' => 3599])];
        };
        $tok = JwtSigner::fromServiceAccount($this->saJson(), $transport)->mintAccessToken(['a', 'b'], 'u@x.com');
        $this->assertSame(['access_token' => 'ya29.x', 'expires_in' => 3599], $tok);
        $this->assertSame('POST', $seen['method']);
        $this->assertSame(JwtSigner::TOKEN_URL, $seen['url']);
        parse_str($seen['body'], $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        $this->assertSame('a b', ((array) JWT::decode($form['assertion'], new Key(self::$pub, 'RS256')))['scope']);
    }

    public function testExchangeFailureIsAuthFailedWithGoogleDescription(): void
    {
        $transport = fn () => ['status' => 400, 'headers' => '', 'body' => json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'])];
        $this->expectException(AuthFailed::class);
        $this->expectExceptionMessage('(sub=u@x.com): Invalid JWT Signature.');
        JwtSigner::fromServiceAccount($this->saJson(), $transport)->mintAccessToken(['a'], 'u@x.com');
    }

    public function testMissingKeyMaterialIsAuthFailedNotAWarning(): void
    {
        $this->expectException(AuthFailed::class);
        JwtSigner::fromServiceAccount('{"client_email":"x@y"}');
    }

    public function testKeyFileFallsBackToClientEmailInsideTheJson(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($f, $this->saJson());
        try {
            $this->assertSame('sa@proj.iam.gserviceaccount.com', JwtSigner::fromKeyFile('', $f)->serviceEmail());
        } finally {
            unlink($f);
        }
    }
}
