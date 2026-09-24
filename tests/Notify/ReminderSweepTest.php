<?php

namespace App {
    /** Fake swept model for ReminderSweepTest (never a real project class name). */
    class RsTestInvoice
    {
        public function __construct(private int $id, private string $due) {}
        public function getIdRsTestInvoice(): int { return $this->id; }
        public function getDueDate($fmt = null) { return $this->due; }
    }

    class RsTestInvoiceQuery
    {
        /** @var list<RsTestInvoice> */
        public static array $rows = [];
        public static function create(): self { return new self(); }
        public function find(): array { return self::$rows; }
    }

    class RsTestLog
    {
        /** @var list<array{0:int,1:int}> */
        public static array $saved = [];
        private int $inv = 0;
        private int $off = 0;
        public function setIdRsTestInvoice($v): void { $this->inv = (int) $v; }
        public function setOffsetDays($v): void { $this->off = (int) $v; }
        public function getOffsetDays(): int { return $this->off; }
        public function save(): void { self::$saved[] = [$this->inv, $this->off]; }
    }

    class RsTestLogQuery
    {
        private int $inv = 0;
        public static function create(): self { return new self(); }
        public function filterByIdRsTestInvoice($v): self { $this->inv = (int) $v; return $this; }
        public function find(): array
        {
            $out = [];
            foreach (RsTestLog::$saved as [$i, $o]) {
                if ($i === $this->inv) {
                    $l = new RsTestLog();
                    $l->setOffsetDays($o);
                    $out[] = $l;
                }
            }
            return $out;
        }
    }
}

namespace ApiGoat\Tests\Notify {

    use ApiGoat\Notify\ReminderSweep;
    use PHPUnit\Framework\TestCase;

    final class ReminderSweepTest extends TestCase
    {
        protected function setUp(): void
        {
            \App\RsTestLog::$saved = [];
            \App\RsTestInvoiceQuery::$rows = [new \App\RsTestInvoice(1, '2026-09-01')];
        }

        /** @param callable(string,string,string,array):bool $send */
        private function spec(callable $send): array
        {
            return [
                'table_php'   => 'RsTestInvoice',
                'date_php'    => 'DueDate',
                'offsets'     => [0, 7],
                'log_php'     => 'RsTestLog',
                'recipient'   => fn($row) => ['a@example.com'],
                'compose'     => fn($row, int $o) => ['subject' => "o{$o}", 'html' => 'x'],
                'send'        => $send,
            ];
        }

        public function testFailedLaterOffsetIsNotLoggedAfterEarlierSuccess(): void
        {
            // offset 0 goes out, offset 7 fails: only 0 may be recorded.
            $send = fn($to, string $subject) => $subject === 'o0';
            $stats = ReminderSweep::run($this->spec($send), new \DateTimeImmutable('2026-09-20'));

            self::assertSame([[1, 0]], \App\RsTestLog::$saved);
            self::assertSame(1, $stats['sent']);
            self::assertSame(1, $stats['mails']);
        }

        public function testFailedOffsetIsRetriedNextRun(): void
        {
            $send = fn($to, string $subject) => $subject === 'o0';
            ReminderSweep::run($this->spec($send), new \DateTimeImmutable('2026-09-20'));
            $sent = [];
            ReminderSweep::run($this->spec(function ($to, string $subject) use (&$sent) {
                $sent[] = $subject;
                return true;
            }), new \DateTimeImmutable('2026-09-20'));

            self::assertSame(['o7'], $sent);
            self::assertSame([[1, 0], [1, 7]], \App\RsTestLog::$saved);
        }

        public function testPlaceholderRecipientsAreSkippedNotFailed(): void
        {
            $sent = [];
            $spec = $this->spec(function ($to) use (&$sent) { $sent[] = $to; return true; });
            $spec['recipient'] = fn($row) => ['noemail-5@apigtutor.invalid', 'deleted-3@deleted.invalid', 'a@example.com'];
            $stats = ReminderSweep::run($spec, new \DateTimeImmutable('2026-09-20'));
            self::assertSame(['a@example.com', 'a@example.com'], $sent);
            self::assertSame(2, $stats['mails']);

            // only placeholders: no recipients, nothing logged (not a failure either)
            \App\RsTestLog::$saved = [];
            $spec['recipient'] = fn($row) => ['noemail-5@apigtutor.invalid'];
            $stats = ReminderSweep::run($spec, new \DateTimeImmutable('2026-09-20'));
            self::assertSame(0, $stats['mails']);
            self::assertSame([], \App\RsTestLog::$saved);
        }

        public function testConcurrentRunIsANoOp(): void
        {
            $inner = null;
            $send = function () use (&$inner) {
                // A second run starting while the first is mid-send must not mail.
                $inner = ReminderSweep::run($this->spec(fn() => true), new \DateTimeImmutable('2026-09-20'));
                return true;
            };
            ReminderSweep::run($this->spec($send), new \DateTimeImmutable('2026-09-20'));

            self::assertTrue($inner['locked'] ?? false);
            self::assertSame(0, $inner['mails']);
            // and the lock is released afterwards
            $after = ReminderSweep::run($this->spec(fn() => true), new \DateTimeImmutable('2026-09-20'));
            self::assertArrayNotHasKey('locked', $after);
        }
    }
}
