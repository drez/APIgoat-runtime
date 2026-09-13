<?php
// tools/call must apply the SAME rights check tools/list does. Before this,
// requiredRight() was consulted only when listing, so a tool hidden from a
// session stayed fully callable by name.
namespace ApiGoat\Sessions {
    if (!class_exists(AuthySession::class, false)) {
        class AuthySession
        {
            public function __construct(private bool $admin = false, private bool $rights = false) {}
            public function isAdmin() { return $this->admin; }
            public function hasRights($m = '', $r = '') { return $this->rights ? 'r' : false; }
        }
    }
}

namespace ApiGoat\Tests\Mcp {

use ApiGoat\Mcp\McpServer;
use ApiGoat\Mcp\McpTool;
use ApiGoat\Mcp\ToolRegistry;
use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;

// PSR-4 for THIS checkout's source. PREPENDED on purpose: the phpunit binary
// used to run this suite belongs to a project, and that project's Composer
// autoloader maps ApiGoat\ to its OWN runtime clone — a different tree. Without
// prepend, this test would silently exercise that clone instead of this source.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ApiGoat\\')) {
        $f = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
}, true, true);

/**
 * Sessions are SUBCLASSED, not stubbed: tests/Mcp runs as one process and the
 * first file loaded wins the ApiGoat\Sessions\AuthySession name — another
 * test's permissive stub, or the real class. Extending whatever is loaded and
 * overriding just the two methods the registry consults makes this test
 * independent of that race.
 */
class DeniedSession extends AuthySession
{
    public function __construct() {}
    public function isAdmin() { return false; }
    public function hasRights($model = '', $needeRight = '') { return false; }
}

final class GrantedSession extends DeniedSession
{
    public function hasRights($model = '', $needeRight = '') { return 'r'; }
}

final class AdminSession extends DeniedSession
{
    public function isAdmin() { return true; }
}

final class GatedTool implements McpTool
{
    public bool $ran = false;
    public function __construct(private ?array $right) {}
    public function name(): string { return 'gated_tool'; }
    public function description(): string { return 'test'; }
    public function inputSchema(): array { return ['type' => 'object']; }
    public function requiredRight(): ?array { return $this->right; }
    public function handle(array $args, AuthySession $session): array
    {
        $this->ran = true;
        return ['content' => [['type' => 'text', 'text' => 'executed']]];
    }
}

final class ToolCallAuthorizationTest extends TestCase
{
    private function call(ToolRegistry $reg, AuthySession $s): array
    {
        return (new McpServer($reg))->handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'gated_tool', 'arguments' => []]],
            $s
        );
    }

    private function names(ToolRegistry $reg, AuthySession $s): array
    {
        return array_column($reg->list($s), 'name');
    }

    public function testTheSuiteIsExercisingThisCheckout(): void
    {
        $file = (new \ReflectionClass(ToolRegistry::class))->getFileName();
        $this->assertSame(
            realpath(__DIR__ . '/../../src/Mcp/ToolRegistry.php'),
            realpath((string) $file),
            'ApiGoat\\ resolved to another runtime clone — the results below would be meaningless'
        );
    }

    public function testUngrantedSessionCannotCallAHiddenTool(): void
    {
        $tool = new GatedTool(['Secret', 'd']);
        $reg  = new ToolRegistry([$tool]);
        $sess = new DeniedSession();

        $this->assertNotContains('gated_tool', $this->names($reg, $sess), 'precondition: tool is hidden');

        $res = $this->call($reg, $sess);
        $this->assertTrue($res['result']['isError'] ?? false, 'call must be refused');
        $this->assertStringContainsString('do not have access', $res['result']['content'][0]['text']);
        $this->assertFalse($tool->ran, 'the handler must never run');
    }

    public function testGrantedSessionCanCallIt(): void
    {
        $tool = new GatedTool(['Secret', 'd']);
        $reg  = new ToolRegistry([$tool]);
        $sess = new GrantedSession();

        $this->assertContains('gated_tool', $this->names($reg, $sess));
        $res = $this->call($reg, $sess);
        $this->assertArrayNotHasKey('isError', $res['result']);
        $this->assertTrue($tool->ran);
    }

    public function testAdminCanCallIt(): void
    {
        $tool = new GatedTool(['Secret', 'd']);
        $res  = $this->call(new ToolRegistry([$tool]), new AdminSession());
        $this->assertTrue($tool->ran);
        $this->assertArrayNotHasKey('isError', $res['result']);
    }

    public function testToolDeclaringNoRightStaysCallable(): void
    {
        $tool = new GatedTool(null);   // crm_* shape: gated by Api's ACL instead
        $res  = $this->call(new ToolRegistry([$tool]), new DeniedSession());
        $this->assertTrue($tool->ran);
        $this->assertArrayNotHasKey('isError', $res['result']);
    }
}

}
