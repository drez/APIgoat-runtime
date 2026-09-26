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

    public function testOnByDefault(): void
    {
        // Default ON since 2026-09-23 (fleet scan: no GET under /api/ logs in).
        self::assertTrue(SessionLifetime::shouldDeferGuiSession(self::ANON_API, []));
        putenv('GC_SESSION_DEFER_ANON_API=');
        self::assertTrue(SessionLifetime::shouldDeferGuiSession(self::ANON_API, []), 'empty = unset = default');
    }

    public function testFalsyFlagValuesOptOut(): void
    {
        foreach (['0', 'false', 'no', 'off', 'OFF', ' No '] as $v) {
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

    public function testGuiSessionBootsInStrictMode(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Auth/SessionLifetime.php');
        $strict = strpos($src, "ini_set('session.use_strict_mode', '1');");
        $start  = strpos($src, 'session_start();');
        self::assertNotFalse($strict);
        self::assertLessThan($start, $strict, 'strict mode must be set before session_start()');
        // The cookie name stays 'ApiGoat' (renaming it would sign everyone out).
        self::assertSame('ApiGoat', SessionLifetime::GUI_COOKIE);
    }

    public function testWithoutCookieDropsOnlyTheNamedSessionCookie(): void
    {
        $headers = [
            'Set-Cookie: ApiGoat=abc123; expires=Mon, 26 Oct 2026 00:00:00 GMT; path=/; secure; HttpOnly; SameSite=Lax',
            'Set-Cookie: other=1; path=/',
            'Cache-Control: no-store',
            'set-cookie: ApiGoat=deleted; path=/',
            'Set-Cookie: ApiGoatX=keep; path=/',
        ];
        self::assertSame(
            ['Set-Cookie: other=1; path=/', 'Set-Cookie: ApiGoatX=keep; path=/'],
            \array_values(SessionLifetime::setCookiesWithout($headers, 'ApiGoat'))
        );
    }

    /**
     * An anonymous visitor with no ApiGoat cookie who is only being bounced
     * to the login page gets no session: no file (3,834 of them on prod,
     * mostly scanners) and no cookie.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testDiscardNewSessionRemovesTheFileWhenNoCookieWasSent(): void
    {
        $dir = sys_get_temp_dir() . '/gc-sess-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        session_save_path($dir);
        session_start();
        $file = $dir . '/sess_' . session_id();
        $_SESSION['x'] = 1;
        session_write_close();
        session_start();
        self::assertFileExists($file);

        self::assertTrue(SessionLifetime::discardNewSession([]));

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertFileDoesNotExist($file);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testDiscardNewSessionNeverTouchesAnExistingCookieSession(): void
    {
        session_save_path(sys_get_temp_dir());
        session_start();
        self::assertFalse(SessionLifetime::discardNewSession([SessionLifetime::GUI_COOKIE => 'abc']));
        self::assertFalse(SessionLifetime::discardNewSession([session_name() => 'abc']), 'the running session\'s own cookie counts too');
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        session_destroy();
    }
}
