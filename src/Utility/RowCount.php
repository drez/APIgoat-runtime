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
 * another's count.
 *
 * Freshness: with a shared cache (APCu) each disk entry carries the table's
 * TableVersion and stays valid until a write bumps it, with VERSIONED_TTL as
 * the safety net for raw-SQL writers that never bump (analytics/ops tables).
 * The menu then costs ~0 queries per page instead of one COUNT per item
 * (63 on apigoatacc) every 30 s. Without APCu the version never moves, so
 * the plain TTL applies. Failures (missing class, no DB column, exception, unwritable
 * cache dir) yield null/no-cache and the caller omits the chip. Never
 * fatal, never unbounded slow queries.
 */
class RowCount
{
    /** Seconds a cached count stays fresh without a shared table version. */
    private const TTL = 30;
    /** Seconds a count stays fresh while its table version is unchanged. */
    private const VERSIONED_TTL = 600;

    /** @var (callable(): int)|null test seam */
    private static $clock = null;
    /** @var bool|null test seam: force the versioned path on/off */
    private static $versions = null;
    /** @var string|null test seam: cache file path */
    private static $file = null;

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

        $now = self::now();
        $version = self::versions() ? TableVersion::get(self::tableFor($Model)) : null;
        $disk = self::loadDisk();
        if (isset($disk[$key])) {
            $age = $now - $disk[$key][1];
            $sameVersion = $version !== null && ($disk[$key][2] ?? null) === $version;
            if ($age < self::TTL || ($sameVersion && $age < self::VERSIONED_TTL)) {
                return self::$cache[$key] = $disk[$key][0];
            }
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
        self::storeDisk($key, $count, $now, $version);
        return $count;
    }

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    private static function versions(): bool
    {
        return self::$versions ?? MicroCache::shared();
    }

    private static function tableFor(string $Model): string
    {
        $peer = '\\App\\' . $Model . 'Peer';
        return class_exists($peer) && defined($peer . '::TABLE_NAME') ? constant($peer . '::TABLE_NAME') : $Model;
    }

    /** Test seam: the clock (null = time()). */
    public static function clock(?callable $fn): void
    {
        self::$clock = $fn;
    }

    /** Test seam: force table-version freshness on/off (null = MicroCache::shared()). */
    public static function useVersions(?bool $on): void
    {
        self::$versions = $on;
    }

    /** Test seam: set the cache file (null keeps it); returns the current one. */
    public static function cacheFileForTest(?string $path): ?string
    {
        if ($path !== null) {
            self::$file = $path;
            self::$disk = null;
        }
        return self::$file;
    }

    /** Test seam: forget the per-request memo (a new request). */
    public static function dropMemo(): void
    {
        self::$cache = [];
        self::$disk = null;
    }

    /** Test seam: back to defaults. */
    public static function reset(): void
    {
        self::$cache = [];
        self::$disk = null;
        self::$clock = null;
        self::$versions = null;
        self::$file = null;
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

    private static function storeDisk($key, $count, int $now, ?string $version)
    {
        $file = self::cacheFile();
        if ($file === null) {
            return;
        }
        $map = self::loadDisk();
        $map[$key] = [$count, $now, $version];
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
        if (self::$file !== null) {
            return self::$file;
        }
        $base = defined('_BASE_DIR') ? _BASE_DIR : (sys_get_temp_dir() . DIRECTORY_SEPARATOR);
        $dir  = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($dir)) {
            return null; // do not create dirs here; tmp/ exists in every project
        }
        return $dir . DIRECTORY_SEPARATOR . 'rowcount-cache.php';
    }
}
