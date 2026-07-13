<?php

namespace ApiGoat\Sync;

/**
 * One-time initial sync: enqueue a push job for every row of every
 * primary-role table (invoices, expenses, payments). Party records
 * (clients/suppliers) follow automatically as dependencies, so only clients
 * with invoices and suppliers with expenses ever reach the provider.
 * Idempotent: enqueue dedupes, and pushes hash-skip already-synced rows.
 */
final class Backfill
{
    public static function enqueueAll(array $map): int
    {
        $n = 0;
        foreach (SyncMap::PRIMARY_ROLES as $role) {
            foreach (SyncMap::tablesByRole($map, $role) as $table => $cfg) {
                $queryClass = '\\App\\' . SyncRuntime::phpName($table) . 'Query';
                if (!class_exists($queryClass)) {
                    continue;
                }
                foreach ($queryClass::create()->find() as $row) {
                    if (SyncQueue::enqueue(SyncQueue::KIND_PUSH, ['table' => $table, 'pk' => (int) $row->getPrimaryKey()]) !== null) {
                        $n++;
                    }
                }
            }
        }
        return $n;
    }
}
