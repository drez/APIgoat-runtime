<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\Config;
use ApiGoat\Ops\CronLog;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/Config.php';
require_once __DIR__ . '/../../src/Ops/CronLog.php';

/**
 * Pure-logic coverage only (R1): this host has no pdo_sqlite, so the actual
 * INSERT (and truncation) against a real ops_cron_run table is tested
 * against MySQL in P/.admin/tests/Custom/OpsCronLogTest.php instead. What's
 * testable here without a database: record()'s no-op-when-disabled contract
 * (mirrors SecEventTest — no _BASE_DIR is ever defined in this file, so
 * Config::enabled() stays false and record() must never even reach the PDO).
 */
final class CronLogTest extends TestCase
{
    protected function setUp(): void
    {
        Config::reset();
    }

    protected function tearDown(): void
    {
        Config::reset();
    }

    public function test_record_is_a_no_op_when_ops_monitor_is_disabled(): void
    {
        $this->assertFalse(Config::enabled());

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->never())->method('prepare');

        CronLog::record($pdo, 'opsNightly', 100, 5, true, 'done');
        $this->addToAssertionCount(1); // reaching here without a fatal is the assertion
    }

    public function test_record_never_throws_when_disabled_even_with_a_bad_job_name(): void
    {
        $this->assertFalse(Config::enabled());

        $pdo = $this->createMock(\PDO::class);

        CronLog::record($pdo, \str_repeat('x', 200), 0, 0, false, \str_repeat('y', 900));
        $this->addToAssertionCount(1);
    }
}
