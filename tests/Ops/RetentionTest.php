<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\IpInfo;
use ApiGoat\Ops\Retention;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/Retention.php';
require_once __DIR__ . '/../../src/Ops/IpInfo.php';

/**
 * Pure-logic coverage only (R1): this host has no pdo_sqlite, so prune()'s
 * actual DELETEs against real ops_* tables are tested against MySQL in
 * P/.admin/tests/Custom/OpsRetentionTest.php instead. What's fully testable
 * here without a database: cutoffs(), the per-table cutoff-timestamp
 * arithmetic that drives every DELETE prune() issues.
 */
final class RetentionTest extends TestCase
{
    private const DAY = 86400;

    private $prevLog;
    private string $logFile;

    protected function setUp(): void
    {
        // prune()'s per-table swallow calls error_log() on a DELETE
        // failure — redirected to a temp file so a failing table never
        // leaks a line to this test run's stdout/stderr (R11).
        $this->logFile = \tempnam(\sys_get_temp_dir(), 'retention');
        $this->prevLog = \ini_get('error_log');
        \ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', (string) $this->prevLog);
        if (\is_file($this->logFile)) {
            \unlink($this->logFile);
        }
    }

    public function test_cutoffs_uses_raw_days_for_request_and_query_slow_tables(): void
    {
        $now = 1_000_000;
        $c = Retention::cutoffs($now, 14, 180);

        $this->assertSame($now - 14 * self::DAY, $c['ops_req_slow']);
        $this->assertSame($now - 14 * self::DAY, $c['ops_query_slow']);
    }

    public function test_cutoffs_triples_raw_days_for_security_events(): void
    {
        $now = 1_000_000;
        $c = Retention::cutoffs($now, 14, 180);

        $this->assertSame($now - 14 * 3 * self::DAY, $c['ops_sec_event']);
    }

    public function test_cutoffs_uses_rollup_days_for_hourly_cron_and_snapshot_tables(): void
    {
        $now = 1_000_000;
        $c = Retention::cutoffs($now, 14, 180);

        $this->assertSame($now - 180 * self::DAY, $c['ops_req_hour']);
        $this->assertSame($now - 180 * self::DAY, $c['ops_cron_run']);
        $this->assertSame($now - 180 * self::DAY, $c['ops_server_snap']);
    }

    public function test_cutoffs_never_mentions_ops_report(): void
    {
        $c = Retention::cutoffs(1_000_000, 14, 180);

        $this->assertArrayNotHasKey('ops_report', $c);
    }

    public function test_cutoffs_covers_exactly_the_pruned_tables(): void
    {
        $expected = [
            'ops_req_slow', 'ops_query_slow', 'ops_sec_event',
            'ops_req_hour', 'ops_cron_run', 'ops_server_snap', 'ops_mcp_hour', 'ops_ip_info',
        ];
        $actual = \array_keys(Retention::cutoffs(1_000_000, 14, 180));
        \sort($expected);
        \sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_ip_cache_is_always_kept_one_month(): void
    {
        $this->assertSame(1_000_000 - IpInfo::TTL, Retention::cutoffs(1_000_000, 1, 30)['ops_ip_info']);
    }

    public function test_cutoffs_scales_with_different_windows(): void
    {
        $now = 5_000_000;
        $c = Retention::cutoffs($now, 1, 30);

        $this->assertSame($now - 1 * self::DAY, $c['ops_query_slow']);
        $this->assertSame($now - 3 * self::DAY, $c['ops_sec_event']);
        $this->assertSame($now - 30 * self::DAY, $c['ops_cron_run']);
    }

    /**
     * prune() itself needs a real ops_req_hour/etc. table to DELETE against
     * (R1), but a PDO that fails to prepare() at all must still not escape
     * prune() — every table is attempted independently and a failure is
     * swallowed to 0 rather than aborting the whole run.
     */
    public function test_prune_swallows_a_pdo_failure_per_table(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willThrowException(new \PDOException("Table 'ops_req_slow' doesn't exist"));

        $result = Retention::prune($pdo, 1_000_000, 14, 180);

        $this->assertSame(0, $result['ops_req_slow']);
        $this->assertSame(0, $result['ops_sec_event']);
        $this->assertCount(6, $result);
    }

    /**
     * R13: prune() must issue `DELETE ... LIMIT $batchSize`, repeated until
     * a batch comes back short, rather than one unbatched full-scan DELETE
     * (a long lock on an actively-written prod table). Isolated to one
     * table (ops_req_slow) via the SQL text prepare() receives — every
     * other table's mocked statement reports 0 rows so it can't add noise
     * to the assertion.
     */
    public function test_prune_batches_deletes_until_a_short_batch(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            if (\str_contains($sql, 'ops_req_slow')) {
                $seq = [3, 3, 1]; // two full batches of 3, then a short one: stop, total 7
                $i = 0;
                $stmt->method('rowCount')->willReturnCallback(static function () use (&$i, $seq) {
                    return $seq[$i++] ?? 0;
                });
            } else {
                $stmt->method('rowCount')->willReturn(0);
            }

            return $stmt;
        });

        $result = Retention::prune($pdo, 1_000_000, 14, 180, 3);

        $this->assertSame(7, $result['ops_req_slow'], 'a batch loop must sum every batch, not just report the last one');
    }

    /**
     * The safety cap: a backlog that never comes back short (every batch
     * is exactly $batchSize) must still stop after MAX_BATCHES_PER_TABLE
     * batches rather than looping forever, and must say so via error_log
     * (redirected to a temp file in setUp — R11) so an operator can tell
     * the table didn't fully catch up in one run.
     */
    public function test_prune_stops_at_the_batch_safety_cap_and_logs_it(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('rowCount')->willReturn(\str_contains($sql, 'ops_req_slow') ? 3 : 0);

            return $stmt;
        });

        $result = Retention::prune($pdo, 1_000_000, 14, 180, 3);

        // MAX_BATCHES_PER_TABLE (1000) full batches of 3, never a short one.
        $this->assertSame(3000, $result['ops_req_slow']);

        $logged = (string) \file_get_contents($this->logFile);
        $this->assertStringContainsString('ops_req_slow', $logged);
        $this->assertStringContainsString('safety cap', $logged);
    }
    private const LOG_DAYS = [
        'authy_log_days' => 90, 'api_log_days' => 90,
        'client_event_days' => 90, 'contact_message_days' => 365,
    ];

    public function test_log_cutoffs_use_each_tables_real_date_column_and_type(): void
    {
        $now = 1_800_000_000;
        $c = Retention::logCutoffs($now, self::LOG_DAYS);

        $this->assertSame(['timestamp', \date('Y-m-d H:i:s', $now - 90 * self::DAY)], $c['authy_log']);
        $this->assertSame(['time', \date('Y-m-d H:i:s', $now - 90 * self::DAY)], $c['api_log']);
        $this->assertSame(['created_at', $now - 90 * self::DAY], $c['client_event'], 'client_event.created_at is a unix INTEGER');
        $this->assertSame(['date_creation', \date('Y-m-d H:i:s', $now - 365 * self::DAY)], $c['contact_message']);
    }

    public function test_log_cutoffs_skip_a_table_whose_window_is_zero_or_missing(): void
    {
        $c = Retention::logCutoffs(1_800_000_000, ['authy_log_days' => 0, 'api_log_days' => 30]);

        $this->assertSame(['api_log'], \array_keys($c));
    }

    public function test_log_day_keys_match_the_config_defaults(): void
    {
        require_once __DIR__ . '/../../src/Ops/Config.php';
        \ApiGoat\Ops\Config::override([]);
        try {
            $this->assertSame(
                ['authy_log_days', 'api_log_days', 'client_event_days', 'contact_message_days'],
                \array_keys(Retention::logDayKeys())
            );
            $this->assertSame(90, \ApiGoat\Ops\Config::get('authy_log_days'));
            $this->assertSame(90, \ApiGoat\Ops\Config::get('api_log_days'));
            $this->assertSame(90, \ApiGoat\Ops\Config::get('client_event_days'));
            $this->assertSame(365, \ApiGoat\Ops\Config::get('contact_message_days'));
        } finally {
            \ApiGoat\Ops\Config::reset();
        }
    }

    /**
     * A project without a table (or with a renamed date column) must be
     * skipped silently: no DELETE prepared, no error_log line, no key in
     * the result. Here only api_log exists, and contact_message exists
     * without its date column.
     */
    public function test_prune_logs_skips_missing_tables_and_columns_silently(): void
    {
        $prepared = [];
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn ($v) => "'{$v}'");
        $pdo->method('query')->willReturnCallback(function (string $sql) {
            $hit = \str_contains($sql, "SHOW TABLES LIKE 'api_log'")
                || \str_contains($sql, "SHOW TABLES LIKE 'contact_message'")
                || \str_contains($sql, "SHOW COLUMNS FROM `api_log` LIKE 'time'");
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn($hit ? 'x' : false);

            return $stmt;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$prepared) {
            $prepared[] = $sql;
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $seq = [2, 1];
            $i = 0;
            $stmt->method('rowCount')->willReturnCallback(static function () use (&$i, $seq) {
                return $seq[$i++] ?? 0;
            });

            return $stmt;
        });

        $result = Retention::pruneLogs($pdo, 1_800_000_000, self::LOG_DAYS, 2);

        $this->assertSame(['api_log' => 3], $result, 'two batches (2 full + 1 short) on the one present table');
        $this->assertSame(['DELETE FROM `api_log` WHERE `time` < :cutoff LIMIT 2'], $prepared);
        $this->assertSame('', (string) \file_get_contents($this->logFile), 'skipping is silent');
    }

    public function test_prune_logs_swallows_a_delete_failure(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('quote')->willReturnCallback(static fn ($v) => "'{$v}'");
        $pdo->method('query')->willReturnCallback(function () {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn('x');

            return $stmt;
        });
        $pdo->method('prepare')->willThrowException(new \PDOException('lock wait timeout'));

        $result = Retention::pruneLogs($pdo, 1_800_000_000, self::LOG_DAYS);

        $this->assertSame(['authy_log' => 0, 'api_log' => 0, 'client_event' => 0, 'contact_message' => 0], $result);
        $this->assertStringContainsString('lock wait timeout', (string) \file_get_contents($this->logFile));
    }
}
