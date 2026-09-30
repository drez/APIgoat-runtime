<?php

namespace ApiGoat\Tests\Mail;

use ApiGoat\Mail\FolderRole;
use PHPUnit\Framework\TestCase;

final class FolderRoleTest extends TestCase
{
    /** @return array<string,array<string,mixed>> */
    private static function byId(array $rows): array
    {
        return array_column($rows, null, 'id');
    }

    public function testRolesAreTheMailStoreEnumInOrder(): void
    {
        $this->assertSame(['Inbox', 'Archive', 'Trash', 'Spam', 'Sent', 'Drafts', 'Other'], FolderRole::ROLES);
    }

    public function testSpecialUseWinsOverNames(): void
    {
        $out = self::byId(FolderRole::assignImap([
            ['id' => 'INBOX', 'delimiter' => '.', 'attributes' => ['\\HasChildren']],
            ['id' => 'Sent', 'delimiter' => '.', 'attributes' => []],
            ['id' => 'Sent Items', 'delimiter' => '.', 'attributes' => ['\\Sent']],
            ['id' => 'Junk', 'delimiter' => '.', 'attributes' => ['\\Junk']],
            ['id' => 'Deleted Messages', 'delimiter' => '.', 'attributes' => ['\\Trash']],
            ['id' => 'Drafts', 'delimiter' => '.', 'attributes' => ['\\Drafts']],
            ['id' => 'Archive', 'delimiter' => '.', 'attributes' => ['\\Archive']],
        ]));

        $this->assertSame('Inbox', $out['INBOX']['role']);
        $this->assertSame('Sent', $out['Sent Items']['role']);
        $this->assertSame('special_use', $out['Sent Items']['role_source']);
        $this->assertSame('Other', $out['Sent']['role'], 'a name never competes with a declared special-use folder');
        $this->assertSame('Spam', $out['Junk']['role']);
        $this->assertSame('Trash', $out['Deleted Messages']['role']);
        $this->assertSame('Drafts', $out['Drafts']['role']);
        $this->assertSame('Archive', $out['Archive']['role']);
        foreach ($out as $id => $f) {
            $this->assertTrue($f['pollable'], $id);
        }
    }

    public function testNameFallbacksWhenTheServerDeclaresNothing(): void
    {
        $out = self::byId(FolderRole::assignImap([
            ['id' => 'INBOX', 'delimiter' => '.'],
            ['id' => 'INBOX.Sent', 'delimiter' => '.'],
            ['id' => 'INBOX.Trash', 'delimiter' => '.'],
            ['id' => 'INBOX.spam', 'delimiter' => '.'],
            ['id' => 'Éléments envoyés', 'delimiter' => '/'],
            ['id' => 'Corbeille', 'delimiter' => '/'],
            ['id' => 'ai.spam', 'delimiter' => '.'],
            ['id' => 'Clients.Sent', 'delimiter' => '.'],
        ]));

        $this->assertSame('Sent', $out['INBOX.Sent']['role']);
        $this->assertSame('name', $out['INBOX.Sent']['role_source']);
        $this->assertSame('Trash', $out['INBOX.Trash']['role']);
        $this->assertSame('Spam', $out['INBOX.spam']['role']);
        $this->assertSame('Sent', $out['Éléments envoyés']['role']);
        $this->assertSame('Trash', $out['Corbeille']['role']);
        $this->assertSame('Other', $out['ai.spam']['role'], 'a user folder under "ai" is not the junk folder');
        $this->assertSame('Other', $out['Clients.Sent']['role']);
        $this->assertTrue($out['ai.spam']['pollable'], 'an ordinary subscribed-or-unknown folder is polled');
    }

    public function testGmailOverImapPollsOnlyRealLocations(): void
    {
        $out = self::byId(FolderRole::assignImap([
            ['id' => 'INBOX', 'delimiter' => '/'],
            ['id' => '[Gmail]', 'delimiter' => '/', 'attributes' => ['\\Noselect', '\\HasChildren']],
            ['id' => '[Gmail]/All Mail', 'delimiter' => '/', 'attributes' => ['\\All', '\\HasNoChildren']],
            ['id' => '[Gmail]/Sent Mail', 'delimiter' => '/', 'attributes' => ['\\Sent']],
            ['id' => '[Gmail]/Spam', 'delimiter' => '/', 'attributes' => ['\\Junk']],
            ['id' => '[Gmail]/Trash', 'delimiter' => '/', 'attributes' => ['\\Trash']],
            ['id' => '[Gmail]/Drafts', 'delimiter' => '/', 'attributes' => ['\\Drafts']],
            ['id' => '[Gmail]/Starred', 'delimiter' => '/', 'attributes' => ['\\Flagged']],
            ['id' => '[Gmail]/Important', 'delimiter' => '/', 'attributes' => ['\\Important']],
            ['id' => 'Clients', 'delimiter' => '/'],
        ]));

        $this->assertSame('Archive', $out['[Gmail]/All Mail']['role'], 'the archive TARGET on Gmail');
        $this->assertFalse($out['[Gmail]/All Mail']['pollable']);
        $this->assertStringContainsString('virtual', (string) $out['[Gmail]/All Mail']['reason']);
        $this->assertFalse($out['[Gmail]']['pollable']);
        $this->assertSame('not selectable', $out['[Gmail]']['reason']);
        $this->assertFalse($out['[Gmail]/Starred']['pollable']);
        $this->assertFalse($out['[Gmail]/Important']['pollable']);
        $this->assertSame('Other', $out['Clients']['role']);
        $this->assertFalse($out['Clients']['pollable'], 'a label folder would duplicate INBOX mail');
        $this->assertStringContainsString('label', (string) $out['Clients']['reason']);
        foreach (['INBOX', '[Gmail]/Sent Mail', '[Gmail]/Spam', '[Gmail]/Trash', '[Gmail]/Drafts'] as $id) {
            $this->assertTrue($out[$id]['pollable'], $id);
        }
    }

    public function testGmailWithAllMailHiddenStillNeverDoubleIngests(): void
    {
        $out = self::byId(FolderRole::assignImap([
            ['id' => 'INBOX', 'delimiter' => '/'],
            ['id' => '[Gmail]', 'delimiter' => '/', 'attributes' => ['\\Noselect']],
            ['id' => '[Gmail]/All Mail', 'delimiter' => '/', 'attributes' => []],
            ['id' => '[Gmail]/Sent Mail', 'delimiter' => '/', 'attributes' => ['\\Sent']],
            ['id' => '[Gmail]/Drafts', 'delimiter' => '/', 'attributes' => ['\\Drafts']],
            ['id' => '[Gmail]/Trash', 'delimiter' => '/', 'attributes' => ['\\Trash']],
            ['id' => '[Gmail]/Spam', 'delimiter' => '/', 'attributes' => ['\\Junk']],
            ['id' => '[Gmail]/Starred', 'delimiter' => '/', 'attributes' => []],
            ['id' => '[Gmail]/Important', 'delimiter' => '/', 'attributes' => []],
            ['id' => 'Clients', 'delimiter' => '/', 'attributes' => []],
        ]));
        $this->assertOnlyRealLocationsPollable($out);
    }

    public function testGmailListingWithNoAttributesAtAll(): void
    {
        $out = self::byId(FolderRole::assignImap([
            ['id' => 'INBOX', 'delimiter' => '/'],
            ['id' => '[Google Mail]/All Mail', 'delimiter' => '/'],
            ['id' => '[Google Mail]/Sent Mail', 'delimiter' => '/'],
            ['id' => '[Google Mail]/Drafts', 'delimiter' => '/'],
            ['id' => '[Google Mail]/Trash', 'delimiter' => '/'],
            ['id' => '[Google Mail]/Spam', 'delimiter' => '/'],
            ['id' => '[Google Mail]/Starred', 'delimiter' => '/'],
            ['id' => '[Google Mail]/Important', 'delimiter' => '/'],
            ['id' => 'Clients', 'delimiter' => '/'],
        ]));
        $this->assertOnlyRealLocationsPollable($out, '[Google Mail]');
    }

    /** @param array<string,array<string,mixed>> $out */
    private function assertOnlyRealLocationsPollable(array $out, string $root = '[Gmail]'): void
    {
        foreach (['INBOX', "$root/Sent Mail", "$root/Drafts", "$root/Trash", "$root/Spam"] as $id) {
            $this->assertTrue($out[$id]['pollable'], $id);
        }
        $this->assertSame('Archive', $out["$root/All Mail"]['role']);
        foreach (["$root/All Mail", "$root/Starred", "$root/Important", 'Clients'] as $id) {
            $this->assertFalse($out[$id]['pollable'], $id);
        }
    }

    public function testNonExistentAndNoselectRoleFoldersAreNotPolled(): void
    {
        $out = self::byId(FolderRole::assignImap([
            ['id' => 'Ghost', 'attributes' => ['\\NonExistent']],
            ['id' => 'Trash', 'attributes' => ['\\Trash', '\\Noselect']],
        ]));
        $this->assertFalse($out['Ghost']['pollable']);
        $this->assertSame('not selectable', $out['Ghost']['reason']);
        $this->assertSame('Trash', $out['Trash']['role']);
        $this->assertFalse($out['Trash']['pollable']);
    }

    public function testAnUnsubscribedOtherFolderIsNotPolled(): void
    {
        $out = self::byId(FolderRole::assignImap([
            ['id' => 'INBOX', 'subscribed' => true],
            ['id' => 'Old', 'subscribed' => false],
            ['id' => 'Unknown'],
            ['id' => 'Junk', 'subscribed' => false],
        ]));
        $this->assertFalse($out['Old']['pollable']);
        $this->assertSame('not subscribed', $out['Old']['reason']);
        $this->assertTrue($out['Unknown']['pollable'], 'unknown subscription is not "unsubscribed"');
        $this->assertTrue($out['Junk']['pollable'], 'a role folder is polled whatever its subscription');
        $this->assertNull($out['Unknown']['subscribed']);
    }

    public function testAttributesAreCaseAndBackslashInsensitive(): void
    {
        $out = self::byId(FolderRole::assignImap([['id' => 'X', 'attributes' => ['\\JUNK']], ['id' => 'Y', 'attributes' => ['sent']]]));
        $this->assertSame('Spam', $out['X']['role']);
        $this->assertSame('Sent', $out['Y']['role']);
        $this->assertSame(['junk'], $out['X']['attributes']);
    }

    public function testFromName(): void
    {
        $this->assertSame('Inbox', FolderRole::fromName('inbox'));
        $this->assertSame('Sent', FolderRole::fromName('[Gmail]/Sent Mail'));
        $this->assertSame('Trash', FolderRole::fromName('[Google Mail]/Bin'));
        $this->assertSame('Spam', FolderRole::fromName('INBOX/SPAM'));
        $this->assertSame('Other', FolderRole::fromName('INBOX/NEWSLETTERS'));
        $this->assertSame('Other', FolderRole::fromName('ai.newsletter'));
        $this->assertSame('Other', FolderRole::fromName('a.b.Sent'));
        $this->assertSame('Drafts', FolderRole::fromName('Brouillons'));
        $this->assertSame('Other', FolderRole::fromName(''));
        $this->assertSame('Other', FolderRole::fromName('Sent.2019', '/'), 'the server delimiter decides what a segment is: "." is not split under "/"');
    }

    public function testGmailLabels(): void
    {
        $this->assertSame(['role' => 'Inbox', 'role_source' => 'provider', 'pollable' => true, 'reason' => null], FolderRole::fromGmailLabel('INBOX', 'system'));
        $this->assertSame('Sent', FolderRole::fromGmailLabel('SENT', 'system')['role']);
        $this->assertSame('Drafts', FolderRole::fromGmailLabel('DRAFT', 'system')['role']);
        $this->assertSame('Spam', FolderRole::fromGmailLabel('SPAM', 'system')['role']);
        $this->assertSame('Trash', FolderRole::fromGmailLabel('TRASH', 'system')['role']);
        foreach (['STARRED', 'UNREAD', 'IMPORTANT', 'CATEGORY_PROMOTIONS', 'CHAT'] as $id) {
            $r = FolderRole::fromGmailLabel($id, 'system');
            $this->assertSame('Other', $r['role'], $id);
            $this->assertFalse($r['pollable'], $id);
            $this->assertSame('not a location', $r['reason'], $id);
        }
        $user = FolderRole::fromGmailLabel('Label_12', 'user');
        $this->assertSame('Other', $user['role']);
        $this->assertFalse($user['pollable']);
        $this->assertStringContainsString('label', (string) $user['reason']);
    }
}
