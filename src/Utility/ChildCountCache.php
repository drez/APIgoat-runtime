<?php
// /var/www/gc/vendor/apigoat/runtime/src/Utility/ChildCountCache.php
namespace ApiGoat\Utility;

/**
 * Cross-request cache for the child-tab count badges on edit forms.
 *
 * The emitted form runs one filterBy{FK}()->count() per visible child tab on
 * every edit-form render. This helper owns the whole lookup (guards + count +
 * cache) so fixes propagate via composer without a fleet rebuild; the emitted
 * call site is a thin class_exists-guarded call.
 *
 * Keyed per (child model, FK, parent id) + the child table's TableVersion
 * generation (bumped by the ORM behavior on every child write, so adding a
 * row shows the new count immediately) + the session tenant token (child
 * COUNTs run through the tenant preSelect filter like any other query; the
 * token is applied unconditionally — 'all' for unscoped sessions — which is
 * always correct at worst one extra cache entry per tenant).
 */
final class ChildCountCache
{
    /** GC_CHILDCOUNT_CACHE_TTL seconds; default 30; 0 disables. */
    public static function ttl(): int
    {
        $v = \function_exists('env') ? env('GC_CHILDCOUNT_CACHE_TTL') : \getenv('GC_CHILDCOUNT_CACHE_TTL');
        return ($v === false || $v === null || $v === '') ? 30 : \max(0, (int) $v);
    }

    /**
     * Count child rows for a parent, cached. Returns null when the child
     * Query class or its filterBy{FK} method can't be located — the caller
     * renders no badge, matching the previous emitted guards.
     *
     * @param int|string $parentId
     */
    public static function count(string $childModel, string $fkPhpName, $parentId): ?int
    {
        $queryClass = '\\App\\' . $childModel . 'Query';
        $filter     = 'filterBy' . $fkPhpName;
        if (! \class_exists($queryClass) || ! \method_exists($queryClass, $filter)) {
            return null;
        }

        $ttl = self::ttl();
        if ($ttl <= 0) {
            return (int) $queryClass::create()->$filter($parentId)->count();
        }

        $key = 'gc:cc:' . TableVersion::ns()
            . ':' . TableVersion::get(self::tableNameFor($childModel))
            . ':' . $childModel . ':' . $fkPhpName . ':' . $parentId
            . ':' . TableVersion::tenantToken();

        $hit = MicroCache::get($key);
        if (\is_int($hit)) {
            return $hit;
        }

        $count = (int) $queryClass::create()->$filter($parentId)->count();
        MicroCache::put($key, $ttl, $count);
        return $count;
    }

    /** Physical table name for the generation key (falls back to the model name). */
    private static function tableNameFor(string $childModel): string
    {
        $peer = '\\App\\' . $childModel . 'Peer';
        if (\class_exists($peer) && \defined($peer . '::TABLE_NAME')) {
            return \constant($peer . '::TABLE_NAME');
        }
        return $childModel;
    }
}
