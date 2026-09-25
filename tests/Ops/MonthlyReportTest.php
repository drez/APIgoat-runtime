<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\MonthlyReport;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/MonthlyReport.php';

/**
 * Pure-logic coverage only (R1: this host has no pdo_sqlite) — errorSignatures()
 * (file parsing) and anomalies() (the fixed anomaly rules) take no PDO/Stats
 * at all. Everything SQL-backed (build()'s Stats calls, run()'s upsert +
 * recipient resolution + mailer seam) is covered against real MySQL in
 * P/.admin/tests/Custom/OpsMonthlyReportTest.php.
 */
final class MonthlyReportTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/mr_test_' . \uniqid();
        \mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tmpDir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->tmpDir);
    }

    // ── errorSignatures ──────────────────────────────────────────────────

    public function test_missing_file_returns_empty(): void
    {
        $this->assertSame([], MonthlyReport::errorSignatures($this->tmpDir . '/nope.log', '2026-08', 10));
    }

    public function test_empty_log_path_returns_empty(): void
    {
        $this->assertSame([], MonthlyReport::errorSignatures('', '2026-08', 10));
    }

    public function test_groups_lines_by_stripped_signature_within_the_period(): void
    {
        $path = $this->tmpDir . '/php-error.log';
        \file_put_contents(
            $path,
            "[15-Aug-2026 10:00:01 UTC] PHP Warning:  Undefined array key \"foo\" in /var/www/x.php on line 12\n"
            . "[16-Aug-2026 11:02:03 UTC] PHP Warning:  Undefined array key \"bar\" in /var/www/x.php on line 45\n"
            . "[01-Sep-2026 09:00:00 UTC] PHP Notice:  Some other thing 123\n"
        );

        $august = MonthlyReport::errorSignatures($path, '2026-08', 10);
        $this->assertCount(1, $august);
        $this->assertSame(2, $august[0]['n']);
        $this->assertSame('PHP Warning:  Undefined array key ? in /var/www/x.php on line #', $august[0]['sig']);

        $september = MonthlyReport::errorSignatures($path, '2026-09', 10);
        $this->assertCount(1, $september);
        $this->assertSame(1, $september[0]['n']);
        $this->assertSame('PHP Notice:  Some other thing #', $september[0]['sig']);
    }

    public function test_reads_the_rotated_dot_one_generation_too(): void
    {
        $path = $this->tmpDir . '/php-error.log';
        \file_put_contents($path, "[02-Aug-2026 10:00:00 UTC] Something broke 1\n");
        \file_put_contents($path . '.1', "[01-Aug-2026 09:00:00 UTC] Something broke 2\n");

        $rows = MonthlyReport::errorSignatures($path, '2026-08', 10);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['n']);
    }

    public function test_limit_caps_the_number_of_returned_signatures(): void
    {
        $path = $this->tmpDir . '/php-error.log';
        \file_put_contents(
            $path,
            "[01-Aug-2026 00:00:00 UTC] Alpha thing\n"
            . "[01-Aug-2026 00:00:01 UTC] Beta thing\n"
            . "[01-Aug-2026 00:00:02 UTC] Gamma thing\n"
        );

        $rows = MonthlyReport::errorSignatures($path, '2026-08', 1);
        $this->assertCount(1, $rows);
    }

    public function test_lines_that_do_not_match_the_bracketed_date_are_skipped(): void
    {
        $path = $this->tmpDir . '/php-error.log';
        \file_put_contents(
            $path,
            "Stack trace:\n#0 {main}\n"
            . "[03-Aug-2026 00:00:00 UTC] Real entry\n"
        );

        $rows = MonthlyReport::errorSignatures($path, '2026-08', 10);
        $this->assertCount(1, $rows);
        $this->assertSame('Real entry', $rows[0]['sig']);
    }

    // ── anomalies() ──────────────────────────────────────────────────────

    public function test_no_anomalies_on_an_empty_month(): void
    {
        $this->assertSame([], MonthlyReport::anomalies(0, 0, 0, null, [], [], []));
    }

    public function test_failed_logins_over_3x_previous_month_is_an_anomaly(): void
    {
        $out = MonthlyReport::anomalies(31, 10, 0, null, [], [], []);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('Failed logins', $out[0]);
    }

    public function test_failed_logins_exactly_3x_is_not_an_anomaly(): void
    {
        $this->assertSame([], MonthlyReport::anomalies(30, 10, 0, null, [], [], []));
    }

    public function test_any_token_reuse_is_an_anomaly(): void
    {
        $out = MonthlyReport::anomalies(0, 0, 1, null, [], [], []);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('Token reuse', $out[0]);
    }

    public function test_disk_over_85_percent_is_an_anomaly(): void
    {
        $out = MonthlyReport::anomalies(0, 0, 0, 90.5, [], [], []);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('Disk usage', $out[0]);
    }

    public function test_disk_at_85_percent_is_not_an_anomaly(): void
    {
        $this->assertSame([], MonthlyReport::anomalies(0, 0, 0, 85.0, [], [], []));
    }

    public function test_a_new_deny_route_is_an_anomaly(): void
    {
        $out = MonthlyReport::anomalies(0, 0, 0, null, [
            ['model' => 'Widget', 'action' => 'list', 'method' => 'POST'],
        ], [], []);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('Widget.list', $out[0]);
        $this->assertStringContainsString('POST', $out[0]);
    }

    public function test_p95_over_2x_previous_month_is_an_anomaly(): void
    {
        $out = MonthlyReport::anomalies(0, 0, 0, null, [], [
            ['route' => '/slow', 'method' => 'GET', 'p95_ms' => 500],
        ], ['/slow|GET' => 200]);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('/slow', $out[0]);
    }

    public function test_p95_regression_with_no_previous_data_is_not_an_anomaly(): void
    {
        // No previous-month traffic for this route at all (0, not just low) —
        // "> 2x the previous month" needs a previous month to regress from.
        $out = MonthlyReport::anomalies(0, 0, 0, null, [], [
            ['route' => '/new', 'method' => 'GET', 'p95_ms' => 500],
        ], []);
        $this->assertSame([], $out);
    }

    public function test_multiple_anomalies_all_reported(): void
    {
        $out = MonthlyReport::anomalies(31, 10, 2, 90.0, [
            ['model' => 'X', 'action' => null, 'method' => 'DELETE'],
        ], [], []);
        $this->assertCount(4, $out);
    }

    // ── previousPeriod() ─────────────────────────────────────────────────

    public function test_previous_period_rolls_over_the_year(): void
    {
        $this->assertSame('2025-12', MonthlyReport::previousPeriod('2026-01'));
    }

    public function test_previous_period_within_the_same_year(): void
    {
        $this->assertSame('2026-08', MonthlyReport::previousPeriod('2026-09'));
    }

    // ── rankByP95() ──────────────────────────────────────────────────────

    /**
     * Fix round 1, finding 1: the "slowest 10 routes (p95)" display must
     * rank by p95 desc, not by Stats::slowestRoutes()'s own ordering
     * (total time spent, sum_ms desc) — a high-traffic-but-fast route can
     * have a larger sum_ms than a rare-but-slow one despite a much lower p95.
     */
    public function test_rank_by_p95_puts_the_slow_rare_route_before_the_fast_high_traffic_one(): void
    {
        $highTrafficFast = ['route' => '/fast', 'method' => 'GET', 'n' => 10000, 'avg_ms' => 50.0, 'p95_ms' => 80, 'n_5xx' => 0];
        $rareSlow = ['route' => '/slow', 'method' => 'GET', 'n' => 5, 'avg_ms' => 900.0, 'p95_ms' => 2200, 'n_5xx' => 0];

        // Mirrors Stats::slowestRoutes()'s own ordering: sum_ms(highTrafficFast)
        // = 10000*50 = 500000 vs sum_ms(rareSlow) = 5*900 = 4500 — the fast
        // route would sort FIRST under that ranking, which is the bug.
        $ranked = MonthlyReport::rankByP95([$highTrafficFast, $rareSlow]);

        $this->assertSame('/slow', $ranked[0]['route'], 'higher p95 must rank first regardless of total time spent');
        $this->assertSame('/fast', $ranked[1]['route']);
    }

    public function test_rank_by_p95_ties_break_on_request_volume_desc(): void
    {
        $lowVolume = ['route' => '/a', 'method' => 'GET', 'n' => 5, 'avg_ms' => 100.0, 'p95_ms' => 300, 'n_5xx' => 0];
        $highVolume = ['route' => '/b', 'method' => 'GET', 'n' => 50, 'avg_ms' => 100.0, 'p95_ms' => 300, 'n_5xx' => 0];

        $ranked = MonthlyReport::rankByP95([$lowVolume, $highVolume]);

        $this->assertSame('/b', $ranked[0]['route']);
        $this->assertSame('/a', $ranked[1]['route']);
    }

    public function test_rank_by_p95_does_not_mutate_the_caller_array_order(): void
    {
        $routes = [
            ['route' => '/fast', 'method' => 'GET', 'n' => 10000, 'avg_ms' => 50.0, 'p95_ms' => 80, 'n_5xx' => 0],
            ['route' => '/slow', 'method' => 'GET', 'n' => 5, 'avg_ms' => 900.0, 'p95_ms' => 2200, 'n_5xx' => 0],
        ];
        $originalOrder = \array_column($routes, 'route');

        MonthlyReport::rankByP95($routes);

        // The anomaly loop iterates the ORIGINAL (sum_ms-ordered) list —
        // rankByP95() must return a re-sorted copy, not sort in place.
        $this->assertSame($originalOrder, \array_column($routes, 'route'));
    }
}
