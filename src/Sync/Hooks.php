<?php

namespace ApiGoat\Sync;

/**
 * Static entrypoints called by the service code the sync_accounting GoatCheese
 * parameter emits (post-save/post-delete, model-first). Must never throw into
 * a save — the emitted call site also wraps in try/catch as a second belt.
 */
final class Hooks
{
    public static function recordSaved(string $table, $model): void
    {
        $map = SyncMap::load();
        if (!$map || !SyncQueue::available() || !is_object($model) || !method_exists($model, 'getPrimaryKey')) {
            return;
        }
        $pk = (int) $model->getPrimaryKey();
        if ($pk <= 0) {
            return;
        }
        $hasLink = static fn (string $t, int $p): bool => class_exists('\App\AcctLinkQuery')
            && \App\AcctLinkQuery::create()->filterByLocalTable($t)->filterByLocalPk($p)->count() > 0;
        if (self::shouldEnqueue($map, $table, $pk, $hasLink)) {
            SyncQueue::enqueue(SyncQueue::KIND_PUSH, ['table' => $table, 'pk' => $pk]);
        }
    }

    /**
     * Pure push policy: primary-role records always enqueue; party-role records
     * (customer/vendor) only propagate updates once already linked — prospects
     * never reach the accounting provider.
     */
    public static function shouldEnqueue(array $map, string $table, int $pk, callable $hasLink): bool
    {
        $cfg = SyncMap::tableConfig($map, $table);
        if (!$cfg) {
            return false;
        }
        $roles = array_keys(SyncMap::rolesOf($cfg));
        if (array_intersect($roles, SyncMap::PRIMARY_ROLES)) {
            return true;
        }
        return (bool) $hasLink($table, $pk);
    }

    /** Local delete never deletes remotely — links are flagged for the bookkeeper. */
    public static function recordDeleted(string $table, $model): void
    {
        $map = SyncMap::load();
        if (!$map || !class_exists('\App\AcctLinkQuery') || !is_object($model) || !method_exists($model, 'getPrimaryKey')) {
            return;
        }
        (new PropelLinkStore())->markDeleted($table, (int) $model->getPrimaryKey());
    }
}
