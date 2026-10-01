<?php
// .admin/vendor/apigoat/runtime/tests/Mail/ImapDraftStoreTest.php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeImapTransport.php';

use ApiGoat\Mail\Connector\ImapConnector;
use ApiGoat\Mail\DraftStore;
use ApiGoat\Mail\UnsupportedOperation;
use PHPUnit\Framework\TestCase;

/**
 * The ONLY deletion sub-project B performs: one of our own draft versions,
 * one UID, inside the Drafts folder, UID EXPUNGE (never a folder-wide EXPUNGE).
 */
final class ImapDraftStoreTest extends TestCase
{
    private FakeImapTransport $imap;

    protected function setUp(): void
    {
        $this->imap = new FakeImapTransport();
        $this->imap->store = ['INBOX' => [], 'Drafts' => [], 'Sent' => []];
        // Any removal other than expungeUid() (plain delete, move) throws.
        $this->imap->onlyUidExpunge = true;
    }

    private function c(): ImapConnector
    {
        return new ImapConnector(['host' => 'h', 'username' => 'u', 'password' => 'p', 'folder' => 'INBOX'], $this->imap);
    }

    private function writes(): array
    {
        return array_values(array_filter($this->imap->log, static fn ($l) => preg_match('/^(append|appendf|expunge|delete|move|copy|seen|flagged):/', $l) === 1));
    }

    public function testTheConnectorIsADraftStore(): void
    {
        $this->assertInstanceOf(DraftStore::class, $this->c());
    }

    public function testAppendDraftFlagsDraftAndSeenAndReturnsTheNewId(): void
    {
        $id = $this->c()->appendDraft('Drafts', "Message-ID: <d1@draft.apigmail.invalid>\r\n\r\nhi");
        $this->assertSame('1:Drafts', $id);
        $this->assertSame(['\\Seen', '\\Draft'], $this->imap->store['Drafts'][1]['flags']);
        $this->assertSame(['appendf:Drafts:\\Seen \\Draft'], $this->writes());
    }

    public function testAppendDraftWithoutAppendUidReturnsEmpty(): void
    {
        $this->imap->reportAppendUid = false;
        $this->assertSame('', $this->c()->appendDraft('Drafts', 'raw'));
    }

    public function testDeleteDraftExpungesThatOneUidOnly(): void
    {
        $this->imap->add('Drafts', 1, ['flags' => ['Deleted']]);   // another client's \Deleted message
        $this->imap->add('Drafts', 2);
        $this->c()->deleteDraft('2:Drafts', 'Drafts', '<m2@x>');
        $this->assertSame(['expunge:Drafts:2'], $this->writes());
        $this->assertArrayHasKey(1, $this->imap->store['Drafts'], 'a folder-wide EXPUNGE would have removed it');
        $this->assertArrayNotHasKey(2, $this->imap->store['Drafts']);
    }

    public function testDeleteDraftRefusesAnIdOutsideTheDraftsFolderBeforeAnyServerCall(): void
    {
        $this->imap->add('INBOX', 5);
        foreach ([['5:INBOX', 'Drafts'], ['5:Drafts', ''], ['5:drafts', 'Drafts'], ['5', 'Drafts']] as [$id, $folder]) {
            try {
                $this->c()->deleteDraft($id, $folder, '<m5@x>');
                $this->fail("deleteDraft({$id}, {$folder}) must be refused");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->imap->log, 'refused before connect');
        $this->assertArrayHasKey(5, $this->imap->store['INBOX']);
    }

    public function testDeleteDraftWithoutUidplusDeletesNothing(): void
    {
        $this->imap->uidPlus = false;
        $this->imap->add('Drafts', 2);
        $this->expectException(UnsupportedOperation::class);
        try {
            $this->c()->deleteDraft('2:Drafts', 'Drafts', '<m2@x>');
        } finally {
            $this->assertSame([], $this->writes());
            $this->assertArrayHasKey(2, $this->imap->store['Drafts']);
        }
    }

    public function testDeleteDraftRefusesAnUnresolvedId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->c()->deleteDraft('unresolved:12', 'Drafts', '<m12@x>');
    }

    public function testFindByMessageIdSearchesTheHeaderAndReturnsTheNewestUid(): void
    {
        $this->imap->add('Sent', 3, ['message_id' => '<abc@fx.example>']);
        $this->imap->add('Sent', 7, ['message_id' => '<abc@fx.example>']);
        $this->imap->add('Sent', 8, ['message_id' => '<zzz@fx.example>']);
        $this->assertSame('7:Sent', $this->c()->findByMessageId('Sent', 'abc@fx.example'));
        $this->assertSame('7:Sent', $this->c()->findByMessageId('Sent', '<abc@fx.example>'));
        $this->assertNull($this->c()->findByMessageId('Sent', '<none@fx.example>'));
        $this->assertContains('search:Sent:Message-ID:<abc@fx.example>', $this->imap->log);
    }

    public function testFindByMessageIdRefusesAQuoteOrANewline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->c()->findByMessageId('Sent', "<a\"b@x>\r\nX");
    }

    public function testDeleteDraftRefusesUidZeroAndANestedLookalikeFolder(): void
    {
        $this->imap->add('Drafts', 2);
        $this->imap->add('Drafts/Old', 2);
        foreach ([['0:Drafts', 'Drafts'], ['2:Drafts/Old', 'Drafts'], ['2:Drafts', 'Drafts/'], ['2: Drafts', 'Drafts']] as [$id, $folder]) {
            try {
                $this->c()->deleteDraft($id, $folder, '<m2@x>');
                $this->fail("deleteDraft({$id}, {$folder}) must be refused");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->imap->log, 'refused before connect');
    }

    public function testDeleteDraftOfAGoneUidIsTransient404AndDeletesNothingElse(): void
    {
        $this->imap->add('Drafts', 1, ['flags' => ['Deleted']]);
        try {
            $this->c()->deleteDraft('9:Drafts', 'Drafts', '<m9@x>');
            $this->fail('a gone uid must throw');
        } catch (\ApiGoat\Sync\Exceptions\TransientError $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertSame([], $this->writes());
        $this->assertArrayHasKey(1, $this->imap->store['Drafts']);
    }

    public function testAWholeDraftCycleNeverTouchesAnotherMessage(): void
    {
        $this->imap->add('Drafts', 1, ['flags' => ['Deleted']]);
        $this->imap->add('Drafts', 2);
        $this->imap->add('INBOX', 2);
        $c  = $this->c();
        $v1 = $c->appendDraft('Drafts', "Message-ID: <draft.1.1.ab@draft.apigmail.invalid>\r\n\r\nv1");
        $v2 = $c->appendDraft('Drafts', "Message-ID: <draft.1.2.cd@draft.apigmail.invalid>\r\n\r\nv2");
        $c->deleteDraft($v1, 'Drafts', '<draft.1.1.ab@draft.apigmail.invalid>');
        $c->deleteDraft($v2, 'Drafts', 'draft.1.2.cd@draft.apigmail.invalid');
        $this->assertSame(['appendf:Drafts:\\Seen \\Draft', 'appendf:Drafts:\\Seen \\Draft', 'expunge:Drafts:3', 'expunge:Drafts:4'], $this->writes());
        $this->assertSame([1, 2], array_keys($this->imap->store['Drafts']));
        $this->assertSame([2], array_keys($this->imap->store['INBOX']));
    }

    public function testATransportWithoutDraftPrimitivesIsUnsupported(): void
    {
        $t = $this->createMock(\ApiGoat\Mail\Imap\ImapTransport::class);
        $t->expects($this->never())->method('delete');
        $c = new ImapConnector(['host' => 'h', 'username' => 'u', 'password' => 'p', 'folder' => 'INBOX'], $t);
        $this->expectException(UnsupportedOperation::class);
        $c->deleteDraft('2:Drafts', 'Drafts', '<m2@x>');
    }

    public function testDeleteDraftRefusesAUidThatHoldsAForeignMessageId(): void
    {
        // UIDVALIDITY changed: uid 2 is now another client's draft.
        $this->imap->add('Drafts', 2, ['message_id' => '<theirs@other.client>']);
        try {
            $this->c()->deleteDraft('2:Drafts', 'Drafts', '<draft.7.3.ef@draft.apigmail.invalid>');
            $this->fail('a foreign Message-ID must be refused');
        } catch (\ApiGoat\Sync\Exceptions\ValidationRejected $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('no longer holds our draft', $e->getMessage());
        }
        $this->assertSame([], $this->writes(), 'no STORE / EXPUNGE');
        $this->assertArrayHasKey(2, $this->imap->store['Drafts']);
    }

    public function testDeleteDraftWithTheMatchingMessageIdDeletesIt(): void
    {
        $this->imap->add('Drafts', 2, ['message_id' => '<draft.7.3.ef@draft.apigmail.invalid>']);
        $this->c()->deleteDraft('2:Drafts', 'Drafts', 'draft.7.3.ef@draft.apigmail.invalid');
        $this->assertSame(['select:Drafts', 'fetchheader:Drafts:2', 'expunge:Drafts:2'], array_values(array_filter(
            $this->imap->log, static fn ($l) => preg_match('/^(select|fetchheader|expunge):/', $l) === 1)));
        $this->assertArrayNotHasKey(2, $this->imap->store['Drafts']);
    }

    public function testDeleteDraftInAReadOnlyFolderDeletesNothing(): void
    {
        $this->imap->readOnlyFolders = ['Drafts'];
        $this->imap->add('Drafts', 2);
        $this->expectException(\ApiGoat\Sync\Exceptions\ValidationRejected::class);
        try {
            $this->c()->deleteDraft('2:Drafts', 'Drafts', '<m2@x>');
        } finally {
            $this->assertSame([], $this->writes());
            $this->assertArrayHasKey(2, $this->imap->store['Drafts']);
        }
    }

    public function testDeleteDraftRefusesAnUnusableExpectedMessageIdBeforeAnyServerCall(): void
    {
        $this->imap->add('Drafts', 2);
        foreach (['', '<>', "<a\r\nb@x>", "<a\x00b@x>", "<é@x>", '<a"b@x>', '<a b@x>', '<<a@x>>'] as $mid) {
            try {
                $this->c()->deleteDraft('2:Drafts', 'Drafts', $mid);
                $this->fail('expected Message-ID ' . json_encode($mid) . ' must be refused');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->imap->log);
    }

    public function testFindByMessageIdRefusesAnEmptyFolderAndNonAsciiIds(): void
    {
        foreach ([['', '<a@x>'], ['Sent', "<\x01a@x>"], ['Sent', "<\xC3\xA9@x>"], ['Sent', '']] as [$folder, $mid]) {
            try {
                $this->c()->findByMessageId($folder, $mid);
                $this->fail('findByMessageId(' . json_encode($folder) . ') must be refused');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->imap->log, 'no silent INBOX search');
    }
}
