<?php

namespace App {
    if (!\class_exists(RcFakeQuery::class)) {
        final class RcFakeQuery
        {
            public static int $calls = 0;
            public static int $rows = 7;
            public static function create(): self { return new self(); }
            public function count(): int { self::$calls++; return self::$rows; }
        }
    }
}

namespace ApiGoat\Tests\Utility {

    use ApiGoat\Utility\RowCount;
    use ApiGoat\Utility\TableVersion;
    use PHPUnit\Framework\TestCase;

    /**
     * Menu chip counts: with a shared cache (APCu) the disk entry carries the
     * table's TableVersion and stays valid until a write bumps it (10 min
     * safety net for raw-SQL writers that never bump); without one, the old
     * 30 s TTL.
     */
    final class RowCountVersionedTest extends TestCase
    {
        private int $now = 1_000_000;

        protected function setUp(): void
        {
            if (!\defined('_AUTH_VAR')) {
                \define('_AUTH_VAR', 'AUTH');
            }
            \App\RcFakeQuery::$calls = 0;
            RowCount::reset();
            RowCount::clock(fn () => $this->now);
            RowCount::cacheFileForTest(\sys_get_temp_dir() . '/rowcount-' . \bin2hex(\random_bytes(4)) . '.php');
        }

        protected function tearDown(): void
        {
            @\unlink((string) RowCount::cacheFileForTest(null));
            RowCount::reset();
        }

        private function fresh(): ?int
        {
            RowCount::dropMemo(); // a new request: per-request memo gone, disk cache stays
            return RowCount::forModel('RcFake');
        }

        public function test_versioned_count_survives_past_30s_until_the_table_changes(): void
        {
            RowCount::useVersions(true);
            $this->assertSame(7, $this->fresh());
            $this->now += 300;
            $this->assertSame(7, $this->fresh());
            $this->assertSame(1, \App\RcFakeQuery::$calls, 'reused 5 min later: same table version');

            TableVersion::bump('RcFake');
            \App\RcFakeQuery::$rows = 8;
            $this->assertSame(8, $this->fresh());
            $this->assertSame(2, \App\RcFakeQuery::$calls, 'a write orphans the cached count');

            $this->now += 601;
            $this->fresh();
            $this->assertSame(3, \App\RcFakeQuery::$calls, '10 min safety net for writers that never bump');
        }

        public function test_without_a_shared_cache_the_30s_ttl_applies(): void
        {
            RowCount::useVersions(false);
            $this->fresh();
            $this->now += 20;
            $this->fresh();
            $this->assertSame(1, \App\RcFakeQuery::$calls);
            $this->now += 15;
            $this->fresh();
            $this->assertSame(2, \App\RcFakeQuery::$calls);
        }
    }
}
