<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeImapTransport.php';

use ApiGoat\Mail\Connector\ImapConnector;
use ApiGoat\Mail\HeaderRecord;
use ApiGoat\Mail\Imap\WebklexTransport;
use ApiGoat\Mail\MailboxState;
use PHPUnit\Framework\TestCase;

/**
 * One message with an unparseable Date header (found live in [Gmail]/Spam,
 * "Date: 09-16-2026/09-16-2026") made webklex throw InvalidMessageDateException
 * while fetching headers, which failed the WHOLE folder fetch every minute.
 */
final class WebklexPoisonDateTest extends TestCase
{
    private const POISON = "Message-ID: <poison@x>\r\nFrom: a@x.com\r\nTo: b@x.com\r\nSubject: bad date\r\nDate: 09-16-2026/09-16-2026\r\n";
    private const GOOD   = "Message-ID: <good@x>\r\nFrom: a@x.com\r\nTo: b@x.com\r\nSubject: good date\r\nDate: Wed, 16 Sep 2026 10:00:00 +0000\r\n";

    protected function setUp(): void
    {
        if (!WebklexTransport::available()) {
            $this->markTestSkipped('webklex/php-imap not installed');
        }
    }

    public function testWebklexDefaultsThrowOnThePoisonDate(): void
    {
        $this->expectException(\Webklex\PHPIMAP\Exceptions\InvalidMessageDateException::class);
        new \Webklex\PHPIMAP\Header(self::POISON, \Webklex\PHPIMAP\Config::make([]));
    }

    public function testTheTransportsConfigParsesThePoisonDateAsNoDate(): void
    {
        $h = new \Webklex\PHPIMAP\Header(self::POISON, \Webklex\PHPIMAP\Config::make(WebklexTransport::managerConfig()));
        $this->assertSame('', WebklexTransport::headerDate($h->get('date')), 'unknown, never 1970-01-01');
        $this->assertSame('<poison@x>', '<' . trim((string) $h->get('message_id'), '<>') . '>', 'the rest of the header still parses');
    }

    public function testAGoodDateIsUntouched(): void
    {
        $h = new \Webklex\PHPIMAP\Header(self::GOOD, \Webklex\PHPIMAP\Config::make(WebklexTransport::managerConfig()));
        $this->assertSame('2026-09-16 10:00:00', HeaderRecord::dateTime(WebklexTransport::headerDate($h->get('date'))));
    }

    public function testTheClientsBuiltByTheTransportCarryTheFallback(): void
    {
        $client = (new \Webklex\PHPIMAP\ClientManager(WebklexTransport::managerConfig()))
            ->make(['host' => 'h', 'username' => 'u', 'password' => 'p']);
        $this->assertSame(WebklexTransport::FALLBACK_DATE, $client->getConfig()->get('options.fallback_date'));
    }

    public function testInternalDateResponsesAreRead(): void
    {
        // webklex 6.2 splits the quoted date-time on spaces: these are the
        // per-uid shapes a UID FETCH (UID INTERNALDATE) returned live 2026-09-30.
        $this->assertSame('24-Sep-2026 17:43:16 +0000', WebklexTransport::internalDate(['UID' => '11648', 'INTERNALDATE' => '"24-Sep-2026', '17:43:16' => '+0000"']));
        $this->assertSame('1-Sep-2026 07:03:16 +0200', WebklexTransport::internalDate(['UID' => '9', 'INTERNALDATE' => '"', '1-Sep-2026' => '07:03:16', '+0200"' => '']), 'date-day-fixed: " 1-Sep-2026"');
        $this->assertSame('16-Sep-2026 10:00:00 +0200', WebklexTransport::internalDate('"16-Sep-2026 10:00:00 +0200"'), 'a tokenizer that keeps the quoted string');
        $this->assertSame('24-Sep-2026', WebklexTransport::internalDate('"24-Sep-2026'), 'the single-item form loses the time: the day is still better than nothing');
        $this->assertSame('', WebklexTransport::internalDate(null));
        $this->assertSame('', WebklexTransport::internalDate(['UID' => '9']));
        $this->assertSame('2026-09-16 08:00:00', HeaderRecord::dateTime(WebklexTransport::internalDate('"16-Sep-2026 10:00:00 +0200"')));
    }

    public function testABadDateMessageAndItsNeighboursAreAllIngested(): void
    {
        $imap = new FakeImapTransport();
        $imap->add('[Gmail]/Spam', 11);
        $imap->add('[Gmail]/Spam', 12, ['date' => '']);                           // poison, no INTERNALDATE
        $imap->add('[Gmail]/Spam', 13, ['date' => '16-Sep-2026 10:00:00 +0000']); // poison, INTERNALDATE fallback
        $imap->add('[Gmail]/Spam', 14);
        $c = new ImapConnector(['host' => 'h', 'username' => 'u', 'password' => 'p'], $imap);

        $rows = $c->fetchHeaders('[Gmail]/Spam', MailboxState::imap(1000, 11), 50)->headers;

        $this->assertSame(['11:[Gmail]/Spam', '12:[Gmail]/Spam', '13:[Gmail]/Spam', '14:[Gmail]/Spam'], array_column($rows, 'provider_message_id'));
        $this->assertSame([
            '2026-08-31 10:00:00', null, '2026-09-16 10:00:00', '2026-08-31 10:00:00',
        ], array_column($rows, 'date_sent'));
    }
}
