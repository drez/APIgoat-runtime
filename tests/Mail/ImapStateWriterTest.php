<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeImapTransport.php';

use ApiGoat\Mail\Connector\ImapConnector;
use ApiGoat\Mail\StateWriter;
use ApiGoat\Mail\UnsupportedOperation;
use PHPUnit\Framework\TestCase;

final class ImapStateWriterTest extends TestCase
{
    private FakeImapTransport $imap;

    protected function setUp(): void
    {
        $this->imap = new FakeImapTransport();
        $this->imap->store = ['INBOX' => [], 'Archive' => [], 'Trash' => []];
    }

    private function connector(): ImapConnector
    {
        return new ImapConnector(['host' => 'h', 'username' => 'u', 'password' => 'p', 'folder' => 'INBOX'], $this->imap);
    }

    /** Gmail over IMAP: INBOX + [Gmail]/… with the special-use attributes Gmail sends ($attrs=false: none, like an old listing). */
    private function gmailServer(bool $attrs = true): void
    {
        $this->imap->store = ['INBOX' => [], '[Gmail]' => [], '[Gmail]/All Mail' => [], '[Gmail]/Trash' => [], '[Gmail]/Spam' => [], 'Clients' => []];
        $this->imap->attributes = $attrs
            ? ['[Gmail]' => ['\\Noselect'], '[Gmail]/All Mail' => ['\\All'], '[Gmail]/Trash' => ['\\Trash'], '[Gmail]/Spam' => ['\\Junk']]
            : [];
    }

    private function movesAndCopies(): array
    {
        return array_values(array_filter($this->imap->log, static fn ($l) => preg_match('/^(move|copy|delete):/', $l) === 1));
    }

    public function testItIsAStateWriter(): void
    {
        $this->assertInstanceOf(StateWriter::class, $this->connector());
    }

    public function testMessageStateReadsSeenAndFlaggedFromTheIdsFolder(): void
    {
        $this->imap->add('INBOX', 7, ['seen' => true, 'flags' => ['Flagged']]);
        $s = $this->connector()->messageState('7:INBOX');
        $this->assertNotNull($s);
        $this->assertTrue($s->seen);
        $this->assertTrue($s->flagged);
        $this->assertSame('INBOX', $s->folder);
        $this->assertNull($s->role, 'IMAP never knows a role: the caller\'s folder registry does');
    }

    public function testMessageStateAcceptsBackslashedFlags(): void
    {
        $this->imap->add('INBOX', 7, ['flags' => ['\\Seen', '\\Flagged']]);
        $s = $this->connector()->messageState('7:INBOX');
        $this->assertTrue($s->seen);
        $this->assertTrue($s->flagged);
    }

    public function testMessageStateIsNullWhenTheUidLeftTheFolder(): void
    {
        $this->assertNull($this->connector()->messageState('99:INBOX'));
    }

    public function testSetFlagTogglesFlaggedAndLeavesSeenAlone(): void
    {
        $this->imap->add('INBOX', 7, ['seen' => false]);
        $c = $this->connector();
        $c->setFlag('7:INBOX', true);
        $this->assertTrue($c->messageState('7:INBOX')->flagged);
        $this->assertFalse($c->messageState('7:INBOX')->seen);
        $c->setFlag('7:INBOX', false);
        $this->assertFalse($c->messageState('7:INBOX')->flagged);
    }

    public function testArchiveMovesToTheGivenFolderAndReturnsTheNewId(): void
    {
        $this->imap->add('INBOX', 7);
        $this->assertSame('1:Archive', $this->connector()->archive('7:INBOX', 'Archive'));
        $this->assertArrayNotHasKey(7, $this->imap->store['INBOX']);
    }

    public function testArchiveWithoutAFolderIsUnsupported(): void
    {
        $this->imap->add('INBOX', 7);
        $this->expectException(UnsupportedOperation::class);
        $this->connector()->archive('7:INBOX');
    }

    public function testArchiveWithoutAFolderUsesTheServersArchiveOverAll(): void
    {
        $this->imap->store = ['INBOX' => [], 'Archive' => [], 'Everything' => []];
        $this->imap->attributes = ['Everything' => ['\\All'], 'Archive' => ['\\Archive']];
        $this->imap->add('INBOX', 7);
        $this->assertSame('1:Archive', $this->connector()->archive('7:INBOX'));
    }

    public function testUntrashMovesBackToTheConfiguredFolderByDefault(): void
    {
        $this->imap->add('Trash', 3);
        $this->assertSame('1:INBOX', $this->connector()->untrash('3:Trash'));
    }

    public function testUntrashMovesToTheGivenFolderAndNeverDeletes(): void
    {
        $this->imap->store = ['INBOX' => [], 'Archive' => []]; // no Trash: trash() would hard-delete
        $this->imap->add('Archive', 3);
        $this->assertSame('1:INBOX', $this->connector()->untrash('3:Archive', 'INBOX'));
        $this->assertSame(['move:Archive:3:INBOX'], $this->movesAndCopies());
    }

    public function testMovesWithoutCopyUidReturnAnEmptyIdNeverAGuess(): void
    {
        $this->imap->reportMoveUid = false;
        $this->imap->add('INBOX', 7);
        $this->imap->add('Trash', 3);
        $c = $this->connector();
        $this->assertSame('', $c->archive('7:INBOX', 'Archive'));
        $this->assertSame('', $c->untrash('3:Trash'));
    }

    public function testEveryMethodRefusesAnUnresolvedIdBeforeAnyServerCall(): void
    {
        $c = $this->connector();
        $calls = [
            'messageState' => fn () => $c->messageState('unresolved:42'),
            'setFlag'      => fn () => $c->setFlag('unresolved:42', true),
            'archive'      => fn () => $c->archive('unresolved:42', 'Archive'),
            'untrash'      => fn () => $c->untrash('unresolved:42', 'INBOX'),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("{$name} accepted an unresolved id");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('unresolved', $e->getMessage(), $name);
            }
        }
        $this->assertSame([], $this->imap->log, 'no server call at all, not even connect');
    }

    // ---- Gmail over IMAP (a label server) -------------------------------

    public function testGmailArchiveIsAMoveFromInboxToAllMail(): void
    {
        $this->gmailServer();
        $this->imap->add('INBOX', 7);
        $this->assertSame('1:[Gmail]/All Mail', $this->connector()->archive('7:INBOX', '[Gmail]/All Mail'));
        $this->assertSame(['move:INBOX:7:[Gmail]/All Mail'], $this->movesAndCopies());
    }

    public function testGmailArchiveWithoutAFolderResolvesAllMail(): void
    {
        $this->gmailServer();
        $this->imap->add('INBOX', 7);
        $this->assertSame('1:[Gmail]/All Mail', $this->connector()->archive('7:INBOX'));
    }

    public function testGmailArchiveOfAMessageAlreadyInAllMailTouchesNothing(): void
    {
        $this->gmailServer();
        $this->imap->add('[Gmail]/All Mail', 5);
        $this->assertSame('5:[Gmail]/All Mail', $this->connector()->archive('5:[Gmail]/All Mail', '[Gmail]/All Mail'));
        $this->assertSame([], $this->movesAndCopies());
    }

    public function testGmailLeavingAllMailIsACopyToInboxNeverAMove(): void
    {
        $this->gmailServer();
        $this->imap->add('[Gmail]/All Mail', 5);
        $this->assertSame('1:INBOX', $this->connector()->untrash('5:[Gmail]/All Mail', 'INBOX'));
        $this->assertSame(['copy:[Gmail]/All Mail:5:INBOX'], $this->movesAndCopies(), 'an expunge from All Mail may DELETE on Gmail');
        $this->assertArrayHasKey(5, $this->imap->store['[Gmail]/All Mail'], 'still in All Mail: it only gained the Inbox label');
    }

    public function testGmailIsDetectedByItsRootWithoutSpecialUseAttributes(): void
    {
        $this->gmailServer(false);
        $this->imap->add('[Gmail]/All Mail', 5);
        $this->imap->reportMoveUid = false;
        $this->assertSame('', $this->connector()->untrash('5:[Gmail]/All Mail'), 'no COPYUID: unknown, never a guess');
        $this->assertSame(['copy:[Gmail]/All Mail:5:INBOX'], $this->movesAndCopies());
    }

    public function testGmailUntrashFromTrashIsAMove(): void
    {
        $this->gmailServer();
        $this->imap->add('[Gmail]/Trash', 3);
        $this->assertSame('1:INBOX', $this->connector()->untrash('3:[Gmail]/Trash'));
        $this->assertSame(['move:[Gmail]/Trash:3:INBOX'], $this->movesAndCopies());
    }

    public function testANonGmailAllFolderIsLeftByAMove(): void
    {
        $this->imap->store = ['INBOX' => [], 'Everything' => []];
        $this->imap->attributes = ['Everything' => ['\\Archive']];
        $this->imap->add('Everything', 5);
        $this->connector()->untrash('5:Everything');
        $this->assertSame(['move:Everything:5:INBOX'], $this->movesAndCopies());
    }
}
