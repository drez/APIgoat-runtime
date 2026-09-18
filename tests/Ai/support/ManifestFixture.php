<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai\Support;

use ApiGoat\Ai\AiManifest;

require_once __DIR__ . '/../../../src/Ai/AiManifest.php';

/**
 * Write a build-emitted config/Built/ai.php the way `gc build` does, so the
 * manifest-reading ladders are exercised through the REAL file, not a seam
 * that only tests use.
 *
 * _BASE_DIR is a constant and can be defined once per process, so every test
 * that needs a manifest shares one directory and rewrites the file.
 */
final class ManifestFixture
{
    /** @param array<string,mixed>|null $manifest null deletes the file (no manifest at all) */
    public static function write(?array $manifest): void
    {
        $dir = self::baseDir() . 'config/Built';
        if (!\is_dir($dir)) {
            \mkdir($dir, 0775, true);
        }
        $path = $dir . '/ai.php';
        if ($manifest === null) {
            if (\is_file($path)) {
                \unlink($path);
            }
        } else {
            \file_put_contents($path, "<?php\nreturn " . \var_export($manifest, true) . ";\n");
        }
        AiManifest::reset();
    }

    /** Drop the file and the cache — call in tearDown. */
    public static function clear(): void
    {
        self::write(null);
    }

    private static function baseDir(): string
    {
        if (!\defined('_BASE_DIR')) {
            $dir = \sys_get_temp_dir() . '/gc-runtime-ai-manifest-test';
            if (!\is_dir($dir)) {
                \mkdir($dir, 0775, true);
            }
            \define('_BASE_DIR', $dir . '/');
        }

        return (string) \constant('_BASE_DIR');
    }
}
