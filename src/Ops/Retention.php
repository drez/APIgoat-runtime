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
    ];

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
}
