<?php
// McpServer::initialize composes the instructions from the build-time project
// identity (McpIdentity preamble, FIRST), the tool-list what's-new notice, and
// the project's own guidance. Driven through the public JSON-RPC handle().
namespace ApiGoat\Sessions { if (!class_exists(AuthySession::class, false)) { class AuthySession {} } }

namespace ApiGoat\Tests\Mcp {

use ApiGoat\Mcp\McpIdentity;
use ApiGoat\Mcp\McpServer;
use ApiGoat\Mcp\ToolRegistry;
use ApiGoat\Mcp\VersionStamp;
use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;

// This repo's classes, not whichever runtime clone the phpunit binary autoloads.
require_once __DIR__ . '/../../src/Mcp/McpTool.php';
require_once __DIR__ . '/../../src/Mcp/ToolError.php';
require_once __DIR__ . '/../../src/Mcp/VersionStamp.php';
require_once __DIR__ . '/../../src/Mcp/McpIdentity.php';
require_once __DIR__ . '/../../src/Mcp/ToolRegistry.php';
require_once __DIR__ . '/../../src/Mcp/McpServer.php';

final class McpServerInstructionsTest extends TestCase
{
    private const IDENTITY = [
        'name'     => 'LL-TEQ CRM',
        'about'    => 'contacts, quotes and invoices for LL-TEQ',
        'keywords' => ['LL-TEQ', 'contact', 'quote', 'invoice'],
        'missing'  => [],
    ];

    /** initialize() reads _BASE_DIR (process-wide): define it once to a temp dir, or reuse an existing one. */
    private static function baseDir(): string
    {
        if (!defined('_BASE_DIR')) {
            $dir = sys_get_temp_dir() . '/msi-' . bin2hex(random_bytes(4)) . '/';
            mkdir($dir . 'config/Built', 0775, true);
            define('_BASE_DIR', $dir);
        }
        $base = rtrim((string) _BASE_DIR, '/\\') . '/';
        if (!is_dir($base . 'config/Built')) {
            @mkdir($base . 'config/Built', 0775, true);
        }
        return $base;
    }

    private function identityPath(): string
    {
        return self::baseDir() . McpIdentity::FILE;
    }

    protected function setUp(): void
    {
        if (!is_dir(dirname($this->identityPath())) || !is_writable(dirname($this->identityPath()))) {
            $this->markTestSkipped('_BASE_DIR/config/Built is not writable: ' . $this->identityPath());
        }
        @unlink($this->identityPath());
    }

    protected function tearDown(): void
    {
        @unlink($this->identityPath());
    }

    private static function registry(?string $instructions): ToolRegistry
    {
        return new class($instructions) extends ToolRegistry {
            public function __construct(private ?string $projectInstructions) {} // no discovery
            public function instructions(): ?string { return $this->projectInstructions; }
            public function manifestValue(string $key) { return null; }
        };
    }

    private static function init(ToolRegistry $registry): array
    {
        $server = new McpServer($registry);
        $resp = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], new AuthySession());
        self::assertIsArray($resp);
        self::assertArrayHasKey('result', $resp, 'initialize failed: ' . json_encode($resp));
        return $resp['result'];
    }

    public function test_preamble_first_then_project_instructions(): void
    {
        file_put_contents($this->identityPath(), '<?php return ' . var_export(self::IDENTITY, true) . ';');

        $r = self::init(self::registry('PROJECT TEXT'));
        $preamble = McpIdentity::preamble(self::IDENTITY);
        $this->assertNotNull($preamble);
        $this->assertStringStartsWith('This server is LL-TEQ CRM — ', $r['instructions']);
        $this->assertStringStartsWith($preamble . "\n\n", $r['instructions']);
        $this->assertStringEndsWith("\n\nPROJECT TEXT", $r['instructions']);
        if (VersionStamp::whatsNew(VersionStamp::read()) === null) {
            $this->assertSame($preamble . "\n\nPROJECT TEXT", $r['instructions']);
        }
        // Identity name is the title fallback (no config/mcp.php title here).
        $this->assertSame('LL-TEQ CRM', $r['serverInfo']['title']);
    }

    public function test_no_identity_file_means_no_preamble(): void
    {
        $this->assertFileDoesNotExist($this->identityPath());

        $r = self::init(self::registry('PROJECT TEXT'));
        $this->assertStringNotContainsString('This server is', $r['instructions']);
        $this->assertStringEndsWith('PROJECT TEXT', $r['instructions']);
        $this->assertSame(McpServer::serverInfo()['title'], $r['serverInfo']['title'], 'title falls back to "<label> MCP"');

        // No project instructions either → the generic default guidance, still without a preamble.
        $r = self::init(self::registry(null));
        $this->assertStringNotContainsString('This server is', $r['instructions']);
        $this->assertStringContainsString('Start with crm_describe', $r['instructions']);
    }

    public function test_identity_without_name_adds_no_preamble_and_no_title(): void
    {
        file_put_contents($this->identityPath(), '<?php return ' . var_export(['name' => '', 'about' => 'x', 'keywords' => ['k'], 'missing' => ['name']], true) . ';');

        $r = self::init(self::registry('PROJECT TEXT'));
        $this->assertStringNotContainsString('This server is', $r['instructions']);
        $this->assertStringEndsWith('PROJECT TEXT', $r['instructions']);
        $this->assertSame(McpServer::serverInfo()['title'], $r['serverInfo']['title']);
    }
}

}
