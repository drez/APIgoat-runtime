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
 */
final class ServerSnap
{
    /**
     * Factory::make() + Source::snapshot() + store(), wrapped so the
     * opsServerSnap cron job always gets a short result string no matter
     * what goes wrong upstream (misconfigured source, missing/corrupt
     * snapshot file, DB failure, ...). Never throws.
     */
    public static function collect(\PDO $pdo, int $now): string
    {
        try {
            $source = Factory::make((string) Config::get('server_source'), (string) Config::get('snapshot_path'));
            if ($source === null) {
                return 'server snap: no source';
            }

            $snap = $source->snapshot();
            if ($snap === null) {
                return 'server snap: unavailable';
            }

            self::store($pdo, $snap, $now);

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
     *
     * @param array{load1:float,mem_pct:float,disk_pct:float,services:array<string,bool>,f2b_banned:int,f2b:array<string,int>,auth:array{ssh_failed:int,ssh_accepted:int,window_h:int}} $snap
     */
    public static function store(\PDO $pdo, array $snap, int $now): void
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
            ':created_at' => $now,
        ]);
    }
}
