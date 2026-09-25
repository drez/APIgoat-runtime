<?php

namespace ApiGoat\Ops;

use ApiGoat\Ops\Server\Factory;

/**
 * Writes one row to ops_server_snap (emitted by with_ops_monitor, Task 1 +
 * Task 6's `auth` column, R15): the host health snapshot behind the
 * Security & Performance dashboards' server panel — load average, memory
 * and disk usage, per-service up/down, fail2ban ban counts, and recent sshd
 * auth activity.
 *
 * Mirror of CronLog/SecEvent's safety contract: collect() is the entry
 * point a project's cron.php calls (via CronJobs::guard(), the opsServerSnap
 * job, same as every other ops_* job) — it must never throw, whether the
 * configured source is unavailable or the DB write itself fails. Every
 * failure is error_log()'d and folded into the one-line summary the cron-run
 * history (ops_cron_run.summary) shows instead.
 *
 * R16 (controller ruling, fix round 1):
 *   - item 5: collect() now gates on Config::enabled() FIRST, exactly like
 *     CronLog::record()/SecEvent::record() — a project that never declared
 *     with_ops_monitor pays nothing (no Factory, no PDO touched at all),
 *     returning 'server snap: disabled'. Only reachable from a real
 *     project (a manifest file must exist), so — like CronLog/SecEvent's
 *     "enabled" branches — this is exercised in
 *     P/.admin/tests/Custom/OpsServerSnapTest.php, not here: RT's test
 *     process never defines _BASE_DIR (see ConfigTest's docblock), so
 *     Config::enabled() is always false in this suite and every branch
 *     past the gate is unreachable from RT alone.
 *   - item 4: the row's `created_at` is the SNAPSHOT's own `at` (when the
 *     collector actually ran), not the cron job's `time()` — two
 *     opsServerSnap cron ticks reading the same not-yet-refreshed snapshot
 *     file (the root cron stopped, or simply hasn't run again yet) must
 *     not create two rows with two different created_at values for what
 *     is the same underlying observation. collect() checks for an existing
 *     row at that exact created_at first and, if found, skips the insert
 *     and returns 'server snap: stale' instead — a signal (visible in
 *     ops_cron_run.summary) that the collector itself has stopped
 *     producing fresh snapshots, not a failure of this code.
 */
final class ServerSnap
{
    /**
     * Factory::make() + Source::snapshot() + store(), wrapped so the
     * opsServerSnap cron job always gets a short result string no matter
     * what goes wrong upstream (misconfigured source, missing/corrupt
     * snapshot file, DB failure, ...). Never throws.
     *
     * Returns exactly one of: 'server snap: disabled' (with_ops_monitor not
     * declared — Factory/DB untouched), 'server snap: no source'
     * (server_source is 'none'/'ispconfig'/unrecognized), 'server snap:
     * unavailable' (the configured source has no data right now),
     * 'server snap: stale' (a row for this exact snapshot timestamp is
     * already stored — the collector hasn't produced a new one),
     * 'server snap: stored', or 'server snap: error' (any \Throwable,
     * logged).
     */
    public static function collect(\PDO $pdo): string
    {
        if (!Config::enabled()) {
            return 'server snap: disabled';
        }

        try {
            $source = Factory::make((string) Config::get('server_source'), (string) Config::get('snapshot_path'));
            if ($source === null) {
                return 'server snap: no source';
            }

            $snap = $source->snapshot();
            if ($snap === null) {
                return 'server snap: unavailable';
            }

            if (self::alreadyStored($pdo, $snap['at'])) {
                return 'server snap: stale';
            }

            self::store($pdo, $snap);

            return 'server snap: stored';
        } catch (\Throwable $e) {
            \error_log('[ops] server snap collect failed: ' . $e->getMessage());

            return 'server snap: error';
        }
    }

    /**
     * The DB write itself, PDO injected so it's testable without exercising
     * the whole collect() path. Does NOT catch — collect() is the boundary
     * that does that (same split as SecEvent::write()/record()).
     * `created_at` is $snap['at'] (R16 item 4) — the snapshot's own
     * observation time, not whatever moment collect() happened to run at.
     *
     * @param array{load1:float,mem_pct:float,disk_pct:float,services:array<string,bool>,f2b_banned:int,f2b:array<string,int>,auth:array{ssh_failed:int,ssh_accepted:int,window_h:int},at:int} $snap
     */
    public static function store(\PDO $pdo, array $snap): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO ops_server_snap (load1, mem_pct, disk_pct, services, f2b_banned, f2b, auth, created_at)
             VALUES (:load1, :mem_pct, :disk_pct, :services, :f2b_banned, :f2b, :auth, :created_at)'
        );
        $stmt->execute([
            ':load1'      => $snap['load1'],
            ':mem_pct'    => $snap['mem_pct'],
            ':disk_pct'   => $snap['disk_pct'],
            ':services'   => \json_encode($snap['services']),
            ':f2b_banned' => $snap['f2b_banned'],
            ':f2b'        => \json_encode($snap['f2b']),
            ':auth'       => \json_encode($snap['auth']),
            ':created_at' => $snap['at'],
        ]);
    }

    /** Is there already a row for this exact snapshot timestamp? (R16 item 4) */
    private static function alreadyStored(\PDO $pdo, int $at): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM ops_server_snap WHERE created_at = :created_at LIMIT 1');
        $stmt->execute([':created_at' => $at]);

        return (bool) $stmt->fetchColumn();
    }
}
