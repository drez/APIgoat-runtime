<?php

namespace ApiGoat\Ops;

/**
 * Writes one row to ops_cron_run (emitted by with_ops_monitor, Task 1): the
 * history of every cron/scheduled job execution behind the Ops dashboard's
 * "cron runs" panel — job name, when it started, how long it took, whether
 * it succeeded, and a short summary of what it did.
 *
 * Mirror of SecEvent/RequestRecorder's safety contract: recording a run must
 * never itself break the job it is describing. record() is the only entry
 * point — a project's CronJobs::guard() (or equivalent) calls it from a
 * `finally` block wrapping the job it just ran, so a telemetry failure here
 * must never mask, replace or interrupt the job's own result. It no-ops
 * when with_ops_monitor isn't declared (Config::enabled()) and swallows any
 * \Throwable (bad connection, missing table, …) to error_log.
 *
 * MySQL only (R1): this host's test runner has no pdo_sqlite, and the
 * project databases are always MySQL, so record() speaks a plain INSERT and
 * nothing else. The actual INSERT (and truncation) against a real
 * ops_cron_run table is tested against MySQL in
 * P/.admin/tests/Custom/OpsCronLogTest.php; what's testable here without a
 * database is the no-op-when-disabled contract (see CronLogTest).
 */
final class CronLog
{
    /** ops_cron_run.job column width. */
    private const JOB_MAX = 64;

    /** ops_cron_run.summary column width. */
    private const SUMMARY_MAX = 512;

    /**
     * Record one cron run. $ok is false when the caller caught a
     * \Throwable from the job; $summary is the job's returned string (or a
     * description of the error), truncated to the column width. Never
     * throws.
     */
    public static function record(\PDO $pdo, string $job, int $startedAt, int $ms, bool $ok, string $summary): void
    {
        if (!Config::enabled()) {
            return;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ops_cron_run (job, started_at, ms, ok, summary, created_at)
                 VALUES (:job, :started_at, :ms, :ok, :summary, :created_at)'
            );
            $stmt->execute([
                ':job'        => \substr($job, 0, self::JOB_MAX),
                ':started_at' => $startedAt,
                ':ms'         => $ms,
                ':ok'         => $ok ? 1 : 0,
                ':summary'    => \substr($summary, 0, self::SUMMARY_MAX),
                ':created_at' => \time(),
            ]);
        } catch (\Throwable $e) {
            \error_log('[ops] cron log failed: ' . $e->getMessage());
        }
    }
}
