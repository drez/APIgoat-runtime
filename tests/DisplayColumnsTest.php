<?php
// Run: php vendor/apigoat/runtime/tests/DisplayColumnsTest.php
require __DIR__ . '/../src/Api/MetaCatalog.php';
use ApiGoat\Api\MetaCatalog;

function assertEq($a, $b, string $m): void { if ($a !== $b) { fwrite(STDERR, "FAIL: $m (".json_encode($a)." !== ".json_encode($b).")\n"); exit(1); } }

// set_main_label present → used verbatim
assertEq(MetaCatalog::displayColumnsFrom(['sku','name'], ['name']), ['sku','name'], 'main_label wins');
// absent → first fallback string column
assertEq(MetaCatalog::displayColumnsFrom(null, ['name','description']), ['name'], 'fallback first string col');
assertEq(MetaCatalog::displayColumnsFrom([], ['code']), ['code'], 'empty main_label → fallback');
// nothing → empty
assertEq(MetaCatalog::displayColumnsFrom(null, []), [], 'no columns → empty');

echo "PASS: displayColumnsFrom OK\n"; exit(0);
