<?php
// design_docs + brand_assets: manifest gating, doc listing/reading, asset
// listing and image/svg content — driven through handle() with a stub session.
namespace ApiGoat\Sessions { if (!class_exists(AuthySession::class, false)) { class AuthySession { public function isAdmin() { return true; } public function hasRights($m = '', $r = '') { return true; } } } }

namespace ApiGoat\Tests\Mcp {

use ApiGoat\Mcp\DesignManifest;
use ApiGoat\Mcp\ToolError;
use ApiGoat\Mcp\Tools\GcBrandAssets;
use ApiGoat\Mcp\Tools\GcDesignDocs;
use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Mcp/McpTool.php';
require_once __DIR__ . '/../../src/Mcp/ToolError.php';
require_once __DIR__ . '/../../src/Mcp/DesignManifest.php';
require_once __DIR__ . '/../../src/Mcp/Tools/GcDesignDocs.php';
require_once __DIR__ . '/../../src/Mcp/Tools/GcBrandAssets.php';

final class DesignToolsTest extends TestCase
{
    // 1x1 transparent PNG
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';

    private static function baseDir(): string
    {
        if (!defined('_BASE_DIR')) {
            $dir = sys_get_temp_dir() . '/dm-' . bin2hex(random_bytes(4)) . '/';
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
        @unlink($base . DesignManifest::FILE);
        DesignManifest::reset();
    }

    private function writeManifest(array $m): void
    {
        file_put_contents(self::baseDir() . DesignManifest::FILE, '<?php return ' . var_export($m, true) . ';');
        DesignManifest::reset();
    }

    private function fullManifest(): void
    {
        $this->writeManifest([
            'docs' => [
                'FEATURES'    => ['summary' => 'What the product does', 'content' => "# Features\n\nThe marketplace."],
                'AI-FEATURES' => ['summary' => 'How the AI functions work', 'content' => "# AI\n\nChat responder."],
            ],
            'assets' => [
                'logo.png'    => ['mime' => 'image/png', 'width' => 1, 'height' => 1,
                                  'bytes' => strlen(base64_decode(self::PNG)), 'data' => self::PNG],
                'favicon.svg' => ['mime' => 'image/svg+xml', 'width' => null, 'height' => null,
                                  'bytes' => strlen(self::SVG), 'data' => base64_encode(self::SVG)],
            ],
        ]);
    }

    private static function docs(array $args = []): array
    {
        return (new GcDesignDocs())->handle($args, new AuthySession());
    }

    private static function assets(array $args = []): array
    {
        return (new GcBrandAssets())->handle($args, new AuthySession());
    }

    private static function textPayload(array $result): array
    {
        return json_decode($result['content'][0]['text'], true);
    }

    public function test_manifest_absent_means_unavailable(): void
    {
        $this->assertFalse(DesignManifest::available());
    }

    public function test_manifest_with_docs_or_assets_is_available(): void
    {
        $this->writeManifest(['docs' => ['X' => ['summary' => 's', 'content' => 'c']], 'assets' => []]);
        $this->assertTrue(DesignManifest::available());
    }

    public function test_tools_carry_no_rbac_gate(): void
    {
        $this->assertNull((new GcDesignDocs())->requiredRight());
        $this->assertNull((new GcBrandAssets())->requiredRight());
        $this->assertSame('design_docs', (new GcDesignDocs())->name());
        $this->assertSame('brand_assets', (new GcBrandAssets())->name());
    }

    public function test_design_docs_lists_names_and_summaries(): void
    {
        $this->fullManifest();
        $payload = self::textPayload(self::docs());
        $this->assertSame(
            [['doc' => 'FEATURES', 'summary' => 'What the product does'],
             ['doc' => 'AI-FEATURES', 'summary' => 'How the AI functions work']],
            $payload['docs']
        );
    }

    public function test_design_docs_returns_full_markdown(): void
    {
        $this->fullManifest();
        $out = self::docs(['doc' => 'AI-FEATURES']);
        $this->assertSame("# AI\n\nChat responder.", $out['content'][0]['text']);
    }

    public function test_design_docs_doc_match_is_case_insensitive(): void
    {
        $this->fullManifest();
        $out = self::docs(['doc' => 'features']);
        $this->assertSame("# Features\n\nThe marketplace.", $out['content'][0]['text']);
    }

    public function test_design_docs_unknown_doc_is_not_found_and_names_the_options(): void
    {
        $this->fullManifest();
        try {
            self::docs(['doc' => 'NOPE']);
            $this->fail('expected ToolError');
        } catch (ToolError $e) {
            $this->assertSame('not_found', $e->kind);
            $this->assertStringContainsString('FEATURES', $e->getMessage());
        }
    }

    public function test_brand_assets_lists_metadata_without_data(): void
    {
        $this->fullManifest();
        $payload = self::textPayload(self::assets());
        $this->assertSame('logo.png', $payload['assets'][0]['name']);
        $this->assertSame('image/png', $payload['assets'][0]['mime']);
        $this->assertSame(1, $payload['assets'][0]['width']);
        $this->assertArrayNotHasKey('data', $payload['assets'][0]);
    }

    public function test_brand_assets_returns_raster_as_image_content(): void
    {
        $this->fullManifest();
        $out = self::assets(['name' => 'logo.png']);
        $this->assertSame('image', $out['content'][0]['type']);
        $this->assertSame(self::PNG, $out['content'][0]['data']);
        $this->assertSame('image/png', $out['content'][0]['mimeType']);
    }

    public function test_brand_assets_returns_svg_as_text_markup(): void
    {
        $this->fullManifest();
        $out = self::assets(['name' => 'favicon.svg']);
        $this->assertSame('text', $out['content'][0]['type']);
        $this->assertSame(self::SVG, $out['content'][0]['text']);
    }

    public function test_brand_assets_unknown_name_is_not_found(): void
    {
        $this->fullManifest();
        try {
            self::assets(['name' => 'ghost.png']);
            $this->fail('expected ToolError');
        } catch (ToolError $e) {
            $this->assertSame('not_found', $e->kind);
            $this->assertStringContainsString('logo.png', $e->getMessage());
        }
    }

    public function test_empty_manifest_tools_report_nothing_gracefully(): void
    {
        $this->writeManifest(['docs' => [], 'assets' => []]);
        $this->assertFalse(DesignManifest::available());
        $this->assertSame([], self::textPayload(self::docs())['docs']);
        $this->assertSame([], self::textPayload(self::assets())['assets']);
    }
}

}
