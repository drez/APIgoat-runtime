<?php

declare(strict_types=1);

// In-memory stand-ins for the Propel models the Stripe / Apple runtime
// resolves by name (StripeDb / AppleIap hard-code "\App\{$entity}"). Only the
// surface the payment code uses is modelled: generic filterByX($v, $cmp)
// conditions evaluated against getX(), find/findOne/count/findPk, and a
// conditional update() that returns the affected-row count like Propel's —
// which is exactly what the CAS claims (paid flag, event lease) rely on.

namespace ApiGoat\Tests\Stripe\Support {

    final class FakeStore
    {
        /** @var array<string, array<int, FakeRow>> row class => pk => row */
        public static array $rows = [];
        /** @var array<string, int> */
        public static array $seq = [];

        public static function reset(): void
        {
            self::$rows = [];
            self::$seq = [];
        }

        /** @return array<int, FakeRow> */
        public static function all(string $class): array
        {
            return self::$rows[$class] ?? [];
        }
    }

    abstract class FakeRow
    {
        /** @var array<string, mixed> column phpName => value */
        public array $d = [];
        public ?int $pk = null;
        public int $saves = 0;

        /** phpNames that must stay unique (mirrors the UNIQUE indexes). */
        protected const UNIQUE = [];

        public function __call(string $name, array $args)
        {
            $prefix = \substr($name, 0, 3);
            $col = \substr($name, 3);
            if ($prefix === 'get') {
                return $this->d[$col] ?? null;
            }
            if ($prefix === 'set') {
                $this->d[$col] = $args[0] ?? null;
                return $this;
            }
            throw new \BadMethodCallException(static::class . '::' . $name);
        }

        public function getPrimaryKey(): ?int
        {
            return $this->pk;
        }

        public function save(): int
        {
            $class = static::class;
            foreach (static::UNIQUE as $col) {
                $v = $this->d[$col] ?? null;
                if ($v === null) {
                    continue;
                }
                foreach (FakeStore::all($class) as $other) {
                    if ($other !== $this && ($other->d[$col] ?? null) === $v) {
                        throw new \RuntimeException("Duplicate entry '{$v}' for {$col}");
                    }
                }
            }
            if ($this->pk === null) {
                $this->pk = FakeStore::$seq[$class] = (FakeStore::$seq[$class] ?? 0) + 1;
            }
            FakeStore::$rows[$class][$this->pk] = $this;
            $this->saves++;
            return 1;
        }
    }

    abstract class FakeQuery
    {
        /** @var array<int, array{0:string,1:mixed,2:?string}> */
        private array $conds = [];

        public static function create(): static
        {
            return new static();
        }

        private static function rowClass(): string
        {
            return \substr(static::class, 0, -\strlen('Query'));
        }

        public function filterByPrimaryKey($pk): static
        {
            $this->conds[] = ['__pk', $pk, null];
            return $this;
        }

        public function __call(string $name, array $args)
        {
            if (\strpos($name, 'filterBy') === 0) {
                $this->conds[] = [\substr($name, 8), $args[0] ?? null, $args[1] ?? null];
                return $this;
            }
            throw new \BadMethodCallException(static::class . '::' . $name);
        }

        private function matches(FakeRow $row): bool
        {
            foreach ($this->conds as [$col, $v, $cmp]) {
                $val = $col === '__pk' ? $row->getPrimaryKey() : ($row->d[$col] ?? null);
                switch ($cmp) {
                    case \Criteria::ISNULL:
                        $ok = $val === null;
                        break;
                    case \Criteria::NOT_EQUAL:
                        $ok = $val !== null && $val != $v;
                        break;
                    case \Criteria::GREATER_THAN:
                        $ok = $val !== null && $val > $v;
                        break;
                    default:
                        $ok = \is_array($v)
                            ? ($val !== null && \in_array($val, $v, false))
                            : ($val !== null && $val == $v);
                }
                if (!$ok) {
                    return false;
                }
            }
            return true;
        }

        /** @return FakeRow[] */
        public function find(): array
        {
            return \array_values(\array_filter(FakeStore::all(self::rowClass()), fn ($r) => $this->matches($r)));
        }

        public function findOne(): ?FakeRow
        {
            return $this->find()[0] ?? null;
        }

        public function findPk($pk): ?FakeRow
        {
            return FakeStore::all(self::rowClass())[(int) $pk] ?? null;
        }

        public function count(): int
        {
            return \count($this->find());
        }

        /** Conditional UPDATE: sets $values on every matching row, returns the affected count. */
        public function update(array $values): int
        {
            $n = 0;
            foreach ($this->find() as $row) {
                foreach ($values as $col => $val) {
                    $row->d[$col] = $val;
                }
                $n++;
            }
            return $n;
        }
    }
}

namespace App {

    use ApiGoat\Tests\Stripe\Support\FakeQuery;
    use ApiGoat\Tests\Stripe\Support\FakeRow;

    class StripePayment extends FakeRow
    {
        protected const UNIQUE = ['StripePaymentIntentId'];
    }
    class StripePaymentQuery extends FakeQuery
    {
    }

    class StripeCustomer extends FakeRow
    {
    }
    class StripeCustomerQuery extends FakeQuery
    {
    }

    class GcClient extends FakeRow
    {
    }
    class GcClientQuery extends FakeQuery
    {
    }

    /** The payable: explicit getters, since the runtime probes them with method_exists(). */
    class GcPayable extends FakeRow
    {
        public function getIsPaid()
        {
            return $this->d['IsPaid'] ?? null;
        }

        public function setIsPaid($v): static
        {
            $this->d['IsPaid'] = $v;
            return $this;
        }

        public function getAmount()
        {
            return $this->d['Amount'] ?? null;
        }

        public function getCurrency()
        {
            return $this->d['Currency'] ?? null;
        }

        public function getIdGcClient()
        {
            return $this->d['IdGcClient'] ?? null;
        }
    }
    class GcPayableQuery extends FakeQuery
    {
    }

    class AppleTransaction extends FakeRow
    {
        protected const UNIQUE = ['TransactionId'];
    }
    class AppleTransactionQuery extends FakeQuery
    {
    }

    class AppleEvent extends FakeRow
    {
        protected const UNIQUE = ['NotificationUuid'];
    }
    class AppleEventQuery extends FakeQuery
    {
    }
}
