<?php
// tests/SyncMapTest.php — Run: php tests/SyncMapTest.php (from the runtime repo root)
require __DIR__ . '/../src/Sync/SyncMap.php';

use ApiGoat\Sync\SyncMap;

$fails = 0;
function check(string $label, $got, $want): void {
    global $fails;
    if ($got !== $want) { $fails++; echo "FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; }
    else { echo "ok   $label\n"; }
}

$map = ['provider' => 'quickbooks', 'tables' => [
    'invoice' => ['role' => 'invoice', 'customer' => 'id_company', 'fields' => ['doc_number' => 'invoice_number']],
    'company' => ['roles' => ['customer' => [], 'vendor' => ['when' => ['is_vendor' => 'Yes']]], 'fields' => ['display_name' => 'name']],
]];

check('tableConfig hit', SyncMap::tableConfig($map, 'invoice')['role'], 'invoice');
check('tableConfig miss', SyncMap::tableConfig($map, 'nope'), null);
check('rolesOf single', array_keys(SyncMap::rolesOf($map['tables']['invoice'])), ['invoice']);
check('rolesOf multi', array_keys(SyncMap::rolesOf($map['tables']['company'])), ['customer', 'vendor']);
check('tablesByRole', array_keys(SyncMap::tablesByRole($map, 'customer')), ['company']);
check('tablesByRole none', SyncMap::tablesByRole($map, 'payment'), []);
check('whenPasses no gate', SyncMap::whenPasses([], ['is_vendor' => 'No']), true);
check('whenPasses gate yes', SyncMap::whenPasses(['when' => ['is_vendor' => 'Yes']], ['is_vendor' => 'Yes']), true);
check('whenPasses gate no', SyncMap::whenPasses(['when' => ['is_vendor' => 'Yes']], ['is_vendor' => 'No']), false);
// when value as array → in_array membership
check('whenPasses array member', SyncMap::whenPasses(['when' => ['status' => ['Sent', 'Paid']]], ['status' => 'Paid']), true);
check('whenPasses array miss', SyncMap::whenPasses(['when' => ['status' => ['Sent', 'Paid']]], ['status' => 'Draft']), false);
// when_not: all must NOT match (scalar + array)
check('when_not blocks', SyncMap::whenPasses(['when_not' => ['status' => 'Cancelled']], ['status' => 'Cancelled']), false);
check('when_not passes', SyncMap::whenPasses(['when_not' => ['status' => 'Cancelled']], ['status' => 'Sent']), true);
check('when_not array blocks', SyncMap::whenPasses(['when_not' => ['status' => ['Cancelled', 'Void']]], ['status' => 'Void']), false);
check('when + when_not combined', SyncMap::whenPasses(['when' => ['kind' => 'A'], 'when_not' => ['status' => 'X']], ['kind' => 'A', 'status' => 'Y']), true);
check('primary roles', SyncMap::PRIMARY_ROLES, ['invoice', 'expense', 'payment']);
check('load without _BASE_DIR', SyncMap::load(), null);

exit($fails ? 1 : 0);
