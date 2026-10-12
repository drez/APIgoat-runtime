<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\Imap\WebklexTransport;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/**
 * headers() used to cost one getMessageByUid (FLAGS + HEADER + SIZE + a STORE) per
 * uid, ~1 s each on a remote server. It now asks for the whole page in ONE
 * `UID FETCH <set> (UID FLAGS RFC822.SIZE BODY.PEEK[HEADER])`; any uid the answer
 * lacks falls back to the per-uid path.
 */
final class WebklexBatchHeadersTest extends TestCase
{
    private static function raw(int $uid, string $extra = ''): string
    {
        return "Message-ID: <m{$uid}@x>\r\nFrom: \"Sender {$uid}\" <s{$uid}@x.com>\r\nTo: me@x.com\r\nCc: c@x.com\r\n"
            . "Subject: Subject {$uid}\r\nDate: Wed, 16 Sep 2026 10:00:00 +0000\r\nReferences: <r@x>\r\nAuthentication-Results: mx; spf=pass\r\n"
            . "X-Custom: v{$uid}\r\n{$extra}\r\n";
    }

    protected function setUp(): void
    {
        if (!WebklexTransport::available()) {
            $this->markTestSkipped('webklex/php-imap not installed');
        }
    }

    /** @param array<int,array<string,mixed>> $server uid => FETCH answer ('FLAGS','RFC822.SIZE','BODY[HEADER]') @return array{0:WebklexTransport,1:object} */
    private function transport(array $server, ?\Closure $perUid = null): array
    {
        $conn = new class($server) {
            /** @var array<int,array{items:array,uids:array}> */
            public array $fetches = [];
            public function __construct(private array $server) {}
            public function fetch(array|string $items, array|int $from, mixed $to = null, int|string $uid = 1): Response
            {
                $this->fetches[] = ['items' => (array) $items, 'uids' => (array) $from];
                if (in_array('X-GM-THRID', (array) $items, true)) {
                    throw new \RuntimeException('BAD not a Gmail server'); // swallowed by gmailThreadIds()
                }
                $want = array_map(static fn ($i) => str_replace('BODY.PEEK[', 'BODY[', $i), (array) $items);
                $out  = [];
                foreach ((array) $from as $u) {
                    if (!isset($this->server[$u])) continue;
                    $row = ['UID' => (string) $u];
                    foreach ($this->server[$u] as $k => $v) if (in_array($k, $want, true)) $row[$k] = $v;
                    $out[$u] = $row;
                }
                return Response::empty()->setResult($out);
            }
        };
        $cfg = \Webklex\PHPIMAP\Config::make(WebklexTransport::managerConfig());
        $log = new \stdClass();
        $log->perUid = [];
        $client = new class($conn, $cfg, $perUid, $log) {
            public array $opened = [];
            public function __construct(public object $conn, private $cfg, private ?\Closure $perUid, private object $log) {}
            public function openFolder(string $f): void { $this->opened[] = $f; }
            public function getConnection(): object { return $this->conn; }
            public function getConfig() { return $this->cfg; }
            public function getFolderByPath(string $p): object
            {
                $log = $this->log;
                $perUid = $this->perUid;
                return new class($log, $perUid) {
                    public function __construct(private object $log, private ?\Closure $perUid) {}
                    public function query(): object
                    {
                        $log = $this->log;
                        $perUid = $this->perUid;
                        return new class($log, $perUid) {
                            public function __construct(private object $log, private ?\Closure $perUid) {}
                            public function __call($n, $a) { return $this; }
                            public function getMessageByUid(int $uid): object
                            {
                                $this->log->perUid[] = $uid;
                                if (!$this->perUid) throw new \LogicException("per-uid path used for $uid");
                                return ($this->perUid)($uid);
                            }
                        };
                    }
                };
            }
        };
        $t = new WebklexTransport(['host' => 'x', 'username' => 'u', 'password' => 'p']);
        (new \ReflectionProperty($t, 'client'))->setValue($t, $client);
        return [$t, $conn, $log];
    }

    private static function answer(int $uid, array $flags = ['\\Seen']): array
    {
        return ['FLAGS' => $flags, 'RFC822.SIZE' => (string) (500 + $uid), 'BODY[HEADER]' => self::raw($uid)];
    }

    /** @return array<int,array<string,mixed>> */
    private function batchFetches(object $conn): array
    {
        return array_values(array_filter($conn->fetches, static fn ($f) => in_array('BODY.PEEK[HEADER]', $f['items'], true)));
    }

    public function testAPageIsOneFetchWhateverItsSize(): void
    {
        foreach ([1, 7, 50, 200] as $n) {
            $uids = range(1001, 1000 + $n);
            $server = [];
            foreach ($uids as $u) $server[$u] = self::answer($u);
            [$t, $conn, $log] = $this->transport($server);

            $rows = $t->headers('INBOX', $uids);

            $this->assertCount($n, $rows);
            $this->assertCount(1, $this->batchFetches($conn), "{$n} uids: ONE header FETCH");
            $this->assertSame([], $log->perUid, 'no per-uid round trip');
            // Every command for the page: the header batch + the best-effort Gmail thread-id probe. O(1), not O(n).
            $this->assertLessThanOrEqual(3, count($conn->fetches), "{$n} uids: commands per page do not grow with the page");
        }
    }

    public function testTheBatchIsAPeekAndAsksForFlagsSizeAndTheHeaderBlock(): void
    {
        [$t, $conn] = $this->transport([5 => self::answer(5)]);
        $t->headers('INBOX', [5]);
        $f = $this->batchFetches($conn)[0];
        $this->assertSame(['UID', 'FLAGS', 'RFC822.SIZE', 'BODY.PEEK[HEADER]'], $f['items'], 'PEEK: the page is left unread');
        $this->assertSame([5], $f['uids']);
    }

    public function testTheRecordHasTheSameShapeAndValuesAsThePerUidPath(): void
    {
        [$t] = $this->transport([7 => self::answer(7, []), 8 => self::answer(8, ['\Seen', '\Flagged'])]);
        $rows = $t->headers('INBOX', [7, 8]);

        $this->assertSame([7, 8], array_keys($rows));
        $r = $rows[8];
        $this->assertSame(8, $r['uid']);
        $this->assertSame('m8@x', $r['message_id'], 'webklex strips the angle brackets');
        $this->assertSame('', $r['in_reply_to']);
        $this->assertSame('"Sender 8" <s8@x.com>', $r['from']);
        $this->assertSame('me@x.com', $r['to']);
        $this->assertSame('c@x.com', $r['cc']);
        $this->assertSame('Subject 8', $r['subject']);
        $this->assertSame('2026-09-16 10:00:00', \ApiGoat\Mail\HeaderRecord::dateTime($r['date']));
        $this->assertSame(508, $r['size']);
        $this->assertFalse($r['has_attachments']);
        $this->assertTrue($r['seen']);
        $this->assertSame(['Seen', 'Flagged'], $r['flags'], 'backslash stripped, like webklex');
        $this->assertSame('', $r['thread_id']);
        $this->assertSame('mx; spf=pass', $r['auth_results']);
        $this->assertSame('<r@x>', $r['references']);
        $this->assertSame(['v8'], $r['headers']['x-custom']);
        $this->assertFalse($rows[7]['seen']);
        $this->assertSame([], $rows[7]['flags']);
        // the same key set the per-uid path built
        $this->assertSame(
            ['uid', 'message_id', 'in_reply_to', 'from', 'to', 'cc', 'subject', 'date', 'size', 'has_attachments', 'seen', 'flags', 'thread_id', 'auth_results', 'references', 'reply_to', 'headers'],
            array_slice(array_keys($r), 0, 17)
        );
        $this->assertSame(array_keys(\ApiGoat\Mail\HeaderRecord::bulkHeaders(self::raw(8))), array_slice(array_keys($r), 17));
    }

    public function testAUidMissingFromTheAnswerFallsBackToThePerUidPath(): void
    {
        $per = function (int $uid): object {
            $header = new \Webklex\PHPIMAP\Header(self::raw($uid), \Webklex\PHPIMAP\Config::make(WebklexTransport::managerConfig()));
            return new class($uid, $header) {
                public function __construct(private int $uid, private object $header) {}
                public function get(string $n) { return $this->header->get($n); }
                public function getFlags(): array { return ['Seen']; }
                public function getSize(): int { return 9999; }
                public function hasAttachments(): bool { return false; }
                public function getHeader(): object { return $this->header; }
            };
        };
        [$t, $conn, $log] = $this->transport([1 => self::answer(1), 3 => self::answer(3)], $per);

        $rows = $t->headers('INBOX', [1, 2, 3]);

        $this->assertSame([1, 2, 3], array_keys($rows), 'order of the request is kept');
        $this->assertSame([2], $log->perUid, 'only the missing uid costs a round trip');
        $this->assertSame(9999, $rows[2]['size']);
        $this->assertSame('Subject 2', $rows[2]['subject']);
        $this->assertSame(503, $rows[3]['size']);
    }

    public function testAUidTheServerNoLongerHasIsSkippedNotFatal(): void
    {
        $per = fn (int $uid) => throw new \Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException('no headers found', 0);
        [$t] = $this->transport([1 => self::answer(1)], $per);
        $this->assertSame([1], array_keys($t->headers('INBOX', [1, 2])), 'expunged between SEARCH and FETCH');
    }

    public function testBigPagesAreSplitIntoBoundedFetches(): void
    {
        $uids = range(1, 450);
        $server = [];
        foreach ($uids as $u) $server[$u] = self::answer($u);
        [$t, $conn] = $this->transport($server);
        $this->assertCount(450, $t->headers('INBOX', $uids));
        $this->assertCount(3, $this->batchFetches($conn), '450 uids = 3 commands of at most 200');
    }

    public function testNoUidsNoCommands(): void
    {
        [$t, $conn] = $this->transport([]);
        $this->assertSame([], $t->headers('INBOX', []));
        $this->assertSame([], $conn->fetches);
    }
}
