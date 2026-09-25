<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\QueryCounter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/QueryCounter.php';

final class QueryCounterTest extends TestCase
{
    protected function setUp(): void
    {
        QueryCounter::reset();
    }

    public function test_starts_at_zero(): void
    {
        $this->assertSame(0, QueryCounter::count());
        $this->assertSame(0.0, QueryCounter::totalMs());
    }

    public function test_inc_increments_count_and_accumulates_ms(): void
    {
        QueryCounter::inc(1.5);
        QueryCounter::inc(2.5);

        $this->assertSame(2, QueryCounter::count());
        $this->assertSame(4.0, QueryCounter::totalMs());
    }

    public function test_reset_zeroes_both(): void
    {
        QueryCounter::inc(10.0);
        QueryCounter::reset();

        $this->assertSame(0, QueryCounter::count());
        $this->assertSame(0.0, QueryCounter::totalMs());
    }
}
