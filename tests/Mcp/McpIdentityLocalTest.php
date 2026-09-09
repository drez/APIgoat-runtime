<?php
// The session-learned overlay (config/mcp.identity.local.php): merge rules,
// keyword normalization, read/write round-trip, and the learn sentence the
// preamble gains when gc_identity_update is available.
namespace ApiGoat\Tests\Mcp;

use ApiGoat\Mcp\McpIdentity;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Mcp/McpIdentity.php';

final class McpIdentityLocalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mil-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config/Built', 0775, true);
        file_put_contents($this->dir . '/' . McpIdentity::FILE, "<?php return " . var_export([
            'name' => 'LL-TEQ CRM', 'about' => 'built about', 'keywords' => ['ll-teq', 'quote'], 'missing' => [],
        ], true) . ";");
    }

    protected function tearDown(): void
    {
        foreach ([McpIdentity::LOCAL_FILE, McpIdentity::FILE] as $f) {
            @unlink($this->dir . '/' . $f);
        }
        @rmdir($this->dir . '/config/Built');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function testKeywordListNormalizes(): void
    {
        $this->assertSame(['a', 'b'], McpIdentity::keywordList('A, b ,,a'));
        $this->assertSame(['ll-teq', 'x'], McpIdentity::keywordList(['LL-TEQ', " x\n", '', 'll-teq']));
        $this->assertSame([], McpIdentity::keywordList(null));
    }

    public function testMergeUnionsAndSubtracts(): void
    {
        $built = ['name' => 'N', 'about' => 'built', 'keywords' => ['a', 'b'], 'missing' => []];
        $this->assertSame($built, McpIdentity::merge($built, null));
        $m = McpIdentity::merge($built, ['about' => '', 'keywords' => ['C', 'a'], 'removed' => ['b']]);
        $this->assertSame(['a', 'c'], $m['keywords']);
        $this->assertSame('built', $m['about']);
        $m = McpIdentity::merge($built, ['about' => 'local', 'keywords' => [], 'removed' => []]);
        $this->assertSame('local', $m['about']);
    }

    public function testMergeClearsMissingWhenOverlaySupplies(): void
    {
        $built = ['name' => 'N', 'about' => '', 'keywords' => [], 'missing' => ['about', 'keywords']];
        $m = McpIdentity::merge($built, ['about' => 'x', 'keywords' => ['k'], 'removed' => []]);
        $this->assertSame([], $m['missing']);
        $m = McpIdentity::merge($built, ['about' => '', 'keywords' => ['k'], 'removed' => []]);
        $this->assertSame(['about'], $m['missing']);
    }

    public function testWriteReadRoundTripAndReadMerges(): void
    {
        $this->assertNull(McpIdentity::readLocal($this->dir));
        $path = McpIdentity::writeLocal(['about' => " New\nabout ", 'keywords' => ['Landlock', 'll-teq'], 'removed' => ['quote']], $this->dir);
        $this->assertSame($this->dir . '/' . McpIdentity::LOCAL_FILE, $path);
        $this->assertSame(
            ['about' => 'New about', 'keywords' => ['landlock', 'll-teq'], 'removed' => ['quote']],
            McpIdentity::readLocal($this->dir)
        );
        $eff = McpIdentity::read($this->dir);
        $this->assertSame('New about', $eff['about']);
        $this->assertSame(['ll-teq', 'landlock'], $eff['keywords']);
        $this->assertSame('built about', McpIdentity::readBuilt($this->dir)['about']);
        // Empty overlay removes the file.
        McpIdentity::writeLocal(['about' => '', 'keywords' => [], 'removed' => []], $this->dir);
        $this->assertFileDoesNotExist($path);
        $this->assertSame(['ll-teq', 'quote'], McpIdentity::read($this->dir)['keywords']);
    }

    public function testPreambleLearnSentence(): void
    {
        $id = McpIdentity::read($this->dir);
        $plain = McpIdentity::preamble($id);
        $learn = McpIdentity::preamble($id, true);
        $this->assertStringNotContainsString('gc_identity_update', $plain);
        $this->assertStringStartsWith($plain, $learn);
        $this->assertStringContainsString('call gc_identity_update to add the term', $learn);
        $this->assertStringContainsString('confirm:true once they approve', $learn);
    }
}
