<?php
// tests/Queue/JobQueueLeaseTest.php — review-3 #20: reclaim counts an attempt,
// Failed at maxAttempts, and a worker whose lease was reclaimed never writes
// its outcome over the new owner's (attempts fence + optional claimed_by).
// No DB: a small SQL interpreter over the FakeStore rows.

namespace ApiGoat\Tests\Queue;

require_once __DIR__ . '/../../src/Sync/Exceptions/RateLimited.php';
require_once __DIR__ . '/../../src/Sync/Exceptions/TransientError.php';
require_once __DIR__ . '/../../src/Sync/Exceptions/ValidationRejected.php';
require_once __DIR__ . '/../../src/Queue/JobQueue.php';
require_once __DIR__ . '/../../src/Sync/SyncQueue.php';
require_once __DIR__ . '/Fakes.php';

use PHPUnit\Framework\TestCase;

/** Interprets the lease SQL JobQueue issues against the FakeStore rows of one model. */
final class LeaseConnection
{
    public static ?LeaseConnection $current = null;

    /** @var array<int, array{sql: string, params: array}> */
    public array $log = [];
    /** @var int[] pks whose lease counts as expired on the next reclaim */
    public array $stale = [];

    public function __construct(public string $modelClass) {}

    public function prepare(string $sql): LeaseStatement { return new LeaseStatement($this, $sql); }

    /** @return object[] */
    public function rows(): array { return FakeStore::$rows[ltrim($this->modelClass, '\\')] ?? []; }

    public function states(): array { return ['Pending', 'Running', 'Done', 'Failed']; }
}

final class LeaseStatement
{
    private int $rowCount = 0;
    private mixed $column = false;

    public function __construct(private LeaseConnection $c, private string $sql) {}

    public function execute(array $p): bool
    {
        $this->c->log[] = ['sql' => $this->sql, 'params' => $p];
        $st = $this->c->states();
        $rows = $this->c->rows();

        if (preg_match('/^UPDATE \w+ SET state = \?, claimed_at = NOW\(\)(, claimed_by = \?)? WHERE \w+ = \? AND state = \?( AND attempts = \?)?( AND run_after <= \?)?$/', $this->sql, $m)) {
            $withBy = !empty($m[1]);
            if (!$withBy) {
                array_splice($p, 1, 0, [null]);
            }
            [$to, $by, $pk, $from] = $p;
            $attempts = !empty($m[2]) ? (int) $p[4] : null;
            $row = $rows[(int) $pk] ?? null;
            if ($row && $row->getState() === $st[$from]
                && ($attempts === null || $row->data['attempts'] === $attempts)) {
                $row->setState($st[$to]);
                $row->data['claimed_by'] = $by;
                $this->rowCount = 1;
            }
            return true;
        }
        if (preg_match('/^UPDATE \w+ SET state = IF\(attempts \+ 1 >= \?, \?, \?\), last_error = \?, attempts = attempts \+ 1 WHERE state = \? AND claimed_at < /', $this->sql)) {
            [$max, $failed, $pending, $err, $running] = $p;
            foreach ($rows as $pk => $row) {
                if ($row->getState() === $st[$running] && in_array($pk, $this->c->stale, true)) {
                    $a = $row->data['attempts'];
                    $row->data['state'] = ($a + 1 >= $max) ? $st[$failed] : $st[$pending];
                    $row->data['last_error'] = $err;
                    $row->data['attempts'] = $a + 1;
                    $this->rowCount++;
                }
            }
            return true;
        }
        if (preg_match('/^(UPDATE \w+ SET claimed_at = NOW\(\)|SELECT COUNT\(\*\) FROM \w+) WHERE \w+ = \? AND state = \? AND attempts = \?( AND claimed_by = \?)?$/', $this->sql, $m)) {
            [$pk, $state, $attempts] = $p;
            $row = $rows[(int) $pk] ?? null;
            $hit = $row && $row->getState() === $st[$state] && $row->data['attempts'] === (int) $attempts
                && (empty($m[2]) || ($row->data['claimed_by'] ?? null) === $p[3]);
            $this->column = $hit ? 1 : 0;
            $this->rowCount = $hit ? 1 : 0;
            return true;
        }
        throw new \RuntimeException('unexpected SQL: ' . $this->sql);
    }

    public function rowCount(): int { return $this->rowCount; }
    public function fetchColumn(): mixed { return $this->column; }
}

final class LeaseJobQueue extends \ApiGoat\Queue\JobQueue
{
    protected function connection() { return LeaseConnection::$current; }
}

final class LeaseByQueue extends \ApiGoat\Queue\JobQueue
{
    protected static function modelClass(): string { return '\App\LeaseJob'; }
    protected function connection() { return LeaseConnection::$current; }
}

final class JobQueueLeaseTest extends TestCase
{
    protected function setUp(): void
    {
        FakeStore::reset();
    }

    private function use(string $model): LeaseConnection
    {
        return LeaseConnection::$current = new LeaseConnection($model);
    }

    public function testReclaimCountsAnAttemptAndRequeues(): void
    {
        $c = $this->use(\App\JobQueue::class);
        LeaseJobQueue::enqueue('k', ['n' => 1]);
        $row = FakeStore::$rows[\App\JobQueue::class][1];
        $row->setState('Running');
        $c->stale = [1];

        // The reclaimed job is re-run by this drain: the handler sees attempts 1.
        $seen = null;
        (new LeaseJobQueue())->drain(10, ['k' => function ($p, $r) use (&$seen) { $seen = $r->getAttempts(); }]);

        self::assertSame(1, $seen, 'the reclaim counted an attempt');
        self::assertSame('Done', $row->getState());
    }

    public function testReclaimAtMaxAttemptsFailsTheJob(): void
    {
        $c = $this->use(\App\JobQueue::class);
        LeaseJobQueue::enqueue('k', []);
        $row = FakeStore::$rows[\App\JobQueue::class][1];
        $row->setState('Running');
        $row->setAttempts(4); // MAX_ATTEMPTS = 5
        $c->stale = [1];

        $ran = false;
        $stats = (new LeaseJobQueue())->drain(10, ['k' => function () use (&$ran) { $ran = true; }]);

        self::assertFalse($ran, 'a job that exhausted its attempts by dying is not re-run');
        self::assertSame('Failed', $row->getState());
        self::assertSame(5, $row->getAttempts());
        self::assertStringContainsString('Reclaimed', (string) $row->getLastError());
        self::assertSame(0, $stats['processed']);
    }

    public function testWorkerWhoseLeaseWasReclaimedDoesNotWriteItsOutcome(): void
    {
        $c = $this->use(\App\JobQueue::class);
        LeaseJobQueue::enqueue('k', []);
        $row = FakeStore::$rows[\App\JobQueue::class][1];

        $stats = (new LeaseJobQueue())->drain(10, ['k' => function ($p, $r) {
            // Simulate a second drainer reclaiming the lease mid-run (the
            // reclaim bumps attempts and puts it back to Pending).
            $r->data['attempts'] = 1;
            $r->data['state'] = 'Pending';
            // ...and re-claiming it.
            $r->data['state'] = 'Running';
        }]);

        self::assertSame('Running', $row->getState(), 'the stale worker must not mark it Done');
        self::assertSame(0, $stats['ok']);
        self::assertSame(1, $stats['processed']);
    }

    public function testFailureOfAReclaimedJobIsNotRecordedByTheStaleWorker(): void
    {
        $c = $this->use(\App\JobQueue::class);
        LeaseJobQueue::enqueue('k', []);
        $row = FakeStore::$rows[\App\JobQueue::class][1];

        (new LeaseJobQueue())->drain(10, ['k' => function ($p, $r) {
            $r->data['attempts'] = 1; // reclaimed + re-claimed elsewhere
            throw new \RuntimeException('boom');
        }]);

        self::assertSame('Running', $row->getState());
        self::assertSame(1, $row->getAttempts(), 'no extra attempt from the stale worker');
        self::assertNull($row->getLastError());
    }

    public function testHeartbeatRenewsTheLeaseAndReportsOwnership(): void
    {
        $c = $this->use(\App\JobQueue::class);
        LeaseJobQueue::enqueue('k', []);
        $queue = new LeaseJobQueue();
        $beats = [];
        $queue->drain(10, ['k' => function ($p, $r) use ($queue, &$beats) {
            $beats[] = $queue->heartbeat($r);
            $r->data['attempts'] = 9; // lost
            $beats[] = $queue->heartbeat($r->data['attempts'] === 9 ? new class($r) {
                public function __construct(private object $r) {}
                public function getPrimaryKey() { return $this->r->getPrimaryKey(); }
                public function getAttempts() { return 0; }
            } : $r);
        }]);
        self::assertSame([true, false], $beats);
        $renewals = array_filter($c->log, fn ($e) => str_starts_with($e['sql'], 'UPDATE job_queue SET claimed_at = NOW()'));
        self::assertCount(2, $renewals);
    }

    public function testClaimedByTokenFencesWhenTheColumnExists(): void
    {
        $c = $this->use(\App\LeaseJob::class);
        LeaseByQueue::enqueue('k', []);
        $row = FakeStore::$rows[\App\LeaseJob::class][1];

        $stats = (new LeaseByQueue())->drain(10, ['k' => function ($p, $r) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $r->data['claimed_by']);
            // Another worker re-claimed at the SAME attempts count (e.g. an
            // admin reset): only the claim token tells the leases apart.
            $r->data['claimed_by'] = 'someone-else';
        }]);

        self::assertSame('Running', $row->getState());
        self::assertSame(0, $stats['ok']);
        $claims = array_values(array_filter($c->log, fn ($e) => str_contains($e['sql'], 'SET state = ?, claimed_at')));
        self::assertStringContainsString('claimed_by = ?', $claims[0]['sql']);
    }

    public function testNoClaimedByColumnKeepsTheLegacyClaimSql(): void
    {
        $c = $this->use(\App\JobQueue::class);
        LeaseJobQueue::enqueue('k', []);
        (new LeaseJobQueue())->drain(10, ['k' => fn () => null]);
        $claims = array_values(array_filter($c->log, fn ($e) => str_contains($e['sql'], 'SET state = ?, claimed_at')));
        self::assertStringNotContainsString('claimed_by', $claims[0]['sql']);
        self::assertSame('Done', FakeStore::$rows[\App\JobQueue::class][1]->getState());
    }
}

namespace App;

use ApiGoat\Tests\Queue\FakePeer;
use ApiGoat\Tests\Queue\FakeQuery;
use ApiGoat\Tests\Queue\FakeRow;

// A queue table emitted WITH the optional claimed_by lease column.
final class LeaseJob extends FakeRow {}
final class LeaseJobQuery extends FakeQuery
{
    protected function modelClass(): string { return LeaseJob::class; }
    public function orderByIdLeaseJob(): static { return $this; }
}
final class LeaseJobPeer extends FakePeer
{
    const TABLE_NAME = 'lease_job';
    const PK = 'id_lease_job';
    const CLAIMED_BY = 'lease_job.claimed_by';
}
