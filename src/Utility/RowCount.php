<?php

namespace ApiGoat\Utility;

/**
 * Cache of "\App\{Model}Query::create()->count()" results.
 *
 * Two layers: a per-request static memo (cheap repeat lookups within one
 * page) over a process-shared TTL disk cache (so the menu's N distinct
 * model counts survive across requests instead of re-COUNTing every page).
 *
 * Counts run through the model query, so the tenant scoping injected by
 * the ORM behavior (basePreSelect) applies: the cache is keyed per
 * TableVersion::tenantToken() ('all' for root/anonymous, 't<id>' per tenant,
 * 'tnone' for a tenant-less non-root user) so one tenant never sees
 * another's count. Failures (missing class, no DB column, exception, unwritable
 * cache dir) yield null/no-cache and the caller omits the chip. Never
 * fatal, never unbounded slow queries.
 */
class RowCount
{
    /** Seconds a cached count stays fresh. */
    private const TTL = 30;

    /** @var array<string, int|null> per-request memo */
    private static $cache = [];

    /** @var array<string, array{0:int|null,1:int}>|null lazily loaded disk map: model => [count, unixts] */
    private static $disk = null;

    /**
     * @param  string $Model  canonical model name (already camelized by caller)
     * @return int|null        null => omit the chip
     */
    public static function forModel($Model)
    {
        if (!is_string($Model) || $Model === '') {
            return null;
        }
        $key = self::cacheKey($Model);
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $disk = self::loadDisk();
        if (isset($disk[$key]) && (time() - $disk[$key][1]) < self::TTL) {
            return self::$cache[$key] = $disk[$key][0];
        }

        $count = null;
        $queryClass = '\\App\\' . $Model . 'Query';
        if (class_exists($queryClass)) {
            try {
                $count = (int) $queryClass::create()->count();
            } catch (\Throwable $e) {
                $count = null;
            }
        }

        self::$cache[$key] = $count;
        self::storeDisk($key, $count);
        return $count;
    }

    /**
     * Cache key for a model under the current tenant scope.
     * Public for tests.
     */
    public static function cacheKey(string $Model): string
    {
        return $Model . '|' . TableVersion::tenantToken();
    }

    /** @return array<string, array{0:int|null,1:int}> */
    private static function loadDisk()
    {
        if (self::$disk !== null) {
            return self::$disk;
        }
        self::$disk = [];
        $file = self::cacheFile();
        if ($file !== null && is_file($file)) {
            try {
                $data = include $file;
                if (is_array($data)) {
                    self::$disk = $data;
                }
            } catch (\Throwable $e) {
                self::$disk = [];
            }
        }
        return self::$disk;
    }

    private static function storeDisk($key, $count)
    {
        $file = self::cacheFile();
        if ($file === null) {
            return;
        }
        $map = self::loadDisk();
        $map[$key] = [$count, time()];
        self::$disk = $map;
        try {
            $tmp = $file . '.' . getmypid() . '.tmp';
            $php = '<?php return ' . var_export($map, true) . ';';
            if (file_put_contents($tmp, $php, LOCK_EX) !== false) {
                @rename($tmp, $file); // atomic; a lost race just re-COUNTs once, harmless
            }
        } catch (\Throwable $e) {
            // unwritable cache dir: silently fall back to per-request counts
        }
    }

    private static function cacheFile()
    {
        $base = defined('_BASE_DIR') ? _BASE_DIR : (sys_get_temp_dir() . DIRECTORY_SEPARATOR);
        $dir  = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($dir)) {
            return null; // do not create dirs here; tmp/ exists in every project
        }
        return $dir . DIRECTORY_SEPARATOR . 'rowcount-cache.php';
    }
}
