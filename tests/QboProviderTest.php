<?php
// tests/QboProviderTest.php — Run: php tests/QboProviderTest.php
// en_de passthrough stub BEFORE loading ConnectionStore (legacy helper absent here)
if (!function_exists('en_de')) {
    function en_de(string $action, string $s): string { return $action === 'encrypt' ? 'enc:' . $s : substr($s, 4); }
}
require __DIR__ . '/../src/Sync/Exceptions/AuthFailed.php';
require __DIR__ . '/../src/Sync/Exceptions/RateLimited.php';
require __DIR__ . '/../src/Sync/Exceptions/TransientError.php';
require __DIR__ . '/../src/Sync/Exceptions/ValidationRejected.php';
require __DIR__ . '/../src/Sync/AccountingProvider.php';
require __DIR__ . '/../src/Sync/QuickBooks/QboApiClient.php';
require __DIR__ . '/../src/Sync/ConnectionStore.php';
require __DIR__ . '/../src/Sync/QuickBooks/QboProvider.php';

use ApiGoat\Sync\QuickBooks\QboApiClient;
use ApiGoat\Sync\QuickBooks\QboProvider;

$fails = 0;
function check(string $label, $got, $want): void {
    global $fails;
    if ($got !== $want) { $fails++; echo "FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; }
    else { echo "ok   $label\n"; }
}

/** Duck-typed acct_connection row (ConnectionStore never touches Propel statics here). */
final class StubConn {
    public array $f = ['realm_id' => '9130', 'status' => 'Connected',
        'access_token_enc' => 'enc:AT', 'refresh_token_enc' => 'enc:RT',
        'access_expires_at' => '', 'refresh_expires_at' => '', 'state_json' => '{"tax_code_id":"5","exempt_code_id":"6","service_item_id":"11"}'];
    public function __construct() { $this->f['access_expires_at'] = date('Y-m-d H:i:s', time() + 3000); }
    public function getRealmId() { return $this->f['realm_id']; }
    public function getStatus() { return $this->f['status']; }
    public function setStatus($v) { $this->f['status'] = $v; }
    public function getAccessTokenEnc() { return $this->f['access_token_enc']; }
    public function setAccessTokenEnc($v) { $this->f['access_token_enc'] = $v; }
    public function getRefreshTokenEnc() { return $this->f['refresh_token_enc']; }
    public function setRefreshTokenEnc($v) { $this->f['refresh_token_enc'] = $v; }
    public function getAccessExpiresAt() { return $this->f['access_expires_at']; }
    public function setAccessExpiresAt($v) { $this->f['access_expires_at'] = $v; }
    public function setRefreshExpiresAt($v) { $this->f['refresh_expires_at'] = $v; }
    public function getStateJson() { return $this->f['state_json']; }
    public function setStateJson($v) { $this->f['state_json'] = $v; }
    public function save() {}
}

$client = new QboApiClient('cid', 'cs', 'sandbox');
$conn   = new StubConn();
$prov   = new QboProvider($client, $conn);

$calls = [];
$respond = function (array $responses) use ($client, &$calls): void {
    $client->transport = function (string $m, string $u, array $h, ?string $b) use (&$calls, &$responses): array {
        $calls[] = [$m, $u, $b];
        $r = array_shift($responses);
        return $r ?? ['status' => 200, 'body' => '{}'];
    };
};

// findMatch: escapes quotes, returns id+synctoken; documents never match
$respond([['status' => 200, 'body' => '{"QueryResponse":{"Customer":[{"Id":"58","SyncToken":"2"}]}}']]);
$m = $prov->findMatch('customer', ['fields' => ['display_name' => "O'Brien Inc"]]);
check('match found', $m, ['id' => '58', 'synctoken' => '2']);
check('quote escaped', str_contains(urldecode($calls[0][1]), "O\\'Brien"), true);
check('invoice never matched', $prov->findMatch('invoice', ['fields' => ['display_name' => 'X']]), null);

// customer create payload
$calls = [];
$respond([['status' => 200, 'body' => '{"Customer":{"Id":"70","SyncToken":"0"}}']]);
$out = $prov->push('customer', ['fields' => ['display_name' => 'Acme', 'email' => 'a@b.c', 'phone' => '555'], 'refs' => []], null);
check('customer pushed', $out, ['id' => '70', 'synctoken' => '0']);
$body = json_decode((string) $calls[0][2], true);
check('customer payload', [$body['DisplayName'], $body['PrimaryEmailAddr']['Address'], $body['PrimaryPhone']['FreeFormNumber']], ['Acme', 'a@b.c', '555']);
check('create has no Id', array_key_exists('Id', $body), false);

// sparse update carries Id + SyncToken
$calls = [];
$respond([['status' => 200, 'body' => '{"Customer":{"Id":"70","SyncToken":"1"}}']]);
$prov->push('customer', ['fields' => ['display_name' => 'Acme 2'], 'refs' => []], ['remote_id' => '70', 'synctoken' => '0']);
$body = json_decode((string) $calls[0][2], true);
check('sparse update', [$body['Id'], $body['SyncToken'], $body['sparse']], ['70', '0', true]);

// invoice payload: lines, item/tax refs from state, tax-mismatch warning
$invDto = ['fields' => ['doc_number' => 'INV-1', 'txn_date' => '2026-07-01', 'due_date' => '2026-07-31', 'email' => 'bill@a.c'],
    'refs' => ['customer' => ['remote_id' => '70']],
    'lines' => [['description' => 'Work', 'qty' => '2', 'unit_price' => '50.00']],
    'taxes' => ['gst' => '5.00', 'qst' => '9.98']];
$calls = [];
$respond([['status' => 200, 'body' => '{"Invoice":{"Id":"301","SyncToken":"0","TxnTaxDetail":{"TotalTax":14.98}}}']]);
$out = $prov->push('invoice', $invDto, null);
$body = json_decode((string) $calls[0][2], true);
check('invoice customer ref', $body['CustomerRef']['value'], '70');
check('invoice line', [$body['Line'][0]['Amount'], $body['Line'][0]['SalesItemLineDetail']['Qty'], $body['Line'][0]['SalesItemLineDetail']['ItemRef']['value'], $body['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value']], [100.0, 2.0, '11', '5']);
check('tax excluded mode', $body['GlobalTaxCalculation'], 'TaxExcluded');
check('no warning on tax match', array_key_exists('warning', $out), false);

$calls = [];
$respond([['status' => 200, 'body' => '{"Invoice":{"Id":"301","SyncToken":"1","TxnTaxDetail":{"TotalTax":11.00}}}']]);
$out = $prov->push('invoice', $invDto, ['remote_id' => '301', 'synctoken' => '0']);
check('tax mismatch flagged', str_contains($out['warning'] ?? '', 'Tax mismatch'), true);

// expense payload: Purchase with account/category/vendor refs, method map
$expDto = ['fields' => ['txn_date' => '2026-07-02', 'amount' => '80.00', 'method' => 'Credit card', 'reference' => 'R-9', 'note' => 'Drill'],
    'refs' => ['vendor' => ['remote_id' => '90'], 'account' => ['remote_id' => '35'], 'category' => ['remote_id' => '41']],
    'taxes' => ['gst' => '4.00', 'qst' => '7.98']];
$calls = [];
$respond([['status' => 200, 'body' => '{"Purchase":{"Id":"501","SyncToken":"0","TxnTaxDetail":{"TotalTax":11.98}}}']]);
$prov->push('expense', $expDto, null);
$body = json_decode((string) $calls[0][2], true);
check('purchase type', $body['PaymentType'], 'CreditCard');
check('purchase accounts', [$body['AccountRef']['value'], $body['Line'][0]['AccountBasedExpenseLineDetail']['AccountRef']['value']], ['35', '41']);
check('purchase vendor', [$body['EntityRef']['value'], $body['EntityRef']['type']], ['90', 'Vendor']);

// expense without account/category refs is rejected with guidance
$threw = false;
try { $prov->push('expense', ['fields' => ['amount' => '1'], 'refs' => [], 'taxes' => []], null); }
catch (\ApiGoat\Sync\Exceptions\ValidationRejected $e) { $threw = true; }
check('expense needs account+category', $threw, true);

// payment payload: LinkedTxn to invoice
$payDto = ['fields' => ['amount' => '114.98', 'txn_date' => '2026-07-10', 'reference' => 'ETR-1'],
    'refs' => ['invoice' => ['remote_id' => '301'], 'customer' => ['remote_id' => '70']]];
$calls = [];
$respond([['status' => 200, 'body' => '{"Payment":{"Id":"601","SyncToken":"0"}}']]);
$prov->push('payment', $payDto, null);
$body = json_decode((string) $calls[0][2], true);
check('payment linked txn', [$body['Line'][0]['LinkedTxn'][0]['TxnId'], $body['Line'][0]['LinkedTxn'][0]['TxnType'], $body['TotalAmt']], ['301', 'Invoice', 114.98]);

// account role: match-only — refuses to create
$threw = false;
try { $prov->push('account', ['fields' => ['display_name' => 'Nope'], 'refs' => []], null); }
catch (\ApiGoat\Sync\Exceptions\ValidationRejected $e) { $threw = true; }
check('account never created', $threw, true);
$out = $prov->push('account', ['fields' => ['display_name' => 'Desjardins'], 'refs' => []], ['remote_id' => '35', 'synctoken' => '0']);
check('account passthrough when matched', $out['id'], '35');

// pullPayments: CDC parse, deleted + unlinked skipped
$cdc = ['CDCResponse' => [['QueryResponse' => [['Payment' => [
    ['Id' => '601', 'TotalAmt' => 114.98, 'TxnDate' => '2026-07-10', 'PaymentRefNum' => 'ETR-1',
     'Line' => [['LinkedTxn' => [['TxnType' => 'Invoice', 'TxnId' => '301']]]]],
    ['Id' => '602', 'status' => 'Deleted'],
    ['Id' => '603', 'TotalAmt' => 5, 'Line' => [['LinkedTxn' => [['TxnType' => 'Estimate', 'TxnId' => '9']]]]],
]]]]]];
$respond([['status' => 200, 'body' => (string) json_encode($cdc)]]);
$batch = $prov->pullPayments('2026-07-09T00:00:00-05:00');
check('one usable payment', count($batch['payments']), 1);
check('payment parsed', [$batch['payments'][0]['remote_id'], $batch['payments'][0]['invoice_remote_id'], $batch['payments'][0]['amount']], ['601', '301', '114.98']);
check('cursor advances', $batch['cursor'] !== '', true);

// tax-code resolution when state is empty: queries TaxCode list and persists
$conn2 = new StubConn();
$conn2->f['state_json'] = '{"service_item_id":"11"}';
$prov2 = new QboProvider($client, $conn2);
$respond([
    ['status' => 200, 'body' => '{"QueryResponse":{"TaxCode":[{"Id":"3","Name":"Exempt"},{"Id":"7","Name":"GST/QST QC - 9.975"}]}}'],
    ['status' => 200, 'body' => '{"Invoice":{"Id":"310","SyncToken":"0","TxnTaxDetail":{"TotalTax":14.98}}}'],
]);
$prov2->push('invoice', $invDto, null);
check('tax code resolved + cached', json_decode($conn2->f['state_json'], true)['tax_code_id'], '7');

exit($fails ? 1 : 0);
