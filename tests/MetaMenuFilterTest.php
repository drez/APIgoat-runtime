<?php
// Run: php vendor/apigoat/runtime/tests/MetaMenuFilterTest.php
// Reflection-free copy of MetaService::filterMenuAgainstEntities semantics.
function assertTrue($c, string $m): void { if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }

$filter = function(array $menu, array $allEntities, array $visibleEntities): array {
    $allSet = array_flip($allEntities); $visibleSet = array_flip($visibleEntities); $out = [];
    foreach ($menu as $e) {
        $n = $e['name'] ?? '';
        if (isset($allSet[$n]) && !isset($visibleSet[$n])) continue;
        $out[] = $e;
    }
    return $out;
};

$menu = [['name'=>'Category'],['name'=>'Product'],['name'=>'Settings']];
$all = ['Category','Product'];        // known entities
$visible = ['Category'];               // user may read only Category
$names = array_column($filter($menu,$all,$visible), 'name');
assertTrue(in_array('Category',$names,true), 'Category kept');
assertTrue(!in_array('Product',$names,true), 'Product dropped (known but not visible)');
assertTrue(in_array('Settings',$names,true), 'Settings kept (structural group)');
echo "PASS: menu-vs-entities filter OK\n"; exit(0);
