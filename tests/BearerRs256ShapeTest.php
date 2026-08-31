<?php

use ApiGoat\OAuth\BearerSessionAuthenticator;
use PHPUnit\Framework\TestCase;

/**
 * looksLikeRs256Jwt decides which side of the 401/fall-through line a bearer
 * that FAILED OAuth validation lands on (2026-08-31 incident: an expired
 * RS256 token fell through to HS256 JwtAuthentication and surfaced as 400,
 * which no client reads as "sign in again").
 */
final class BearerRs256ShapeTest extends TestCase
{
    private static function jwt(array $header): string
    {
        $b64 = rtrim(strtr(base64_encode((string) json_encode($header)), '+/', '-_'), '=');
        return $b64 . '.eyJzdWIiOiIxIn0.c2ln';
    }

    public function testAnRs256HeaderIsRecognised(): void
    {
        $this->assertTrue(BearerSessionAuthenticator::looksLikeRs256Jwt(
            self::jwt(['alg' => 'RS256', 'typ' => 'JWT'])
        ));
    }

    public function testHs256AndOtherAlgsFallThrough(): void
    {
        $this->assertFalse(BearerSessionAuthenticator::looksLikeRs256Jwt(self::jwt(['alg' => 'HS256'])));
        $this->assertFalse(BearerSessionAuthenticator::looksLikeRs256Jwt(self::jwt(['alg' => 'none'])));
        $this->assertFalse(BearerSessionAuthenticator::looksLikeRs256Jwt(self::jwt([])));
    }

    public function testNonJwtShapesFallThrough(): void
    {
        $this->assertFalse(BearerSessionAuthenticator::looksLikeRs256Jwt(''));
        $this->assertFalse(BearerSessionAuthenticator::looksLikeRs256Jwt('opaque-token'));
        $this->assertFalse(BearerSessionAuthenticator::looksLikeRs256Jwt('a.b'));
        $this->assertFalse(BearerSessionAuthenticator::looksLikeRs256Jwt('!!.??.##'));
        $this->assertFalse(BearerSessionAuthenticator::looksLikeRs256Jwt(
            rtrim(strtr(base64_encode('not json'), '+/', '-_'), '=') . '.x.y'
        ));
    }
}
