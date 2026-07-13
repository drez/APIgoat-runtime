<?php
// tests/PaymentPullerTest.php — Run: php tests/PaymentPullerTest.php
require __DIR__ . '/../src/Sync/SyncMap.php';
require __DIR__ . '/../src/Sync/LinkStore.php';
require __DIR__ . '/../src/Sync/AccountingProvider.php';
require __DIR__ . '/../src/Sync/Exceptions/ValidationRejected.php';
require __DIR__ . '/../src/Sync/PaymentPuller.php';

use ApiGoat\Sync\AccountingProvider;
use ApiGoat\Sync\LinkStore;
use ApiGoat\Sync\PaymentPuller;

$fails = 0;
function check(string $label, $got, $want): void {
    global $fails;
    if ($got !== $want) { $fails++; echo "FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; }
    else { echo "ok   $label\n"; }
}

final class MemLinks implements LinkStore {
    public array $rows = [];
    private function k($r, $t, $p) { return "$r|$t|$p"; }
    public function find(string $role, string $table, int $pk): ?array { return $this->rows[$this->k($role, $table, $pk)] ?? null; }
    public function save(string $role, string $table, int $pk, array $link): void { $this->rows[$this->k($role, $table, $pk)] = $link; }
    public function findByRemote(string $role, string $remoteId): ?array {
        foreach ($this->rows as $k => $l) { [$r, $t, $p] = explode('|', $k); if ($r === $role && ($l['remote_id'] ?? '') === $remoteId) return ['table' => $t, 'pk' => (int) $p]; }
        return null;
    }
    public function markDeleted(string $table, int $pk): void {}
}

final class PullProvider implements AccountingProvider {
    public array $batch;
    public function push(string $role, array $dto, ?array $existing): array { return ['id' => '1', 'synctoken' => '0']; }
    public function findMatch(string $role, array $dto): ?array { return null; }
    public function pullPayments(?string $cursor): array { return ['payments' => $this->batch, 'cursor' => '2026-07-13T12:00:00-04:00']; }
}

$map = ['provider' => 'quickbooks', 'tables' => [
    'invoice' => ['role' => 'invoice', 'customer' => 'id_company', 'fields' => [],
        'rollup' => ['amount_field' => 'amount', 'paid_field' => 'amount_paid', 'total_field' => 'total',
                     'balance_field' => 'balance', 'status_field' => 'status', 'paid_value' => 'Paid', 'partial_value' => 'Partial']],
    'payment' => ['role' => 'payment', 'invoice' => 'id_invoice',
        'fields' => ['amount' => 'amount', 'txn_date' => 'paid_on', 'reference' => 'reference']],
]];

$db = ['invoice' => [12 => ['id_company' => 3, 'total' => '114.98', 'amount_paid' => '0', 'balance' => '114.98', 'status' => 'Unpaid']],
       'payment' => []];
$nextPk = 100;
$loadRecord = function (string $t, int $pk) use (&$db): ?array { return $db[$t][$pk] ?? null; };
$insertRow  = function (string $t, array $values) use (&$db, &$nextPk): int { $db[$t][++$nextPk] = $values; return $nextPk; };
$updateRow  = function (string $t, int $pk, array $values) use (&$db): void { $db[$t][$pk] = $values + $db[$t][$pk]; };

$links = new MemLinks();
$links->save('invoice', 'invoice', 12, ['remote_id' => '301', 'synctoken' => '0', 'hash' => 'h', 'status' => 'Synced']);

$prov = new PullProvider();
$prov->batch = [
    ['remote_id' => '601', 'invoice_remote_id' => '301', 'amount' => '114.98', 'date' => '2026-07-10', 'method' => null, 'reference' => 'ETR-1'],
    ['remote_id' => '699', 'invoice_remote_id' => '999', 'amount' => '5', 'date' => '2026-07-10', 'method' => null, 'reference' => ''],  // unknown invoice
];
$puller = new PaymentPuller($prov, $links, $map, $loadRecord, $insertRow, $updateRow);
$puller->sumPayments = function (string $t, string $col, int $pk, string $amountCol) use (&$db): float {
    $s = 0.0;
    foreach ($db[$t] as $r) { if ((int) ($r[$col] ?? 0) === $pk) { $s += (float) ($r[$amountCol] ?? 0); } }
    return $s;
};
$res = $puller->pull(null);

check('one inserted', $res['inserted'], 1);
check('cursor forwarded', $res['cursor'], '2026-07-13T12:00:00-04:00');
check('payment row values', [$db['payment'][101]['id_invoice'], $db['payment'][101]['amount'], $db['payment'][101]['paid_on'], $db['payment'][101]['reference']], [12, '114.98', '2026-07-10', 'ETR-1']);
check('payment linked', $links->findByRemote('payment', '601'), ['table' => 'payment', 'pk' => 101]);
check('rollup paid', $db['invoice'][12]['amount_paid'], 114.98);
check('rollup balance', $db['invoice'][12]['balance'], 0.0);
check('rollup status', $db['invoice'][12]['status'], 'Paid');

// Second pull with the same batch: already-linked payment 601 is skipped
$res2 = $puller->pull($res['cursor']);
check('idempotent re-pull', $res2['inserted'], 0);
check('no duplicate row', count($db['payment']), 1);

exit($fails ? 1 : 0);
