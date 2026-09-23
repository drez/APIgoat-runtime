<?php

declare(strict_types=1);

namespace ApiGoat\Auth;

use ApiGoat\Sessions\AuthySession;
use ApiGoat\Utility\MicroCache;
use ApiGoat\Utility\TableVersion;

/**
 * Account-security primitives shared by the emitted login code, the runtime
 * middlewares and each project's AccountServiceWrapper (which calls these as
 * one-liners, so a later fix ships through `gc upgrade` instead of a
 * per-project hand port).
 *
 * Session epoch (review 3, Wave 1 #5)
 * -----------------------------------
 * `authy.session_epoch` (INT NOT NULL DEFAULT 0, added to every auth table by
 * the GoatCheese AuthyBase behavior) is re-rolled to a fresh random value when
 * the password, deactivate or expire column changes (emitted Authy preSave)
 * and when a user's API/OAuth tokens are all revoked (revokeAllForUser()).
 * setSession() copies it into the AuthySession; AuthySession::revalidate()
 * logs out any session whose epoch no longer matches the row. A random value
 * rather than a counter: whatever a client posts for the column is overwritten
 * on the bump, and an old value can never come back around.
 *
 * Revalidation hits the DB at most once a minute on GETs, so a bump also
 * publishes a per-user marker in MicroCache (APCu): a session or cached bearer
 * blob whose epoch differs from the marker is re-checked on its very next
 * request. The marker only ever forces a check; the DB row stays the truth.
 *
 * Re-auth throttle (review 3, Wave 1 #9)
 * --------------------------------------
 * verifyCurrentPassword() guards the "confirm with your current password"
 * step of the account page: 5 failures per user in 15 minutes refuse further
 * checks (without running password_verify on the real hash) and sign the
 * asking session out. Failures are logged to authy_log (result 'reauth') —
 * durable and shared across FPM workers — with a MicroCache fallback when the
 * table is unavailable.
 */
final class AccountSecurity
{
    public const REAUTH_MAX_FAILURES = 5;
    public const REAUTH_WINDOW_SECONDS = 900;
    /** authy_log.result marker for a failed account re-auth. */
    public const REAUTH_LOG_RESULT = 'reauth';
    /** How long a published epoch marker forces re-checks (must outlive the
     *  60 s GUI recheck window and the bearer-cache TTL). */
    public const EPOCH_MARKER_TTL = 900;
    /** bcrypt of a random throwaway string: timing ballast for refused checks. */
    public const DUMMY_BCRYPT = '$2y$12$rxT/aYDKVbRKFsiMO9LKyOhHt/tnU2oa3p.hXDZxN6Ntg8d5QuoBO';

    // ---------------------------------------------------------------- epoch

    /** The row's session epoch, or null when the model has no such column. */
    public static function epochOf($authy): ?int
    {
        if (!\is_object($authy) || !\method_exists($authy, 'getSessionEpoch')) {
            return null;
        }
        try {
            return (int) $authy->getSessionEpoch();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** A fresh random epoch (fits a signed INT), never equal to $current. */
    public static function newEpoch(?int $current = null): int
    {
        do {
            $e = \random_int(1, 2147483647);
        } while ($e === $current);
        return $e;
    }

    /**
     * Re-roll $authy's epoch (every session opened before now is logged out on
     * its next check). Saves the row unless $save is false. Null when the model
     * has no session_epoch column (project not rebuilt yet).
     */
    public static function bumpEpoch($authy, bool $save = true): ?int
    {
        if (!\is_object($authy) || !\method_exists($authy, 'setSessionEpoch')) {
            return null;
        }
        $epoch = self::newEpoch(self::epochOf($authy));
        $authy->setSessionEpoch($epoch);
        if ($save) {
            $authy->save();
            self::publishEpoch((int) $authy->getIdAuthy(), $epoch);
        }
        return $epoch;
    }

    public static function markerKey(int $idAuthy): string
    {
        return 'gc:sepoch:' . TableVersion::ns() . ':' . $idAuthy;
    }

    /** Announce a user's new epoch so live sessions re-check immediately. */
    public static function publishEpoch(int $idAuthy, int $epoch): void
    {
        if ($idAuthy <= 0) {
            return;
        }
        try {
            MicroCache::put(self::markerKey($idAuthy), self::EPOCH_MARKER_TTL, $epoch);
        } catch (\Throwable $e) {
            // cache trouble only delays detection to the next periodic check
        }
    }

    public static function epochMarker(int $idAuthy): ?int
    {
        if ($idAuthy <= 0) {
            return null;
        }
        try {
            $v = MicroCache::get(self::markerKey($idAuthy));
        } catch (\Throwable $e) {
            return null;
        }
        return \is_int($v) ? $v : null;
    }

    /**
     * True when a published marker says $session's epoch is out of date: the
     * caller must re-check the session against the DB now.
     */
    public static function sessionEpochStale($session): bool
    {
        if (!\is_object($session) || !\method_exists($session, 'getIdAuthy')) {
            return false;
        }
        $marker = self::epochMarker((int) $session->getIdAuthy());
        if ($marker === null) {
            return false;
        }
        $mine = $session->get('session_epoch');
        return $mine === null || (int) $mine !== $marker;
    }

    /**
     * An app (HS256) JWT carries the epoch it was minted under (`sep`); the
     * request's session was hydrated from the row just now, so a difference
     * means the password / status changed after the token was issued. Tokens
     * without the claim (minted before it existed) and sessions without an
     * epoch pass.
     */
    public static function tokenEpochMismatch($claims, $session): bool
    {
        if (!\is_array($claims) || !isset($claims['sep']) || !\is_numeric($claims['sep'])) {
            return false;
        }
        if (!\is_object($session) || !\method_exists($session, 'get')) {
            return false;
        }
        $mine = $session->get('session_epoch');
        return $mine !== null && (int) $claims['sep'] !== (int) $mine;
    }

    // ------------------------------------------------------ token revocation

    /**
     * Revoke every API refresh token (with_refresh_tokens) and OAuth token
     * (mobile app, MCP connectors) of a user and, unless told otherwise,
     * re-roll the session epoch so the user's browser sessions end too.
     * Best effort per store: one failing store never blocks the other.
     */
    public static function revokeAllForUser(int $idAuthy, bool $bumpEpoch = true): void
    {
        if ($idAuthy <= 0) {
            return;
        }
        try {
            $svc = RefreshTokenService::forProject();
            if ($svc) {
                $svc->revokeAllForUser($idAuthy);
            }
        } catch (\Throwable $e) {
            \error_log('AccountSecurity: refresh-token revoke failed for user ' . $idAuthy . ': ' . $e->getMessage());
        }
        try {
            if (\class_exists('\\ApiGoat\\OAuth\\RefreshTokenRepository')
                && \class_exists('\\App\\OauthRefreshTokenQuery')
                && \class_exists('\\App\\OauthAccessTokenQuery')) {
                (new \ApiGoat\OAuth\RefreshTokenRepository())->revokeAllForUser($idAuthy);
            }
        } catch (\Throwable $e) {
            \error_log('AccountSecurity: OAuth token revoke failed for user ' . $idAuthy . ': ' . $e->getMessage());
        }
        if ($bumpEpoch && \class_exists('\\App\\AuthyQuery')) {
            try {
                $authy = \App\AuthyQuery::create()->findPk($idAuthy);
                if ($authy) {
                    self::bumpEpoch($authy);
                }
            } catch (\Throwable $e) {
                \error_log('AccountSecurity: epoch bump failed for user ' . $idAuthy . ': ' . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------- re-auth

    /**
     * Check the current password of the signed-in user before an account-
     * takeover-grade change. Throttled per user id; a refused (throttled) check
     * and a wrong password look identical to the caller (false), so the
     * throttle is no oracle. Reaching the limit signs the asking session out.
     */
    public static function verifyCurrentPassword($authy, string $plain): bool
    {
        if (!\is_object($authy) || !\method_exists($authy, 'getIdAuthy') || $plain === '') {
            return false;
        }
        $id = (int) $authy->getIdAuthy();
        if ($id <= 0) {
            return false;
        }
        if (self::reauthFailures($id) >= self::REAUTH_MAX_FAILURES) {
            \password_verify($plain, self::DUMMY_BCRYPT);
            \error_log('AccountSecurity: re-auth throttled for user ' . $id . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
            self::endSessionOf($id);
            return false;
        }
        $hash = (string) $authy->getPasswdHash();
        if ($hash !== '' && \password_verify($plain, $hash)) {
            return true;
        }
        if ($hash === '') {
            \password_verify($plain, self::DUMMY_BCRYPT);
        }
        $failures = self::recordReauthFailure($authy);
        \error_log('AccountSecurity: re-auth failed for user ' . $id . ' (' . $failures . '/' . self::REAUTH_MAX_FAILURES . ')');
        if ($failures >= self::REAUTH_MAX_FAILURES) {
            self::endSessionOf($id);
        }
        return false;
    }

    /** Failed re-auths of $idAuthy inside the window. */
    public static function reauthFailures(int $idAuthy): int
    {
        if (\class_exists('\\App\\AuthyLogQuery')) {
            try {
                return (int) \App\AuthyLogQuery::create()
                    ->filterByIdAuthy($idAuthy)
                    ->filterByResult(self::REAUTH_LOG_RESULT)
                    ->filterByTimestamp(\time() - self::REAUTH_WINDOW_SECONDS, \Criteria::GREATER_EQUAL)
                    ->count();
            } catch (\Throwable $e) {
                // fall through to the cache counter
            }
        }
        $v = MicroCache::get(self::reauthKey($idAuthy));
        return \is_array($v) ? (int) ($v['n'] ?? 0) : 0;
    }

    /** Log one failure; returns the failure count inside the window. */
    public static function recordReauthFailure($authy): int
    {
        $id = (int) $authy->getIdAuthy();
        if (\class_exists('\\App\\AuthyLog')) {
            try {
                $log = new \App\AuthyLog();
                $log->setIdAuthy($id);
                $log->setLogin(\mb_substr(\strtolower((string) (\method_exists($authy, 'getUsername') && $authy->getUsername()
                    ? $authy->getUsername() : (\method_exists($authy, 'getEmail') ? $authy->getEmail() : $id))), 0, 50));
                $log->setIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
                $log->setResult(self::REAUTH_LOG_RESULT);
                $log->setTimestamp(\time());
                $log->setCount(1);
                $log->save();
                return self::reauthFailures($id);
            } catch (\Throwable $e) {
                \error_log('AccountSecurity: re-auth log failed: ' . $e->getMessage());
            }
        }
        $key = self::reauthKey($id);
        $v = MicroCache::get($key);
        $v = \is_array($v) ? $v : ['n' => 0, 'since' => \time()];
        $v['n'] = (int) $v['n'] + 1;
        $ttl = \max(1, self::REAUTH_WINDOW_SECONDS - (\time() - (int) $v['since']));
        MicroCache::put($key, $ttl, $v);
        return $v['n'];
    }

    private static function reauthKey(int $idAuthy): string
    {
        return 'gc:reauth:' . TableVersion::ns() . ':' . $idAuthy;
    }

    // ------------------------------------------------------ password change

    /**
     * Call right after a user's password change was SAVED by that user. Every
     * other session and token of the user ends (the Authy preSave re-rolled the
     * epoch; API/OAuth tokens are revoked here), while the session making the
     * change continues: it gets a fresh session id and adopts the new epoch.
     */
    public static function passwordChanged($authy): void
    {
        if (!\is_object($authy) || !\method_exists($authy, 'getIdAuthy')) {
            return;
        }
        $id = (int) $authy->getIdAuthy();
        $session = (\defined('_AUTH_VAR') && isset($_SESSION[\_AUTH_VAR]) && \is_object($_SESSION[\_AUTH_VAR]))
            ? $_SESSION[\_AUTH_VAR] : null;
        $mine = ($session && \method_exists($session, 'getIdAuthy') && (int) $session->getIdAuthy() === $id)
            ? $session : null;

        $epoch = self::epochOf($authy);
        // Safety net: a model built without the preSave re-roll (or a save path
        // that skipped it) still leaves the epoch the session was opened with.
        if ($epoch !== null && $mine && $mine->get('session_epoch') !== null
            && (int) $mine->get('session_epoch') === $epoch) {
            $epoch = self::bumpEpoch($authy);
        } elseif ($epoch !== null) {
            self::publishEpoch($id, $epoch);
        }

        self::revokeAllForUser($id, false);

        if ($mine) {
            if (\session_status() === \PHP_SESSION_ACTIVE) {
                \session_regenerate_id(true);
                $mine->set('sess_id', \md5(\session_id()));
            }
            $mine->set('session_epoch', $epoch);
            $mine->set('stale_check_ts', \time());
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * A signed-out replacement for $prev: a fresh AuthySession carrying only the
     * per-browser state (UI language, per-request config, csrf token) so the
     * page's re-auth modal can still sign back in with the token it holds.
     */
    public static function signedOutSession($prev): AuthySession
    {
        $s = new AuthySession();
        if (\is_object($prev)) {
            $s->lang = $prev->lang ?? null;
            $s->config = \is_array($prev->config ?? null) ? $prev->config : [];
            if (\method_exists($prev, 'getCsrf')) {
                $s->setCsrf($prev->getCsrf());
            }
        }
        $s->set('isConnected', 'NO');
        return $s;
    }

    /** Sign the current session out when it belongs to $idAuthy. */
    private static function endSessionOf(int $idAuthy): void
    {
        if (!\defined('_AUTH_VAR') || !isset($_SESSION[\_AUTH_VAR]) || !\is_object($_SESSION[\_AUTH_VAR])) {
            return;
        }
        $s = $_SESSION[\_AUTH_VAR];
        if (!\method_exists($s, 'getIdAuthy') || (int) $s->getIdAuthy() !== $idAuthy) {
            return;
        }
        $_SESSION[\_AUTH_VAR] = self::signedOutSession($s);
        if (\session_status() === \PHP_SESSION_ACTIVE) {
            \session_regenerate_id(true);
        }
    }
}
