<?php

use ApiGoat\Pdf\PdfGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The stored copy's path comes from a DB column: only a .pdf really under
 * public/file/<class_dir>/ may be read (no "../../.env", absolute path, or
 * non-pdf file).
 */
final class PdfGeneratorLocalPathTest extends TestCase
{
    private static function localFile(string $stored): ?string
    {
        $row = new class($stored) {
            public function __construct(private string $f) {}
            public function getFile() { return $this->f; }
        };
        $m = new ReflectionMethod(PdfGenerator::class, 'localFile');
        return $m->invoke(null, $row, ['files' => ['file_php' => 'File', 'class_dir' => 'QuoteFile']]);
    }

    public function testOnlyPdfsInsideTheClassDirAreServed(): void
    {
        if (!defined('_BASE_DIR')) {
            define('_BASE_DIR', sys_get_temp_dir() . '/gc-pdfpath-' . getmypid() . '/');
        }
        $base = (string) _BASE_DIR;
        if (strpos($base, sys_get_temp_dir()) !== 0) {
            $this->markTestSkipped('_BASE_DIR points at a real project');
        }
        @mkdir($base . 'public/file/QuoteFile', 0777, true);
        @mkdir($base . 'public/file/Other', 0777, true);
        file_put_contents($base . 'public/file/QuoteFile/ok.pdf', '%PDF');
        file_put_contents($base . 'public/file/QuoteFile/notes.txt', 'x');
        file_put_contents($base . 'public/file/Other/x.pdf', '%PDF');
        file_put_contents($base . '.env', 'SECRET');

        $this->assertSame(realpath($base . 'public/file/QuoteFile/ok.pdf'), self::localFile('public/file/QuoteFile/ok.pdf'));
        foreach ([
            '.env', 'public/file/QuoteFile/../../../.env', '/etc/passwd', 'public/file/QuoteFile/notes.txt',
            'public/file/Other/x.pdf', 'public/file/QuoteFile/../Other/x.pdf', 'public/file/QuoteFile/missing.pdf', '',
        ] as $bad) {
            $this->assertNull(self::localFile($bad), $bad);
        }
    }
}
