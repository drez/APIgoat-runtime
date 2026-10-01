<?php

namespace ApiGoat\Ops;

/**
 * Prunes the ops_* telemetry tables emitted by with_ops_monitor (Task 1),
 * called nightly by a project's cron.php (the `opsNightly` job — see
 * CronJobs::guard() in the generated project). Every table but ops_report
 * carries a `created_at INTEGER` (unix) column that a uniform prune control
 * can point at (R5); ops_req_hour is the one exception — it prunes on its
 * own `hour` bucket instead, since that column IS the row's timeline.
 *
 * Retention windows (controller ruling R5 — the raw window from
 * Config::get('raw_days'), the rollup window from
 * Config::get('rollup_days')):
 *   - ops_req_slow, ops_query_slow: rawDays
 *   - ops_sec_event: rawDays x 3 (security evidence is kept 3x longer)
 *   - ops_req_hour, ops_cron_run, ops_server_snap: rollupDays
 *   - ops_report: never pruned (not in this list at all)
 *
 * pruneLogs() applies the same batched age-based prune to the generic
 * IP-bearing log tables a project may have (authy_log, api_log,
 * client_event, contact_message — see LOG_TABLES), each with its own
 * window: Config keys authy_log_days / api_log_days / client_event_days
 * (default 90) and contact_message_days (default 365); 0 = keep forever.
 *
 * cutoffs() is pure (no PDO) so it is unit-testable without a database
 * (R1: this host has no pdo_sqlite); prune() is the DB-backed half, run
 * against MySQL in P/.admin/tests/Custom/OpsRetentionTest.php. Never
 * throws: a single table's DELETE failing (e.g. the table doesn't exist on
 * a project that only partially declared with_ops_monitor) is swallowed to
 * error_log and reported as 0 rows deleted for that table, so one bad table
 * can never stop the others from being pruned.
 */
final class Retention
{
    private const SECURITY_MULTIPLIER = 3;

    private const SECONDS_PER_DAY = 86400;

    /** Default rows deleted per DELETE ... LIMIT, per prune() call. */
    private const DEFAULT_BATCH_SIZE = 5000;

    /**
     * Safety cap on how many batches a single table can run through in one
     * prune() call — an unbounded backlog (e.g. retention was never run for
     * months) must not turn a nightly job into an unbounded one; it just
     * catches up over several nights instead.
     */
    private const MAX_BATCHES_PER_TABLE = 1000;

    /** table => the column its cutoff is compared against. */
    private const PRUNE_COLUMN = [
        'ops_req_slow'    => 'created_at',
        'ops_query_slow'  => 'created_at',
        'ops_sec_event'   => 'created_at',
        'ops_req_hour'    => 'hour',
        'ops_cron_run'    => 'created_at',
        'ops_server_snap' => 'created_at',
        'ops_mcp_hour'    => 'hour',
        'ops_ip_info'     => 'created_at',
    ];

    /** Tables emitted only on some projects (with_mcp): skipped quietly when absent. */
    private const OPTIONAL_TABLES = ['ops_mcp_hour', 'ops_ip_info'];

    /**
     * table => cutoff timestamp (exclusive lower bound to KEEP; anything
     * strictly older than this is pruned). Pure — no PDO, no clock read —
     * so it is fully unit-testable.
     *
     * @return array<string,int>
     */
    public static function cutoffs(int $now, int $rawDays, int $rollupDays): array
    {
        $raw     = $now - $rawDays * self::SECONDS_PER_DAY;
        $rawSec  = $now - $rawDays * self::SECURITY_MULTIPLIER * self::SECONDS_PER_DAY;
        $rollup  = $now - $rollupDays * self::SECONDS_PER_DAY;

        return [
            'ops_req_slow'    => $raw,
            'ops_query_slow'  => $raw,
            'ops_sec_event'   => $rawSec,
            'ops_req_hour'    => $rollup,
            'ops_cron_run'    => $rollup,
            'ops_server_snap' => $rollup,
            'ops_mcp_hour'    => $rollup,
            // A lookup cache, not telemetry: always a month (IpInfo::TTL).
            'ops_ip_info'     => $now - IpInfo::TTL,
        ];
    }

    /**
     * Delete every row in each pruned table older than that table's cutoff
     * (see cutoffs()). Returns the number of rows deleted per table —
     * ops_report is never included (it is never pruned).
     *
     * R13 (controller ruling): on an actively-written prod table, one
     * unbatched `DELETE ... WHERE col < :cutoff` can hold a long lock over
     * a full-table scan (some of these columns have no index — see the
     * with_ops_monitor `<table>_created_at_index` fix that's the other
     * half of R13). So each table is deleted in batches of $batchSize rows
     * — `DELETE ... LIMIT $batchSize`, repeated until a batch comes back
     * short (fewer than $batchSize rows), which means nothing older than
     * the cutoff is left. MAX_BATCHES_PER_TABLE caps how many batches a
     * single table can run in one call — a backlog too big to fully clear
     * tonight is still bounded, and simply finishes over the next few
     * nightly runs instead of blocking this one indefinitely.
     *
     * Never throws: a single table's DELETE failing partway through its
     * batches (e.g. the table doesn't exist on a project that only
     * partially declared with_ops_monitor) is swallowed to error_log and
     * reported as however many rows that table's batches deleted before
     * the failure (0 if none), so one bad table can never stop the others
     * from being pruned.
     *
     * @return array<string,int>
     */
    public static function prune(\PDO $pdo, int $now, int $rawDays, int $rollupDays, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $cutoffs = self::cutoffs($now, $rawDays, $rollupDays);
        $deleted = [];

        foreach ($cutoffs as $table => $cutoff) {
            $column = self::PRUNE_COLUMN[$table];
            $total = 0;
            if (\in_array($table, self::OPTIONAL_TABLES, true) && !self::tableExists($pdo, $table)) {
                continue;
            }
            try {
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$column} < :cutoff LIMIT {$batchSize}");
                for ($batch = 0; $batch < self::MAX_BATCHES_PER_TABLE; $batch++) {
                    $stmt->execute([':cutoff' => $cutoff]);
                    $n = $stmt->rowCount();
                    $total += $n;
                    if ($n < $batchSize) {
                        break; // fewer than a full batch came back: nothing older than the cutoff remains
                    }
                    if ($batch === self::MAX_BATCHES_PER_TABLE - 1) {
                        \error_log("[ops] retention prune: {$table} hit the {$batch}-batch safety cap; more rows may remain for the next run");
                    }
                }
            } catch (\Throwable $e) {
                \error_log("[ops] retention prune failed for {$table}: " . $e->getMessage());
            }
            $deleted[$table] = $total;
        }

        return $deleted;
    }

    /**
     * Generic IP-bearing log tables pruned by age, beyond the ops_* set.
     * table => [date column, column kind ('epoch' = INTEGER unix seconds,
     * 'datetime' = DATETIME in the app's timezone), Config key holding the
     * retention window in days]. Every entry is optional: a project only
     * has the tables its behaviors/schema declare, so pruneLogs() skips a
     * missing table or column silently. Columns verified against a real
     * generated schema.sql (2026-10-01):
     *   - authy_log.timestamp        DATETIME (login log, ip)
     *   - api_log.time               DATETIME (same column add_prune_action uses)
     *   - client_event.created_at    INTEGER unix (with_client_telemetry, ip)
     *   - contact_message.date_creation DATETIME (project table, ip_address/user_agent)
     */
    private const LOG_TABLES = [
        'authy_log'       => ['timestamp', 'datetime', 'authy_log_days'],
        'api_log'         => ['time', 'datetime', 'api_log_days'],
        'client_event'    => ['created_at', 'epoch', 'client_event_days'],
        'contact_message' => ['date_creation', 'datetime', 'contact_message_days'],
    ];

    /** The Config keys of the generic log windows: config key => table. */
    public static function logDayKeys(): array
    {
        $out = [];
        foreach (self::LOG_TABLES as $table => [, , $key]) {
            $out[$key] = $table;
        }

        return $out;
    }

    /**
     * Pure: table => [column, cutoff value] for the generic log tables.
     * $days maps a Config key (authy_log_days, …) to its window; a key
     * absent or <= 0 means "never prune that table" and drops it from the
     * result. The cutoff is an int for an epoch column and a
     * 'Y-m-d H:i:s' string (PHP's default timezone — the one the app
     * writes those DATETIMEs with) for a datetime column.
     *
     * @param array<string,int|string|null> $days
     * @return array<string,array{0:string,1:int|string}>
     */
    public static function logCutoffs(int $now, array $days): array
    {
        $out = [];
        foreach (self::LOG_TABLES as $table => [$column, $kind, $key]) {
            $d = (int) ($days[$key] ?? 0);
            if ($d <= 0) {
                continue;
            }
            $ts = $now - $d * self::SECONDS_PER_DAY;
            $out[$table] = [$column, $kind === 'epoch' ? $ts : \date('Y-m-d H:i:s', $ts)];
        }

        return $out;
    }

    /**
     * Age-based retention for the generic IP-bearing log tables (see
     * LOG_TABLES), same bounded batching as prune(). A table — or its date
     * column — that does not exist on this project is skipped silently and
     * absent from the result; a DELETE failure is swallowed to error_log
     * like prune(). Rows whose date column is NULL are never matched.
     *
     * @param array<string,int|string|null> $days Config key => window in days
     * @return array<string,int> table => rows deleted
     */
    public static function pruneLogs(\PDO $pdo, int $now, array $days, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $deleted = [];
        foreach (self::logCutoffs($now, $days) as $table => [$column, $cutoff]) {
            if (!self::tableExists($pdo, $table) || !self::columnExists($pdo, $table, $column)) {
                continue;
            }
            $deleted[$table] = self::deleteInBatches($pdo, $table, $column, $cutoff, $batchSize);
        }

        return $deleted;
    }

    /** Batched `DELETE ... LIMIT` loop shared by pruneLogs(); never throws. */
    private static function deleteInBatches(\PDO $pdo, string $table, string $column, int|string $cutoff, int $batchSize): int
    {
        $total = 0;
        try {
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` < :cutoff LIMIT {$batchSize}");
            for ($batch = 0; $batch < self::MAX_BATCHES_PER_TABLE; $batch++) {
                $stmt->execute([':cutoff' => $cutoff]);
                $n = $stmt->rowCount();
                $total += $n;
                if ($n < $batchSize) {
                    break;
                }
                if ($batch === self::MAX_BATCHES_PER_TABLE - 1) {
                    \error_log("[ops] retention prune: {$table} hit the {$batch}-batch safety cap; more rows may remain for the next run");
                }
            }
        } catch (\Throwable $e) {
            \error_log("[ops] retention prune failed for {$table}: " . $e->getMessage());
        }

        return $total;
    }

    private static function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        try {
            return (bool) $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column))->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function tableExists(\PDO $pdo, string $table): bool
    {
        try {
            return (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
