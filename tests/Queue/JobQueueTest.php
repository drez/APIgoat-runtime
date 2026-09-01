<?php
// tests/Queue/JobQueueTest.php — Run (from the runtime repo root, any project's phpunit works):
//   /var/www/gc/p/test/.admin/vendor/bin/phpunit -c phpunit.xml tests/Queue/JobQueueTest.php
// The files under test are required explicitly so a project autoloader mapping
// ApiGoat\ to ITS OWN runtime clone can never shadow this checkout.

namespace ApiGoat\Tests\Queue;

require_once __DIR__ . '/../../src/Sync/Exceptions/RateLimited.php';
require_once __DIR__ . '/../../src/Sync/Exceptions/TransientError.php';
require_once __DIR__ . '/../../src/Sync/Exceptions/ValidationRejected.php';
require_once __DIR__ . '/../../src/Queue/JobQueue.php';
require_once __DIR__ . '/../../src/Sync/SyncQueue.php';
require_once __DIR__ . '/Fakes.php';

use ApiGoat\Queue\JobQueue;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;
use ApiGoat\Sync\Exceptions\ValidationRejected;
use ApiGoat\Sync\SyncQueue;
use PHPUnit\Framework\TestCase;

/** Binds a model whose Peer does not exist — exercises the snake_case tableName() fallback. */
final class OrphanQueue extends JobQueue
{
    protected static function modelClass(): string { return '\App\MailOutboundJob'; }
    public static function table(): string { return static::tableName(); }
    public static function pk(): string { return static::pkColumn(); }
    public static function pkPhp(): string { return static::pkPhpName(); }
}

/** Reads the protected seams of any binding without a DB. */
function seam(string $class, string $method): string
{
    return (string) (new \ReflectionMethod($class, $method))->invoke(null);
}

final class JobQueueTest extends TestCase
{
    private FakeConnection $conn;

    protected function setUp(): void
    {
        FakeStore::reset();
        $this->conn = new FakeConnection();
        FakeConnection::$current = $this->conn;
    }

    private function row(string $class, int $pk): object
    {
        return FakeStore::$rows[$class][$pk] ?? self::fail("no $class row $pk");
    }

    // ---- enqueue -------------------------------------------------------

    public function testEnqueueStampsTenantWhenModelSupportsIt(): void
    {
        $pk = JobQueue::enqueue('mail.send', ['to' => 'a@b'], null, 7);
        self::assertSame(1, $pk);
        $row = $this->row(\App\JobQueue::class, 1);
        self::assertSame(7, $row->getIdTenant());
        self::assertSame('mail.send', $row->getKind());
        self::assertSame('{"to":"a@b"}', $row->getPayloadJson());
        self::assertSame('Pending', $row->getState());
        self::assertSame(0, $row->getAttempts());
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row->getRunAfter());
    }

    public function testEnqueueLeavesTenantUnsetWhenOmitted(): void
    {
        JobQueue::enqueue('mail.send', []);
        self::assertNull($this->row(\App\JobQueue::class, 1)->getIdTenant());
        self::assertArrayNotHasKey('id_tenant', $this->row(\App\JobQueue::class, 1)->data);
    }

    public function testEnqueueIgnoresTenantWhenModelHasNoSetter(): void
    {
        self::assertFalse(method_exists(\App\AcctSyncJob::class, 'setIdTenant'));
        $pk = SyncQueue::enqueue(SyncQueue::KIND_PUSH, ['table' => 'invoice', 'pk' => 3], null, 7);
        self::assertSame(1, $pk);
        $row = $this->row(\App\AcctSyncJob::class, 1);
        self::assertArrayNotHasKey('id_tenant', $row->data);
        self::assertSame([], FakeStore::rows(\App\JobQueue::class), 'SyncQueue must not touch job_queue');
    }

    public function testEnqueueDedupesIdenticalPendingJob(): void
    {
        self::assertSame(1, JobQueue::enqueue('k', ['a' => 1]));
        self::assertNull(JobQueue::enqueue('k', ['a' => 1]));
        self::assertSame(2, JobQueue::enqueue('k', ['a' => 2]), 'different payload is a new job');
        self::assertSame(3, JobQueue::enqueue('k2', ['a' => 1]), 'different kind is a new job');
    }

    public function testEnqueueAcceptsStringOrDateTimeRunAfter(): void
    {
        JobQueue::enqueue('k', ['s' => 1], '2030-01-02 03:04:05');
        JobQueue::enqueue('k', ['d' => 1], new \DateTimeImmutable('2031-05-06 07:08:09'));
        self::assertSame('2030-01-02 03:04:05', $this->row(\App\JobQueue::class, 1)->getRunAfter());
        self::assertSame('2031-05-06 07:08:09', $this->row(\App\JobQueue::class, 2)->getRunAfter());
    }

    // ---- drain: both call styles --------------------------------------

    public function testDrainWithHandlerMapArgument(): void
    {
        JobQueue::enqueue('a', ['n' => 1]);
        JobQueue::enqueue('b', ['n' => 2]);
        $seen = [];
        $stats = (new TestJobQueue())->drain(10, [
            'a' => function (array $p) use (&$seen) { $seen[] = ['a', $p]; },
            'b' => function (array $p, $row) use (&$seen) { $seen[] = ['b', $p, $row->getPrimaryKey()]; },
        ]);
        self::assertSame(['processed' => 2, 'ok' => 2, 'failed' => 0, 'deferred' => 0], $stats);
        self::assertSame([['a', ['n' => 1]], ['b', ['n' => 2], 2]], $seen);
        self::assertSame('Done', $this->row(\App\JobQueue::class, 1)->getState());
        self::assertSame('Done', $this->row(\App\JobQueue::class, 2)->getState());
        self::assertNull($this->row(\App\JobQueue::class, 1)->getLastError());
    }

    public function testDrainWithRegisteredHandlers(): void
    {
        JobQueue::enqueue('a', ['n' => 1]);
        JobQueue::enqueue('b', ['n' => 2]);
        $seen  = [];
        $queue = new TestJobQueue();
        self::assertSame($queue, $queue->register('a', function (array $p, $row) use (&$seen) { $seen[] = 'a' . $row->getPrimaryKey(); }));
        $queue->register('b', function (array $p) use (&$seen) { $seen[] = 'b'; });
        self::assertSame(['a', 'b'], array_keys($queue->handlers()));
        $stats = $queue->drain();
        self::assertSame(['processed' => 2, 'ok' => 2, 'failed' => 0, 'deferred' => 0], $stats);
        self::assertSame(['a1', 'b'], $seen);
    }

    public function testExplicitHandlerMapWinsOverRegisteredForSameKind(): void
    {
        JobQueue::enqueue('a', []);
        JobQueue::enqueue('b', []);
        $seen  = [];
        $queue = (new TestJobQueue())
            ->register('a', function () use (&$seen) { $seen[] = 'registered-a'; })
            ->register('b', function () use (&$seen) { $seen[] = 'registered-b'; });
        $queue->drain(10, ['a' => function () use (&$seen) { $seen[] = 'explicit-a'; }]);
        self::assertSame(['explicit-a', 'registered-b'], $seen);
    }

    public function testDrainHonoursLimitAndRunAfter(): void
    {
        JobQueue::enqueue('a', ['n' => 1]);
        JobQueue::enqueue('a', ['n' => 2]);
        JobQueue::enqueue('a', ['n' => 3], '2999-01-01 00:00:00');
        $stats = (new TestJobQueue())->drain(1, ['a' => fn () => null]);
        self::assertSame(1, $stats['processed']);
        self::assertSame('Done', $this->row(\App\JobQueue::class, 1)->getState());
        self::assertSame('Pending', $this->row(\App\JobQueue::class, 2)->getState());
        $stats = (new TestJobQueue())->drain(10, ['a' => fn () => null]);
        self::assertSame(1, $stats['processed'], 'the future job stays untouched');
        self::assertSame('Pending', $this->row(\App\JobQueue::class, 3)->getState());
    }

    // ---- drain: failure paths ------------------------------------------

    public function testMissingHandlerCountsAnAttemptAndDefers(): void
    {
        JobQueue::enqueue('nope', []);
        $before = time();
        $stats  = (new TestJobQueue())->drain(10, []);
        self::assertSame(['processed' => 1, 'ok' => 0, 'failed' => 0, 'deferred' => 1], $stats);
        $row = $this->row(\App\JobQueue::class, 1);
        self::assertSame('Pending', $row->getState());
        self::assertSame(1, $row->getAttempts());
        self::assertSame('No handler for nope', $row->getLastError());
        self::assertGreaterThanOrEqual(date('Y-m-d H:i:s', $before + 30), $row->getRunAfter());
    }

    public function testDeferrableExceptionsDoNotCountAnAttempt(): void
    {
        JobQueue::enqueue('a', ['x' => 1]);
        JobQueue::enqueue('b', ['x' => 2]);
        $stats = (new TestJobQueue())->drain(10, [
            'a' => function () { throw new RateLimited('429'); },
            'b' => function () { throw new TransientError('503'); },
        ]);
        self::assertSame(['processed' => 2, 'ok' => 0, 'failed' => 0, 'deferred' => 2], $stats);
        foreach ([1, 2] as $pk) {
            $row = $this->row(\App\JobQueue::class, $pk);
            self::assertSame('Pending', $row->getState());
            self::assertSame(0, $row->getAttempts());
            self::assertGreaterThan(date('Y-m-d H:i:s'), $row->getRunAfter());
        }
        self::assertSame('429', $this->row(\App\JobQueue::class, 1)->getLastError());
    }

    public function testPermanentExceptionFailsOnFirstAttempt(): void
    {
        JobQueue::enqueue('a', []);
        $stats = (new TestJobQueue())->drain(10, ['a' => function () { throw new ValidationRejected('bad input'); }]);
        self::assertSame(['processed' => 1, 'ok' => 0, 'failed' => 1, 'deferred' => 0], $stats);
        $row = $this->row(\App\JobQueue::class, 1);
        self::assertSame('Failed', $row->getState());
        self::assertSame(1, $row->getAttempts());
        self::assertSame('bad input', $row->getLastError());
    }

    public function testGenericExceptionRetriesUntilMaxAttempts(): void
    {
        JobQueue::enqueue('a', []);
        $row = $this->row(\App\JobQueue::class, 1);
        $row->setAttempts(JobQueue::MAX_ATTEMPTS - 2);
        $handlers = ['a' => function () { throw new \RuntimeException('boom'); }];

        $stats = (new TestJobQueue())->drain(10, $handlers);
        self::assertSame(1, $stats['deferred']);
        self::assertSame('Pending', $row->getState());
        self::assertSame(JobQueue::MAX_ATTEMPTS - 1, $row->getAttempts());

        $row->setRunAfter('2000-01-01 00:00:00'); // make it due again
        $stats = (new TestJobQueue())->drain(10, $handlers);
        self::assertSame(1, $stats['failed']);
        self::assertSame('Failed', $row->getState());
        self::assertSame(JobQueue::MAX_ATTEMPTS, $row->getAttempts());
        self::assertSame('boom', $row->getLastError());
    }

    public function testConstructorMaxAttemptsOverride(): void
    {
        JobQueue::enqueue('a', []);
        $stats = (new TestJobQueue(1))->drain(10, ['a' => function () { throw new \RuntimeException('once'); }]);
        self::assertSame(1, $stats['failed']);
        self::assertSame('Failed', $this->row(\App\JobQueue::class, 1)->getState());
    }

    public function testLastErrorIsTruncatedTo2000Chars(): void
    {
        JobQueue::enqueue('a', []);
        (new TestJobQueue())->drain(10, ['a' => function () { throw new \RuntimeException(str_repeat('é', 3000)); }]);
        self::assertSame(2000, mb_strlen((string) $this->row(\App\JobQueue::class, 1)->getLastError()));
    }

    // ---- claim / reclaim ----------------------------------------------

    public function testLostClaimRaceSkipsTheRow(): void
    {
        JobQueue::enqueue('a', ['n' => 1]);
        JobQueue::enqueue('a', ['n' => 2]);
        $this->conn->loseClaimFor = [1];
        $seen  = [];
        $stats = (new TestJobQueue())->drain(10, ['a' => function (array $p) use (&$seen) { $seen[] = $p['n']; }]);
        self::assertSame(['processed' => 1, 'ok' => 1, 'failed' => 0, 'deferred' => 0], $stats);
        self::assertSame([2], $seen);
        self::assertSame('Pending', $this->row(\App\JobQueue::class, 1)->getState(), 'the other drainer owns it');
    }

    public function testClaimAndReclaimSqlBindTableAndStateIndexes(): void
    {
        JobQueue::enqueue('a', []);
        (new TestJobQueue())->drain(10, ['a' => fn () => null]);
        self::assertSame([
            ['sql' => 'UPDATE job_queue SET state = ? WHERE state = ? AND claimed_at < (NOW() - INTERVAL 10 MINUTE)', 'params' => [0, 1]],
            ['sql' => 'UPDATE job_queue SET state = ?, claimed_at = NOW() WHERE id_job_queue = ? AND state = ?', 'params' => [1, 1, 0]],
        ], $this->conn->log);
    }

    public function testSyncQueueSqlTargetsAcctSyncJob(): void
    {
        SyncQueue::enqueue(SyncQueue::KIND_PUSH, ['table' => 't', 'pk' => 1]);
        $stats = (new TestSyncQueue())->drain(25, [SyncQueue::KIND_PUSH => fn () => null]);
        self::assertSame(1, $stats['ok']);
        self::assertSame([
            ['sql' => 'UPDATE acct_sync_job SET state = ? WHERE state = ? AND claimed_at < (NOW() - INTERVAL 10 MINUTE)', 'params' => [0, 1]],
            ['sql' => 'UPDATE acct_sync_job SET state = ?, claimed_at = NOW() WHERE id_acct_sync_job = ? AND state = ?', 'params' => [1, 1, 0]],
        ], $this->conn->log);
        self::assertSame('Done', $this->row(\App\AcctSyncJob::class, 1)->getState());
    }

    // ---- backoff + subclass binding ------------------------------------

    public function testBackoffTableUnchanged(): void
    {
        $want = [1 => 30, 2 => 120, 3 => 270, 4 => 480, 5 => 750, 15 => 6750, 16 => 7200, 50 => 7200];
        foreach ($want as $attempt => $seconds) {
            self::assertSame($seconds, JobQueue::backoffSeconds($attempt), "attempt $attempt");
            self::assertSame($seconds, SyncQueue::backoffSeconds($attempt), "sync attempt $attempt");
        }
    }

    public function testSyncQueueKeepsItsPublicSurface(): void
    {
        self::assertSame('sync.push', SyncQueue::KIND_PUSH);
        self::assertSame('sync.pull_payments', SyncQueue::KIND_PULL_PAYMENTS);
        self::assertSame('sync.backfill', SyncQueue::KIND_BACKFILL);
        self::assertSame(5, SyncQueue::MAX_ATTEMPTS);
        self::assertSame(10, SyncQueue::STALE_RUNNING_MINUTES);
        self::assertTrue(SyncQueue::available());
        self::assertTrue(JobQueue::available());
        self::assertInstanceOf(JobQueue::class, new SyncQueue());
        self::assertSame('\App\AcctSyncJob', seam(SyncQueue::class, 'modelClass'));
        self::assertSame('\App\AcctSyncJobQuery', seam(SyncQueue::class, 'queryClass'));
        self::assertSame('\App\AcctSyncJobPeer', seam(SyncQueue::class, 'peerClass'));
        self::assertSame('acct_sync_job', seam(SyncQueue::class, 'tableName'));
        self::assertSame('id_acct_sync_job', seam(SyncQueue::class, 'pkColumn'));
        self::assertSame('\App\JobQueue', seam(JobQueue::class, 'modelClass'));
        self::assertSame('job_queue', seam(JobQueue::class, 'tableName'), 'Peer::TABLE_NAME wins when the Peer exists');
        // SyncQueue::enqueue lands in acct_sync_job, JobQueue::enqueue in job_queue — independent dedup scopes
        self::assertSame(1, SyncQueue::enqueue('k', ['a' => 1]));
        self::assertSame(2, JobQueue::enqueue('k', ['a' => 1]));
        self::assertCount(1, FakeStore::rows(\App\AcctSyncJob::class));
        self::assertCount(1, FakeStore::rows(\App\JobQueue::class));
    }

    public function testTableNameFallsBackToSnakeCaseWithoutAPeer(): void
    {
        self::assertFalse(OrphanQueue::available());
        self::assertSame('mail_outbound_job', OrphanQueue::table());
        self::assertSame('id_mail_outbound_job', OrphanQueue::pk());
        self::assertSame('IdMailOutboundJob', OrphanQueue::pkPhp());
    }
}
