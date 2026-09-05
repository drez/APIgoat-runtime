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
 *
 * Owner/Group scoping: the emitted selectBox body narrows the FK query with
 * AuthySession::applyOwnerGroupScope when the FK model carries the ownership
 * columns, so the cached options are per-user. The key therefore carries a
 * scope token (scopeToken()) — without it the first caller's scoped option
 * list would be served to the next user of the same reference table.
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
    public static function fetch(string $fkTableName, string $method, bool $tenantScoped, string $scopeToken = 'all'): ?array
    {
        if (self::ttl() <= 0) {
            return null;
        }
        $hit = MicroCache::get(self::key($fkTableName, $method, $tenantScoped, $scopeToken));
        return \is_array($hit) ? $hit : null;
    }

    public static function store(string $fkTableName, string $method, bool $tenantScoped, array $options, string $scopeToken = 'all'): void
    {
        $ttl = self::ttl();
        if ($ttl <= 0) {
            return;
        }
        MicroCache::put(self::key($fkTableName, $method, $tenantScoped, $scopeToken), $ttl, $options);
    }

    /**
     * Owner/Group discriminator for the cache key of $model's option list.
     *
     * Mirrors what AuthySession::applyOwnerGroupScope() actually narrows on, so
     * two users share a cache entry only when their scoped query is identical:
     * 'all' for unrestricted (or ungranted — no narrowing either way) rights,
     * otherwise the owner id and/or the group id set the filter uses.
     */
    public static function scopeToken(string $model): string
    {
        if (! \defined('_AUTH_VAR') || ! isset($_SESSION[\_AUTH_VAR]) || ! \is_object($_SESSION[\_AUTH_VAR])
            || ! \method_exists($_SESSION[\_AUTH_VAR], 'hasRights')) {
            return 'all';
        }
        $scope = $_SESSION[\_AUTH_VAR]->hasRights($model, 'r');
        if (! \is_array($scope)) {
            return 'all'; // true (unrestricted) or false (no grant): no narrowing
        }

        $parts = [];
        if (\in_array('Owner', $scope, true)) {
            $parts[] = 'o' . $_SESSION[\_AUTH_VAR]->getIdAuthy();
        }
        if (\in_array('Group', $scope, true)) {
            $groups = $_SESSION[\_AUTH_VAR]->getGroups();
            $groups = \is_array($groups) ? $groups : [];
            \sort($groups);
            $parts[] = 'g' . \implode('.', $groups);
        }

        return $parts === [] ? 'all' : \implode('-', $parts);
    }

    private static function key(string $fkTableName, string $method, bool $tenantScoped, string $scopeToken = 'all'): string
    {
        return 'gc:sb:' . TableVersion::ns()
            . ':' . TableVersion::get($fkTableName)
            . ':' . $method
            . ':' . ($tenantScoped ? TableVersion::tenantToken() : 'all')
            . ':' . ($scopeToken !== '' ? $scopeToken : 'all');
    }
}
