<?php
// /var/www/gc/vendor/apigoat/runtime/src/Utility/TableVersion.php
namespace ApiGoat\Utility;

/**
 * Per-table generation tokens for write-through cache invalidation.
 *
 * Emitted ORM hooks (GoatCheese behavior postSave/postDelete and the
 * query-level post-update/post-delete) call bump(<table>) on every write;
 * cache consumers (SelectBoxCache, ChildCountCache, the RBAC ruleset cache)
 * embed get(<table>) in their value keys, so a bump implicitly orphans every
 * entry cached under the previous generation — no deletes, no key registry.
 * Orphaned entries expire via their own short TTLs.
 *
 * Called from generated code on every save: must be dependency-free and must
 * never throw (cache invalidation can never be allowed to break a write).
 */
final class TableVersion
{
    /** Bump the generation for a table. Never throws. */
    public static function bump(string $tableName): void
    {
        try {
            MicroCache::increment(self::genKey($tableName));
        } catch (\Throwable $e) {
            // invalidation is best-effort; the consumer TTLs are the backstop
        }
    }

    /** Current generation token for a table ('0' when never bumped). */
    public static function get(string $tableName): string
    {
        try {
            return (string) MicroCache::counter(self::genKey($tableName));
        } catch (\Throwable $e) {
            return '0';
        }
    }

    /**
     * Per-project key namespace: APCu is shared across every project in one
     * FPM pool, so keys are scoped by the project base dir.
     */
    public static function ns(): string
    {
        static $ns = null;
        return $ns ??= \substr(\md5(\defined('_BASE_DIR') ? \_BASE_DIR : (string) \getcwd()), 0, 8);
    }

    /**
     * Tenant token for cache keys of id_tenant-scoped tables. Mirrors the
     * condition of the GoatCheese behavior's tenantQueryGuard VERBATIM: the
     * ORM injects filterByIdTenant() into preSelect exactly when a connected,
     * non-root user carries a truthy id_tenant — so those (and only those)
     * reads see tenant-filtered rows and must not share cache entries with
     * root/unscoped reads.
     */
    public static function tenantToken(): string
    {
        if (\defined('_AUTH_VAR') && isset($_SESSION[\_AUTH_VAR]) && \is_object($_SESSION[\_AUTH_VAR])
            && \method_exists($_SESSION[\_AUTH_VAR], 'get')
            && $_SESSION[\_AUTH_VAR]->get('connected') == 'YES'
            && ! $_SESSION[\_AUTH_VAR]->get('isRoot')
            && $_SESSION[\_AUTH_VAR]->get('id_tenant')) {
            return 't' . $_SESSION[\_AUTH_VAR]->get('id_tenant');
        }
        return 'all';
    }

    private static function genKey(string $tableName): string
    {
        return 'gc:gen:' . self::ns() . ':' . $tableName;
    }
}
