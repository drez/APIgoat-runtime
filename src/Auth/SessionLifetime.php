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

        session_name('ApiGoat');
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
