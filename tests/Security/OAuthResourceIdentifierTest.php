<?php
// Run: php tests/Security/OAuthResourceIdentifierTest.php
//
// RFC 9728: the `resource` in /.well-known/oauth-protected-resource is an
// IDENTIFIER the client must recognise as the server it dialled. MCP clients
// accept the URL they were configured with OR its origin, and reject anything
// else BEFORE a token is ever minted.
//
// The bug (apigTutor, 2026-09-13): `resource` was built from _SITE_URL, which
// on the canonical GoatCheese layout carries the admin sub-directory. A client
// configured with https://host/api/v1/mcp was told the resource was
// https://host/.admin/api/v1/mcp and refused with "Protected resource ... does
// not match expected ... (or origin)". The MCP connector could not be added at
// all, and re-pointing the client at the /.admin/ spelling did not help: both
// spellings serve the same endpoint, and only one string can be advertised.
//
// The ORIGIN satisfies the check for BOTH spellings, so a sub-directory install
// advertises that — the same reasoning issuer() already follows.

// PSR-4 shim, same reasoning as OAuthBrandingTest: the package declares no
// composer autoload section, so ApiGoat\ is mapped here by hand.
$BOOT = <<<'BOOTPHP'
require %AUTOLOAD%;
spl_autoload_register(function ($class) {
    if (strncmp($class, 'ApiGoat\\', 8) === 0) {
        $file = %SRC% . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($file)) { require $file; }
    }
});
BOOTPHP;
$BOOT = str_replace(
    ['%AUTOLOAD%', '%SRC%'],
    [var_export(__DIR__ . '/../../vendor/autoload.php', true), var_export(__DIR__ . '/../../src/', true)],
    $BOOT
);
eval($BOOT);

function ok($c, string $m): void { if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } echo "PASS  $m\n"; }

/** Re-evaluate the metadata with a given install shape. */
function metaFor(string $siteUrl, string $subDir): array
{
    $code = <<<'PHP'
        $doc = \ApiGoat\Services\OAuthMetadataService::protectedResourceMetadata(
            \ApiGoat\Services\OAuthMetadataService::issuer(),
            _SITE_URL
        );
        echo json_encode($doc);
PHP;
    global $BOOT;
    $php = '<?php ' . $BOOT
        . 'define("_SITE_URL", ' . var_export($siteUrl, true) . ');'
        . 'define("_SUB_DIR_URL", ' . var_export($subDir, true) . ');'
        . $code;
    $tmp = tempnam(sys_get_temp_dir(), 'prm') . '.php';
    file_put_contents($tmp, $php);
    $out = shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp));
    unlink($tmp);
    return json_decode((string) $out, true) ?: [];
}

// --- the canonical GoatCheese layout: app under /.admin/, front-end owns root
$sub = metaFor('https://apigtutor.apigoat.com/.admin/', '/.admin/');
ok($sub['resource'] === 'https://apigtutor.apigoat.com',
    'sub-directory install advertises the ORIGIN, never the /.admin/ path');
ok(!str_contains($sub['resource'], '.admin'),
    'the internal admin directory never leaks into the resource identifier');
ok($sub['authorization_servers'] === ['https://apigtutor.apigoat.com'],
    'the AS is still the origin');

// A client configured either way must match it.
foreach ([
    'https://apigtutor.apigoat.com/api/v1/mcp',
    'https://apigtutor.apigoat.com/.admin/api/v1/mcp',
] as $configured) {
    $p = parse_url($configured);
    $origin = $p['scheme'] . '://' . $p['host'];
    ok($sub['resource'] === $configured || $sub['resource'] === $origin,
        "a client on {$configured} matches by URL or origin");
}

// --- a root install is unchanged
$root = metaFor('https://example.com/', '/');
ok($root['resource'] === 'https://example.com/api/v1/mcp',
    'root install keeps the full MCP path (its base IS the origin)');

// --- a dev checkout served under /<project>/.admin/ keeps its own prefix
$dev = metaFor('https://gc.local/apigTutor/.admin/', '/apigTutor/.admin/');
ok($dev['resource'] === 'https://gc.local/apigTutor/.admin/api/v1/mcp',
    'a two-segment dev prefix is not origin discovery and is left alone');

echo "\nAll protected-resource identifier tests passed.\n";
