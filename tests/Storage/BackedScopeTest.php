<?php

use ApiGoat\Storage\Backed\BackedEntity;
use ApiGoat\Storage\Backed\BackedQuery;
use ApiGoat\Storage\Drive\Exceptions\TransientError;
use ApiGoat\Storage\Drive\GoogleClientFactory;
use ApiGoat\Storage\Drive\GoogleDriveStorage;
use PHPUnit\Framework\TestCase;

final class BackedScopeTestFile extends BackedEntity
{
    public static function columnMap(): array
    {
        return ['id_drive_file' => 'id', 'file_name' => 'name', 'mime_type' => 'mimeType'];
    }

    public static function primaryKeyColumn(): string
    {
        return 'id_drive_file';
    }
}

/**
 * SECURITY: by-id operations on drive-backed entities must stay inside the
 * scope folder — a user limited to "CRM/Alice" must not read, rename or
 * trash a file in "CRM/Bob" by supplying its id.
 */
final class BackedScopeTest extends TestCase
{
    /** @var array<int, array{0:string,1:string,2:array}> */
    private array $calls = [];

    private function storage(): GoogleDriveStorage
    {
        $files = [
            'FCRM'   => ['id' => 'FCRM', 'name' => 'CRM', 'parents' => ['ROOTID']],
            'FALICE' => ['id' => 'FALICE', 'name' => 'Alice', 'parents' => ['FCRM']],
            'FBOB'   => ['id' => 'FBOB', 'name' => 'Bob', 'parents' => ['FCRM']],
            'A1'     => ['id' => 'A1', 'name' => 'alice.pdf', 'parents' => ['FALICE']],
            'B1'     => ['id' => 'B1', 'name' => 'bob.pdf', 'parents' => ['FBOB']],
        ];
        $calls = &$this->calls;
        $factory = new class($files, $calls) extends GoogleClientFactory {
            private array $callsRef;
            public function __construct(private array $files, array &$calls)
            {
                $this->callsRef = &$calls;
            }
            public function get(string $url, array $scopes, ?string $subject = null): array
            {
                $this->callsRef[] = ['GET', $url, []];
                if (preg_match("/[?&]q=([^&]+)/", $url, $m)) {
                    $q = rawurldecode($m[1]);
                    preg_match("/name='([^']*)'/", $q, $n);
                    preg_match("/'([^']*)' in parents/", $q, $p);
                    foreach ($this->files as $f) {
                        $parent = $p[1] === 'root' ? 'ROOTID' : $p[1];
                        if (isset($n[1]) && $f['name'] === $n[1] && in_array($parent, $f['parents'], true)) {
                            return ['files' => [['id' => $f['id'], 'name' => $f['name']]]];
                        }
                    }
                    return ['files' => []];
                }
                preg_match('#/files/([^/?]+)#', $url, $m);
                if (!isset($this->files[$m[1] ?? ''])) {
                    throw new TransientError('not found', 404);
                }
                return $this->files[$m[1]];
            }
            public function patch(string $url, array $payload, array $scopes, ?string $subject = null): array
            {
                $this->callsRef[] = ['PATCH', $url, $payload];
                preg_match('#/files/([^/?]+)#', $url, $m);
                return ['id' => $m[1]] + $payload;
            }
            public function post(string $url, array $payload, array $scopes, ?string $subject = null): array
            {
                throw new \LogicException('no folder creation expected');
            }
        };
        return new GoogleDriveStorage($factory, 'user@example.com');
    }

    private function patches(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === 'PATCH'));
    }

    public function testInScopeChecksDirectParent(): void
    {
        $s = $this->storage();
        $this->assertTrue($s->inScope('A1', 'CRM/Alice'));
        $this->assertFalse($s->inScope('B1', 'CRM/Alice'));
        $this->assertFalse($s->inScope('NOPE', 'CRM/Alice'));
        $this->assertFalse($s->inScope('A1', 'CRM/Nobody'));
        $this->assertFalse($s->inScope("A1' or '1", 'CRM/Alice'));
        $this->assertTrue($s->inScope('B1', ''), 'unscoped is unchanged');
    }

    public function testFindPkRefusesAnIdOutsideTheScope(): void
    {
        $s = $this->storage();
        $q = BackedQuery::create(BackedScopeTestFile::class, $s, 'CRM/Alice');
        $this->assertNull($q->findPk('B1'));
        $hit = $q->findPk('A1');
        $this->assertNotNull($hit);
        $this->assertSame('alice.pdf', $hit->getFileName());
        $this->assertNotNull(BackedQuery::create(BackedScopeTestFile::class, $s)->findPk('B1'), 'unscoped findPk unchanged');
    }

    private function clientEntity(string $id, string $scope): BackedScopeTestFile
    {
        $e = new BackedScopeTestFile();
        $e->setStorage($this->storage())->setScope($scope);
        $e->fromArray(['IdDriveFile' => $id, 'FileName' => 'renamed.pdf']);
        return $e;
    }

    public function testSaveRefusesAClientIdOutsideTheScope(): void
    {
        $e = $this->clientEntity('B1', 'CRM/Alice');
        try {
            $e->save();
            $this->fail('save() of an out-of-scope id must throw');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('scope', $ex->getMessage());
        }
        $this->assertSame([], $this->patches());
    }

    public function testSaveInScopeStillPatches(): void
    {
        $this->assertTrue($this->clientEntity('A1', 'CRM/Alice')->save());
        $this->assertCount(1, $this->patches());
        $this->assertSame(['name' => 'renamed.pdf'], $this->patches()[0][2]);
    }

    public function testDeleteRefusesAClientIdOutsideTheScope(): void
    {
        $this->assertFalse($this->clientEntity('B1', 'CRM/Alice')->delete());
        $this->assertSame([], $this->patches());
    }

    public function testDeleteInScopeAndUnscopedStillTrash(): void
    {
        $this->assertTrue($this->clientEntity('A1', 'CRM/Alice')->delete());
        $this->assertTrue($this->clientEntity('B1', '')->delete());
        $this->assertCount(2, $this->patches());
        $this->assertSame(['trashed' => true], $this->patches()[1][2]);
    }
}
