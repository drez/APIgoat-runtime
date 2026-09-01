<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeImapTransport.php';

use ApiGoat\Mail\Connector\ImapConnector;
use ApiGoat\Mail\FetchResult;
use ApiGoat\Mail\HeaderRecord;
use ApiGoat\Mail\MailboxState;
use ApiGoat\Mail\MailConnector;
use ApiGoat\Mail\UnsupportedOperation;
use ApiGoat\Sync\Exceptions\AuthFailed;
use PHPUnit\Framework\TestCase;

final class ImapConnectorTest extends TestCase
{
    private FakeImapTransport $imap;

    protected function setUp(): void
    {
        $this->imap = new FakeImapTransport();
        $this->imap->store = ['INBOX' => [], 'Trash' => []];
    }

    private function connector(array $cfg = []): ImapConnector
    {
        return new ImapConnector($cfg + ['host' => 'h', 'username' => 'u', 'password' => 'p', 'folder' => 'INBOX', 'cold_start_days' => 30], $this->imap);
    }

    public function testRequiredConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImapConnector(['host' => 'h', 'username' => 'u'], $this->imap);
    }

    public function testCapabilitiesAndUnsupportedSend(): void
    {
        $c = $this->connector();
        $this->assertContains(MailConnector::CAP_MOVE, $c->capabilities());
        $this->assertNotContains(MailConnector::CAP_SEND, $c->capabilities());
        $this->expectException(UnsupportedOperation::class);
        $c->send(['to' => 'a@b', 'subject' => 'x']);
    }

    public function testVerifyConnectsAndChecksTheFolder(): void
    {
        $c = $this->connector();
        $c->verify();
        $this->assertSame(['connect', 'status:INBOX'], $this->imap->log);
        unset($c);
        $this->assertSame('disconnect', end($this->imap->log), 'destructor closes the session');
    }

    public function testVerifySurfacesAuthFailureUntouched(): void
    {
        $this->imap->connectError = new AuthFailed('bad creds');
        $this->expectException(AuthFailed::class);
        $this->connector()->verify();
    }

    public function testInitialColdStartIsBoundedFlaggedAndCursorCoversWhatWasReturned(): void
    {
        $this->imap->add('INBOX', 5, ['date' => 'Mon, 01 Jan 2024 10:00:00 +0000']); // outside the 30-day window
        $this->imap->add('INBOX', 7, ['date' => gmdate('D, d M Y H:i:s +0000', time() - 86400)]);
        $this->imap->add('INBOX', 9, ['date' => gmdate('D, d M Y H:i:s +0000', time() - 3600), 'seen' => true, 'flags' => ['Seen'], 'has_attachments' => true]);

        $r = $this->connector()->fetchHeaders('INBOX', null, 50);
        $this->assertTrue($r->coldStart);
        $this->assertSame(FetchResult::REASON_INITIAL, $r->coldStartReason);
        $this->assertTrue($r->complete);
        $this->assertSame(['7:INBOX', '9:INBOX'], array_column($r->headers, 'provider_message_id'), 'oldest first, window-bounded');
        $this->assertSame(1000, $r->cursor->uidvalidity());
        $this->assertSame(10, $r->cursor->uidnext());
        $this->assertStringContainsString('uids:INBOX:-:' . gmdate('Y-m-d', time() - 30 * 86400), implode(' ', $this->imap->log));

        $h = $r->headers[1];
        $this->assertSame(HeaderRecord::KEYS, array_keys($h));
        $this->assertSame('s9@x.com', $h['from_addr']);
        $this->assertSame('Sender 9', $h['from_name']);
        $this->assertSame('m9@x', $h['message_id_header']);
        $this->assertSame('INBOX', $h['folder_at_fetch']);
        $this->assertTrue($h['was_read_at_fetch']);
        $this->assertTrue($h['has_attachments']);
        $this->assertSame(['Seen'], $h['labels']);
        $this->assertSame([['addr' => 'me@x.com', 'name' => '']], $h['to']);
        $this->assertNull($h['thread_id']);
    }

    public function testGmailThreadIdFromTheTransportIsPassedThrough(): void
    {
        $this->imap->add('INBOX', 3, ['thread_id' => '1872447076284731937']);
        $this->imap->add('INBOX', 4, ['thread_id' => '']);
        $r = $this->connector()->fetchHeaders('INBOX', null, 50);
        $this->assertSame('1872447076284731937', $r->headers[0]['thread_id']);
        $this->assertNull($r->headers[1]['thread_id'], 'an empty X-GM-THRID (non-Gmail server) stays null');
    }

    public function testColdStartTakesTheNewestMaxOfTheWindow(): void
    {
        foreach ([1, 2, 3, 4] as $u) $this->imap->add('INBOX', $u);
        $r = $this->connector()->fetchHeaders('INBOX', null, 2);
        $this->assertSame(['3:INBOX', '4:INBOX'], array_column($r->headers, 'provider_message_id'));
        $this->assertSame(5, $r->cursor->uidnext());
        $this->assertTrue($r->coldStart);
    }

    public function testIncrementalFetchAdvancesOnlyOverWhatWasReturned(): void
    {
        foreach ([10, 11, 12, 13, 14] as $u) $this->imap->add('INBOX', $u);
        $cursor = MailboxState::imap(1000, 12);

        $r = $this->connector()->fetchHeaders('INBOX', $cursor, 2);
        $this->assertFalse($r->coldStart);
        $this->assertFalse($r->complete);
        $this->assertSame(['12:INBOX', '13:INBOX'], array_column($r->headers, 'provider_message_id'));
        $this->assertSame(14, $r->cursor->uidnext());
        $this->assertStringContainsString('uids:INBOX:12:-', implode(' ', $this->imap->log));

        $r2 = $this->connector()->fetchHeaders('INBOX', $r->cursor, 2);
        $this->assertTrue($r2->complete);
        $this->assertSame(['14:INBOX'], array_column($r2->headers, 'provider_message_id'));
        $this->assertSame(15, $r2->cursor->uidnext());

        $r3 = $this->connector()->fetchHeaders('INBOX', $r2->cursor, 2);
        $this->assertSame([], $r3->headers);
        $this->assertTrue($r3->complete);
        $this->assertSame(15, $r3->cursor->uidnext(), 'an empty fetch never moves the cursor backwards');
    }

    public function testCursorNeverRegressesWhenServerUidnextIsAhead(): void
    {
        // Messages 20..22 existed and were deleted; server UIDNEXT is 23, nothing to fetch.
        $this->imap->add('INBOX', 22);
        unset($this->imap->store['INBOX'][22]);
        $this->imap->store['INBOX'][22] = null; unset($this->imap->store['INBOX'][22]);
        $r = $this->connector()->fetchHeaders('INBOX', MailboxState::imap(1000, 5), 10);
        $this->assertSame([], $r->headers);
        $this->assertSame(5, $r->cursor->uidnext());
    }

    public function testUidvalidityChangeIsALoudColdStart(): void
    {
        $this->imap->add('INBOX', 3);
        $this->imap->uidvalidity = 2000;
        $r = $this->connector()->fetchHeaders('INBOX', MailboxState::imap(1000, 99), 10);
        $this->assertTrue($r->coldStart);
        $this->assertSame(FetchResult::REASON_UIDVALIDITY_CHANGED, $r->coldStartReason);
        $this->assertSame(2000, $r->cursor->uidvalidity());
        $this->assertSame(['3:INBOX'], array_column($r->headers, 'provider_message_id'));
        $this->assertSame(4, $r->cursor->uidnext());
    }

    public function testMessagesVanishingBetweenSearchAndFetchAreSkipped(): void
    {
        $this->imap->add('INBOX', 1);
        $this->imap->add('INBOX', 2);
        $imap = $this->imap;
        $wrapped = new class ($imap) extends FakeImapTransport {
            public function __construct(private FakeImapTransport $inner) { }
            public function connect(): void { $this->inner->connect(); }
            public function status(string $f): array { return $this->inner->status($f); }
            public function uids(string $f, ?int $m, ?\DateTimeInterface $s): array { return [1, 2]; }
            public function headers(string $f, array $u): array { return $this->inner->headers($f, [2]); }
        };
        $c = new ImapConnector(['host' => 'h', 'username' => 'u', 'password' => 'p'], $wrapped);
        $r = $c->fetchHeaders('INBOX', MailboxState::imap(1000, 1), 10);
        $this->assertSame(['2:INBOX'], array_column($r->headers, 'provider_message_id'));
    }

    public function testProviderIdCarriesTheFolderAndDefaultsToConfigured(): void
    {
        $c = $this->connector();
        $this->assertSame([42, 'INBOX'], $c->parseId('42'));
        $this->assertSame([42, 'Archive/2026'], $c->parseId('42:Archive/2026'));
        $this->assertSame([7, 'A:B'], $c->parseId('7:A:B'));
        $this->expectException(\InvalidArgumentException::class);
        $c->parseId('abc');
    }

    public function testMoveReturnsTheNewProviderId(): void
    {
        $this->imap->add('INBOX', 5);
        $this->imap->add('Archive', 1);
        $new = $this->connector()->move('5:INBOX', 'Archive');
        $this->assertSame('2:Archive', $new);
        $this->assertArrayNotHasKey(5, $this->imap->store['INBOX']);
    }

    public function testTrashUsesConfiguredOrDetectedTrashFolderElseDeletes(): void
    {
        $this->imap->add('INBOX', 5);
        $this->assertSame('1:Trash', $this->connector()->trash('5'));

        $this->imap->add('INBOX', 6);
        $this->assertSame('1:Junk', $this->connector(['trash_folder' => 'Junk'])->trash('6'));

        unset($this->imap->store['Trash'], $this->imap->store['Junk']);
        $this->imap->add('INBOX', 7);
        $this->assertSame('7:INBOX', $this->connector()->trash('7:INBOX'));
        $this->assertArrayNotHasKey(7, $this->imap->store['INBOX']);
    }

    public function testMarkReadAndFetchBody(): void
    {
        $this->imap->add('INBOX', 5, ['raw' => "From: a@b\r\nSubject: hi\r\nContent-Type: text/plain\r\n\r\nhello body"]);
        $c = $this->connector();
        $c->markRead('5', true);
        $this->assertTrue($this->imap->store['INBOX'][5]['seen']);
        $body = $c->fetchBody('5:INBOX');
        $this->assertSame('hello body', $body->text);
        $this->assertSame('5:INBOX', $body->providerMessageId);
        $this->assertSame('hi', $body->headers['subject']);
    }

    public function testNormaliseDecodesEncodedSubjects(): void
    {
        $h = ImapConnector::normalise(['uid' => 1, 'subject' => '=?UTF-8?Q?Caf=C3=A9?=', 'from' => 'a@b'], 'INBOX');
        $this->assertSame('Café', $h['subject']);
        $this->assertSame('1:INBOX', $h['provider_message_id']);
    }
}
