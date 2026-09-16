<?php
// /var/www/gc/vendor/apigoat/runtime/src/Utility/PublicCacheMap.php
namespace ApiGoat\Utility;

/**
 * Declaration map + key builder for the anonymous public-API response cache
 * (PublicResponseCacheMiddleware).
 *
 * The map is EMITTED at build time into config/Built/cache.map.php from the
 * HJSON schema — the runtime never decides on its own what is safe to cache;
 * it only honours what the build declared. The file `return`s:
 *
 *   [
 *     '_build' => '<build fingerprint>',
 *     'routes' => [
 *       'Model/action/METHOD' => [
 *         'ttl'           => int,            // seconds; <= 0 => GC_HTTPCACHE_TTL
 *         'tables'        => string[],       // TableVersion generations folded into the key
 *         'params'        => null|string[],  // query allowlist (null = any)
 *         'bypass_params' => string[],       // any of these present => BYPASS
 *         'vary'          => string[],       // request headers folded into the key
 *       ],
 *       // compact forms tolerated at runtime (hand-written maps, older emitters):
 *       'Model/action/METHOD' => 60,        // int  => ttl only
 *       'Model/action/METHOD' => true,      // true => env default ttl
 *     ],
 *   ]
 *
 * Keys embed the build id, the RBAC ruleset generation and every declared
 * table generation, so a deploy, an ACL change or a write to a backing table
 * implicitly orphans every entry (same write-through scheme as SelectBoxCache
 * and the RBAC ruleset cache: no deletes, no key registry — orphans expire
 * through their own TTL).
 *
 * Every accessor is static and never throws: a broken map must degrade to
 * "feature off", never to a 500 on a public route.
 */
final class PublicCacheMap
{
    /** Hard caps on what a query string may contribute to a key. */
    public const MAX_QUERY_PARAMS = 32;
    public const MAX_QUERY_BYTES  = 2048;

    /** Default `GC_HTTPCACHE_MAX_KB` (response body cap, in KiB). */
    public const DEFAULT_MAX_KB = 512;

    private const EMPTY = ['_build' => '', 'routes' => []];

    /** @var array{_build:string,routes:array<string,array>}|null */
    private static ?array $map = null;

    /**
     * Load (and memoise) the map. An explicit $file always re-reads and
     * replaces the memo (tests point at a fixture); null uses the memo or the
     * default config/Built/cache.map.php.
     *
     * @return array{_build:string,routes:array<string,array>}
     */
    public static function load(?string $file = null): array
    {
        if ($file === null && self::$map !== null) {
            return self::$map;
        }
        if ($file === null) {
            if (!\defined('_BASE_DIR')) {
                return self::$map = self::EMPTY;
            }
            $file = \rtrim((string) \constant('_BASE_DIR'), '/\\') . '/config/Built/cache.map.php';
        }

        $raw = null;
        try {
            if (\is_file($file) && \is_readable($file)) {
                $raw = include $file;
            }
        } catch (\Throwable $e) {
            $raw = null;
        }

        return self::$map = self::normalizeMap($raw);
    }

    /** Test helper: drop the memoised map so the next load() re-reads. */
    public static function reset(): void
    {
        self::$map = null;
    }

    /**
     * Declaration for a parsed route, or null when the route is not declared
     * (=> the middleware is OFF for it). The returned array always carries all
     * five keys, with `ttl` resolved (env default applied) at call time so a
     * per-process env change is honoured without a reload.
     *
     * @return array{ttl:int,tables:string[],params:string[]|null,bypass_params:string[],vary:string[]}|null
     */
    public static function lookup(string $model, string $action, string $method): ?array
    {
        $routes = self::load()['routes'];
        $decl   = $routes[$model . '/' . $action . '/' . \strtoupper($method)] ?? null;
        if ($decl === null) {
            return null;
        }
        if ($decl['ttl'] <= 0) {
            $decl['ttl'] = self::ttl();
        }
        return $decl;
    }

    /** `GC_HTTPCACHE_TTL` seconds; default 0 = feature off. */
    public static function ttl(): int
    {
        $v = \function_exists('env') ? env('GC_HTTPCACHE_TTL') : \getenv('GC_HTTPCACHE_TTL');
        return ($v === false || $v === null || $v === '') ? 0 : \max(0, (int) $v);
    }

    /** `GC_HTTPCACHE_VERIFY=1`: serve fresh, compare with the cached copy, log divergence. */
    public static function verify(): bool
    {
        $v = \function_exists('env') ? env('GC_HTTPCACHE_VERIFY') : \getenv('GC_HTTPCACHE_VERIFY');
        return \filter_var((string) $v, \FILTER_VALIDATE_BOOL);
    }

    /** `GC_HTTPCACHE_MAX_KB` (default 512) as BYTES: bodies above this are never stored. */
    public static function maxBytes(): int
    {
        $v  = \function_exists('env') ? env('GC_HTTPCACHE_MAX_KB') : \getenv('GC_HTTPCACHE_MAX_KB');
        $kb = ($v === false || $v === null || $v === '') ? self::DEFAULT_MAX_KB : \max(0, (int) $v);
        return $kb * 1024;
    }

    /**
     * Cache key for one request, or null when the query is too large to be
     * worth hashing (> MAX_QUERY_PARAMS keys or > MAX_QUERY_BYTES serialised)
     * — the caller treats null as BYPASS so a crafted query can never bloat
     * the store with one-off entries.
     *
     * Layout: gc:http:<ns>:<build>:<rbac gen>:<t1 gen>.<t2 gen>…:<sha1>
     * The generation tokens live OUTSIDE the hash on purpose: a bump changes
     * the key prefix, so the old entry is simply never looked up again.
     *
     * The timezone is folded in because generated list/detail payloads format
     * dates in the process default — two workers with different defaults must
     * not share entries.
     *
     * @param array<string,mixed>  $query
     * @param array<string,string> $varyHeaders header name => value
     */
    public static function key(array $decl, string $method, string $route, array $query, array $varyHeaders, string $build): ?string
    {
        if (\count($query) > self::MAX_QUERY_PARAMS) {
            return null;
        }
        $qs = \http_build_query(self::normalizeQuery($query));
        if (\strlen($qs) > self::MAX_QUERY_BYTES) {
            return null;
        }

        $gens = [];
        foreach ((array) ($decl['tables'] ?? []) as $table) {
            $gens[] = TableVersion::get((string) $table);
        }

        $hash = \sha1(\implode('|', [
            \strtoupper($method),
            $route,
            $qs,
            (string) \json_encode($varyHeaders),
            \date_default_timezone_get(),
        ]));

        return 'gc:http:' . TableVersion::ns()
            . ':' . $build
            . ':' . TableVersion::get('api_rbac@rules')
            . ':' . \implode('.', $gens)
            . ':' . $hash;
    }

    /**
     * Order-insensitive, type-insensitive view of a query array: keys ksorted
     * at every level, scalars cast to string (so `?a=1` and `?a[]=1`-style
     * PHP-coerced ints/bools hash the same as their string form). Non-scalar,
     * non-array values (objects, resources) are dropped rather than
     * serialised — they never come from a real query string.
     *
     * @param array<mixed> $q
     * @return array<mixed>
     */
    public static function normalizeQuery(array $q): array
    {
        $out = [];
        foreach ($q as $k => $v) {
            if (\is_array($v)) {
                $out[$k] = self::normalizeQuery($v);
            } elseif ($v === null) {
                $out[$k] = '';
            } elseif (\is_scalar($v)) {
                $out[$k] = \is_bool($v) ? ($v ? '1' : '0') : (string) $v;
            }
        }
        \ksort($out, \SORT_STRING);
        return $out;
    }

    /**
     * @param mixed $raw whatever the map file returned
     * @return array{_build:string,routes:array<string,array>}
     */
    private static function normalizeMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return self::EMPTY;
        }
        $routes = [];
        foreach ((array) ($raw['routes'] ?? []) as $key => $decl) {
            $norm = self::normalizeDecl($decl);
            if ($norm === null || !\is_string($key) || \substr_count($key, '/') !== 2) {
                continue;
            }
            // Method segment is matched case-insensitively (lookup uppercases).
            [$model, $action, $method] = \explode('/', $key, 3);
            $routes[$model . '/' . $action . '/' . \strtoupper($method)] = $norm;
        }
        return [
            '_build' => \is_scalar($raw['_build'] ?? null) ? (string) $raw['_build'] : '',
            'routes' => $routes,
        ];
    }

    /**
     * @return array{ttl:int,tables:string[],params:string[]|null,bypass_params:string[],vary:string[]}|null
     */
    private static function normalizeDecl(mixed $decl): ?array
    {
        if ($decl === true) {
            $decl = [];
        } elseif (\is_int($decl)) {
            $decl = ['ttl' => $decl];
        } elseif (!\is_array($decl)) {
            return null; // false / null / garbage => not declared
        }

        $strings = static function (mixed $v): array {
            $out = [];
            foreach ((array) $v as $s) {
                if (\is_scalar($s) && (string) $s !== '') {
                    $out[] = (string) $s;
                }
            }
            return \array_values(\array_unique($out));
        };

        return [
            'ttl'           => \max(0, (int) ($decl['ttl'] ?? 0)),
            'tables'        => $strings($decl['tables'] ?? []),
            'params'        => \array_key_exists('params', $decl) && \is_array($decl['params']) ? $strings($decl['params']) : null,
            'bypass_params' => $strings($decl['bypass_params'] ?? []),
            'vary'          => $strings($decl['vary'] ?? []),
        ];
    }
}
