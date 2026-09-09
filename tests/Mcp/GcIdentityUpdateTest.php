<?php
// gc_identity_update: validation, the confirm gate (echo without writing),
// and the overlay write — driven through handle() with a stub session.
namespace ApiGoat\Sessions { if (!class_exists(AuthySession::class, false)) { class AuthySession { public function isAdmin() { return true; } public function hasRights($m = '', $r = '') { return true; } } } }

namespace ApiGoat\Tests\Mcp {

use ApiGoat\Mcp\McpIdentity;
use ApiGoat\Mcp\ToolError;
use ApiGoat\Mcp\Tools\GcIdentityUpdate;
use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Mcp/McpTool.php';
require_once __DIR__ . '/../../src/Mcp/ToolError.php';
require_once __DIR__ . '/../../src/Mcp/McpIdentity.php';
require_once __DIR__ . '/../../src/Mcp/Tools/GcIdentityUpdate.php';

final class GcIdentityUpdateTest extends TestCase
{
    private static function baseDir(): string
    {
        if (!defined('_BASE_DIR')) {
            $dir = sys_get_temp_dir() . '/giu-' . bin2hex(random_bytes(4)) . '/';
            mkdir($dir . 'config/Built', 0775, true);
            define('_BASE_DIR', $dir);
        }
        $base = rtrim((string) _BASE_DIR, '/\\') . '/';
        if (!is_dir($base . 'config/Built')) {
            @mkdir($base . 'config/Built', 0775, true);
        }
        return $base;
    }

    protected function setUp(): void
    {
        $base = self::baseDir();
        if (!is_writable($base . 'config/Built')) {
            $this->markTestSkipped('_BASE_DIR/config/Built is not writable');
        }
        @unlink($base . McpIdentity::LOCAL_FILE);
        file_put_contents($base . McpIdentity::FILE, "<?php return " . var_export([
            'name' => 'LL-TEQ CRM', 'about' => 'built about', 'keywords' => ['ll-teq'], 'missing' => [],
        ], true) . ";");
    }

    protected function tearDown(): void
    {
        @unlink(self::baseDir() . McpIdentity::LOCAL_FILE);
        @unlink(self::baseDir() . McpIdentity::FILE);
    }

    private function call(array $args): array
    {
        return (new GcIdentityUpdate())->handle($args, new AuthySession());
    }

    public function testShapeAndGate(): void
    {
        $t = new GcIdentityUpdate();
        $this->assertSame('gc_identity_update', $t->name());
        $this->assertSame(['Config', 'w'], $t->requiredRight());
        $this->assertArrayHasKey('confirm', $t->inputSchema()['properties']);
    }

    public function testNothingToChangeIsRejected(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('Nothing to change');
        $this->call([]);
    }

    public function testAddAndRemoveSameTermIsRejected(): void
    {
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('both added and removed');
        $this->call(['add_keywords' => ['x'], 'remove_keywords' => ['X']]);
    }

    public function testWithoutConfirmEchoesAndWritesNothing(): void
    {
        try {
            $this->call(['add_keywords' => ['Landlock'], 'about' => 'new about']);
            $this->fail('expected the confirm gate');
        } catch (ToolError $e) {
            $this->assertStringContainsString('re-call with confirm:true', $e->getMessage());
            $this->assertStringContainsString('"keywords":["ll-teq","landlock"]', $e->getMessage());
            $this->assertStringContainsString('"about":"new about"', $e->getMessage());
        }
        $this->assertFileDoesNotExist(self::baseDir() . McpIdentity::LOCAL_FILE);
    }

    public function testConfirmWritesOverlayAndMergesOnRead(): void
    {
        $r = $this->call(['add_keywords' => ['Landlock', 'crm'], 'confirm' => true]);
        $out = json_decode($r['content'][0]['text'], true);
        $this->assertTrue($out['saved']);
        $this->assertSame(['ll-teq', 'landlock', 'crm'], $out['identity']['keywords']);
        $this->assertSame(['ll-teq', 'landlock', 'crm'], McpIdentity::read()['keywords']);
        // Removing a built term, and re-adding a removed one, keeps the overlay minimal.
        $this->call(['remove_keywords' => ['ll-teq', 'crm'], 'confirm' => true]);
        $this->assertSame(['landlock'], McpIdentity::read()['keywords']);
        $this->assertSame(['landlock'], McpIdentity::readLocal()['keywords']);
        $this->assertSame(['ll-teq', 'crm'], McpIdentity::readLocal()['removed']);
        $this->call(['add_keywords' => ['ll-teq'], 'confirm' => true]);
        $this->assertSame(['ll-teq', 'landlock'], McpIdentity::read()['keywords']);
        $this->assertSame(['crm'], McpIdentity::readLocal()['removed']);
        // about equal to the built one is not stored as an override.
        $this->call(['about' => 'built about', 'confirm' => true]);
        $this->assertSame('', McpIdentity::readLocal()['about']);
    }

    public function testNoBuiltIdentityIsNotFound(): void
    {
        @unlink(self::baseDir() . McpIdentity::FILE);
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('no MCP identity');
        $this->call(['add_keywords' => ['x'], 'confirm' => true]);
    }
}
}
