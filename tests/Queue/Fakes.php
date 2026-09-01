<?php
// tests/Queue/Fakes.php — in-memory stand-ins for the emitted Propel classes
// ApiGoat\Queue\JobQueue duck-types against (model / Query / Peer) plus the
// \Propel connection the claim/reclaim SQL runs through. No DB.

namespace {
    if (!class_exists('Criteria')) { class Criteria { const LESS_EQUAL = '<='; } }
}

namespace ApiGoat\Tests\Queue {
    final class FakeStore
    {
        /** @var array<string, array<int, object>> model class => pk => row */
        public static array $rows = [];
        public static int $seq = 0;

        public static function reset(): void { self::$rows = []; self::$seq = 0; }

        /** @return object[] */
        public static function rows(string $class): array { return array_values(self::$rows[ltrim($class, '\\')] ?? []); }
    }

    /** Records every prepared statement; applies the claim UPDATE to the store. */
    final class FakeConnection
    {
        /** The connection the test queue subclasses hand back from connection(). */
        public static ?FakeConnection $current = null;

        /** @var array<int, array{sql: string, params: array}> */
        public array $log = [];
        /** @var int[] pks whose claim must lose the race (rowCount 0) */
        public array $loseClaimFor = [];

        public function prepare(string $sql): FakeStatement { return new FakeStatement($this, $sql); }
    }

    final class FakeStatement
    {
        private int $rowCount = 0;

        public function __construct(private FakeConnection $conn, private string $sql) {}

        public function execute(array $params): bool
        {
            $this->conn->log[] = ['sql' => $this->sql, 'params' => $params];
            if (!preg_match('/^UPDATE (\w+) SET state = \?, claimed_at = NOW\(\) WHERE (\w+) = \? AND state = \?$/', $this->sql, $m)) {
                return true; // reclaim: nothing stale in the fake store
            }
            [$table, $pkCol] = [$m[1], $m[2]];
            [$to, $pk, $from] = $params;
            if (in_array((int) $pk, $this->conn->loseClaimFor, true)) {
                return true;
            }
            foreach (FakeStore::$rows as $class => $rows) {
                $peer = $class . 'Peer';
                if ($peer::TABLE_NAME !== $table || $peer::PK !== $pkCol) {
                    continue;
                }
                $row = $rows[(int) $pk] ?? null;
                $set = $peer::getValueSet($peer::STATE);
                if ($row && $row->getState() === $set[$from]) {
                    $row->setState($set[$to]);
                    $this->rowCount = 1;
                }
            }
            return true;
        }

        public function rowCount(): int { return $this->rowCount; }
    }

    /** Test bindings: the real classes but with the claim/reclaim SQL routed to FakeConnection::$current. */
    final class TestJobQueue extends \ApiGoat\Queue\JobQueue
    {
        protected function connection() { return FakeConnection::$current; }
    }
    final class TestSyncQueue extends \ApiGoat\Sync\SyncQueue
    {
        protected function connection() { return FakeConnection::$current; }
    }

    abstract class FakeRow
    {
        public ?int $pk = null;
        public array $data = ['attempts' => 0];
        public int $saves = 0;

        public function getPrimaryKey(): ?int { return $this->pk; }
        public function setKind(string $v): void { $this->data['kind'] = $v; }
        public function getKind(): string { return $this->data['kind']; }
        public function setPayloadJson(?string $v): void { $this->data['payload_json'] = $v; }
        public function getPayloadJson(): ?string { return $this->data['payload_json'] ?? null; }
        public function setState(string $v): void { $this->data['state'] = $v; }
        public function getState(): string { return $this->data['state']; }
        public function setAttempts(int $v): void { $this->data['attempts'] = $v; }
        public function getAttempts(): int { return $this->data['attempts']; }
        public function setRunAfter(string $v): void { $this->data['run_after'] = $v; }
        public function getRunAfter(): string { return $this->data['run_after']; }
        public function setLastError(?string $v): void { $this->data['last_error'] = $v; }
        public function getLastError(): ?string { return $this->data['last_error'] ?? null; }

        public function save(): void
        {
            $this->saves++;
            if ($this->pk === null) {
                $this->pk = ++FakeStore::$seq;
            }
            FakeStore::$rows[static::class][$this->pk] = $this;
        }
    }

    abstract class FakeQuery
    {
        protected array $where = [];
        protected ?int $limit = null;

        abstract protected function modelClass(): string;

        public static function create(): static { return new static(); }
        public function filterByKind(string $v): static { $this->where[] = fn ($r) => $r->getKind() === $v; return $this; }
        public function filterByState(string $v): static { $this->where[] = fn ($r) => $r->getState() === $v; return $this; }
        public function filterByPayloadJson(string $v): static { $this->where[] = fn ($r) => $r->getPayloadJson() === $v; return $this; }
        public function filterByRunAfter(string $v, string $op): static
        {
            if ($op !== \Criteria::LESS_EQUAL) { throw new \RuntimeException("unexpected op $op"); }
            $this->where[] = fn ($r) => $r->getRunAfter() <= $v;
            return $this;
        }
        public function limit(int $n): static { $this->limit = $n; return $this; }

        /** @return object[] */
        public function find(): array
        {
            $rows = FakeStore::rows($this->modelClass());
            $rows = array_values(array_filter($rows, function ($r) {
                foreach ($this->where as $w) { if (!$w($r)) return false; }
                return true;
            }));
            usort($rows, fn ($a, $b) => $a->pk <=> $b->pk);
            return $this->limit === null ? $rows : array_slice($rows, 0, $this->limit);
        }

        public function count(): int { return count($this->find()); }
    }

    abstract class FakePeer
    {
        const STATE = 'state';
        public static function getValueSet(string $col): array
        {
            if ($col !== static::STATE) { throw new \RuntimeException("unexpected column $col"); }
            return ['Pending', 'Running', 'Done', 'Failed'];
        }
    }
}

namespace App {
    use ApiGoat\Tests\Queue\FakePeer;
    use ApiGoat\Tests\Queue\FakeQuery;
    use ApiGoat\Tests\Queue\FakeRow;

    // with_job_queue shape: id_job_queue pk + id_tenant
    final class JobQueue extends FakeRow
    {
        public function setIdTenant(?int $v): void { $this->data['id_tenant'] = $v; }
        public function getIdTenant(): ?int { return $this->data['id_tenant'] ?? null; }
    }
    final class JobQueueQuery extends FakeQuery
    {
        protected function modelClass(): string { return JobQueue::class; }
        public function orderByIdJobQueue(): static { return $this; }
    }
    final class JobQueuePeer extends FakePeer
    {
        const TABLE_NAME = 'job_queue';
        const PK = 'id_job_queue';
    }

    // with_accounting_sync shape: id_acct_sync_job pk, NO id_tenant
    final class AcctSyncJob extends FakeRow {}
    final class AcctSyncJobQuery extends FakeQuery
    {
        protected function modelClass(): string { return AcctSyncJob::class; }
        public function orderByIdAcctSyncJob(): static { return $this; }
    }
    final class AcctSyncJobPeer extends FakePeer
    {
        const TABLE_NAME = 'acct_sync_job';
        const PK = 'id_acct_sync_job';
    }
}
