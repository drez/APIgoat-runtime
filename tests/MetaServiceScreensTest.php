<?php
// Run: php vendor/apigoat/runtime/tests/MetaServiceScreensTest.php
// Verifies filterScreens() drops entities the session cannot read.

require __DIR__ . '/../src/Services/MetaFilter.php';

use ApiGoat\Services\MetaFilter;

function assertTrue($c, string $m): void { if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }

// Fake session: admin=false; may read Category, not Product.
$session = new class {
    public function isAdmin(): bool { return false; }
    public function hasRights($model = '', $letter = '') {
        return ($model === 'Category' && $letter === 'r');
    }
};

$screens = [
    'Category' => ['list' => ['columns' => ['Name']], 'edit' => ['fields' => []]],
    'Product'  => ['list' => ['columns' => ['Name']], 'edit' => ['fields' => []]],
];

$filteredScreens = MetaFilter::filterScreens($screens, $session);
assertTrue(isset($filteredScreens['Category']), 'Category screen kept');
assertTrue(!isset($filteredScreens['Product']), 'Product screen dropped (no read right)');

echo "PASS: MetaService screen/menu filtering OK\n";
exit(0);
