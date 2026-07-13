<?php

namespace ApiGoat\Sync;

/**
 * Queue accessor over the emitted acct_sync_job table (with_accounting_sync).
 * Same job-lifecycle semantics as apicrm's App\Domains\Queue\JobQueue, plus an
 * atomic Pending→Running claim (safe under overlapping cron drainers) and a
 * stale-Running reclaim.
 */
final class SyncQueue
{
    public const KIND_PUSH          = 'sync.push';
    public const KIND_PULL_PAYMENTS = 'sync.pull_payments';
    public const KIND_BACKFILL      = 'sync.backfill';
    public const MAX_ATTEMPTS       = 5;
    public const STALE_RUNNING_MINUTES = 10;

    public static function available(): bool
    {
        return class_exists('\App\AcctSyncJob');
    }

    /** 30s, 2m, 4m30, 8m, ... capped at 2h. */
    public static function backoffSeconds(int $attempt): int
    {
        return min(7200, 30 * $attempt * $attempt);
    }

    /**
     * GoatCheese emits ENUM columns as TINYINT storing the valueSet INDEX, so raw
     * SQL must compare the integer index, never the string literal. Translate a
     * state label through the generated Peer's valueSet.
     */
    private static function stateIndex(string $state): int
    {
        $set = \App\AcctSyncJobPeer::getValueSet(\App\AcctSyncJobPeer::STATE);
        $i = array_search($state, $set, true);
        if ($i === false) {
            throw new \RuntimeException("Unknown acct_sync_job state '$state'");
        }
        return (int) $i;
    }

    /** Insert a job unless an identical Pending one exists. @return ?int pk, null when deduped */
    public static function enqueue(string $kind, array $payload = [], ?string $runAfter = null): ?int
    {
        $json = (string) json_encode($payload);
        $dup  = \App\AcctSyncJobQuery::create()
            ->filterByKind($kind)->filterByState('Pending')->filterByPayloadJson($json)->count();
        if ($dup > 0) {
            return null;
        }
        $job = new \App\AcctSyncJob();
        $job->setKind($kind);
        $job->setPayloadJson($json);
        $job->setState('Pending');
        $job->setAttempts(0);
        $job->setRunAfter($runAfter ?? date('Y-m-d H:i:s'));
        $job->save();
        return (int) $job->getPrimaryKey();
    }

    /** @param array<string, callable(array):void> $handlers kind => handler */
    public function drain(int $limit, array $handlers): array
    {
        $stats = ['processed' => 0, 'ok' => 0, 'failed' => 0, 'deferred' => 0];
        $this->reclaimStale();
        $rows = \App\AcctSyncJobQuery::create()
            ->filterByState('Pending')
            ->filterByRunAfter(date('Y-m-d H:i:s'), \Criteria::LESS_EQUAL)
            ->orderByIdAcctSyncJob()
            ->limit($limit)
            ->find();
        foreach ($rows as $job) {
            if (!$this->claim((int) $job->getPrimaryKey())) {
                continue; // another drainer won the race
            }
            // Keep the in-memory object consistent with the row the claim just wrote,
            // else a later setState('Pending') on defer is a no-op modification.
            $job->setState('Running');
            $stats['processed']++;
            try {
                $handler = $handlers[$job->getKind()] ?? null;
                if (!$handler) {
                    throw new \RuntimeException('No handler for ' . $job->getKind());
                }
                $handler(json_decode((string) $job->getPayloadJson(), true) ?: []);
                $job->setState('Done');
                $job->setLastError(null);
                $job->save();
                $stats['ok']++;
            } catch (Exceptions\RateLimited | Exceptions\TransientError $e) {
                // Throttle / transient outage: always defer, NEVER count toward
                // MAX_ATTEMPTS (attempts stays put — a 429 isn't the job's fault).
                $attempts = (int) $job->getAttempts();
                $job->setState('Pending');
                $job->setLastError(mb_substr($e->getMessage(), 0, 2000));
                $job->setRunAfter(date('Y-m-d H:i:s', time() + self::backoffSeconds(max(1, $attempts))));
                $job->save();
                $stats['deferred']++;
            } catch (\Throwable $e) {
                $attempt = (int) $job->getAttempts() + 1;
                $job->setAttempts($attempt);
                $job->setLastError(mb_substr($e->getMessage(), 0, 2000));
                if ($attempt >= self::MAX_ATTEMPTS || $e instanceof Exceptions\ValidationRejected) {
                    $job->setState('Failed');   // retrying identical input cannot succeed
                    $stats['failed']++;
                } else {
                    $job->setState('Pending');
                    $job->setRunAfter(date('Y-m-d H:i:s', time() + self::backoffSeconds($attempt)));
                    $stats['deferred']++;
                }
                $job->save();
            }
        }
        return $stats;
    }

    /** Atomic Pending→Running; false when another worker claimed it first. */
    private function claim(int $pk): bool
    {
        // state is TINYINT (valueSet index) — bind the translated indexes, not labels.
        $st = \Propel::getConnection()->prepare(
            "UPDATE acct_sync_job SET state = ?, claimed_at = NOW() WHERE id_acct_sync_job = ? AND state = ?"
        );
        $st->execute([self::stateIndex('Running'), $pk, self::stateIndex('Pending')]);
        return $st->rowCount() === 1;
    }

    private function reclaimStale(): void
    {
        $st = \Propel::getConnection()->prepare(
            "UPDATE acct_sync_job SET state = ? WHERE state = ? AND claimed_at < (NOW() - INTERVAL " . self::STALE_RUNNING_MINUTES . " MINUTE)"
        );
        $st->execute([self::stateIndex('Pending'), self::stateIndex('Running')]);
    }
}
