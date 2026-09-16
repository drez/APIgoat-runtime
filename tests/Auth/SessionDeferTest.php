<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\SessionLifetime;
use PHPUnit\Framework\TestCase;

/**
 * Truth table for SessionLifetime::shouldDeferGuiSession() — the predicate
 * that keeps anonymous public API GETs from minting a session file and a
 * Set-Cookie. Pure function, so no session is ever started here.
 */
final class SessionDeferTest extends TestCase
{
    private const ANON_API = [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI'    => '/.admin/api/v1/Product/list?page=1',
    ];

    protected function setUp(): void
    {
        putenv('GC_SESSION_DEFER_ANON_API');
    }

    protected function tearDown(): void
    {
        putenv('GC_SESSION_DEFER_ANON_API');
    }

    public function testOffByDefault(): void
    {
        self::assertFalse(SessionLifetime::shouldDeferGuiSession(self::ANON_API, []));
    }

    public function testFalsyFlagValuesStayOff(): void
    {
        foreach (['0', 'false', 'no', 'off', ''] as $v) {
            putenv('GC_SESSION_DEFER_ANON_API=' . $v);
            self::assertFalse(SessionLifetime::shouldDeferGuiSession(self::ANON_API, []), "flag=$v");
        }
    }

    public function testAllClearDefers(): void
    {
        foreach (['1', 'true', 'yes', 'TRUE', ' Yes '] as $v) {
            putenv('GC_SESSION_DEFER_ANON_API=' . $v);
            self::assertTrue(SessionLifetime::shouldDeferGuiSession(self::ANON_API, []), "flag=$v");
        }
        self::assertTrue(SessionLifetime::shouldDeferGuiSession(['REQUEST_METHOD' => 'HEAD'] + self::ANON_API, []));
        // Other cookies are irrelevant; only the session cookie matters.
        self::assertTrue(SessionLifetime::shouldDeferGuiSession(self::ANON_API, ['lang' => 'fr']));
        self::assertTrue(SessionLifetime::shouldDeferGuiSession(self::ANON_API, [SessionLifetime::GUI_COOKIE => '']));
    }

    public function testPostDoesNotDefer(): void
    {
        putenv('GC_SESSION_DEFER_ANON_API=1');
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', ''] as $m) {
            self::assertFalse(SessionLifetime::shouldDeferGuiSession(['REQUEST_METHOD' => $m] + self::ANON_API, []), "method=$m");
        }
    }

    public function testSessionCookieDoesNotDefer(): void
    {
        putenv('GC_SESSION_DEFER_ANON_API=1');
        self::assertFalse(SessionLifetime::shouldDeferGuiSession(self::ANON_API, [SessionLifetime::GUI_COOKIE => 'abc123']));
        self::assertSame('ApiGoat', SessionLifetime::GUI_COOKIE, 'must match session_name() in startGuiSession()');
    }

    public function testAuthHeadersDoNotDefer(): void
    {
        putenv('GC_SESSION_DEFER_ANON_API=1');
        self::assertFalse(SessionLifetime::shouldDeferGuiSession(self::ANON_API + ['HTTP_AUTHORIZATION' => 'Bearer x'], []));
        self::assertFalse(SessionLifetime::shouldDeferGuiSession(self::ANON_API + ['HTTP_X_AUTHORIZATION' => 'Bearer x'], []));
    }

    public function testNonApiPathDoesNotDefer(): void
    {
        putenv('GC_SESSION_DEFER_ANON_API=1');
        foreach (['/.admin/', '/.admin/index.php', '/.admin/Product/list', '/api/v/Product', '', '/x?redirect=/api/v1/'] as $uri) {
            self::assertFalse(SessionLifetime::shouldDeferGuiSession(['REQUEST_URI' => $uri] + self::ANON_API, []), "uri=$uri");
        }
        // With and without the .admin prefix, any version.
        self::assertTrue(SessionLifetime::shouldDeferGuiSession(['REQUEST_URI' => '/api/v2/Thing'] + self::ANON_API, []));
    }
}
