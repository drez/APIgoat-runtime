<?php
// Run: php tests/GeoServiceTest.php   (from the runtime repo root)
//
// GeoService (Nominatim geocode proxy for the `location` input type):
// param validation, limit clamping, cache-key normalization, and the
// geocode/reverseGeocode flows with a MOCKED HTTP transport — no live
// Nominatim calls, no session, no container. The parent Service class is
// stubbed so this file is runnable standalone (same pattern as
// OAuthMiddlewarePassthroughTest.php).

namespace ApiGoat\Services {
    if (!class_exists(\ApiGoat\Services\Service::class, false)) {
        class Service
        {
            public $args = [];
            public $response;
        }
    }
}

namespace {

require __DIR__ . '/../src/Services/GeoService.php';

use ApiGoat\Services\GeoService;

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    $ok = ($got === $want);
    // Loose compare for floats
    if (!$ok && is_float($want) && is_float($got)) {
        $ok = abs($got - $want) < 1e-9;
    }
    if ($ok) {
        echo "  ok  $label\n";
    } else {
        $fail++;
        fwrite(STDERR, "FAIL  $label\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n");
    }
}

/** @return GeoService with mocked transport + isolated file cache */
function makeService(array $args, callable $http): GeoService
{
    $svc = (new ReflectionClass(GeoService::class))->newInstanceWithoutConstructor();
    $svc->args = $args;
    $svc->http = $http;
    $svc->cacheDir = sys_get_temp_dir() . '/gc-geocache-test-' . getmypid();
    return $svc;
}

// Fresh cache dir per run
$testCache = sys_get_temp_dir() . '/gc-geocache-test-' . getmypid();
foreach (glob($testCache . '/*') ?: [] as $f) {
    @unlink($f);
}

echo "clampLimit\n";
check('null -> default 8', GeoService::clampLimit(null), 8);
check("'' -> default 8", GeoService::clampLimit(''), 8);
check("'abc' -> default 8", GeoService::clampLimit('abc'), 8);
check("'3' -> 3", GeoService::clampLimit('3'), 3);
check('0 -> min 1', GeoService::clampLimit(0), 1);
check('-5 -> min 1', GeoService::clampLimit(-5), 1);
check("'99' -> max 10", GeoService::clampLimit('99'), 10);
check('10 -> 10', GeoService::clampLimit(10), 10);

echo "normalizeCountry\n";
check('null -> null (worldwide)', GeoService::normalizeCountry(null), null);
check("'' -> null", GeoService::normalizeCountry(''), null);
check("'CA' -> 'ca'", GeoService::normalizeCountry('CA'), 'ca');
check("'ca, us' -> 'ca,us'", GeoService::normalizeCountry('ca, us'), 'ca,us');
check("'canada' -> false (invalid)", GeoService::normalizeCountry('canada'), false);
check("'c1' -> false (invalid)", GeoService::normalizeCountry('c1'), false);
check("'ca,' -> false (invalid)", GeoService::normalizeCountry('ca,'), false);

echo "normalizeQuery\n";
check('trim + collapse whitespace + lowercase',
    GeoService::normalizeQuery("  1260  Remembrance\tRd "), '1260 remembrance rd');
check('multibyte case folding', GeoService::normalizeQuery('MONTRÉAL'), 'montréal');

echo "parseCoord\n";
check("'abc' -> null", GeoService::parseCoord('abc', -90.0, 90.0), null);
check("'' -> null", GeoService::parseCoord('', -90.0, 90.0), null);
check('null -> null', GeoService::parseCoord(null, -90.0, 90.0), null);
check("'45.5' -> 45.5", GeoService::parseCoord('45.5', -90.0, 90.0), 45.5);
check('91 lat -> null (out of range)', GeoService::parseCoord(91, -90.0, 90.0), null);
check('-90 lat -> -90.0 (boundary ok)', GeoService::parseCoord(-90, -90.0, 90.0), -90.0);
check("'181' lng -> null (out of range)", GeoService::parseCoord('181', -180.0, 180.0), null);

echo "cache keys\n";
check('search key: whitespace/case-insensitive',
    GeoService::cacheKeySearch(' Montreal   QC ', 'ca', 8),
    GeoService::cacheKeySearch('montreal qc', 'ca', 8));
check('search key: country changes key',
    GeoService::cacheKeySearch('montreal', 'ca', 8) === GeoService::cacheKeySearch('montreal', null, 8), false);
check('search key: limit changes key',
    GeoService::cacheKeySearch('montreal', 'ca', 8) === GeoService::cacheKeySearch('montreal', 'ca', 9), false);
check('reverse key: rounded to 5 decimals (~1m)',
    GeoService::cacheKeyReverse(45.500001, -73.600004), GeoService::cacheKeyReverse(45.5000011, -73.6000041));
check('reverse key: distinct coords differ',
    GeoService::cacheKeyReverse(45.50001, -73.6) === GeoService::cacheKeyReverse(45.50002, -73.6), false);

echo "geocodeAction — param validation\n";
$neverCalled = function (string $url): ?string {
    fwrite(STDERR, "FAIL  upstream called for an invalid request: $url\n");
    exit(1);
};
[$s] = makeService(['q' => ''], $neverCalled)->geocodeAction();
check('missing q -> 400', $s, 400);
[$s] = makeService(['q' => str_repeat('x', 301)], $neverCalled)->geocodeAction();
check('oversized q -> 400', $s, 400);
[$s] = makeService(['q' => 'montreal', 'country' => 'canada'], $neverCalled)->geocodeAction();
check('bad country -> 400', $s, 400);

echo "geocodeAction — upstream + cache\n";
$calls = [];
$rows = [[
    'lat' => '45.5', 'lon' => '-73.6', 'display_name' => '1260 Remembrance Rd, Montreal',
    'address' => ['house_number' => '1260', 'road' => 'Remembrance Rd', 'city' => 'Montreal', 'postcode' => 'H3H 1A2', 'country' => 'Canada'],
]];
$http = function (string $url) use (&$calls, $rows): ?string {
    $calls[] = $url;
    return json_encode($rows);
};
$svc = makeService(['q' => 'Montreal QC', 'country' => 'CA', 'limit' => '99'], $http);
[$s, $p] = $svc->geocodeAction();
check('happy path -> 200', $s, 200);
check('passthrough rows returned', $p, $rows);
check('one upstream call made', count($calls), 1);
check('upstream URL has format=json', strpos($calls[0], 'format=json') !== false, true);
check('upstream URL has addressdetails=1', strpos($calls[0], 'addressdetails=1') !== false, true);
check('upstream URL clamps limit to 10', strpos($calls[0], 'limit=10') !== false, true);
check('upstream URL carries countrycodes=ca', strpos($calls[0], 'countrycodes=ca') !== false, true);
check('upstream URL carries the query', strpos($calls[0], 'q=Montreal+QC') !== false, true);

// Same normalized request (different whitespace/case) must be a cache HIT.
$svc2 = makeService(['q' => '  montreal   qc ', 'country' => 'ca', 'limit' => 10], $http);
[$s, $p] = $svc2->geocodeAction();
check('normalized repeat -> 200 from cache', $s, 200);
check('cache hit returns same rows', $p, $rows);
check('no second upstream call (cache)', count($calls), 1);

// Worldwide (no country) is a different cache key -> second upstream call.
$svc3 = makeService(['q' => 'montreal qc', 'limit' => 10], $http);
$svc3->geocodeAction();
check('different key (no country) -> upstream call', count($calls), 2);

echo "geocodeAction — upstream failure\n";
[$s, $p] = makeService(['q' => 'nowhere-ville-x'], function ($u) { return null; })->geocodeAction();
check('transport failure -> 502', $s, 502);
check('502 carries error message', isset($p['error']), true);
[$s] = makeService(['q' => 'nowhere-ville-y'], function ($u) { return 'not json at all'; })->geocodeAction();
check('invalid upstream JSON -> 502', $s, 502);

echo "reverseGeocodeAction\n";
[$s] = makeService(['lat' => 'abc', 'lng' => '-73.6'], $neverCalled)->reverseGeocodeAction();
check('bad lat -> 400', $s, 400);
[$s] = makeService(['lat' => '45.5'], $neverCalled)->reverseGeocodeAction();
check('missing lng -> 400', $s, 400);
[$s] = makeService(['lat' => '95', 'lng' => '-73.6'], $neverCalled)->reverseGeocodeAction();
check('lat out of range -> 400', $s, 400);

$revObj = ['lat' => '45.5', 'lon' => '-73.6', 'display_name' => 'Somewhere', 'address' => ['city' => 'Montreal', 'postcode' => 'H3H 1A2']];
$revCalls = [];
$revHttp = function (string $url) use (&$revCalls, $revObj): ?string {
    $revCalls[] = $url;
    return json_encode($revObj);
};
[$s, $p] = makeService(['lat' => '45.5', 'lng' => '-73.6'], $revHttp)->reverseGeocodeAction();
check('reverse happy path -> 200', $s, 200);
check('reverse passthrough object', $p, $revObj);
check('reverse URL carries lat', strpos($revCalls[0], 'lat=45.50000000') !== false, true);
check('reverse URL carries lon', strpos($revCalls[0], 'lon=-73.60000000') !== false, true);

// coords rounding to the same key -> cache hit, no upstream call
[$s, $p] = makeService(['lat' => '45.5000000001', 'lng' => '-73.6000000001'], $revHttp)->reverseGeocodeAction();
check('reverse cache hit on rounded coords', count($revCalls), 1);
check('reverse cache returns same object', $p, $revObj);

[$s, $p] = makeService(['lat' => '12.34', 'lng' => '56.78'], function ($u) { return json_encode(['error' => 'Unable to geocode']); })->reverseGeocodeAction();
check('nothing found -> 200', $s, 200);
check('nothing found -> {} (empty object)', $p instanceof stdClass, true);
check('nothing found -> {} serializes as {}', json_encode($p), '{}');

[$s] = makeService(['lat' => '13.34', 'lng' => '56.78'], function ($u) { return null; })->reverseGeocodeAction();
check('reverse transport failure -> 502', $s, 502);

echo "dispatch\n";
// Unknown action must 400 without requiring auth wiring — exercised via the
// action methods above; getApiResponse() itself needs the PSR-7 response +
// session and is covered by the live smoke (spec Verification #3).

// cleanup
foreach (glob($testCache . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($testCache);

if ($fail) {
    fwrite(STDERR, "\n$fail check(s) FAILED\n");
    exit(1);
}
echo "\nPASS: GeoService param validation, cache-key normalization, mocked geocode/reverse flows OK\n";
exit(0);

} // namespace {
