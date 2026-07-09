<?php
// /var/www/gc/vendor/apigoat/runtime/src/Utility/SelectBoxCache.php
namespace ApiGoat\Utility;

/**
 * Cross-request cache for emitted selectBox{Table}_{Column}() option arrays.
 *
 * Each of those methods loads an ENTIRE reference table (select + orderBy,
 * no filter) on every list render (search drawer), edit form, and child-list
 * render. The emitter wraps the cacheable ones — static filters only, and no
 * beginSelectbox* / selectboxData* project hooks — in fetch()/store() calls.
 *
 * Invalidation: the FK table's TableVersion generation is embedded in the
 * key, and the ORM behavior bumps it on every save/delete — so a quick-add
 * insert is visible on the very next render. The TTL only bounds staleness
 * for writes that bypass ORM hooks (raw SQL seeds, restore, deleteAll()).
 *
 * Tenant scoping: tables with an id_tenant column are read through the ORM's
 * tenant preSelect filter, so their keys carry the session tenant token.
 */
final class SelectBoxCache
{
    /** GC_SELECTBOX_CACHE_TTL seconds; default 60; 0 disables. */
    public static function ttl(): int
    {
        $v = \function_exists('env') ? env('GC_SELECTBOX_CACHE_TTL') : \getenv('GC_SELECTBOX_CACHE_TTL');
        return ($v === false || $v === null || $v === '') ? 60 : \max(0, (int) $v);
    }

    /** @return array|null null = miss or caching disabled */
    public static function fetch(string $fkTableName, string $method, bool $tenantScoped): ?array
    {
        if (self::ttl() <= 0) {
            return null;
        }
        $hit = MicroCache::get(self::key($fkTableName, $method, $tenantScoped));
        return \is_array($hit) ? $hit : null;
    }

    public static function store(string $fkTableName, string $method, bool $tenantScoped, array $options): void
    {
        $ttl = self::ttl();
        if ($ttl <= 0) {
            return;
        }
        MicroCache::put(self::key($fkTableName, $method, $tenantScoped), $ttl, $options);
    }

    private static function key(string $fkTableName, string $method, bool $tenantScoped): string
    {
        return 'gc:sb:' . TableVersion::ns()
            . ':' . TableVersion::get($fkTableName)
            . ':' . $method
            . ':' . ($tenantScoped ? TableVersion::tenantToken() : 'all');
    }
}
