<?php

namespace ApiGoat\Ai;

/**
 * Fixed-window rate limit / quota for AI endpoints.
 *
 * Three projects had solved this three ways, over three different stores:
 *
 *   vidifye     App\Domains\Ai\SearchThrottle — 60s/20-req per IP, counted
 *               from authy_log rows namespaced by `event`
 *   apichatbot  App\Domains\Corpus\Quota — per-API-key RPM + a monthly token
 *               cap counted from usage_log, emitting X-RateLimit and X-Quota
 *   apigTutor   App\Domains\Tutor\DailyUsage — per-user daily counters
 *
 * The mechanism vidifye chose is kept: count recent authy_log rows filtered
 * by subject/event within a window. authy_log already carries an `event`
 * column exactly for namespacing distinct throttle kinds, it is a normal
 * Propel-backed table (durable across requests and workers, unlike APCu
 * which is per-process), and it is already audited.
 *
 * FAIL OPEN. A missing throttle table, or any error counting, allows the
 * request. Rate limiting is a fair-use control, not an authorization
 * boundary — the RBAC/ACL layer is the boundary, and breaking every AI
 * request because a log table is absent trades a small abuse risk for a
 * total outage.
 */
final class AiQuota
{
    /**
     * Pure fixed-window predicate, extracted so the math is unit testable
     * without a DB: allowed while the count already seen in the window is
     * below the cap. A cap of 0 or less means unlimited.
     */
    public static function withinLimit(int $recentCount, int $max): bool
    {
        if ($max <= 0) {
            return true;
        }

        return $recentCount < $max;
    }

    /**
     * Check and record one request against the manifest's quota config.
     *
     * @param string $subject the throttled identity — an IP, API key or user id
     * @return bool true when allowed (and recorded), false when throttled
     */
    public static function allow(string $subject, string $event = 'ai_call'): bool
    {
        $q = AiManifest::quota();
        $max = (int) ($q['max'] ?? 0);
        if ($max <= 0) {
            return true;
        }
        $window = (int) ($q['window'] ?? 60);

        if (!\class_exists('\App\AuthyLog') || !\method_exists('\App\AuthyLog', 'setEvent')) {
            return true; // no throttle table on this schema — fail open
        }

        $now = \time();
        try {
            if (!self::withinLimit(self::countRecent($subject, $event, $now, $window), $max)) {
                return false;
            }
            self::record($subject, $event, $now);
        } catch (\Throwable $e) {
            \error_log('AiQuota failed (failing open): ' . $e->getMessage());

            return true;
        }

        return true;
    }

    /**
     * Response headers describing the current window, for callers that
     * surface the limit to clients (apichatbot's Quota did this and it is
     * worth keeping — a 429 with no headers is undebuggable).
     *
     * @return array<string,string>
     */
    public static function headers(int $recentCount): array
    {
        $q = AiManifest::quota();
        $max = (int) ($q['max'] ?? 0);
        if ($max <= 0) {
            return [];
        }

        return [
            'X-RateLimit-Limit'     => (string) $max,
            'X-RateLimit-Remaining' => (string) \max(0, $max - $recentCount),
            'X-RateLimit-Window'    => (string) (int) ($q['window'] ?? 60),
        ];
    }

    private static function countRecent(string $subject, string $event, int $now, int $window): int
    {
        return (int) \App\AuthyLogQuery::create()
            ->filterByEvent($event)
            ->filterByIp(self::subjectKey($subject))
            ->filterByTimestamp($now - $window, \Criteria::GREATER_EQUAL)
            ->count();
    }

    private static function record(string $subject, string $event, int $now): void
    {
        $row = new \App\AuthyLog();
        $row->setEvent($event);
        $row->setIp(self::subjectKey($subject));
        // login and result are NOT NULL on authy_log; the throttle rows carry
        // no login, so write empty strings exactly as SearchThrottle does.
        $row->setLogin('');
        $row->setResult('');
        // authy_log declares add_tablestamp exclude="all", so there is no
        // date_creation to fall back on — `timestamp` is the only time column,
        // and it stores a unix int, not a datetime string.
        $row->setTimestamp($now);
        $row->save();
    }

    /**
     * Fit any subject into authy_log.ip, which is VARCHAR(16).
     *
     * That width holds an IPv4 address and nothing else: an IPv6 address is up
     * to 45 characters, and an API key or user identifier is arbitrary. A
     * silently truncated subject would merge distinct callers into one bucket
     * and throttle them together, so anything that does not fit is replaced by
     * a hash — collisions become vanishingly unlikely instead of systematic.
     */
    public static function subjectKey(string $subject): string
    {
        if (\strlen($subject) <= 16) {
            return $subject;
        }

        return \substr(\hash('sha256', $subject), 0, 16);
    }
}
