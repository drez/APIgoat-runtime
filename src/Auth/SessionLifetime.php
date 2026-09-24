<?php

declare(strict_types=1);

namespace ApiGoat\Auth;

/**
 * Session-lifetime knobs, read from the project .env (values in DAYS):
 *
 *   GC_SESSION_GUI_DAYS  browser (PHP session)        default 30, max 90
 *   GC_SESSION_API_DAYS  API bearer (JWT refresh)     default 90, max 365
 *   GC_SESSION_MCP_DAYS  OAuth bearer (MCP + mobile)  default 90, max 365
 *
 * Unset, non-numeric or < 1 values fall back to the default; values above
 * the cap are clamped down to it. Centralised here (shared runtime) so the
 * policy propagates via composer — per-project config copies
 * (settings.defaults.php, legacy.php) are NOT drift-synced.
 */
final class SessionLifetime
{
    public const GUI_DEFAULT_DAYS = 30;
    public const GUI_MAX_DAYS     = 90;
    public const API_DEFAULT_DAYS = 90;
    public const API_MAX_DAYS     = 365;
    public const MCP_DEFAULT_DAYS = 90;
    public const MCP_MAX_DAYS     = 365;

    public static function guiDays(): int
    {
        return self::envDays('GC_SESSION_GUI_DAYS', self::GUI_MAX_DAYS) ?? self::GUI_DEFAULT_DAYS;
    }

    public static function apiDays(): int
    {
        return self::apiDaysFromEnv() ?? self::API_DEFAULT_DAYS;
    }

    /**
     * The API knob alone, null when unset/invalid — RefreshTokenService uses
     * this to let a set knob override stale per-project jwt_middleware
     * settings while an unset knob still honours them.
     */
    public static function apiDaysFromEnv(): ?int
    {
        return self::envDays('GC_SESSION_API_DAYS', self::API_MAX_DAYS);
    }

    public static function mcpDays(): int
    {
        return self::envDays('GC_SESSION_MCP_DAYS', self::MCP_MAX_DAYS) ?? self::MCP_DEFAULT_DAYS;
    }

    /** OAuth refresh-token TTL for the authorization server (MCP + mobile app). */
    public static function mcpRefreshTtl(): \DateInterval
    {
        return new \DateInterval('P' . self::mcpDays() . 'D');
    }

    /**
     * GUI session boot — owns what config/legacy.php used to do inline, plus
     * the lifetime policy. The cookie lifetime is absolute from the last
     * session-id issue (login regenerates the id, so effectively N days from
     * login); the server-side file slides on activity via gc_maxlifetime.
     */
    public static function startGuiSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        if (self::shouldDeferGuiSession($_SERVER, $_COOKIE)) {
            return;
        }
        // A bearer request authenticates from its token on every call; it
        // must never own a cookie session. Starting one here let the bearer
        // hydrate (setSession + regenerate) persist a 30-day ApiGoat cookie
        // that then authenticated on its own — outliving a 15-minute or
        // revoked token. $_SESSION stays a plain in-process array, exactly
        // like the deferred anonymous path above.
        if (self::isBearerRequest($_SERVER, $_COOKIE)) {
            return;
        }
        // The API credential exchange (POST api/vN/Authy/auth|refresh) answers
        // with a token and nothing else: it used to start (and persist) an
        // ApiGoat cookie session too, and its "clean session for API auth"
        // replaced — i.e. signed out — the GUI session of a browser that called
        // it with its cookie. The API client gets only the token.
        if (self::isApiTokenExchange($_SERVER)) {
            return;
        }
        $lifetime = self::guiDays() * 86400;

        // Long-lived sessions need a project-local save path: distro session
        // GC (e.g. Debian's sessionclean cron) reads php.ini — not runtime
        // ini_set() — and would reap files in the shared path after the
        // php.ini gc_maxlifetime (~24 min idle). .admin/tmp/ is denied by the
        // template .htaccess, and the dir is 0700.
        if (defined('_BASE_DIR')) {
            $dir = rtrim((string) constant('_BASE_DIR'), '/\\') . DIRECTORY_SEPARATOR
                . 'tmp' . DIRECTORY_SEPARATOR . 'sessions';
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                ini_set('session.save_path', $dir);
                ini_set('session.gc_maxlifetime', (string) $lifetime);
                // Debian/Ubuntu ship gc_probability=0 (their cron does the
                // GC); nothing crons our private dir, so re-enable PHP's own.
                ini_set('session.gc_probability', '1');
                ini_set('session.gc_divisor', '100');
            }
        }

        // Strict mode: never adopt a session id the server did not issue (a
        // planted/unknown ApiGoat cookie gets a fresh id instead of creating
        // a session file under the attacker's chosen id). The cookie NAME is
        // unchanged on purpose — renaming it would sign every user out.
        ini_set('session.use_strict_mode', '1');
        session_name(self::GUI_COOKIE);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'httponly' => true,
            'samesite' => 'Lax',
            // Mark the cookie secure whenever the request came in over TLS
            // (directly or via a reverse proxy).
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        ]);
        session_start();
    }

    /**
     * True when the request carries a Bearer credential (Authorization or
     * X-Authorization, however the web server exposed it).
     *
     * SECURITY: the same test JimTools JwtAuthentication applies
     * (/Bearer\s+(.*)$/i, so any whitespace, not just "Bearer "), plus its
     * `token` cookie on /api/v* routes — a JWT delivered either way used to
     * mint a 30-day cookie session.
     *
     * @param array<string,mixed> $server $_SERVER
     * @param array<string,mixed> $cookies $_COOKIE
     */
    public static function isBearerRequest(array $server, array $cookies = []): bool
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_AUTHORIZATION'] as $k) {
            if (self::hasBearerCredential((string) ($server[$k] ?? ''))) {
                return true;
            }
        }
        if (is_string($cookies['token'] ?? null) && trim($cookies['token']) !== '') {
            $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            return (bool) preg_match('#/api/v[0-9]+/#', $path);
        }
        return false;
    }

    /** Header value carries a bearer token — JwtAuthentication's own pattern. */
    public static function hasBearerCredential(string $header): bool
    {
        return (bool) preg_match(self::BEARER_PATTERN, $header);
    }

    /** JimTools JwtAuthentication's default `regexp`, with a non-empty token. */
    public const BEARER_PATTERN = '/Bearer\s+(\S.*)$/i';

    /**
     * POST to the API credential exchange (api/vN/Authy/auth or /refresh):
     * a token-only response that must not own a cookie session.
     *
     * @param array<string,mixed> $server $_SERVER
     */
    public static function isApiTokenExchange(array $server): bool
    {
        if (strtoupper((string) ($server['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return false;
        }
        $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return (bool) preg_match('#/api/v[0-9]+/Authy/(auth|refresh)/?$#', $path);
    }

    /** Cookie name startGuiSession() registers via session_name(). */
    public const GUI_COOKIE = 'ApiGoat';

    /**
     * Should the GUI session boot be skipped for this request?
     *
     * Why: every anonymous public API GET (feeds, browse lists, category
     * trees — the traffic PublicResponseCacheMiddleware exists for) used to
     * mint a brand-new session file in tmp/sessions AND a Set-Cookie on
     * every response, because startGuiSession() ran unconditionally from
     * legacy.php. Thousands of one-shot files for visitors that never log in,
     * plus a cookie that makes every shared cache treat the response as
     * personal. Deferring costs nothing downstream: $_SESSION keeps working
     * as a plain (empty) array, so the `connected` / `isRoot` reads in the
     * RBAC, TableVersion::tenantToken() and the actions all see "not logged
     * in" exactly as they would with a fresh empty session.
     *
     * Pure: takes $_SERVER / $_COOKIE as arguments so the truth table is
     * unit-testable without a session. True only when ALL of:
     *   - GC_SESSION_DEFER_ANON_API is not switched off. DEFAULT ON since
     *     2026-09-23 (was opt-in): a fleet scan found no GET under /api/ that
     *     logs a user in or writes a session that must persist (the /api/
     *     Authy/confirm GETs only activate the row; magic-link / OAuth
     *     callbacks are not routed under /api/). A project that adds such a
     *     route sets GC_SESSION_DEFER_ANON_API=0 (also false/no/off);
     *   - the method is GET or HEAD (a POST may be a login);
     *   - no `ApiGoat` session cookie (a returning user must be re-hydrated);
     *   - no Authorization / X-Authorization header (bearer flows may write
     *     to the session before SessionReleaseMiddleware closes it);
     *   - the path is an API route (/api/vN/): GUI pages always get a session.
     *
     * @param array<string,mixed> $server  $_SERVER
     * @param array<string,mixed> $cookies $_COOKIE
     */
    public static function shouldDeferGuiSession(array $server, array $cookies): bool
    {
        if (!self::deferAnonApiEnabled()) {
            return false;
        }
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? ''));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }
        if (isset($cookies[self::GUI_COOKIE]) && (string) $cookies[self::GUI_COOKIE] !== '') {
            return false;
        }
        if (!empty($server['HTTP_AUTHORIZATION']) || !empty($server['HTTP_X_AUTHORIZATION'])) {
            return false;
        }
        $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return (bool) preg_match('#/api/v[0-9]+/#', $path);
    }

    /** GC_SESSION_DEFER_ANON_API: default ON; '0'/'false'/'no'/'off' opts out. */
    public static function deferAnonApiEnabled(): bool
    {
        if (\function_exists('env')) {
            // Ahc\Env\Retriever turns "true"/"false" into bools; unset = null.
            $flag = env('GC_SESSION_DEFER_ANON_API');
            if (is_bool($flag)) {
                return $flag;
            }
        } else {
            $flag = getenv('GC_SESSION_DEFER_ANON_API'); // unset = false
            if ($flag === false) {
                return true;
            }
        }
        if ($flag === null) {
            return true;
        }
        $v = strtolower(trim((string) $flag));
        return !in_array($v, ['0', 'false', 'no', 'off'], true);
    }

    private static function envDays(string $key, int $max): ?int
    {
        $raw = getenv($key);
        if ($raw === false) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return null;
        }
        return min($n, $max);
    }
}
