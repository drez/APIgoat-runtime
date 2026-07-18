<?php
// Run: php tests/AssetsCssUrlRebaseTest.php   (from the runtime repo root)
//
// Assets::rewriteCssRelativeUrls must rebase relative url() references so
// the bundle is PORTABLE across hosts: relative to the OUTPUT bundle
// location (e.g. public/css/min/), not absolutized against _SITE_URL (which
// bakes the build-time host into the artifact — the bug this test guards
// against). Already-absolute/protocol/data/fragment URLs must stay untouched.

chdir(__DIR__ . '/../src/Utility');
require __DIR__ . '/../src/Utility/Assets.php';

use ApiGoat\Utility\Assets;

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

// --- Fixture filesystem -----------------------------------------------
// tmp/
//   public/
//     css/
//       remix/remixicon.css      <- source stylesheet
//       some/sub/file.css        <- source stylesheet (deeper nesting)
//       file.css                 <- source stylesheet (same dir as output when pipeline_dir is empty)
//       min/                     <- pipeline output dir (pipeline_dir = "min")
$tmpRoot = sys_get_temp_dir() . '/assets_css_rebase_' . getmypid();
@mkdir($tmpRoot . '/public/css/remix', 0777, true);
@mkdir($tmpRoot . '/public/css/some/sub', 0777, true);
@mkdir($tmpRoot . '/public/css/min', 0777, true);
touch($tmpRoot . '/public/css/remix/remixicon.css');
touch($tmpRoot . '/public/css/some/sub/file.css');
touch($tmpRoot . '/public/css/file.css');

if (!defined('_SITE_URL')) {
    define('_SITE_URL', 'https://gc.local/vidifye/.admin/');
}
if (!defined('_BASE_DIR')) {
    define('_BASE_DIR', $tmpRoot . '/');
}

// Build without the constructor: it pulls in ApiGoat\Utility\Settings /
// Selective\Config\Configuration, which need a full app bootstrap we don't
// want for this hermetic unit test. public_dir defaults to "" which is
// exactly what this fixture's paths assume.
$assets = (new ReflectionClass(Assets::class))->newInstanceWithoutConstructor();

$ref = new ReflectionMethod(Assets::class, 'rewriteCssRelativeUrls');
$ref->setAccessible(true);

$call = function (string $css, string $sourcePath, string $outputDirRel) use ($assets, $ref) {
    return $ref->invoke($assets, $css, $sourcePath, $outputDirRel);
};

// 1. Sibling-dir source (public/css/remix) -> output (public/css/min): one
//    level up, then into remix. Query string preserved.
check(
    'sibling dir + query string',
    $call(
        'a{background:url("remixicon.ttf?t=12345")}',
        $tmpRoot . '/public/css/remix/remixicon.css',
        'public/css/min'
    ),
    'a{background:url("../remix/remixicon.ttf?t=12345")}'
);

// 2. Deeper-nested source with an already-relative-up reference.
check(
    'deep nested source with ../ in url()',
    $call(
        'a{background:url(../../img/sprite.png)}',
        $tmpRoot . '/public/css/some/sub/file.css',
        'public/css/min'
    ),
    'a{background:url("../img/sprite.png")}'
);

// 3. Source dir == output dir (pipeline_dir empty / bundle alongside source):
//    no leading ../ needed.
check(
    'same dir as bundle output',
    $call('a{background:url(icon.png)}', $tmpRoot . '/public/css/file.css', 'public/css'),
    'a{background:url("icon.png")}'
);

// 4. Absolute / protocol / data / fragment URLs are left untouched.
foreach ([
    'url(/already/absolute.png)',
    "url('http://cdn.example.com/x.png')",
    'url(https://cdn.example.com/x.png)',
    'url(//cdn.example.com/x.png)',
    'url("data:image/png;base64,AAAA")',
    'url(#fragment-only)',
] as $untouched) {
    check(
        "untouched: $untouched",
        $call("a{b:$untouched}", $tmpRoot . '/public/css/remix/remixicon.css', 'public/css/min'),
        "a{b:$untouched}"
    );
}

// 5. Portability: the rewritten output must NEVER contain _SITE_URL / an
//    absolute http(s) URL for what was a relative reference. This is the
//    actual regression check for the reported bug.
$rebased = $call(
    'a{background:url("remixicon.ttf?t=12345")}',
    $tmpRoot . '/public/css/remix/remixicon.css',
    'public/css/min'
);
check('portable: no _SITE_URL leaked in', strpos($rebased, 'gc.local') !== false, false);
check('portable: no http(s) URL leaked in', (bool) preg_match('#https?://#', $rebased), false);

echo $fail === 0 ? "\nAll good.\n" : "\n$fail check(s) FAILED.\n";
exit($fail === 0 ? 0 : 1);
