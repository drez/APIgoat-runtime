<?php

namespace ApiGoat\Ops;

/**
 * The ops housekeeping that needs no cron entry: RequestRecorder's
 * post-response shutdown hook calls maybeRun() on every request, and at
 * most once per INTERVAL one request actually does the work —
 *
 *   - Forwarder::run()     every tick (no-op unless GC_OPS_HUB_URL/KEY set)
 *   - ServerSnap::collect  hourly
 *   - Retention::prune     daily
 *   - IpInfo::resolvePending every tick (a few IPs per run, time-boxed)
 *
 * so every app with with_ops_monitor keeps its tables trimmed and reports
 * to the hub without hand-editing its config/cron.php. A project that ALSO
 * schedules these in cron (apigoatacc) is fine: all three are idempotent.
 *
 * Concurrency: a non-blocking flock — a request that finds the lock taken
 * skips instead of waiting. Never throws.
 */
final class Tick
{
    public const INTERVAL = 300;

    private const DUE = ['forward' => 300, 'snap' => 3600, 'prune' => 86400, 'ipinfo' => 300];

    /** @param ?string $dir test seam: where the lock/state files live */
    public static function maybeRun(?int $now = null, ?string $dir = null, ?callable $runner = null): ?array
    {
        if (!Config::enabled()) {
            return null;
        }
        $now ??= \time();
        $dir ??= \defined('_BASE_DIR') ? _BASE_DIR . 'tmp/' : null;
        if ($dir === null || !\is_dir($dir)) {
            return null;
        }
        $stateFile = $dir . 'ops-tick.json';

        // Cheap pre-check without the lock: most requests stop here.
        $state = self::load($stateFile);
        if ($now - (int) ($state['forward'] ?? 0) < self::INTERVAL) {
            return null;
        }

        $lock = @\fopen($dir . 'ops-tick.lock', 'c');
        if ($lock === false || !\flock($lock, \LOCK_EX | \LOCK_NB)) {
            return null;
        }
        try {
            $state = self::load($stateFile); // re-read under the lock
            $due = [];
            foreach (self::DUE as $job => $every) {
                if ($now - (int) ($state[$job] ?? 0) >= $every) {
                    $due[] = $job;
                    $state[$job] = $now;
                }
            }
            if ($due === []) {
                return null;
            }
            // Stamp BEFORE running: a job that fatals must not be retried by
            // every following request.
            @\file_put_contents($stateFile, (string) \json_encode($state));

            $out = [];
            foreach ($due as $job) {
                try {
                    $out[$job] = ($runner ?? [self::class, 'runJob'])($job, $now);
                } catch (\Throwable $e) {
                    $out[$job] = 'failed: ' . $e->getMessage();
                    \error_log("[ops] tick {$job} failed: " . $e->getMessage());
                }
            }

            return $out;
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }
    }

    private static function runJob(string $job, int $now): string
    {
        $pdo = \Propel::getConnection(_DATA_SRC);

        return match ($job) {
            'forward' => Forwarder::run($pdo, $now),
            'snap'    => ServerSnap::collect($pdo),
            'prune'   => (string) \json_encode(Retention::prune($pdo, $now, (int) Config::get('raw_days'), (int) Config::get('rollup_days'))),
            'ipinfo'  => IpInfo::resolvePending($pdo, $now),
        };
    }

    /** @return array<string,int> */
    private static function load(string $file): array
    {
        $raw = \is_file($file) ? @\file_get_contents($file) : false;
        $d = $raw !== false ? \json_decode($raw, true) : null;

        return \is_array($d) ? $d : [];
    }
}
