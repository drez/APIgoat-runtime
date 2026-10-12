<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeImapTransport.php';
require_once __DIR__ . '/FakeTokenSource.php';

use ApiGoat\Mail\Connector\GmailConnector;
use ApiGoat\Mail\Connector\GraphConnector;
use ApiGoat\Mail\Connector\ImapConnector;
use ApiGoat\Mail\HistoryCounter;
use ApiGoat\Mail\MailConnector;
use PHPUnit\Framework\TestCase;

final class HistoryCounterTest extends TestCase
{
    public function testTheThreeRealConnectorsCountHistory(): void
    {
        $imap  = new ImapConnector(['host' => 'h', 'username' => 'u', 'password' => 'p'], new FakeImapTransport());
        $gmail = new GmailConnector(new FakeTokenSource(), fn () => ['status' => 200, 'headers' => '', 'body' => '{}']);
        $graph = new GraphConnector(new FakeTokenSource(), '/me', fn () => ['status' => 200, 'headers' => '', 'body' => '{}']);
        foreach ([$imap, $gmail, $graph] as $c) {
            $this->assertInstanceOf(HistoryCounter::class, $c);
        }
    }

    public function testAConnectorWithoutTheInterfaceIsNotACounter(): void
    {
        $fake = new class extends \ApiGoat\Mail\BaseConnector {
            public function capabilities(): array { return []; }
            public function verify(): void {}
            public function listFolders(): array { return []; }
            public function fetchHeaders(string $folder, ?\ApiGoat\Mail\MailboxState $cursor, int $max): \ApiGoat\Mail\FetchResult { throw new \LogicException('n/a'); }
            public function fetchBody(string $providerId): \ApiGoat\Mail\MailBody { throw new \LogicException('n/a'); }
        };
        $this->assertInstanceOf(MailConnector::class, $fake);
        $this->assertNotInstanceOf(HistoryCounter::class, $fake);
    }
}
