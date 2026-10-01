<?php

namespace ApiGoat\Ops;

/**
 * Daily age-based retention of the generic IP-bearing log tables
 * (authy_log, api_log, client_event, contact_message — see
 * Retention::pruneLogs()) for EVERY gc project, not only the ones that
 * declared with_ops_monitor (privacy audit 2026-10-01).
 *
 * Projects WITH the ops monitor are left to Ops\Tick, whose daily `prune` job
 * already calls pruneLogs() with the manifest's windows — LogPrune stands
 * aside there so the work never runs twice. Without the monitor there is no
 * manifest, so Config::get() returns the fleet defaults (90 days for the
 * three logs, 365 for contact messages).
 *
 * Scheduling mirrors Tick: SecurityHeadersMiddleware (present in every
 * project's middleware stack) calls schedule(), which costs one stat of a
 * state file per request and, at most once a day, queues run() for after the
 * response via RequestRecorder::deferHook(). A non-blocking flock keeps
 * concurrent requests from doing the work twice. Never throws.
 */
final class LogPrune
{
    public const INTERVAL = 86400;
    private const STATE = 'log-prune.json';
    private const LOCK = 'log-prune.lock';

    /** Per-request entry point: cheap check, real work deferred past the response. */
    public static function schedule(): void
    {
        try {
            if (self::due()) {
                RequestRecorder::deferHook(static function (): void {
                    self::run();
                });
            }
        } catch (\Throwable $e) {
            \error_log('[ops] log prune schedule failed: ' . $e->getMessage());
        }
    }

    /** True when this project should prune now (monitor off, tmp/ present, a day since the last run). */
    public static function due(?int $now = null, ?string $dir = null): bool
    {
        if (Config::enabled()) {
            return false;
        }
        $dir ??= self::defaultDir();
        if ($dir === null || !\is_dir($dir)) {
            return false;
        }
        $now ??= \time();

        return $now - self::lastRun($dir . self::STATE) >= self::INTERVAL;
    }

    /**
     * Prune if due. Returns the per-table delete counts, or null when skipped
     * (not due, monitor on, lock held, or the pruner failed).
     *
     * @param ?callable $pruner test seam: fn(int $now, array $days): array
     * @return array<string,int>|null
     */
    public static function run(?int $now = null, ?string $dir = null, ?callable $pruner = null): ?array
    {
        $now ??= \time();
        $dir ??= self::defaultDir();
        if (!self::due($now, $dir)) {
            return null;
        }
        $lock = @\fopen($dir . self::LOCK, 'c');
        if ($lock === false || !\flock($lock, \LOCK_EX | \LOCK_NB)) {
            return null;
        }
        try {
            if ($now - self::lastRun($dir . self::STATE) < self::INTERVAL) {
                return null; // another request ran it while we waited for nothing
            }
            // Stamp BEFORE pruning: a failure must not be retried by every
            // following request for the rest of the day.
            @\file_put_contents($dir . self::STATE, (string) \json_encode(['ran' => $now]));
            $days = self::days();
            try {
                return $pruner !== null
                    ? $pruner($now, $days)
                    : Retention::pruneLogs(\Propel::getConnection(\defined('_DATA_SRC') ? _DATA_SRC : null), $now, $days);
            } catch (\Throwable $e) {
                \error_log('[ops] log prune failed: ' . $e->getMessage());
                return null;
            }
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }
    }

    /** @return array<string,int> Config key => retention days (fleet defaults without a manifest). */
    private static function days(): array
    {
        $out = [];
        foreach (\array_keys(Retention::logDayKeys()) as $key) {
            $out[$key] = (int) Config::get($key);
        }
        return $out;
    }

    private static function lastRun(string $file): int
    {
        $raw = \is_file($file) ? @\file_get_contents($file) : false;
        $d = $raw !== false ? \json_decode($raw, true) : null;
        return \is_array($d) ? (int) ($d['ran'] ?? 0) : 0;
    }

    private static function defaultDir(): ?string
    {
        return \defined('_BASE_DIR') ? _BASE_DIR . 'tmp/' : null;
    }
}
