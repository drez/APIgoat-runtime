<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\Imap\WebklexTransport;
use PHPUnit\Framework\TestCase;

final class WebklexCopyUidTest extends TestCase
{
    public function testUntaggedCopyUidOfAMove(): void
    {
        $this->assertSame(45, WebklexTransport::copyUid(['* OK [COPYUID 1765182910 11727 45] Moved', '* 3 EXPUNGE', 'A5 OK Move completed.'], 11727));
    }

    public function testSetsAreMatchedByPosition(): void
    {
        $this->assertSame(22, WebklexTransport::copyUid([['A5 OK [COPYUID 9 10:12 20:22] Done']], 12));
        $this->assertSame(31, WebklexTransport::copyUid('OK [COPYUID 9 4,7 30,31] Done', 7));
    }

    public function testNoCopyUidIsZeroNeverAGuess(): void
    {
        $this->assertSame(0, WebklexTransport::copyUid(['A5 OK Move completed.'], 11727));
        $this->assertSame(0, WebklexTransport::copyUid(['OK [COPYUID 9 4 30] Done'], 5), 'another uid moved: not ours');
    }

    public function testAMissingUidIsGoneNotTransient(): void
    {
        $t = new WebklexTransport(['host' => 'x', 'username' => 'u', 'password' => 'p']);
        $conn = new class {
            public function fetch(): \Webklex\PHPIMAP\Connection\Protocols\Response
            {
                return \Webklex\PHPIMAP\Connection\Protocols\Response::empty()->setResult([]);
            }
        };
        $client = new class($conn) {
            public function __construct(private object $c) {}
            public function openFolder(string $f): void {}
            public function getConnection(): object { return $this->c; }
        };
        $p = new \ReflectionProperty($t, 'client');
        $p->setValue($t, $client);
        foreach (['raw', 'rawHeader'] as $m) {
            try {
                $t->$m('INBOX', 999999999);
                $this->fail("$m must throw");
            } catch (\ApiGoat\Sync\Exceptions\ValidationRejected $e) {
                $this->assertSame(404, $e->getCode());
            }
        }
    }
}
