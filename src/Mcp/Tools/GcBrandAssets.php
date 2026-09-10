<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\DesignManifest;
use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;

/**
 * brand_assets — the project's logos and icons for design tools. Asset bytes
 * are embedded at build time into config/Built/mcp.design.php by the with_mcp
 * behavior (brand_assets key). Call without arguments for the inventory
 * (name, mime, dimensions, bytes); pass name to fetch one — raster images
 * come back as MCP image content (base64), SVG as its markup (text). Gated
 * in ToolRegistry::builtins() on DesignManifest::available(). No RBAC gate:
 * these are the public brand assets.
 */
class GcBrandAssets implements \ApiGoat\Mcp\McpTool
{
    public function name(): string { return 'brand_assets'; }

    public function description(): string
    {
        return 'Brand assets (logos, icons): call without arguments to list them with mime '
             . 'type and dimensions, then pass name to fetch one — raster images are '
             . 'returned as base64 image content, SVG as its markup.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string',
                'description' => 'Asset name from the no-argument listing; omit to list'],
        ]];
    }

    public function requiredRight(): ?array { return null; }

    public function handle(array $args, AuthySession $session): array
    {
        $assets = DesignManifest::assets();
        $want = isset($args['name']) && \is_string($args['name']) ? \trim($args['name']) : '';
        if ($want === '') {
            $list = [];
            foreach ($assets as $name => $a) {
                $list[] = [
                    'name'   => (string) $name,
                    'mime'   => (string) ($a['mime'] ?? ''),
                    'width'  => $a['width'] ?? null,
                    'height' => $a['height'] ?? null,
                    'bytes'  => (int) ($a['bytes'] ?? 0),
                ];
            }
            return ['content' => [['type' => 'text', 'text' => \json_encode(
                ['assets' => $list, 'hint' => 'Pass name to fetch one.'],
                JSON_UNESCAPED_SLASHES
            )]]];
        }
        $asset = $assets[$want] ?? null;
        if ($asset === null) {
            throw new ToolError(
                "Unknown asset '{$want}'. Available: " . \implode(', ', \array_map('strval', \array_keys($assets))),
                [],
                'not_found'
            );
        }
        $mime = (string) ($asset['mime'] ?? 'application/octet-stream');
        $data = (string) ($asset['data'] ?? '');
        if ($mime === 'image/svg+xml') {
            return ['content' => [['type' => 'text', 'text' => (string) \base64_decode($data)]]];
        }
        return ['content' => [['type' => 'image', 'data' => $data, 'mimeType' => $mime]]];
    }
}
