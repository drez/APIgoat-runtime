<?php
namespace ApiGoat\Tests\Storage;

use ApiGoat\Storage\UploadGuards;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Storage/UploadGuards.php';

final class UploadGuardsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ug-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testPrivateBodyDeniesAllAndCarriesItsSentinel(): void
    {
        $body = UploadGuards::htaccessBody(true);
        $this->assertStringContainsString('Require all denied', $body);
        $this->assertStringContainsString(UploadGuards::SENTINEL_PRIVATE, $body);
        $this->assertStringNotContainsString(UploadGuards::SENTINEL_PUBLIC, $body);
    }

    public function testPublicBodyDoesNotDenyReadsButStillKillsScripts(): void
    {
        $body = UploadGuards::htaccessBody(false);
        $this->assertStringContainsString(UploadGuards::SENTINEL_PUBLIC, $body);
        $this->assertStringContainsString('RemoveHandler', $body);
        // deny-all appears ONLY inside the php FilesMatch block, never at top level
        $this->assertSame(1, substr_count($body, 'Require all denied'));
    }

    public function testEnsureDirCreatesTheDirectoryAndTheGuard(): void
    {
        $this->assertSame('', UploadGuards::ensureDir($this->dir, true));
        $this->assertDirectoryExists($this->dir);
        $this->assertStringContainsString(
            'Require all denied',
            (string) file_get_contents($this->dir . '/.htaccess')
        );
    }

    public function testEnsureDirNeverOverwritesAnExistingGuard(): void
    {
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/.htaccess', "# hand-written\n");
        $this->assertSame('', UploadGuards::ensureDir($this->dir, true));
        $this->assertSame("# hand-written\n", (string) file_get_contents($this->dir . '/.htaccess'));
    }

    public function testEnsureDirWritesTheIndexStubWhenAsked(): void
    {
        UploadGuards::ensureDir($this->dir, true, $this->dir . '/index.php');
        $this->assertFileExists($this->dir . '/index.php');
    }
}
