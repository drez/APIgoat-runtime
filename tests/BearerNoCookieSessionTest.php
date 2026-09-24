<?php

declare(strict_types=1);

namespace ApiGoat\Tests;

use ApiGoat\Auth\SessionLifetime;
use ApiGoat\OAuth\BearerSessionAuthenticator;
use ApiGoat\Utility\TableVersion;
use PHPUnit\Framework\TestCase;

/**
 * Review 3 (2026-09-23): a bearer request hydrated a cookie session that PHP
 * then persisted (30-day ApiGoat cookie that authenticated on its own), and
 * the bearer fast-path cache key carried no project namespace (APCu is shared
 * by every project on one PHP-FPM master) nor the token's expiry.
 */
final class BearerNoCookieSessionTest extends TestCase
{
    public function testBearerHeadersAreRecognisedHoweverExposed(): void
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_AUTHORIZATION'] as $k) {
            self::assertTrue(SessionLifetime::isBearerRequest([$k => 'Bearer abc.def.ghi']), $k);
            self::assertTrue(SessionLifetime::isBearerRequest([$k => 'bearer abc']), $k . ' lowercase');
        }
    }

    public function testEveryJwtDeliveryJimToolsAcceptsIsABearer(): void
    {
        // JwtAuthentication's /Bearer\s+(.*)$/i: any whitespace after Bearer.
        self::assertTrue(SessionLifetime::isBearerRequest(['HTTP_AUTHORIZATION' => "Bearer\tabc"]));
        self::assertTrue(SessionLifetime::isBearerRequest(['HTTP_AUTHORIZATION' => 'Bearer   abc']));
        self::assertTrue(SessionLifetime::isBearerRequest(['HTTP_AUTHORIZATION' => '  bearer abc']));
        self::assertFalse(SessionLifetime::isBearerRequest(['HTTP_AUTHORIZATION' => 'Bearer ']));
        // ... and its `token` cookie on API routes.
        self::assertTrue(SessionLifetime::isBearerRequest(['REQUEST_URI' => '/x/api/v1/Client?a=1'], ['token' => 'abc.def.ghi']));
        self::assertFalse(SessionLifetime::isBearerRequest(['REQUEST_URI' => '/x/Client'], ['token' => 'abc']), 'GUI page keeps its session');
        self::assertFalse(SessionLifetime::isBearerRequest(['REQUEST_URI' => '/x/api/v1/Client'], ['token' => '']));
    }

    public function testNonBearerRequestsKeepTheirSession(): void
    {
        self::assertFalse(SessionLifetime::isBearerRequest([]));
        self::assertFalse(SessionLifetime::isBearerRequest(['HTTP_AUTHORIZATION' => '']));
        self::assertFalse(SessionLifetime::isBearerRequest(['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz']));
        self::assertFalse(SessionLifetime::isBearerRequest(['HTTP_COOKIE' => 'ApiGoat=x']));
    }

    public function testCacheKeyIsProjectNamespaced(): void
    {
        $key = BearerSessionAuthenticator::cacheKey('tok');
        self::assertSame('gc:bearer:' . TableVersion::ns() . ':' . hash('sha256', 'tok'), $key);
        self::assertStringNotContainsString('tok:', $key);
        self::assertSame('', BearerSessionAuthenticator::cacheKey(''));
    }

    public function testJwtExpIsRead(): void
    {
        $b64 = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
        $jwt = $b64(['alg' => 'RS256']) . '.' . $b64(['exp' => 1700000000, 'sub' => '5']) . '.sig';
        self::assertSame(1700000000, BearerSessionAuthenticator::jwtExp($jwt));
        self::assertNull(BearerSessionAuthenticator::jwtExp($b64(['alg' => 'x']) . '.' . $b64(['sub' => '5']) . '.s'));
        self::assertNull(BearerSessionAuthenticator::jwtExp('opaque-token'));
    }
}
