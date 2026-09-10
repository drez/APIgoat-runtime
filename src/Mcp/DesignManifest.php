<?php

namespace ApiGoat\Mcp;

/**
 * Reader for the build-emitted design-context manifest
 * (config/Built/mcp.design.php), written by the with_mcp behavior when the
 * schema declares design_docs / brand_assets. Mirror of StripeManifest:
 * the design_docs + brand_assets tools are gated on available().
 *
 * Shape: ['docs'   => [name => ['summary' => string, 'content' => string]],
 *         'assets' => [name => ['mime' => string, 'width' => ?int,
 *                               'height' => ?int, 'bytes' => int,
 *                               'data' => base64 string]]]
 */
final class DesignManifest
{
    public const FILE = 'config/Built/mcp.design.php';

    private static ?array $cache = null;

    public static function all(?string $baseDir = null): array
    {
        if (self::$cache === null) {
            self::$cache = ['docs' => [], 'assets' => []];
            $base = $baseDir ?? (\defined('_BASE_DIR') ? _BASE_DIR : null);
            if ($base !== null && \is_file($base . self::FILE)) {
                $m = require $base . self::FILE;
                if (\is_array($m)) {
                    self::$cache = [
                        'docs'   => \is_array($m['docs'] ?? null) ? $m['docs'] : [],
                        'assets' => \is_array($m['assets'] ?? null) ? $m['assets'] : [],
                    ];
                }
            }
        }
        return self::$cache;
    }

    /** @return array<string,array{summary:string,content:string}> */
    public static function docs(?string $baseDir = null): array
    {
        return self::all($baseDir)['docs'];
    }

    /** @return array<string,array{mime:string,width:?int,height:?int,bytes:int,data:string}> */
    public static function assets(?string $baseDir = null): array
    {
        return self::all($baseDir)['assets'];
    }

    public static function available(?string $baseDir = null): bool
    {
        $m = self::all($baseDir);
        return $m['docs'] !== [] || $m['assets'] !== [];
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
