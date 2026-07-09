<?php
// /var/www/gc/vendor/apigoat/runtime/src/Utility/MicroCache.php
namespace ApiGoat\Utility;

/**
 * Minimal TTL cache: APCu when available (shared across FPM requests),
 * otherwise a per-process static array (CLI / hosts without APCu — the
 * fallback still helps composite tools that hit the same key many times
 * inside one request, and keeps tests deterministic).
 *
 * Values are stored serialized so a cache hit ALWAYS returns a fresh copy —
 * callers may mutate the result without poisoning the cache (matches APCu
 * semantics exactly, so both backends behave identically).
 */
final class MicroCache
{
    /** @var array<string, array{0:int,1:string}> [expiresAt, serialized] */
    private static array $store = [];

    /** @var array<string, int> per-process fallback counters (no TTL) */
    private static array $counters = [];

    public static function remember(string $key, int $ttl, callable $fn): mixed
    {
        if ($ttl <= 0) {
            return $fn();
        }

        if (self::apcuUsable()) {
            $hit = apcu_fetch($key, $ok);
            if ($ok) {
                return unserialize($hit);
            }
            $val = $fn();
            apcu_store($key, serialize($val), $ttl);
            return $val;
        }

        $now = time();
        $entry = self::$store[$key] ?? null;
        if ($entry !== null && $entry[0] > $now) {
            return unserialize($entry[1]);
        }
        $val = $fn();
        self::$store[$key] = [$now + $ttl, serialize($val)];
        return $val;
    }

    public static function forget(string $key): void
    {
        if (self::apcuUsable()) {
            apcu_delete($key);
        }
        unset(self::$store[$key]);
    }

    /**
     * Return the cached value for $key, or null on a miss (without running any
     * callable). Complements put() for callers that need a check-then-act pattern
     * without the null-placeholder awkwardness of using remember().
     */
    public static function get(string $key): mixed
    {
        if (self::apcuUsable()) {
            $hit = apcu_fetch($key, $ok);
            return $ok ? unserialize($hit) : null;
        }
        $now   = time();
        $entry = self::$store[$key] ?? null;
        if ($entry !== null && $entry[0] > $now) {
            return unserialize($entry[1]);
        }
        return null;
    }

    /**
     * Store $val under $key for $ttl seconds. No-op when $ttl <= 0.
     * Returned value from a subsequent get() is always a fresh copy (unserialize
     * round-trip), identical to the guarantee remember() and APCu provide.
     */
    public static function put(string $key, int $ttl, mixed $val): void
    {
        if ($ttl <= 0) {
            return;
        }
        if (self::apcuUsable()) {
            apcu_store($key, serialize($val), $ttl);
            return;
        }
        self::$store[$key] = [time() + $ttl, serialize($val)];
    }

    /**
     * Atomic counter (generation tokens for write-through invalidation).
     * Counters are stored as raw integers — separate from the serialized
     * value entries — and carry no TTL. First use seeds with time() (via an
     * atomic add) so an APCu restart or eviction can never hand back a
     * previously-used generation, which would resurrect still-TTL-live
     * entries cached under an old token. Returns the new counter value.
     */
    public static function increment(string $key, int $step = 1): int
    {
        if (self::apcuUsable()) {
            // Seed only if absent (atomic), then increment the existing key —
            // avoids relying on apcu_inc's create-from-zero semantics.
            apcu_add($key, time());
            $val = apcu_inc($key, $step, $ok);
            if ($ok) {
                return (int) $val;
            }
            return time();
        }
        return self::$counters[$key] = (self::$counters[$key] ?? time()) + $step;
    }

    /** Current counter value (0 when never incremented). Raw read — counters
     *  are not serialized, so get() must not be used for them. */
    public static function counter(string $key): int
    {
        if (self::apcuUsable()) {
            $val = apcu_fetch($key, $ok);
            return $ok ? (int) $val : 0;
        }
        return self::$counters[$key] ?? 0;
    }

    /** Test helper: clear the per-process fallback store. */
    public static function flushLocal(): void
    {
        self::$store = [];
        self::$counters = [];
    }

    private static function apcuUsable(): bool
    {
        return \function_exists('apcu_store')
            && (\PHP_SAPI !== 'cli'
                || \filter_var(\ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOL));
    }
}
