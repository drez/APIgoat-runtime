<?php

namespace ApiGoat\Tests\Utility;

use ApiGoat\Utility\MicroCache;
use ApiGoat\Utility\PublicCacheMap;
use ApiGoat\Utility\TableVersion;
use PHPUnit\Framework\TestCase;

final class PublicCacheMapTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function setUp(): void
    {
        MicroCache::flushLocal();
        PublicCacheMap::reset();
        putenv('GC_HTTPCACHE_TTL');
        putenv('GC_HTTPCACHE_VERIFY');
        putenv('GC_HTTPCACHE_MAX_KB');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        putenv('GC_HTTPCACHE_TTL');
        putenv('GC_HTTPCACHE_VERIFY');
        putenv('GC_HTTPCACHE_MAX_KB');
        PublicCacheMap::reset();
        MicroCache::flushLocal();
    }

    public function testMissingFileIsEmptyMap(): void
    {
        $map = PublicCacheMap::load(sys_get_temp_dir() . '/definitely-missing-' . uniqid() . '.php');

        self::assertSame(['_build' => '', 'routes' => []], $map);
        self::assertNull(PublicCacheMap::lookup('Thing', 'list', 'GET'));
    }

    public function testInvalidFileIsEmptyMap(): void
    {
        $map = PublicCacheMap::load($this->fixture('<?php return "nope";'));

        self::assertSame(['_build' => '', 'routes' => []], $map);
    }

    public function testFullDeclarationRoundTrips(): void
    {
        PublicCacheMap::load($this->map([
            'Thing/list/GET' => [
                'ttl'           => 30,
                'tables'        => ['thing', 'category'],
                'params'        => ['page'],
                'bypass_params' => ['debug'],
                'vary'          => ['Accept-Language'],
            ],
        ], 'b1'));

        self::assertSame('b1', PublicCacheMap::load()['_build']);
        self::assertSame([
            'ttl'           => 30,
            'tables'        => ['thing', 'category'],
            'params'        => ['page'],
            'bypass_params' => ['debug'],
            'vary'          => ['Accept-Language'],
        ], PublicCacheMap::lookup('Thing', 'list', 'GET'));
        // Method segment is case-insensitive, model/action are not.
        self::assertNotNull(PublicCacheMap::lookup('Thing', 'list', 'get'));
        self::assertNull(PublicCacheMap::lookup('thing', 'list', 'GET'));
        self::assertNull(PublicCacheMap::lookup('Thing', 'list', 'POST'));
    }

    public function testCompactForms(): void
    {
        putenv('GC_HTTPCACHE_TTL=45');
        PublicCacheMap::load($this->map([
            'A/list/GET' => 15,
            'B/list/GET' => true,
            'C/list/GET' => ['tables' => ['c']],   // ttl omitted => env default
            'D/list/GET' => false,                  // explicitly not declared
            'E/list/GET' => null,
            'bad-key'    => 10,                     // not Model/action/METHOD
        ]));

        self::assertSame(15, PublicCacheMap::lookup('A', 'list', 'GET')['ttl']);
        self::assertSame([], PublicCacheMap::lookup('A', 'list', 'GET')['tables']);
        self::assertNull(PublicCacheMap::lookup('A', 'list', 'GET')['params']);
        self::assertSame(45, PublicCacheMap::lookup('B', 'list', 'GET')['ttl']);
        self::assertSame(45, PublicCacheMap::lookup('C', 'list', 'GET')['ttl']);
        self::assertSame(['c'], PublicCacheMap::lookup('C', 'list', 'GET')['tables']);
        self::assertNull(PublicCacheMap::lookup('D', 'list', 'GET'));
        self::assertNull(PublicCacheMap::lookup('E', 'list', 'GET'));
        self::assertCount(3, PublicCacheMap::load()['routes']);
    }

    public function testEnvKnobs(): void
    {
        self::assertSame(0, PublicCacheMap::ttl());
        self::assertFalse(PublicCacheMap::verify());
        self::assertSame(512 * 1024, PublicCacheMap::maxBytes());

        putenv('GC_HTTPCACHE_TTL=120');
        putenv('GC_HTTPCACHE_VERIFY=1');
        putenv('GC_HTTPCACHE_MAX_KB=64');
        self::assertSame(120, PublicCacheMap::ttl());
        self::assertTrue(PublicCacheMap::verify());
        self::assertSame(64 * 1024, PublicCacheMap::maxBytes());

        putenv('GC_HTTPCACHE_TTL=-5');
        self::assertSame(0, PublicCacheMap::ttl());
    }

    public function testKeyIsStableAndChangesWithItsInputs(): void
    {
        $decl = ['ttl' => 60, 'tables' => ['thing'], 'params' => null, 'bypass_params' => [], 'vary' => []];

        $base = PublicCacheMap::key($decl, 'GET', 'Thing/list', ['b' => '2', 'a' => '1'], [], 'b1');
        self::assertNotNull($base);
        self::assertStringStartsWith('gc:http:' . TableVersion::ns() . ':b1:', $base);
        self::assertMatchesRegularExpression('/:[0-9a-f]{40}$/', $base);

        // Stable across calls and across query order / scalar type.
        self::assertSame($base, PublicCacheMap::key($decl, 'GET', 'Thing/list', ['a' => 1, 'b' => 2], [], 'b1'));
        self::assertSame($base, PublicCacheMap::key($decl, 'get', 'Thing/list', ['a' => '1', 'b' => '2'], [], 'b1'));

        // Each input moves it.
        self::assertNotSame($base, PublicCacheMap::key($decl, 'GET', 'Thing/list', ['a' => '1', 'b' => '2'], [], 'b2'), 'build id');
        self::assertNotSame($base, PublicCacheMap::key($decl, 'GET', 'Thing/list', ['a' => '1', 'b' => '3'], [], 'b1'), 'query value');
        self::assertNotSame($base, PublicCacheMap::key($decl, 'GET', 'Thing/list', ['a' => '1'], [], 'b1'), 'query key set');
        self::assertNotSame($base, PublicCacheMap::key($decl, 'GET', 'Thing/other', ['a' => '1', 'b' => '2'], [], 'b1'), 'route');
        self::assertNotSame($base, PublicCacheMap::key($decl, 'GET', 'Thing/list', ['a' => '1', 'b' => '2'], ['Accept-Language' => 'fr'], 'b1'), 'vary header');

        // Table generation lives in the prefix: a bump changes the key, an
        // unrelated bump does not.
        TableVersion::bump('unrelated');
        self::assertSame($base, PublicCacheMap::key($decl, 'GET', 'Thing/list', ['a' => '1', 'b' => '2'], [], 'b1'));
        TableVersion::bump('thing');
        self::assertNotSame($base, PublicCacheMap::key($decl, 'GET', 'Thing/list', ['a' => '1', 'b' => '2'], [], 'b1'), 'table gen');

        // ...and so does the RBAC ruleset generation.
        $before = PublicCacheMap::key($decl, 'GET', 'Thing/list', [], [], 'b1');
        TableVersion::bump('api_rbac@rules');
        self::assertNotSame($before, PublicCacheMap::key($decl, 'GET', 'Thing/list', [], [], 'b1'), 'rbac gen');
    }

    public function testNestedQueryIsNormalisedRecursively(): void
    {
        self::assertSame(
            ['a' => '1', 'f' => ['x' => '0', 'y' => ['k' => 'v', 'z' => '']]],
            PublicCacheMap::normalizeQuery(['f' => ['y' => ['z' => null, 'k' => 'v'], 'x' => false], 'a' => 1])
        );
    }

    public function testSizeGuardsReturnNull(): void
    {
        $decl = ['ttl' => 60, 'tables' => [], 'params' => null, 'bypass_params' => [], 'vary' => []];

        $many = [];
        for ($i = 0; $i < 33; $i++) {
            $many['p' . $i] = '1';
        }
        self::assertNull(PublicCacheMap::key($decl, 'GET', 'r', $many, [], 'b'), '> 32 params');
        array_pop($many);
        self::assertNotNull(PublicCacheMap::key($decl, 'GET', 'r', $many, [], 'b'), '32 params is fine');

        self::assertNull(PublicCacheMap::key($decl, 'GET', 'r', ['q' => str_repeat('x', 2100)], [], 'b'), '> 2048 bytes');
        self::assertNotNull(PublicCacheMap::key($decl, 'GET', 'r', ['q' => str_repeat('x', 2000)], [], 'b'));
    }

    private function map(array $routes, string $build = 'b'): string
    {
        return $this->fixture('<?php return ' . var_export(['_build' => $build, 'routes' => $routes], true) . ';');
    }

    private function fixture(string $php): string
    {
        $f = tempnam(sys_get_temp_dir(), 'gc-cachemap-');
        file_put_contents($f, $php);
        $this->files[] = $f;
        return $f;
    }
}
