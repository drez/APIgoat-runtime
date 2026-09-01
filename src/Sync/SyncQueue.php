<?php

namespace ApiGoat\Sync;

use ApiGoat\Queue\JobQueue;

/**
 * Queue accessor over the emitted acct_sync_job table (with_accounting_sync).
 * A thin binding of the generic ApiGoat\Queue\JobQueue: same lifecycle
 * (atomic Pending→Running claim, stale-Running reclaim, backoff, attempt cap)
 * against the accounting-sync model instead of job_queue.
 */
class SyncQueue extends JobQueue
{
    public const KIND_PUSH          = 'sync.push';
    public const KIND_PULL_PAYMENTS = 'sync.pull_payments';
    public const KIND_BACKFILL      = 'sync.backfill';

    protected static function modelClass(): string
    {
        return '\App\AcctSyncJob';
    }

    /** The emitted Peer carries TABLE_NAME, but pin it so the raw SQL never depends on class loading. */
    protected static function tableName(): string
    {
        return 'acct_sync_job';
    }
}
