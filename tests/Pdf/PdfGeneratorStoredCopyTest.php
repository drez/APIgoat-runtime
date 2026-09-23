<?php

use ApiGoat\Pdf\PdfGenerator;
use ApiGoat\Storage\Drive\GoogleClientFactory;
use ApiGoat\Storage\Drive\GoogleDriveStorage;
use PHPUnit\Framework\TestCase;

/**
 * Review-3 #15: a drive-only with_pdf document must be STREAMED from Drive on
 * a read, never re-rendered. storedBytes() finds the copy by listing the
 * record's own folder and downloads it by id; replaceOnDrive() uploads before
 * it trashes the previous copies so there is never a window with no copy.
 *
 * Drive is an in-memory fake behind the real GoogleDriveStorage (only the
 * HTTP-level GoogleClientFactory is replaced).
 */
final class PdfGeneratorStoredCopyTest extends TestCase
{
    private FakeDriveHttp $http;

    protected function setUp(): void
    {
        $this->http = new FakeDriveHttp();
        $http = $this->http;
        PdfGenerator::useDriveFactory(fn(string $email) => new GoogleDriveStorage($http, $email));
    }

    protected function tearDown(): void
    {
        PdfGenerator::useDriveFactory(null);
    }

    private static function entry(): array
    {
        return [
            'table' => 'quote', 'entity' => 'quote', 'pk_php' => 'IdQuote',
            'storage' => ['drive'], 'folder' => 'CRM/{CompanyName}', 'filename' => '{Number}',
        ];
    }

    private static function record(string $company = 'Acme', string $number = 'Q-1'): object
    {
        return new class($company, $number) {
            public function __construct(private string $c, private string $n) {}
            public function getCompanyName() { return $this->c; }
            public function getNumber() { return $this->n; }
            public function getIdQuote() { return 1; }
            public function getPdfUrl() { return 'https://drive.google.com/file/d/X/view'; }
        };
    }

    private function drive(): GoogleDriveStorage
    {
        return new GoogleDriveStorage($this->http, 'u@example.com');
    }

    public function testNothingStoredIsNullAndWritesNothing(): void
    {
        $this->assertNull(PdfGenerator::storedBytes(self::record(), self::entry(), 'u@example.com'));
        $this->assertNull(PdfGenerator::currentBytes(self::record(), self::entry(), 'u@example.com', false),
            'a caller without the write right gets null, not a render');
        $this->assertSame(0, $this->http->writes, 'a read never uploads, trashes or creates folders');
    }

    public function testStoredCopyIsStreamedByIdWithoutAnyWrite(): void
    {
        $name = PdfGenerator::canonicalName(self::record(), self::entry());
        PdfGenerator::replaceOnDrive($this->drive(), 'CRM/Acme', $name, '%PDF-v1');
        $writes = $this->http->writes;

        $got = PdfGenerator::storedBytes(self::record(), self::entry(), 'u@example.com');
        $this->assertSame('%PDF-v1', $got['bytes']);
        $this->assertSame($name, $got['name']);
        $this->assertFalse($got['generated']);

        $again = PdfGenerator::currentBytes(self::record(), self::entry(), 'u@example.com');
        $this->assertSame('%PDF-v1', $again['bytes']);
        $this->assertSame($writes, $this->http->writes, 'streaming stored bytes writes nothing');
    }

    public function testReplaceUploadsFirstThenTrashesOnlyTheOlderSameNameCopies(): void
    {
        $d = $this->drive();
        PdfGenerator::replaceOnDrive($d, 'CRM/Acme', 'Q-1.pdf', 'v1');
        $this->http->add('CRM/Acme', 'Q-10.pdf', 'other document');       // prefix match, other name
        $this->http->add('CRM/Acme', 'Q-1.pdf', 'stray duplicate');       // e.g. a crashed run
        PdfGenerator::replaceOnDrive($d, 'CRM/Acme', 'Q-1.pdf', 'v2');

        $live = $this->http->live('CRM/Acme');
        sort($live);
        $this->assertSame(['Q-1.pdf', 'Q-10.pdf'], $live, 'one current copy; the other document is untouched');
        $this->assertSame('v2', PdfGenerator::storedBytes(self::record(), self::entry(), 'u@example.com')['bytes']);

        // The new copy existed before any older one was trashed.
        $log = $this->http->log;
        $upV2 = array_search('upload:v2', $log, true);
        $firstTrashAfter = null;
        foreach ($log as $i => $op) {
            if ($i > $upV2 - 1 && str_starts_with($op, 'trash:')) { $firstTrashAfter = $i; break; }
        }
        $this->assertNotNull($firstTrashAfter);
        $this->assertGreaterThan($upV2, $firstTrashAfter);
    }

    public function testNewestDuplicateWinsAndOtherFoldersAreNeverRead(): void
    {
        $this->http->add('CRM/Rival', 'Q-1.pdf', 'someone else');
        $this->assertNull(PdfGenerator::storedBytes(self::record(), self::entry(), 'u@example.com'),
            'the same file name in another folder is not this record\'s copy');

        $this->http->add('CRM/Acme', 'Q-1.pdf', 'old');
        $this->http->add('CRM/Acme', 'Q-1.pdf', 'new');
        $this->assertSame('new', PdfGenerator::storedBytes(self::record(), self::entry(), 'u@example.com')['bytes']);
    }

    public function testNoWorkspaceEmailMeansNoDriveCopy(): void
    {
        $this->http->add('CRM/Acme', 'Q-1.pdf', 'x');
        $this->assertNull(PdfGenerator::storedBytes(self::record(), self::entry(), ''));
    }

    public function testDownloadValidatesTheIdAndTheSize(): void
    {
        $id = $this->http->add('CRM/Acme', 'big.pdf', str_repeat('x', 50));
        $d = $this->drive();
        $this->assertSame(50, strlen($d->download($id)));
        try {
            $d->download($id, 10);
            $this->fail('oversize download must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exceeds', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $d->download('../x?alt=media&');
    }
}

/** In-memory Drive at the HTTP level (GoogleClientFactory API). */
final class FakeDriveHttp extends GoogleClientFactory
{
    /** @var array<string,array{id:string,name:string,mimeType:string,parent:string,modifiedTime:string,trashed:bool,bytes:string}> */
    public array $files = [];
    public array $log = [];
    public int $writes = 0;
    private int $seq = 0;

    public function __construct()
    {
        // no parent::__construct — nothing is signed
    }

    private function mk(string $name, string $mime, string $parent, string $bytes = ''): string
    {
        $id = 'F' . (++$this->seq);
        $this->files[$id] = [
            'id' => $id, 'name' => $name, 'mimeType' => $mime, 'parent' => $parent,
            'modifiedTime' => sprintf('2026-09-23T10:%02d:00.000Z', $this->seq), 'trashed' => false, 'bytes' => $bytes,
        ];
        return $id;
    }

    private function folderId(string $path): string
    {
        $parent = 'root';
        foreach (explode('/', $path) as $seg) {
            $found = null;
            foreach ($this->files as $f) {
                if ($f['name'] === $seg && $f['parent'] === $parent && !$f['trashed'] && $f['mimeType'] === 'application/vnd.google-apps.folder') {
                    $found = $f['id'];
                }
            }
            $parent = $found ?? $this->mk($seg, 'application/vnd.google-apps.folder', $parent);
        }
        return $parent;
    }

    /** Test helper: drop a file straight into a folder path. */
    public function add(string $path, string $name, string $bytes): string
    {
        return $this->mk($name, 'application/pdf', $this->folderId($path), $bytes);
    }

    /** @return string[] names of the live (untrashed) files in $path */
    public function live(string $path): array
    {
        $pid = $this->folderId($path);
        return array_values(array_map(fn($f) => $f['name'], array_filter($this->files, fn($f) => $f['parent'] === $pid && !$f['trashed'])));
    }

    public function get(string $url, array $scopes, ?string $subject = null): array
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $qs);
        if (!isset($qs['q'])) {
            $id = basename($parts['path']);
            $f = $this->files[$id] ?? null;
            if ($f === null) {
                throw new \ApiGoat\Storage\Drive\Exceptions\TransientError('not found', 404);
            }
            return ['id' => $f['id'], 'name' => $f['name'], 'mimeType' => $f['mimeType'], 'size' => (string) strlen($f['bytes']), 'modifiedTime' => $f['modifiedTime']];
        }
        $q = (string) $qs['q'];
        preg_match("/'([^']+)' in parents/", $q, $pm);
        $out = [];
        foreach ($this->files as $f) {
            if ($f['trashed'] || $f['parent'] !== ($pm[1] ?? '')) { continue; }
            if (preg_match("/name='([^']*)'/", $q, $nm) && $f['name'] !== stripslashes($nm[1])) { continue; }
            if (preg_match("/name contains '([^']*)'/", $q, $cm) && !str_contains($f['name'], stripslashes($cm[1]))) { continue; }
            if (preg_match("/mimeType='([^']*)'/", $q, $mm) && $f['mimeType'] !== $mm[1]) { continue; }
            $out[] = ['id' => $f['id'], 'name' => $f['name'], 'mimeType' => $f['mimeType'], 'size' => (string) strlen($f['bytes']), 'modifiedTime' => $f['modifiedTime']];
        }
        return ['files' => $out];
    }

    public function getRaw(string $url, array $scopes, ?string $subject = null): string
    {
        $parts = parse_url($url);
        $id = basename($parts['path']);
        $this->log[] = 'download:' . $id;
        return $this->files[$id]['bytes'];
    }

    public function post(string $url, array $payload, array $scopes, ?string $subject = null): array
    {
        $this->writes++;
        return ['id' => $this->mk($payload['name'], $payload['mimeType'], $payload['parents'][0])];
    }

    public function uploadMultipart(string $url, array $metadata, string $body, string $bodyMime, array $scopes, ?string $subject = null): array
    {
        $this->writes++;
        $this->log[] = 'upload:' . $body;
        $id = $this->mk($metadata['name'], $bodyMime, $metadata['parents'][0], $body);
        return ['id' => $id, 'name' => $metadata['name'], 'mimeType' => $bodyMime, 'webViewLink' => 'https://drive/' . $id];
    }

    public function patch(string $url, array $payload, array $scopes, ?string $subject = null): array
    {
        $this->writes++;
        $id = basename(parse_url($url, PHP_URL_PATH));
        if (!empty($payload['trashed'])) {
            $this->files[$id]['trashed'] = true;
            $this->log[] = 'trash:' . $id;
        }
        return ['id' => $id];
    }
}
