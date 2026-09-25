<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\SlowQueryBuffer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/SqlFingerprint.php';
require_once __DIR__ . '/../../src/Ops/SlowQueryBuffer.php';

/**
 * Pure queue/cap logic only (R1: no pdo_sqlite on this host). flush()'s own
 * INSERTs against a real ops_query_slow table are covered against MySQL in
 * P/.admin/tests/Custom/OpsSlowQueryTest.php.
 */
final class SlowQueryBufferTest extends TestCase
{
    protected function setUp(): void
    {
        SlowQueryBuffer::reset();
    }

    public function test_starts_empty(): void
    {
        $this->assertSame(0, SlowQueryBuffer::count());
        $this->assertSame([], SlowQueryBuffer::peek());
    }

    public function test_queue_normalizes_the_sql_before_storing_it(): void
    {
        SlowQueryBuffer::queue(500, "SELECT * FROM authy WHERE email = 'a@b.c'");

        $rows = SlowQueryBuffer::peek();
        $this->assertCount(1, $rows);
        $this->assertSame(500, $rows[0]['ms']);
        $this->assertSame('SELECT * FROM authy WHERE email = ?', $rows[0]['sql_text']);
        $this->assertStringNotContainsString('a@b.c', $rows[0]['sql_text']);
        $this->assertSame(40, \strlen($rows[0]['sql_hash']));
    }

    public function test_caps_at_max_rows(): void
    {
        for ($i = 0; $i < SlowQueryBuffer::MAX_ROWS + 5; $i++) {
            SlowQueryBuffer::queue(300, "SELECT {$i}");
        }

        $this->assertSame(SlowQueryBuffer::MAX_ROWS, SlowQueryBuffer::count());
    }

    public function test_reset_clears_the_queue(): void
    {
        SlowQueryBuffer::queue(300, 'SELECT 1');
        SlowQueryBuffer::reset();

        $this->assertSame(0, SlowQueryBuffer::count());
    }

    public function test_flush_is_a_noop_on_an_empty_queue(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->never())->method('prepare');

        SlowQueryBuffer::flush($pdo, '/x');
    }

    public function test_flush_clears_the_queue_even_if_the_insert_fails(): void
    {
        SlowQueryBuffer::queue(300, 'SELECT 1');

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willThrowException(new \PDOException("Table 'ops_query_slow' doesn't exist"));

        // flush()'s swallow logs via error_log() — redirected to a temp file
        // so the line never reaches this run's stderr (R11), and asserted on.
        $log = \tempnam(\sys_get_temp_dir(), 'slowq');
        $prev = \ini_get('error_log');
        \ini_set('error_log', $log);
        try {
            SlowQueryBuffer::flush($pdo, '/x');
            $logged = (string) \file_get_contents($log);
        } finally {
            \ini_set('error_log', (string) $prev);
            @\unlink($log);
        }

        $this->assertStringContainsString("ops_query_slow' doesn't exist", $logged);
        $this->assertSame(0, SlowQueryBuffer::count());
        $this->assertFalse(SlowQueryBuffer::isFlushing());
    }

    public function test_flushing_flag_is_false_outside_of_flush(): void
    {
        $this->assertFalse(SlowQueryBuffer::isFlushing());
    }
}
