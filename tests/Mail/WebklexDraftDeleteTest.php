<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\Imap\WebklexTransport;
use ApiGoat\Sync\Exceptions\TransientError;
use ApiGoat\Sync\Exceptions\ValidationRejected;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/**
 * WebklexTransport::expungeUid() against a recording protocol double: the
 * real method body (forced SELECT, header check, STORE, UID EXPUNGE) runs;
 * only the wire is faked. Any STORE / EXPUNGE outside the expected pair fails.
 */
final class WebklexDraftDeleteTest extends TestCase
{
    private const OURS = '<draft.7.3.ef@draft.apigmail.invalid>';

    /** @param array<int,array<string,string>> $fetchRows */
    private function transport(array $fetchRows, bool $readOnly = false): array
    {
        $calls = new \ArrayObject();
        $conn  = new class($calls, $fetchRows, $readOnly) {
            public function __construct(private \ArrayObject $calls, private array $rows, private bool $ro) {}
            public function selectFolder(string $f): Response
            {
                $this->calls[] = "SELECT $f";
                return Response::empty()->setResponse(['* 3 EXISTS', 'TAG1 OK [' . ($this->ro ? 'READ-ONLY' : 'READ-WRITE') . '] Select completed.'])
                    ->setResult(['exists' => 3, 'uidvalidity' => 9]);
            }
            public function fetch(array $items, array $from, mixed $to = null, mixed $uid = null): Response
            {
                $this->calls[] = 'UID FETCH ' . implode(',', $from) . ' ' . implode(' ', $items);
                return Response::empty()->setResult($this->rows);
            }
            public function store(array $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, mixed $uid = null): Response
            {
                $this->calls[] = "UID STORE $from " . ($mode ?? '') . 'FLAGS' . ($silent ? '.SILENT' : '') . ' (' . implode(' ', $flags) . ')';
                return Response::empty()->setCanBeEmpty(true)->setResult([true]);
            }
            public function requestAndResponse(string $command, array $tokens = []): Response
            {
                $this->calls[] = trim($command . ' ' . implode(' ', $tokens));
                return Response::empty()->setCanBeEmpty(true)->setResult([true]);
            }
            public function expunge(): Response
            {
                throw new \LogicException('plain EXPUNGE is forbidden');
            }
        };
        $client = new class($conn, $calls) {
            public ?string $active = 'INBOX';
            public function __construct(private object $c, private \ArrayObject $calls) {}
            public function checkConnection(): bool { return true; }
            public function getConnection(): object { return $this->c; }
            public function setActiveFolder(?string $f = null): void { $this->active = $f; }
            public function openFolder(string $f, bool $force = false): array { throw new \LogicException('expungeUid must SELECT on the protocol, not through the folder cache'); }
        };
        $t = new WebklexTransport(['host' => 'x', 'username' => 'u', 'password' => 'p']);
        (new \ReflectionProperty($t, 'client'))->setValue($t, $client);
        return [$t, $calls, $client];
    }

    public function testOurDraftIsStoredDeletedAndUidExpungedAlone(): void
    {
        [$t, $calls, $client] = $this->transport([42 => ['UID' => '42', 'BODY[HEADER]' => "From: me@x\r\nMessage-Id: " . self::OURS . "\r\nSubject: s\r\n\r\n"]]);
        $t->expungeUid('Drafts', 42, self::OURS);
        $this->assertSame([
            'SELECT Drafts',
            'UID FETCH 42 UID BODY.PEEK[HEADER]',
            'UID STORE 42 +FLAGS.SILENT (\\Deleted)',
            'UID EXPUNGE 42',
        ], $calls->getArrayCopy());
        $this->assertSame('Drafts', $client->active);
    }

    public function testAnEmptyFetchIsTransient404AndWritesNothing(): void
    {
        [$t, $calls] = $this->transport([]);
        try {
            $t->expungeUid('Drafts', 42, self::OURS);
            $this->fail('a gone uid must throw');
        } catch (TransientError $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertSame(['SELECT Drafts', 'UID FETCH 42 UID BODY.PEEK[HEADER]'], $calls->getArrayCopy());
    }

    public function testAForeignMessageIdIsRejectedAndWritesNothing(): void
    {
        foreach (["Message-ID: <theirs@other.client>\r\n", "Subject: no id\r\n", "Message-ID: " . self::OURS . "x\r\n", "X-Message-ID: " . self::OURS . "\r\n"] as $hdr) {
            [$t, $calls] = $this->transport([42 => ['UID' => '42', 'BODY[HEADER]' => $hdr]]);
            try {
                $t->expungeUid('Drafts', 42, self::OURS);
                $this->fail('must refuse: ' . trim($hdr));
            } catch (ValidationRejected $e) {
                $this->assertSame(409, $e->getCode());
            }
            $this->assertSame(['SELECT Drafts', 'UID FETCH 42 UID BODY.PEEK[HEADER]'], $calls->getArrayCopy());
        }
    }

    public function testAFoldedMessageIdHeaderStillMatches(): void
    {
        [$t, $calls] = $this->transport([42 => ['UID' => '42', 'BODY[HEADER]' => "Message-ID:\r\n " . self::OURS . "\r\n"]]);
        $t->expungeUid('Drafts', 42, self::OURS);
        $this->assertContains('UID EXPUNGE 42', $calls->getArrayCopy());
    }

    public function testAReadOnlySelectIsRefusedBeforeTheFetch(): void
    {
        [$t, $calls] = $this->transport([42 => ['UID' => '42', 'BODY[HEADER]' => 'Message-ID: ' . self::OURS]], true);
        try {
            $t->expungeUid('Drafts', 42, self::OURS);
            $this->fail('read-only must be refused');
        } catch (ValidationRejected $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertSame(['SELECT Drafts'], $calls->getArrayCopy());
    }

    public function testBadArgumentsAreRefusedBeforeTheWire(): void
    {
        foreach ([[0, self::OURS], [42, 'draft@x'], [42, "<a\r\nb@x>"], [42, '<a"b@x>'], [42, '<>']] as [$uid, $mid]) {
            [$t, $calls] = $this->transport([]);
            try {
                $t->expungeUid('Drafts', $uid, $mid);
                $this->fail("must refuse $uid " . json_encode($mid));
            } catch (\InvalidArgumentException) {
            }
            $this->assertSame([], $calls->getArrayCopy());
        }
    }
}
