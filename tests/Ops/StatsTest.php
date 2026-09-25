<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\Stats;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/Stats.php';

/**
 * Pure-logic coverage only (R1: this host has no pdo_sqlite) — p95FromBuckets()
 * is the one part of Stats that needs no database. Every SQL-backed method is
 * covered against real MySQL in P/.admin/tests/Custom/OpsStatsTest.php.
 */
final class StatsTest extends TestCase
{
    public function test_p95_returns_upper_bound_of_first_bucket_reaching_95_percent(): void
    {
        $this->assertSame(250, Stats::p95FromBuckets([
            'b100' => 90, 'b250' => 5, 'b500' => 5, 'b1000' => 0, 'b2500' => 0, 'b_inf' => 0,
        ]));
    }

    public function test_p95_all_zero_returns_zero(): void
    {
        $this->assertSame(0, Stats::p95FromBuckets([
            'b100' => 0, 'b250' => 0, 'b500' => 0, 'b1000' => 0, 'b2500' => 0, 'b_inf' => 0,
        ]));
    }

    public function test_p95_empty_array_returns_zero(): void
    {
        $this->assertSame(0, Stats::p95FromBuckets([]));
    }

    public function test_p95_everything_in_first_bucket_returns_its_bound(): void
    {
        $this->assertSame(100, Stats::p95FromBuckets([
            'b100' => 10, 'b250' => 0, 'b500' => 0, 'b1000' => 0, 'b2500' => 0, 'b_inf' => 0,
        ]));
    }

    /** 20+30+45 = 95 of 100 total: the 95th percentile lands exactly on the b500 bucket. */
    public function test_p95_needs_the_full_cumulative_across_middle_buckets(): void
    {
        $this->assertSame(500, Stats::p95FromBuckets([
            'b100' => 20, 'b250' => 30, 'b500' => 45, 'b1000' => 5, 'b2500' => 0, 'b_inf' => 0,
        ]));
    }

    public function test_p95_in_inf_bucket_uses_max_ms_when_present(): void
    {
        $this->assertSame(9000, Stats::p95FromBuckets([
            'b100' => 1, 'b250' => 0, 'b500' => 0, 'b1000' => 0, 'b2500' => 0, 'b_inf' => 99,
            'max_ms' => 9000,
        ]));
    }

    public function test_p95_in_inf_bucket_without_max_ms_falls_back_to_sentinel_above_2500(): void
    {
        $this->assertSame(2501, Stats::p95FromBuckets([
            'b100' => 1, 'b250' => 0, 'b500' => 0, 'b1000' => 0, 'b2500' => 0, 'b_inf' => 99,
        ]));
    }

    public function test_p95_treats_negative_or_missing_max_ms_as_absent(): void
    {
        $this->assertSame(2501, Stats::p95FromBuckets([
            'b_inf' => 1, 'max_ms' => 0,
        ]));
    }
}
