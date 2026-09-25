<?php

namespace ApiGoat\Ops;

/**
 * Per-request query counter feeding ops_req_hour/ops_req_slow's `queries`
 * column. Static for the same reason ApiGoat\Utility\Timing is: the thing
 * worth counting (every SQL query Propel runs) has no route to a
 * request-scoped object. ServerTimingMiddleware reset()s this next to
 * Timing::reset() at the top of every request, so the count never leaks
 * across requests.
 *
 * Also the seam Task 3's slow-query buffer will build on: it can call
 * inc($ms) from the same place this counter is incremented and keep its own
 * buffer of the queries whose $ms crossed slow_query_ms, flushed from the
 * shutdown hook RequestRecorder::defer() registers.
 */
final class QueryCounter
{
    private static int $count = 0;

    private static float $totalMs = 0.0;

    /** Record one query. $ms is its duration, kept for a future slow-query buffer (Task 3). */
    public static function inc(float $ms): void
    {
        self::$count++;
        self::$totalMs += $ms;
    }

    public static function count(): int
    {
        return self::$count;
    }

    /** Sum of every inc()'d duration this request, in ms. */
    public static function totalMs(): float
    {
        return self::$totalMs;
    }

    public static function reset(): void
    {
        self::$count = 0;
        self::$totalMs = 0.0;
    }
}
