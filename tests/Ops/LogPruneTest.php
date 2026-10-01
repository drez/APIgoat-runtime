<?php

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\Config;
use ApiGoat\Ops\LogPrune;
use PHPUnit\Framework\TestCase;

/**
 * Log retention for EVERY gc project (privacy audit 2026-10-01): authy_log,
 * api_log, client_event and contact_message are pruned daily even when the
 * project never declared with_ops_monitor. Projects that did declare it are
 * left to Ops\Tick, which already runs Retention::pruneLogs() — LogPrune must
 * stand aside there so the work never runs twice.
 */
final class LogPruneTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        Config::reset();
        $this->dir = sys_get_temp_dir() . '/logprune-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        Config::reset();
        foreach (glob($this->dir . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testRunsOncePerDayWhenTheOpsMonitorIsOff(): void
    {
        Config::forceEnabled(false);
        $calls = [];
        $pruner = function (int $now, array $days) use (&$calls): array {
            $calls[] = [$now, $days];
            return ['authy_log' => 3];
        };

        $t0 = 1_800_000_000;
        $this->assertSame(['authy_log' => 3], LogPrune::run($t0, $this->dir, $pruner));
        $this->assertNull(LogPrune::run($t0 + 3600, $this->dir, $pruner), 'same day: skipped');
        $this->assertNotNull(LogPrune::run($t0 + 86400, $this->dir, $pruner), 'next day: runs again');
        $this->assertCount(2, $calls);
    }

    public function testUsesTheFleetDefaultsWithoutAManifest(): void
    {
        Config::forceEnabled(false);
        $seen = null;
        LogPrune::run(1_800_000_000, $this->dir, function (int $now, array $days) use (&$seen): array {
            $seen = $days;
            return [];
        });
        $this->assertSame(
            ['authy_log_days' => 90, 'api_log_days' => 90, 'client_event_days' => 90, 'contact_message_days' => 365],
            $seen
        );
    }

    public function testStandsAsideWhenTheOpsMonitorRunsRetention(): void
    {
        Config::forceEnabled(true);
        $ran = false;
        $this->assertNull(LogPrune::run(1_800_000_000, $this->dir, function () use (&$ran): array {
            $ran = true;
            return [];
        }));
        $this->assertFalse($ran);
        $this->assertFalse(LogPrune::due(1_800_000_000, $this->dir));
    }

    public function testDueIsACheapStateFileCheck(): void
    {
        Config::forceEnabled(false);
        $this->assertTrue(LogPrune::due(1_800_000_000, $this->dir), 'never ran');
        LogPrune::run(1_800_000_000, $this->dir, fn () => []);
        $this->assertFalse(LogPrune::due(1_800_000_000 + 60, $this->dir));
        $this->assertTrue(LogPrune::due(1_800_000_000 + 86400, $this->dir));
    }

    public function testAFailingPrunerNeverThrowsAndIsNotRetriedTheSameDay(): void
    {
        Config::forceEnabled(false);
        $n = 0;
        $boom = function () use (&$n): array {
            $n++;
            throw new \RuntimeException('db down');
        };
        $this->assertNull(LogPrune::run(1_800_000_000, $this->dir, $boom));
        $this->assertNull(LogPrune::run(1_800_000_000 + 10, $this->dir, $boom));
        $this->assertSame(1, $n);
    }

    public function testMissingTmpDirIsANoOp(): void
    {
        Config::forceEnabled(false);
        $this->assertFalse(LogPrune::due(1_800_000_000, '/nonexistent/' . bin2hex(random_bytes(3)) . '/'));
    }
}
