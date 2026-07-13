<?php

namespace ApiGoat\Sync;

use ApiGoat\Sync\QuickBooks\QboProvider;

/**
 * Everything a project cron needs, in one line inside config/cron.php
 * (before $scheduler->run()):
 *
 *     \ApiGoat\Sync\CronBindings::register($scheduler);
 *
 * No-ops (jobs stay Pending) when the behavior isn't built, the map is empty
 * or QuickBooks isn't connected yet.
 */
final class CronBindings
{
    public static function register(\GO\Scheduler $scheduler): void
    {
        $map = SyncMap::load();
        if (!$map || !SyncQueue::available()) {
            return;
        }

        $scheduler->call(function () use ($map) {
            $engine = SyncRuntime::engine();
            if (!$engine) {
                return 'sync: not connected — jobs pending';
            }
            $handlers = [
                SyncQueue::KIND_PUSH => function (array $p) use ($engine): void {
                    $engine->pushRecord((string) ($p['table'] ?? ''), (int) ($p['pk'] ?? 0));
                },
                SyncQueue::KIND_BACKFILL => function () use ($map): void {
                    Backfill::enqueueAll($map);
                },
                SyncQueue::KIND_PULL_PAYMENTS => function () use ($map): void {
                    $provider = QboProvider::fromProject();
                    $conn     = ConnectionStore::find();
                    if (!$provider || !$conn) {
                        throw new Exceptions\AuthFailed('QuickBooks not connected');
                    }
                    $puller = new PaymentPuller(
                        $provider, new PropelLinkStore(), $map,
                        SyncRuntime::loadRecord(), SyncRuntime::insertRow(), SyncRuntime::updateRow()
                    );
                    $state = ConnectionStore::getState($conn);
                    $res   = $puller->pull($state['payment_cursor'] ?? null);
                    $state['payment_cursor'] = $res['cursor'];
                    ConnectionStore::setState($conn, $state);
                },
            ];
            $stats = (new SyncQueue())->drain(25, $handlers);
            return $stats['processed'] > 0 ? 'sync: ' . json_encode($stats) : true;
        }, [], 'accountingSyncDrain')->everyMinute();

        // Enqueue (deduped) a payment pull every 15 minutes; the drain executes it.
        $scheduler->call(function () {
            if (ConnectionStore::available() && ConnectionStore::find()) {
                SyncQueue::enqueue(SyncQueue::KIND_PULL_PAYMENTS);
            }
            return true;
        }, [], 'accountingSyncPullPayments')->everyMinute(15);
    }
}
