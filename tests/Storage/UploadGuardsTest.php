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
        // Defense-in-depth: handler neutralization blocks execution even if directory access is granted
        $this->assertStringContainsString('RemoveHandler', $body);
        $this->assertStringContainsString('RemoveType', $body);
        $this->assertStringContainsString('<FilesMatch', $body);
        $this->assertStringContainsString('SetHandler none', $body);
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

    public function testEnsureDirDefaultsToPrivateWhenNoThirdArgumentIsPassed(): void
    {
        // Crux of the whole change: callers that omit $private must still get
        // the deny-all guard, not an accidentally-public one.
        $this->assertSame('', UploadGuards::ensureDir($this->dir));
        $body = (string) file_get_contents($this->dir . '/.htaccess');
        $this->assertStringContainsString(UploadGuards::SENTINEL_PRIVATE, $body);
        $this->assertStringContainsString('Require all denied', $body);
    }

    public function testPublicBodyGrantsAtTopLevelButStillDeniesPhpFiles(): void
    {
        $body = UploadGuards::htaccessBody(false);
        // Top-level grant so a public:true subdirectory isn't shadowed by the
        // parent public/file/ directory's deny-all (this was the bug: no
        // top-level Require meant "public" was a no-op under inheritance).
        $this->assertMatchesRegularExpression('/^Require all granted$/m', $body);
        // But script execution inside <FilesMatch> for php variants is still denied.
        $this->assertMatchesRegularExpression(
            '/<FilesMatch[^>]*php[^>]*>\s*SetHandler none\s*Require all denied\s*<\/FilesMatch>/',
            $body
        );
    }

    public function testPrivateBodyNeverGrantsAtTopLevel(): void
    {
        $body = UploadGuards::htaccessBody(true);
        $this->assertDoesNotMatchRegularExpression('/Require all granted/', $body);
    }

    /**
     * Wave 4: the public body used a DENYLIST (html/svg/xml forced to
     * download) — any other scriptable or sniffable type (xhtml variants,
     * .htm with odd casing, .js, .swf, office docs, unknown extensions)
     * rendered inline under the app origin. Now every file is an attachment
     * with nosniff, and only an allowlist of inline-safe types renders.
     */
    public function testPublicBodyDefaultsEveryFileToAttachmentWithNosniff(): void
    {
        $body = UploadGuards::htaccessBody(false);
        $top = substr($body, 0, (int) strpos($body, '<FilesMatch'));
        $this->assertStringContainsString('Header set Content-Disposition attachment', $top, 'attachment is the directory-wide default');
        $this->assertStringContainsString('Header set X-Content-Type-Options nosniff', $top);
    }

    public function testPublicBodyInlineAllowlistIsSafeTypesOnly(): void
    {
        $body = UploadGuards::htaccessBody(false);
        $this->assertSame(1, preg_match('/<FilesMatch "\(\?i\)\\\.\(([^)]*)\)\$">\n<IfModule mod_headers\.c>\nHeader set Content-Disposition inline/', $body, $m), 'one inline allowlist block');
        $exts = explode('|', $m[1]);
        foreach (['png', 'jpe?g', 'gif', 'webp', 'pdf', 'txt'] as $ok) {
            $this->assertContains($ok, $exts);
        }
        foreach (['svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'js', 'swf', 'css'] as $bad) {
            $this->assertNotContains($bad, $exts, $bad . ' must never be inline');
        }
    }

    public function testPublicBodyStillNeutralisesMarkupTypes(): void
    {
        $body = UploadGuards::htaccessBody(false);
        $this->assertStringContainsString('ForceType text/plain', $body);
    }
}
