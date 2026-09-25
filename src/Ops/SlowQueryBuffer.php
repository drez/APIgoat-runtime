<?php

namespace ApiGoat\Ops;

/**
 * Per-request queue of slow queries (fed by TimedStatement::execute()),
 * flushed into ops_query_slow from RequestRecorder::defer()'s shutdown hook.
 *
 * Static for the same reason QueryCounter is: TimedStatement has no
 * request-scoped object to hand its findings to. queue() is the single
 * choke point that normalizes SQL before it is ever held in memory (see
 * SqlFingerprint) — nothing downstream (flush(), peek()) ever sees a raw,
 * literal-carrying query string.
 */
final class SlowQueryBuffer
{
    /** Hard cap on rows written per request (and, to bound memory, per queue()). */
    public const MAX_ROWS = 20;

    /** @var list<array{ms:int,sql_hash:string,sql_text:string}> */
    private static array $queue = [];

    /**
     * True while flush() is running its own INSERTs. TimedStatement checks
     * this before instrumenting a query: the flush's own INSERT statements
     * run on the very same connection TimedStatement is attached to, so
     * without this guard each flushed row's INSERT would itself be timed
     * and (if slow) re-queued — recursively, forever growing the next
     * request's flush.
     */
    private static bool $flushing = false;

    public static function isFlushing(): bool
    {
        return self::$flushing;
    }

    /**
     * Queue one slow query. $sql is the RAW statement text — normalization
     * happens here, once, so nothing else needs to remember to do it.
     * Silently drops anything past MAX_ROWS for this request.
     */
    public static function queue(int $ms, string $sql): void
    {
        if (\count(self::$queue) >= self::MAX_ROWS) {
            return;
        }

        self::$queue[] = [
            'ms'       => $ms,
            'sql_hash' => SqlFingerprint::hash($sql),
            'sql_text' => SqlFingerprint::normalize($sql),
        ];
    }

    public static function count(): int
    {
        return \count(self::$queue);
    }

    /** Test seam: the queued rows, in order — never a raw SQL string (see class doc). */
    public static function peek(): array
    {
        return self::$queue;
    }

    /** Drop everything queued so far without writing it. */
    public static function reset(): void
    {
        self::$queue = [];
    }

    /**
     * Write the queued rows (at most MAX_ROWS — queue() already enforces
     * that, but flush() only ever sends what it dequeues) to ops_query_slow
     * and empty the queue. Never throws: a DB failure here must not break
     * the request it is finishing up after.
     */
    public static function flush(\PDO $pdo, string $route): void
    {
        if (self::$flushing || self::$queue === []) {
            return;
        }

        $rows = self::$queue;
        self::$queue = [];
        self::$flushing = true;

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ops_query_slow (route, ms, sql_hash, sql_text, created_at)
                 VALUES (:route, :ms, :sql_hash, :sql_text, :created_at)'
            );
            $route = \substr($route, 0, 191);
            $now = \time();

            foreach ($rows as $row) {
                $stmt->execute([
                    ':route'      => $route,
                    ':ms'         => $row['ms'],
                    ':sql_hash'   => $row['sql_hash'],
                    ':sql_text'   => $row['sql_text'],
                    ':created_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            \error_log('[ops] slow query flush failed: ' . $e->getMessage());
        } finally {
            self::$flushing = false;
        }
    }
}
