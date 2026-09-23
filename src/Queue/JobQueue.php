<?php

namespace ApiGoat\Queue;

use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;
use ApiGoat\Sync\Exceptions\ValidationRejected;

/**
 * Generic DB-backed job queue over an emitted Propel row (default: the
 * `job_queue` table the with_job_queue behavior emits, columns modelled on
 * acct_sync_job: kind, payload_json, run_after, attempts, state, last_error,
 * claimed_at, plus id_tenant).
 *
 * Lifecycle: Pending → Running (atomic claim, safe under overlapping cron
 * drainers) → Done | Pending (deferred with backoff) | Failed. claimed_at is
 * the LEASE: a long handler keeps it fresh with heartbeat($row); a Running row
 * whose lease is older than STALE_RUNNING_MINUTES is reclaimed on the next
 * drain — which counts an attempt (a job that keeps killing its worker must
 * end Failed, not loop forever) and marks it Failed at maxAttempts.
 *
 * Fencing: attempts doubles as the lease generation (every reclaim bumps it),
 * plus an optional `claimed_by` column (VARCHAR(64)) holding a per-claim
 * token when the emitted table has it. A worker whose lease was reclaimed
 * finds the row no longer matches its (attempts, claimed_by) and does NOT
 * write its outcome over the new owner's. Without claimed_by the attempts
 * fence still works; the column only closes the same-attempts corner.
 *
 * Two drain call styles are served so both existing shapes (Sync\SyncQueue's
 * `drain($limit, $handlers)` and apicrm's `register($kind, $h)` + `drain($limit)`)
 * migrate without a surface change; handlers passed to drain() win over
 * registered ones for the same kind. Handlers receive (array $payload, $row).
 *
 * Model access is duck-typed on the class names returned by the seams below
 * (the emitted model does not exist in this repo), so a subclass binds a
 * different table by overriding modelClass() alone; queryClass()/peerClass()/
 * tableName()/pkColumn() derive from it and stay overridable.
 */
class JobQueue
{
    public const MAX_ATTEMPTS          = 5;
    public const STALE_RUNNING_MINUTES = 10;

    public const STATE_PENDING = 'Pending';
    public const STATE_RUNNING = 'Running';
    public const STATE_DONE    = 'Done';
    public const STATE_FAILED  = 'Failed';

    /** @var array<string, callable> kind => handler */
    private array $handlers = [];

    private int $maxAttempts;

    /** @var array<int, string> pk => claim token of the rows this worker holds */
    private array $claimTokens = [];

    public function __construct(?int $maxAttempts = null)
    {
        $this->maxAttempts = $maxAttempts ?? static::MAX_ATTEMPTS;
    }

    // ---- model seams ---------------------------------------------------

    /** Emitted Propel model (FQCN) the queue persists to. */
    protected static function modelClass(): string
    {
        return '\App\JobQueue';
    }

    protected static function queryClass(): string
    {
        return static::modelClass() . 'Query';
    }

    protected static function peerClass(): string
    {
        return static::modelClass() . 'Peer';
    }

    /** Raw table name for the claim/reclaim SQL — Peer::TABLE_NAME when emitted, else snake_case of the model. */
    protected static function tableName(): string
    {
        $peer = static::peerClass();
        if (defined($peer . '::TABLE_NAME')) {
            return (string) constant($peer . '::TABLE_NAME');
        }
        $short = substr(strrchr('\\' . ltrim(static::modelClass(), '\\'), '\\'), 1);
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }

    /**
     * Does the emitted table carry $column? Read off the generated Peer's
     * column constant (e.g. JobQueuePeer::CLAIMED_BY), so the queue keeps
     * working on tables emitted before a column was added.
     */
    protected static function hasColumn(string $column): bool
    {
        return \defined(static::peerClass() . '::' . \strtoupper($column));
    }

    /** GoatCheese convention: the pk column is `id_<table>`. */
    protected static function pkColumn(): string
    {
        return 'id_' . static::tableName();
    }

    /** Propel phpName of the pk column (`id_job_queue` → `IdJobQueue`), used for the orderBy* finder. */
    protected static function pkPhpName(): string
    {
        return str_replace('_', '', ucwords(static::pkColumn(), '_'));
    }

    /** Payload serialisation; dedup compares this string, so keep it deterministic per class. */
    protected static function encodePayload(array $payload): string
    {
        return (string) json_encode($payload);
    }

    /** Skip inserting when an identical Pending (kind + payload) row already exists. */
    protected static function dedupe(): bool
    {
        return true;
    }

    /** Throttle / transient outage: defer without counting an attempt. */
    protected function isDeferrable(\Throwable $e): bool
    {
        return $e instanceof RateLimited || $e instanceof TransientError;
    }

    /** Retrying identical input cannot succeed: fail on the first attempt. */
    protected function isPermanent(\Throwable $e): bool
    {
        return $e instanceof ValidationRejected;
    }

    /** @return \PDO|object anything with prepare(): {execute(), rowCount()} */
    protected function connection()
    {
        return \Propel::getConnection();
    }

    // ---- public surface ------------------------------------------------

    public static function available(): bool
    {
        return class_exists(static::modelClass());
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
    protected static function stateIndex(string $state): int
    {
        $peer = static::peerClass();
        $set  = $peer::getValueSet(constant($peer . '::STATE'));
        $i    = array_search($state, $set, true);
        if ($i === false) {
            throw new \RuntimeException('Unknown ' . static::tableName() . " state '$state'");
        }
        return (int) $i;
    }

    /**
     * Insert a job unless an identical Pending one exists.
     *
     * @param string|\DateTimeInterface|null $runAfter  'Y-m-d H:i:s' string (or a DateTime; normalised
     *   to a string because Propel 1's PropelDateTime rejects DateTimeImmutable under PHP 8.4)
     * @param ?int $idTenant stamped when the model exposes setIdTenant(), silently ignored otherwise
     * @return ?int pk, null when deduped
     */
    public static function enqueue(string $kind, array $payload = [], $runAfter = null, ?int $idTenant = null): ?int
    {
        $json  = static::encodePayload($payload);
        $model = static::modelClass();
        $query = static::queryClass();
        if ($runAfter instanceof \DateTimeInterface) {
            $runAfter = $runAfter->format('Y-m-d H:i:s');
        }
        if (static::dedupe()) {
            $dup = $query::create()
                ->filterByKind($kind)->filterByState(static::STATE_PENDING)->filterByPayloadJson($json)->count();
            if ($dup > 0) {
                return null;
            }
        }
        $job = new $model();
        $job->setKind($kind);
        $job->setPayloadJson($json);
        $job->setState(static::STATE_PENDING);
        $job->setAttempts(0);
        $job->setRunAfter($runAfter ?? date('Y-m-d H:i:s'));
        if ($idTenant !== null && method_exists($job, 'setIdTenant')) {
            $job->setIdTenant($idTenant);
        }
        $job->save();
        return (int) $job->getPrimaryKey();
    }

    /**
     * Register a handler for a kind (apicrm call style); drain() uses these
     * when no handler map is passed, or as the fallback for kinds the map lacks.
     *
     * @param callable(array $payload, object $row): void $handler
     * @return static
     */
    public function register(string $kind, callable $handler): self
    {
        $this->handlers[$kind] = $handler;
        return $this;
    }

    /** @return array<string, callable> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    /**
     * Pull up to $limit due Pending rows, claim each, run its handler and
     * transition to Done / Pending (backoff) / Failed.
     *
     * @param ?array<string, callable(array):void> $handlers kind => handler; merged over register()ed ones
     * @return array{processed: int, ok: int, failed: int, deferred: int}
     */
    public function drain(int $limit = 25, ?array $handlers = null): array
    {
        $handlers = ($handlers ?? []) + $this->handlers;
        $query    = static::queryClass();
        $stats    = ['processed' => 0, 'ok' => 0, 'failed' => 0, 'deferred' => 0];
        $this->reclaimStale();
        $rows = $query::create()
            ->filterByState(static::STATE_PENDING)
            ->filterByRunAfter(date('Y-m-d H:i:s'), \Criteria::LESS_EQUAL)
            ->{'orderBy' . static::pkPhpName()}()
            ->limit($limit)
            ->find();
        foreach ($rows as $job) {
            if (!$this->claim((int) $job->getPrimaryKey())) {
                continue; // another drainer won the race
            }
            $leaseAttempts = (int) $job->getAttempts();
            // Keep the in-memory object consistent with the row the claim just wrote,
            // else a later setState('Pending') on defer is a no-op modification.
            $job->setState(static::STATE_RUNNING);
            $stats['processed']++;
            try {
                $handler = $handlers[$job->getKind()] ?? null;
                if (!$handler) {
                    throw new \RuntimeException('No handler for ' . $job->getKind());
                }
                $handler(json_decode((string) $job->getPayloadJson(), true) ?: [], $job);
                if (!$this->stillOwns($job, $leaseAttempts)) {
                    continue; // lease reclaimed meanwhile: the new owner decides the outcome
                }
                $job->setState(static::STATE_DONE);
                $job->setLastError(null);
                $job->save();
                $stats['ok']++;
            } catch (\Throwable $e) {
                if (!$this->stillOwns($job, $leaseAttempts)) {
                    continue; // lease reclaimed meanwhile: the new owner decides the outcome
                }
                if ($this->isDeferrable($e)) {
                    // Throttle / transient outage: always defer, NEVER count toward
                    // maxAttempts (attempts stays put — a 429 isn't the job's fault).
                    $attempts = (int) $job->getAttempts();
                    $job->setState(static::STATE_PENDING);
                    $job->setLastError(mb_substr($e->getMessage(), 0, 2000));
                    $job->setRunAfter(date('Y-m-d H:i:s', time() + static::backoffSeconds(max(1, $attempts))));
                    $job->save();
                    $stats['deferred']++;
                    continue;
                }
                $attempt = (int) $job->getAttempts() + 1;
                $job->setAttempts($attempt);
                $job->setLastError(mb_substr($e->getMessage(), 0, 2000));
                if ($attempt >= $this->maxAttempts || $this->isPermanent($e)) {
                    $job->setState(static::STATE_FAILED);   // retrying identical input cannot succeed
                    $stats['failed']++;
                } else {
                    $job->setState(static::STATE_PENDING);
                    $job->setRunAfter(date('Y-m-d H:i:s', time() + static::backoffSeconds($attempt)));
                    $stats['deferred']++;
                }
                $job->save();
            } finally {
                unset($this->claimTokens[(int) $job->getPrimaryKey()]);
                // Commit point between jobs: long-running drainers never reach
                // a request shutdown, so flush queued cache-generation bumps.
                if (\class_exists(\ApiGoat\Utility\TableVersion::class, false)) {
                    \ApiGoat\Utility\TableVersion::flushPending();
                }
            }
        }
        return $stats;
    }

    // ---- claim / reclaim ----------------------------------------------

    /** Atomic Pending→Running; false when another worker claimed it first. */
    protected function claim(int $pk): bool
    {
        // state is TINYINT (valueSet index) — bind the translated indexes, not labels.
        $token = \bin2hex(\random_bytes(8));
        if (static::hasColumn('claimed_by')) {
            $st = $this->connection()->prepare(
                'UPDATE ' . static::tableName() . ' SET state = ?, claimed_at = NOW(), claimed_by = ? WHERE ' . static::pkColumn() . ' = ? AND state = ?'
            );
            $st->execute([static::stateIndex(static::STATE_RUNNING), $token, $pk, static::stateIndex(static::STATE_PENDING)]);
        } else {
            $st = $this->connection()->prepare(
                'UPDATE ' . static::tableName() . ' SET state = ?, claimed_at = NOW() WHERE ' . static::pkColumn() . ' = ? AND state = ?'
            );
            $st->execute([static::stateIndex(static::STATE_RUNNING), $pk, static::stateIndex(static::STATE_PENDING)]);
        }
        if ($st->rowCount() !== 1) {
            return false;
        }
        $this->claimTokens[$pk] = $token;
        return true;
    }

    /**
     * Renew the lease of a job this worker is running. A handler that can run
     * longer than STALE_RUNNING_MINUTES calls this periodically (the handler
     * receives the row: `$queue->heartbeat($row)`). Returns false when the
     * lease is already lost (reclaimed by another drainer) — the handler
     * should stop; its outcome will not be written either way.
     */
    public function heartbeat(object $job): bool
    {
        $pk = (int) $job->getPrimaryKey();
        [$fence, $params] = $this->leaseFence($pk, (int) $job->getAttempts());
        $st = $this->connection()->prepare(
            'UPDATE ' . static::tableName() . ' SET claimed_at = NOW() WHERE ' . $fence
        );
        $st->execute($params);
        // rowCount can be 0 for a same-second renewal (MySQL counts CHANGED
        // rows), so ownership is re-read rather than inferred from it.
        return $this->stillOwns($job, (int) $job->getAttempts());
    }

    /**
     * Is the row still Running under this worker's lease? $attempts is the
     * attempts value at claim time (a reclaim bumps it). On any read failure
     * the answer is true: the pre-lease behaviour (write the outcome) is the
     * safer degradation than silently dropping a finished job's result.
     */
    protected function stillOwns(object $job, int $attempts): bool
    {
        $pk = (int) $job->getPrimaryKey();
        try {
            [$fence, $params] = $this->leaseFence($pk, $attempts);
            $st = $this->connection()->prepare(
                'SELECT COUNT(*) FROM ' . static::tableName() . ' WHERE ' . $fence
            );
            $st->execute($params);
            $n = $st->fetchColumn();
        } catch (\Throwable $e) {
            return true;
        }
        if ($n === false || $n === null) {
            return true;
        }
        if ((int) $n === 1) {
            return true;
        }
        \error_log(\sprintf('[gc-queue] %s #%d: lease lost (reclaimed while running) — outcome not written', static::tableName(), $pk));
        return false;
    }

    /** @return array{0: string, 1: array} WHERE clause + params matching this worker's lease on $pk */
    private function leaseFence(int $pk, int $attempts): array
    {
        $sql    = static::pkColumn() . ' = ? AND state = ? AND attempts = ?';
        $params = [$pk, static::stateIndex(static::STATE_RUNNING), $attempts];
        if (isset($this->claimTokens[$pk]) && static::hasColumn('claimed_by')) {
            $sql     .= ' AND claimed_by = ?';
            $params[] = $this->claimTokens[$pk];
        }
        return [$sql, $params];
    }

    /**
     * Reclaim Running rows whose lease (claimed_at, renewed by heartbeat)
     * expired: the worker died or hung. Each reclaim COUNTS an attempt, so a
     * job that keeps killing its worker ends Failed at maxAttempts instead of
     * being re-run forever; the bumped attempts also fences the old worker.
     * MySQL evaluates single-table SET assignments left to right, so state
     * and last_error read the pre-increment attempts.
     */
    protected function reclaimStale(): void
    {
        $st = $this->connection()->prepare(
            'UPDATE ' . static::tableName()
            . ' SET state = IF(attempts + 1 >= ?, ?, ?),'
            . ' last_error = ?,'
            . ' attempts = attempts + 1'
            . ' WHERE state = ? AND claimed_at < (NOW() - INTERVAL ' . static::STALE_RUNNING_MINUTES . ' MINUTE)'
        );
        $st->execute([
            $this->maxAttempts,
            static::stateIndex(static::STATE_FAILED),
            static::stateIndex(static::STATE_PENDING),
            'Reclaimed: lease expired after ' . static::STALE_RUNNING_MINUTES . ' minutes without a heartbeat',
            static::stateIndex(static::STATE_RUNNING),
        ]);
    }
}
