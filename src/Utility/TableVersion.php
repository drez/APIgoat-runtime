<?php
// /var/www/gc/vendor/apigoat/runtime/src/Utility/TableVersion.php
namespace ApiGoat\Utility;

use ApiGoat\Realtime;

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
    /**
     * Tables written inside a still-open transaction, waiting for the
     * post-commit bump: table => the PropelPDO (or any object exposing
     * isInTransaction()) the write ran on.
     *
     * @var array<string, object|null>
     */
    private static array $pending = [];

    private static bool $shutdownRegistered = false;

    private static bool $flushing = false;

    /**
     * Bump the generation for a table. Never throws.
     *
     * $con is the connection the write ran on (emitted hooks pass the save()/
     * delete() $con). The emitted hooks run INSIDE save()'s own transaction,
     * before $con->commit(): a bump there lets a concurrent reader fetch the
     * new generation, read the still-uncommitted (old) rows and cache them
     * under the new generation for the full TTL. So when $con is still in a
     * transaction the bump happens now (harmless) AND again once the
     * outermost transaction has committed (flushPending), which orphans
     * anything cached in that window. The realtime signal is sent only at
     * that post-commit point, so clients never re-fetch before the data is
     * visible. Without $con, or outside a transaction, it is one immediate
     * bump + signal, as before.
     */
    public static function bump(string $tableName, $con = null): void
    {
        self::increment($tableName);

        if (self::inTransaction($con)) {
            self::$pending[$tableName] = $con;
            self::registerShutdown();
            return;
        }

        // A write outside any transaction is itself a flush point for writes
        // queued earlier whose transaction has since committed.
        if (self::$pending) {
            self::flushPending();
        }
        self::signal($tableName);
    }

    /**
     * Post-commit half of bump(): re-bump + signal every queued table whose
     * transaction is no longer open. Called from bump()/get() (the next
     * TableVersion touch after a commit), from queue drainers between jobs,
     * and from a shutdown function as the fallback; $force (shutdown) flushes
     * everything — by then the request's transactions are committed or gone,
     * and a spurious bump after a rollback only costs a cache miss.
     */
    public static function flushPending(bool $force = false): void
    {
        if (self::$flushing) {
            return; // signal() -> get() re-enters; the outer loop owns the queue
        }
        self::$flushing = true;
        try {
            foreach (self::$pending as $tableName => $con) {
                if (!$force && self::inTransaction($con)) {
                    continue;
                }
                unset(self::$pending[$tableName]);
                self::increment($tableName);
                self::signal($tableName);
            }
        } finally {
            self::$flushing = false;
        }
    }

    /** Tables still waiting for their post-commit bump (tests / diagnostics). */
    public static function pendingTables(): array
    {
        return \array_keys(self::$pending);
    }

    private static function inTransaction($con): bool
    {
        if (!\is_object($con) || !\method_exists($con, 'isInTransaction')) {
            return false;
        }
        try {
            return (bool) $con->isInTransaction();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        try {
            \register_shutdown_function(static function (): void {
                try {
                    self::flushPending(true);
                } catch (\Throwable $e) {
                    // never let invalidation break shutdown
                }
            });
        } catch (\Throwable $e) {
            self::$shutdownRegistered = false;
        }
    }

    private static function increment(string $tableName): void
    {
        try {
            MicroCache::increment(self::genKey($tableName));
        } catch (\Throwable $e) {
            // invalidation is best-effort; the consumer TTLs are the backstop
        }
    }

    /**
     * Same event, second consumer: tell the realtime sidecar something
     * changed so subscribed clients can re-fetch. Inert unless the project
     * sets GC_RT_ENABLED=1 — enabled() is a memoised bool, so the off path
     * costs one static read on a code path that runs on EVERY write.
     * Separate from the cache bump so a signalling problem can never cost
     * us the invalidation, and never throws either way.
     */
    private static function signal(string $tableName): void
    {
        try {
            if (Realtime\Signal::enabled()) {
                Realtime\Signal::emit($tableName, self::get($tableName), self::tenantToken());
            }
        } catch (\Throwable $e) {
            // a notification is never worth failing a write over
        }
    }

    /** Current generation token for a table ('0' when never bumped). */
    public static function get(string $tableName): string
    {
        // A reader after a commit must not see (and cache under) the
        // pre-commit generation of a write queued in this process.
        if (self::$pending) {
            self::flushPending();
        }
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
     * condition of the GoatCheese behavior's tenantQueryGuard VERBATIM (keep
     * the two in sync — goatcheese GoatCheese.php): for a connected, non-root
     * user the ORM injects filterByIdTenant(<tenant>) into preSelect when the
     * session carries a truthy id_tenant ('t<id>'), and fails closed
     * (where 1 = 0) when it does not ('tnone' — those reads see no rows and
     * must never share entries with root/unscoped reads). Everyone else
     * (root, not connected) reads unscoped: 'all'.
     */
    public static function tenantToken(): string
    {
        if (\defined('_AUTH_VAR') && isset($_SESSION[\_AUTH_VAR]) && \is_object($_SESSION[\_AUTH_VAR])
            && \method_exists($_SESSION[\_AUTH_VAR], 'get')
            && $_SESSION[\_AUTH_VAR]->get('connected') == 'YES'
            && ! $_SESSION[\_AUTH_VAR]->get('isRoot')) {
            if ($_SESSION[\_AUTH_VAR]->get('id_tenant')) {
                return 't' . $_SESSION[\_AUTH_VAR]->get('id_tenant');
            }
            return 'tnone';
        }
        return 'all';
    }

    private static function genKey(string $tableName): string
    {
        return 'gc:gen:' . self::ns() . ':' . $tableName;
    }
}
