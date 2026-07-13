<?php
// tests/SyncEngineTest.php — Run: php tests/SyncEngineTest.php (from the runtime repo root)
require __DIR__ . '/../src/Sync/SyncMap.php';
require __DIR__ . '/../src/Sync/LinkStore.php';
require __DIR__ . '/../src/Sync/AccountingProvider.php';
require __DIR__ . '/../src/Sync/Exceptions/AuthFailed.php';
require __DIR__ . '/../src/Sync/Exceptions/RateLimited.php';
require __DIR__ . '/../src/Sync/Exceptions/TransientError.php';
require __DIR__ . '/../src/Sync/Exceptions/ValidationRejected.php';
require __DIR__ . '/../src/Sync/SyncEngine.php';

use ApiGoat\Sync\AccountingProvider;
use ApiGoat\Sync\LinkStore;
use ApiGoat\Sync\SyncEngine;

$fails = 0;
function check(string $label, $got, $want): void {
    global $fails;
    if ($got !== $want) { $fails++; echo "FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; }
    else { echo "ok   $label\n"; }
}

final class MemLinks implements LinkStore {
    public array $rows = [];
    private function k(string $r, string $t, int $p): string { return "$r|$t|$p"; }
    public function find(string $role, string $table, int $pk): ?array { return $this->rows[$this->k($role, $table, $pk)] ?? null; }
    public function save(string $role, string $table, int $pk, array $link): void { $this->rows[$this->k($role, $table, $pk)] = $link + ['status' => 'Synced']; }
    public function findByRemote(string $role, string $remoteId): ?array {
        foreach ($this->rows as $k => $l) { [$r, $t, $p] = explode('|', $k); if ($r === $role && $l['remote_id'] === $remoteId) return ['table' => $t, 'pk' => (int) $p]; }
        return null;
    }
    public function markDeleted(string $table, int $pk): void {}
}

final class FakeProvider implements AccountingProvider {
    public array $pushes = [];          // list of [role, dto, existing]
    public array $matches = [];         // "role|display_name" => ['id'=>..,'synctoken'=>..]
    private int $seq = 100;
    public function push(string $role, array $dto, ?array $existing): array {
        $this->pushes[] = [$role, $dto, $existing];
        if (in_array($role, ['account', 'category'], true) && !$existing) {
            throw new \ApiGoat\Sync\Exceptions\ValidationRejected("no QuickBooks account named '{$dto['fields']['display_name']}'");
        }
        $id = $existing['remote_id'] ?? (string) (++$this->seq);
        return ['id' => $id, 'synctoken' => '0'];
    }
    public function findMatch(string $role, array $dto): ?array {
        return $this->matches["$role|" . ($dto['fields']['display_name'] ?? '')] ?? null;
    }
    public function pullPayments(?string $cursor): array { return ['payments' => [], 'cursor' => (string) $cursor]; }
}

$map = ['provider' => 'quickbooks', 'tables' => [
    'invoice' => ['role' => 'invoice', 'customer' => 'id_company',
        'fields' => ['doc_number' => 'invoice_number', 'txn_date' => 'issue_date'],
        'lines' => ['table' => 'invoice_line', 'fields' => ['description' => 'description', 'qty' => 'qty', 'unit_price' => 'unit_price']],
        'taxes' => ['gst' => 'tps_amount', 'qst' => 'tvq_amount']],
    'payment' => ['role' => 'payment', 'invoice' => 'id_invoice', 'fields' => ['amount' => 'amount', 'txn_date' => 'paid_on']],
    'expense' => ['role' => 'expense', 'vendor' => 'id_company',
        'account' => ['column' => 'id_bank_account', 'table' => 'bank_account', 'label' => 'name'],
        'fields' => ['amount' => 'amount']],
    'company' => ['roles' => ['customer' => [], 'vendor' => ['when' => ['is_vendor' => 'Yes']]], 'fields' => ['display_name' => 'name']],
]];

$db = [
    'invoice'      => [12 => ['invoice_number' => 'INV-0012', 'issue_date' => '2026-07-01', 'id_company' => 3, 'tps_amount' => '5.00', 'tvq_amount' => '9.98']],
    'invoice_line' => [1 => ['id_invoice' => 12, 'description' => 'Work', 'qty' => '2', 'unit_price' => '50.00']],
    'payment'      => [7 => ['id_invoice' => 12, 'amount' => '114.98', 'paid_on' => '2026-07-10']],
    'expense'      => [4 => ['id_company' => 9, 'id_bank_account' => 2, 'amount' => '80.00']],
    'company'      => [3 => ['name' => 'Acme', 'is_vendor' => 'No'], 9 => ['name' => 'Depot', 'is_vendor' => 'Yes']],
    'bank_account' => [2 => ['name' => 'Desjardins']],
];
$loadRecord = fn (string $t, int $pk): ?array => $db[$t][$pk] ?? null;
$loadChildren = function (string $t, string $fk, int $pk) use ($db): array {
    return array_values(array_filter($db[$t] ?? [], fn ($r) => (int) ($r[$fk] ?? 0) === $pk));
};

// 1. invoice push: customer pushed first (dependency order), then invoice
$links = new MemLinks(); $prov = new FakeProvider();
$engine = new SyncEngine($prov, $links, $loadRecord, $loadChildren, $map);
check('invoice synced', $engine->pushRecord('invoice', 12), 'synced');
check('order: customer first', array_column($prov->pushes, 0), ['customer', 'invoice']);
check('customer linked', $links->find('customer', 'company', 3)['remote_id'], '101');
check('invoice ref carries remote id', $prov->pushes[1][1]['refs']['customer']['remote_id'], '101');
check('lines mapped', $prov->pushes[1][1]['lines'], [['description' => 'Work', 'qty' => '2', 'unit_price' => '50.00']]);
check('taxes mapped', $prov->pushes[1][1]['taxes'], ['gst' => '5.00', 'qst' => '9.98']);

// 2. unchanged re-push is a hash skip
$before = count($prov->pushes);
check('re-push skipped', $engine->pushRecord('invoice', 12), 'skipped');
check('no extra api call', count($prov->pushes), $before);

// 3. when-gate: customer-only company gets no vendor push
check('company synced', $engine->pushRecord('company', 3), 'skipped'); // already Synced as customer, is_vendor No
check('missing table', $engine->pushRecord('nope', 1), 'missing');
check('missing row', $engine->pushRecord('invoice', 999), 'missing');

// 4. findMatch links instead of creating
$links2 = new MemLinks(); $prov2 = new FakeProvider();
$prov2->matches['customer|Acme'] = ['id' => '77', 'synctoken' => '3'];
$engine2 = new SyncEngine($prov2, $links2, $loadRecord, $loadChildren, $map);
$engine2->pushRecord('invoice', 12);
check('matched customer id reused', $links2->find('customer', 'company', 3)['remote_id'], '77');

// 5. expense: vendor + unmapped account ref; no QBO match => ValidationRejected
$links3 = new MemLinks(); $prov3 = new FakeProvider();
$engine3 = new SyncEngine($prov3, $links3, $loadRecord, $loadChildren, $map);
$threw = false;
try { $engine3->pushRecord('expense', 4); } catch (\ApiGoat\Sync\Exceptions\ValidationRejected $e) { $threw = true; }
check('unmatched account rejects', $threw, true);
$prov3->matches['account|Desjardins'] = ['id' => '35', 'synctoken' => '0'];
check('expense synced once account matches', $engine3->pushRecord('expense', 4), 'synced');
check('vendor pushed as dependency', $links3->find('vendor', 'company', 9)['remote_id'] !== null, true);

// 6. payment inherits the invoice's customer ref
$links4 = new MemLinks(); $prov4 = new FakeProvider();
$engine4 = new SyncEngine($prov4, $links4, $loadRecord, $loadChildren, $map);
$engine4->pushRecord('payment', 7);
$payDto = null;
foreach ($prov4->pushes as [$role, $dto]) { if ($role === 'payment') $payDto = $dto; }
check('payment has invoice ref', isset($payDto['refs']['invoice']), true);
check('payment has customer ref', isset($payDto['refs']['customer']), true);

exit($fails ? 1 : 0);
