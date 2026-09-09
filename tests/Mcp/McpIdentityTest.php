<?php
namespace ApiGoat\Tests\Mcp;

use ApiGoat\Mcp\McpIdentity;
use PHPUnit\Framework\TestCase;

// This repo's class, not whichever runtime clone the phpunit binary autoloads.
require_once __DIR__ . '/../../src/Mcp/McpIdentity.php';

final class McpIdentityTest extends TestCase
{
    private const RULE = 'list the candidate servers and ask the user which one to use BEFORE calling any tool';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mi-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config/Built', 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/' . McpIdentity::FILE);
        @rmdir($this->dir . '/config/Built'); @rmdir($this->dir . '/config'); @rmdir($this->dir);
    }

    public function test_preamble_is_null_without_identity_or_name(): void
    {
        $this->assertNull(McpIdentity::preamble(null));
        $this->assertNull(McpIdentity::preamble(['name' => '', 'about' => 'x', 'keywords' => ['a'], 'missing' => []]));
        $this->assertNull(McpIdentity::preamble(['name' => "  \n ", 'about' => 'x', 'keywords' => ['a'], 'missing' => []]));
        $this->assertNull(McpIdentity::preamble([]));
    }

    public function test_full_identity_text(): void
    {
        $p = McpIdentity::preamble([
            'name'     => 'LL-TEQ CRM',
            'about'    => 'contacts, quotes and invoices for LL-TEQ',
            'keywords' => ['LL-TEQ', 'contact', 'quote', 'invoice'],
            'missing'  => [],
        ]);
        $this->assertStringStartsWith('This server is LL-TEQ CRM — contacts, quotes and invoices for LL-TEQ. ', $p);
        $this->assertStringContainsString(
            'It is the FIRST and authoritative source for anything about: LL-TEQ, contact, quote, invoice. ',
            $p
        );
        $this->assertStringContainsString(self::RULE, $p);
        $this->assertStringContainsString('never answer questions about it from another server', $p);
        $this->assertStringEndsWith('unless the user switches.', $p);
        $this->assertStringNotContainsString("\n", $p, 'one paragraph');
    }

    public function test_about_only_omits_keyword_list(): void
    {
        $p = McpIdentity::preamble(['name' => 'Shop', 'about' => 'an online shop', 'keywords' => [], 'missing' => []]);
        $this->assertStringStartsWith('This server is Shop — an online shop. ', $p);
        $this->assertStringContainsString('anything about this project. Use it whenever', $p);
        $this->assertStringNotContainsString('about:', $p);
    }

    public function test_keywords_only_omits_about(): void
    {
        $p = McpIdentity::preamble(['name' => 'Shop', 'about' => '', 'keywords' => ['order', 'sku'], 'missing' => ['x']]);
        $this->assertStringStartsWith('This server is Shop. It is the FIRST and authoritative source for anything about: order, sku. ', $p);
        $this->assertStringNotContainsString(' — ', $p);
    }

    public function test_newlines_and_control_chars_are_flattened(): void
    {
        $p = McpIdentity::preamble([
            'name'     => " LL-TEQ\nCRM ",
            'about'    => "line one\r\n  line\ttwo\x00",
            'keywords' => ["ord\ner", '', "  sku  ", "\n"],
            'missing'  => [],
        ]);
        $this->assertStringStartsWith('This server is LL-TEQ CRM — line one line two. ', $p);
        $this->assertStringContainsString('anything about: ord er, sku. ', $p);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $p);
    }

    public function test_read_returns_null_when_file_absent(): void
    {
        $this->assertNull(McpIdentity::read($this->dir));
        $this->assertNull(McpIdentity::read(''));
    }

    public function test_read_returns_null_when_file_is_not_an_array(): void
    {
        file_put_contents($this->dir . '/' . McpIdentity::FILE, "<?php\nreturn 'nope';\n");
        $this->assertNull(McpIdentity::read($this->dir));
    }

    public function test_read_normalizes_keys_and_types(): void
    {
        file_put_contents($this->dir . '/' . McpIdentity::FILE, '<?php return ' . var_export([
            'name'     => '  LL-TEQ CRM ',
            'keywords' => ['a', '', 7, ['nested'], ' b '],
            'missing'  => 'not-a-list',
        ], true) . ';');
        $this->assertSame(
            ['name' => 'LL-TEQ CRM', 'about' => '', 'keywords' => ['a', '7', 'b'], 'missing' => []],
            McpIdentity::read($this->dir)
        );
        // Trailing slash tolerated like VersionStamp.
        $this->assertSame('LL-TEQ CRM', McpIdentity::read($this->dir . '/')['name']);
    }
}
