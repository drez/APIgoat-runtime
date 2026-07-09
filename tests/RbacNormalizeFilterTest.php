<?php
// Run: php tests/RbacNormalizeFilterTest.php   (from the runtime repo root)
//
// Regression: RbacMiddleware::normalizeFilter() fataled on PHP 8 when
// args['data'] arrived as a raw string (GET ?query=<json> left undecoded
// upstream) — string['query'] throws "Cannot access offset of type string
// on string", 500ing an unauthenticated request. The guard must pass
// non-array / filter-less bodies through untouched and still normalize
// numeric-keyed filters.

namespace Psr\Http\Server {
    if (!interface_exists(MiddlewareInterface::class, false)) {
        interface MiddlewareInterface
        {
        }
    }
}

namespace {

require __DIR__ . '/../src/Middlewares/RbacMiddleware.php';

use ApiGoat\Middlewares\RbacMiddleware;

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    if ($got === $want) {
        echo "  ok  $label\n";
    } else {
        $fail++;
        fwrite(STDERR, "FAIL  $label\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n");
    }
}

/** @return array the args after normalizeFilter ran on them */
function runNormalize(array $args): array
{
    $ref = new ReflectionClass(RbacMiddleware::class);
    $mw = $ref->newInstanceWithoutConstructor();
    $prop = $ref->getProperty('args');
    $prop->setAccessible(true);
    $prop->setValue($mw, $args);
    $mw->normalizeFilter();
    return $prop->getValue($mw);
}

// The regression: raw-string data must not fatal and must pass through.
$out = runNormalize(['model' => 'Client', 'data' => '{"filter":{"Client":[["name","x"]]}}']);
check('string data: no fatal, passed through', $out['data'], '{"filter":{"Client":[["name","x"]]}}');

$out = runNormalize(['model' => 'Client', 'data' => null]);
check('null data: untouched', $out['data'], null);

$out = runNormalize(['model' => 'Client', 'data' => ['name' => 'x']]);
check('array data without query: untouched', $out['data'], ['name' => 'x']);

$out = runNormalize(['model' => 'Client', 'data' => ['query' => ['select' => ['name']]]]);
check('query without filter: untouched', $out['data'], ['query' => ['select' => ['name']]]);

$out = runNormalize(['model' => 'Client', 'data' => ['query' => ['filter' => 'notanarray']]]);
check('non-array filter: untouched', $out['data'], ['query' => ['filter' => 'notanarray']]);

// Existing behavior: numeric-keyed filter rows normalize onto args['model'].
$out = runNormalize(['model' => 'Client', 'data' => ['query' => ['filter' => [[['name', 'x']][0]]]]]);
check('numeric-keyed filter normalizes under the request model',
    $out['data']['query']['filter'], ['Client' => [['name', 'x']]]);

// Existing behavior: model-keyed filters kept verbatim.
$out = runNormalize(['model' => 'Client', 'data' => ['query' => ['filter' => ['Other' => [['a', 'b']]]]]]);
check('model-keyed filter kept', $out['data']['query']['filter'], ['Other' => [['a', 'b']]]);

if ($fail) {
    fwrite(STDERR, "\n$fail failure(s)\n");
    exit(1);
}
echo "\nAll normalizeFilter checks passed\n";

}
