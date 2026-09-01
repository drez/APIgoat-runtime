<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\MailboxState;
use PHPUnit\Framework\TestCase;

final class MailboxStateTest extends TestCase
{
    public function testImapRoundTrip(): void
    {
        $s = MailboxState::imap(1234, 567, 'INBOX');
        $json = $s->toJson();
        $this->assertSame('{"uidvalidity":1234,"uidnext":567,"folder":"INBOX"}', $json);
        $back = MailboxState::fromJson($json);
        $this->assertSame(1234, $back->uidvalidity());
        $this->assertSame(567, $back->uidnext());
        $this->assertNull($back->historyId());
    }

    public function testGmailRoundTripKeepsPageTokenAndExtras(): void
    {
        $s = MailboxState::gmail('99881', 'tok')->with('cold_start', 'initial');
        $back = MailboxState::fromJson($s->toJson());
        $this->assertSame('99881', $back->historyId());
        $this->assertSame('tok', $back->pageToken());
        $this->assertSame('initial', $back->get('cold_start'));
        $this->assertNull($back->uidnext());
        $this->assertNull(MailboxState::gmail('1')->pageToken());
        $this->assertNull($back->with('page_token', null)->pageToken());
    }

    public function testEmptyOrGarbageJsonIsNoCursor(): void
    {
        $this->assertNull(MailboxState::fromJson(null));
        $this->assertNull(MailboxState::fromJson(''));
        $this->assertNull(MailboxState::fromJson('{}'));
        $this->assertNull(MailboxState::fromJson('not json'));
        $this->assertNull(MailboxState::fromArray([]));
    }

    public function testJsonSerializable(): void
    {
        $this->assertSame('{"history_id":"5"}', json_encode(MailboxState::gmail('5')));
    }
}
