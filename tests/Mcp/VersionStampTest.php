<?php
namespace ApiGoat\Tests\Mcp;

use ApiGoat\Mcp\VersionStamp;
use PHPUnit\Framework\TestCase;

final class VersionStampTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vs-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config/Built', 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/' . VersionStamp::FILE);
        @rmdir($this->dir . '/config/Built'); @rmdir($this->dir . '/config'); @rmdir($this->dir);
    }

    public function test_first_stamp_announces_nothing_and_is_stable(): void
    {
        $this->assertNull(VersionStamp::read($this->dir));
        $r = VersionStamp::write($this->dir, ['crm_list', 'crmx_find', 'crm_get'], '2026-08-27 09:00');
        $this->assertTrue($r['changed']);
        $this->assertSame(['crm_get', 'crm_list', 'crmx_find'], $r['stamp']['tools'], 'sorted, deduped');
        $this->assertSame([], $r['stamp']['added']);
        $this->assertSame([], $r['stamp']['removed']);
        $this->assertNull($r['stamp']['previous_version']);
        $this->assertMatchesRegularExpression('/^2026\.08\.27-[0-9a-f]{6}$/', $r['stamp']['version']);
        $this->assertNull(VersionStamp::whatsNew($r['stamp']));

        // Same list again (any order) → untouched stamp, no rewrite.
        $again = VersionStamp::write($this->dir, ['crmx_find', 'crm_get', 'crm_list'], '2026-09-01 10:00');
        $this->assertFalse($again['changed']);
        $this->assertSame($r['stamp'], $again['stamp']);
        $this->assertSame($r['stamp'], VersionStamp::read($this->dir));
    }

    public function test_changed_list_records_added_removed_and_whats_new(): void
    {
        VersionStamp::write($this->dir, ['crm_list', 'crm_get', 'old_tool'], '2026-08-27 09:00');
        $r = VersionStamp::write($this->dir, ['crm_list', 'crm_get', 'crmx_campaign_send', 'crmx_create_campaign'], '2026-08-28 11:30');
        $this->assertTrue($r['changed']);
        $this->assertSame(['crmx_campaign_send', 'crmx_create_campaign'], $r['stamp']['added']);
        $this->assertSame(['old_tool'], $r['stamp']['removed']);
        $this->assertNotNull($r['stamp']['previous_version']);
        $this->assertNotSame($r['stamp']['previous_version'], $r['stamp']['version']);

        $msg = VersionStamp::whatsNew(VersionStamp::read($this->dir));
        $this->assertStringContainsString('SERVER UPDATE (2026-08-28 11:30', $msg);
        $this->assertStringContainsString('new tools: crmx_campaign_send, crmx_create_campaign', $msg);
        $this->assertStringContainsString('removed tools: old_tool', $msg);

        // An unchanged rebuild keeps the notice (until the list changes again).
        VersionStamp::write($this->dir, ['crmx_create_campaign', 'crm_get', 'crm_list', 'crmx_campaign_send'], '2026-09-02 08:00');
        $this->assertSame(['crmx_campaign_send', 'crmx_create_campaign'], VersionStamp::read($this->dir)['added']);
    }
}
