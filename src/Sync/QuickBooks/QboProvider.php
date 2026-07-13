<?php

namespace ApiGoat\Sync\QuickBooks;

use ApiGoat\Sync\AccountingProvider;
use ApiGoat\Sync\ConnectionStore;
use ApiGoat\Sync\Exceptions;

/**
 * QuickBooks Online adapter. Maps canonical sync roles to QBO entities:
 * customer→Customer, vendor→Vendor, invoice→Invoice, expense→Purchase,
 * payment→Payment, account|category→Account (name-match only, never created).
 * Canada edition: taxes ride line-level TaxCodeRef (GlobalTaxCalculation
 * TaxExcluded); the combined GST/QST code, the Exempt code and the generic
 * "Services" item are resolved once per realm and cached in state_json.
 */
final class QboProvider implements AccountingProvider
{
    private const ENTITY = [
        'customer' => 'Customer', 'vendor' => 'Vendor', 'invoice' => 'Invoice',
        'expense' => 'Purchase', 'payment' => 'Payment', 'account' => 'Account', 'category' => 'Account',
    ];
    private const PAYMENT_TYPE = ['Cheque' => 'Check', 'Credit card' => 'CreditCard'];
    private const MATCH_ONLY   = ['account', 'category'];
    private const DOCUMENTS    = ['invoice', 'expense', 'payment'];

    public function __construct(private QboApiClient $client, private $conn)
    {
    }

    /** Feature gate: null when the behavior/models/keys/connection are absent. */
    public static function fromProject(): ?self
    {
        if (!ConnectionStore::available()) {
            return null;
        }
        $conn = ConnectionStore::find();
        if (!$conn || $conn->getStatus() !== 'Connected') {
            return null;
        }
        try {
            return new self(QboApiClient::fromEnv(), $conn);
        } catch (Exceptions\AuthFailed) {
            return null;
        }
    }

    private function token(): string
    {
        return ConnectionStore::accessToken($this->conn, $this->client);
    }

    private function realm(): string
    {
        return (string) $this->conn->getRealmId();
    }

    public function findMatch(string $role, array $dto): ?array
    {
        $entity = self::ENTITY[$role] ?? null;
        if (!$entity || in_array($role, self::DOCUMENTS, true)) {
            return null; // documents are ours alone — never adopt a QBO one
        }
        $name = (string) ($dto['fields']['display_name'] ?? '');
        if ($name === '') {
            return null;
        }
        $nameProp = in_array($role, self::MATCH_ONLY, true) ? 'Name' : 'DisplayName';
        $hit = $this->queryByName($entity, $nameProp, $name);
        // A company that is both Customer and Vendor lives in QBO under a decorated
        // DisplayName (QBO enforces cross-entity name uniqueness) — look for it too.
        if (!$hit && $role === 'vendor') {
            $hit = $this->queryByName($entity, $nameProp, $name . ' (Vendor)');
        }
        return $hit;
    }

    private function queryByName(string $entity, string $nameProp, string $name): ?array
    {
        $q    = "select Id, SyncToken from {$entity} where {$nameProp} = '" . str_replace("'", "\\'", $name) . "'";
        $rows = $this->client->query($this->realm(), $q, $this->token())[$entity] ?? [];
        return $rows
            ? ['id' => (string) $rows[0]['Id'], 'synctoken' => (string) ($rows[0]['SyncToken'] ?? '0')]
            : null;
    }

    public function push(string $role, array $dto, ?array $existing): array
    {
        $remoteId = $existing['remote_id'] ?? $existing['id'] ?? null;
        if (in_array($role, self::MATCH_ONLY, true)) {
            if ($remoteId) {
                return ['id' => (string) $remoteId, 'synctoken' => $existing['synctoken'] ?? null];
            }
            throw new Exceptions\ValidationRejected(
                "No QuickBooks account named '" . ($dto['fields']['display_name'] ?? '?')
                . "' — create or rename it in QuickBooks (Chart of accounts), then retry the job."
            );
        }

        $entity  = self::ENTITY[$role] ?? throw new Exceptions\ValidationRejected("Unknown sync role '{$role}'");
        $payload = $this->payload($role, $dto);
        $isUpdate = (bool) $remoteId;
        if ($isUpdate) {
            $payload['Id']        = (string) $remoteId;
            $payload['SyncToken'] = (string) ($existing['synctoken'] ?? '0');
            $payload['sparse']    = true;
        }
        try {
            $res = $this->client->api($this->realm(), 'POST', strtolower($entity), $payload, $this->token());
        } catch (Exceptions\ValidationRejected $e) {
            // QBO enforces DisplayName uniqueness ACROSS Customer+Vendor: a vendor
            // create colliding with an existing customer retries once, decorated.
            if (!$isUpdate && $role === 'vendor'
                && preg_match('/already using this name|Duplicate Name/i', $e->getMessage())) {
                $payload['DisplayName'] = (string) ($dto['fields']['display_name'] ?? '') . ' (Vendor)';
                $res = $this->client->api($this->realm(), 'POST', strtolower($entity), $payload, $this->token());
            } else {
                throw $e;
            }
        }
        $ent = $res[$entity] ?? [];
        $out = ['id' => (string) ($ent['Id'] ?? ''), 'synctoken' => (string) ($ent['SyncToken'] ?? '0')];

        // Books-diverge guard: local GST+QST vs what QBO computed (> 2¢ = flag).
        if (in_array($role, ['invoice', 'expense'], true) && isset($dto['taxes'])) {
            $ours   = round((float) ($dto['taxes']['gst'] ?? 0) + (float) ($dto['taxes']['qst'] ?? 0), 2);
            $theirs = round((float) ($ent['TxnTaxDetail']['TotalTax'] ?? $ours), 2);
            if (abs($ours - $theirs) > 0.02) {
                $out['warning'] = "Tax mismatch: local {$ours} vs QuickBooks {$theirs} — check the realm's GST/QST code";
            }
        }
        return $out;
    }

    private function payload(string $role, array $dto): array
    {
        $f = $dto['fields'] ?? [];
        switch ($role) {
            case 'customer':
            case 'vendor':
                $p = ['DisplayName' => (string) ($f['display_name'] ?? '')];
                if (!empty($f['email'])) {
                    $p['PrimaryEmailAddr'] = ['Address' => (string) $f['email']];
                }
                if (!empty($f['phone'])) {
                    $p['PrimaryPhone'] = ['FreeFormNumber' => (string) $f['phone']];
                }
                return $p;

            case 'invoice': {
                if (empty($dto['refs']['customer']['remote_id'])) {
                    throw new Exceptions\ValidationRejected('Invoice has no customer to bill in QuickBooks');
                }
                $taxable = ((float) ($dto['taxes']['gst'] ?? 0)) > 0 || ((float) ($dto['taxes']['qst'] ?? 0)) > 0;
                $taxRef  = $this->resolveTaxCode($taxable);
                $itemRef = $this->resolveServiceItem();
                $lines   = [];
                foreach (($dto['lines'] ?? []) as $l) {
                    $qty  = (float) ($l['qty'] ?? 1);
                    $unit = (float) ($l['unit_price'] ?? 0);
                    // Per-line taxability overrides the invoice-level code when the map
                    // supplies taxable_gst/taxable_qst. QBO has no GST-only/QST-only code,
                    // so a mixed line is rejected rather than silently mis-taxed.
                    $lineTaxRef = $taxRef;
                    if (array_key_exists('taxable_gst', $l) || array_key_exists('taxable_qst', $l)) {
                        $gstYes = ((string) ($l['taxable_gst'] ?? '')) === 'Yes';
                        $qstYes = ((string) ($l['taxable_qst'] ?? '')) === 'Yes';
                        if ($gstYes !== $qstYes) {
                            throw new Exceptions\ValidationRejected(
                                'Line "' . (string) ($l['description'] ?? '')
                                . '": GST-only or QST-only lines are not supported by the QuickBooks sync'
                                . ' — make the line fully taxable or fully exempt'
                            );
                        }
                        $lineTaxRef = $this->resolveTaxCode($gstYes && $qstYes);
                    }
                    $lines[] = [
                        'DetailType'  => 'SalesItemLineDetail',
                        'Amount'      => (float) round($qty * $unit, 2),
                        'Description' => (string) ($l['description'] ?? ''),
                        'SalesItemLineDetail' => [
                            'ItemRef' => ['value' => $itemRef], 'Qty' => $qty, 'UnitPrice' => $unit,
                            'TaxCodeRef' => ['value' => $lineTaxRef],
                        ],
                    ];
                }
                // Invoice-level discount → a DiscountLineDetail line (percent or amount).
                if (((float) ($f['discount'] ?? 0)) > 0) {
                    if (((string) ($f['discount_type'] ?? '%')) === '%') {
                        $lines[] = [
                            'DetailType'        => 'DiscountLineDetail',
                            'DiscountLineDetail' => ['PercentBased' => true, 'DiscountPercent' => (float) $f['discount']],
                        ];
                    } else {
                        $lines[] = [
                            'DetailType'        => 'DiscountLineDetail',
                            'Amount'            => (float) $f['discount'],
                            'DiscountLineDetail' => ['PercentBased' => false],
                        ];
                    }
                }
                $p = ['CustomerRef' => ['value' => (string) $dto['refs']['customer']['remote_id']],
                      'Line' => $lines, 'GlobalTaxCalculation' => 'TaxExcluded'];
                if (!empty($f['doc_number'])) { $p['DocNumber'] = (string) $f['doc_number']; }
                if (!empty($f['txn_date']))   { $p['TxnDate'] = (string) $f['txn_date']; }
                if (!empty($f['due_date']))   { $p['DueDate'] = (string) $f['due_date']; }
                if (!empty($f['email']))      { $p['BillEmail'] = ['Address' => (string) $f['email']]; }
                if (!empty($f['note']))       { $p['CustomerMemo'] = ['value' => mb_substr(strip_tags((string) $f['note']), 0, 1000)]; }
                return $p;
            }

            case 'expense': {
                if (empty($dto['refs']['account']['remote_id']) || empty($dto['refs']['category']['remote_id'])) {
                    throw new Exceptions\ValidationRejected(
                        'Expense needs both a payment account (bank_account) and a category mapped to QuickBooks accounts'
                    );
                }
                $taxable = ((float) ($dto['taxes']['gst'] ?? 0)) > 0 || ((float) ($dto['taxes']['qst'] ?? 0)) > 0;
                $p = [
                    'PaymentType' => self::PAYMENT_TYPE[(string) ($f['method'] ?? '')] ?? 'Cash',
                    'AccountRef'  => ['value' => (string) $dto['refs']['account']['remote_id']],
                    'GlobalTaxCalculation' => 'TaxExcluded',
                    'Line' => [[
                        'DetailType'  => 'AccountBasedExpenseLineDetail',
                        'Amount'      => (float) ($f['amount'] ?? 0),
                        'Description' => (string) ($f['note'] ?? ''),
                        'AccountBasedExpenseLineDetail' => [
                            'AccountRef' => ['value' => (string) $dto['refs']['category']['remote_id']],
                            'TaxCodeRef' => ['value' => $this->resolveTaxCode($taxable)],
                        ],
                    ]],
                ];
                if (isset($dto['refs']['vendor']['remote_id'])) {
                    $p['EntityRef'] = ['value' => (string) $dto['refs']['vendor']['remote_id'], 'type' => 'Vendor'];
                }
                if (!empty($f['txn_date']))  { $p['TxnDate'] = (string) $f['txn_date']; }
                if (!empty($f['reference'])) { $p['DocNumber'] = mb_substr((string) $f['reference'], 0, 21); }
                return $p;
            }

            case 'payment': {
                if (empty($dto['refs']['invoice']['remote_id']) || empty($dto['refs']['customer']['remote_id'])) {
                    throw new Exceptions\ValidationRejected('Payment needs a synced invoice (and its customer) in QuickBooks');
                }
                $amount = (float) ($f['amount'] ?? 0);
                return [
                    'CustomerRef'   => ['value' => (string) $dto['refs']['customer']['remote_id']],
                    'TotalAmt'      => $amount,
                    'TxnDate'       => (string) ($f['txn_date'] ?? date('Y-m-d')),
                    'PaymentRefNum' => mb_substr((string) ($f['reference'] ?? ''), 0, 21),
                    'Line' => [[
                        'Amount'    => $amount,
                        'LinkedTxn' => [['TxnId' => (string) $dto['refs']['invoice']['remote_id'], 'TxnType' => 'Invoice']],
                    ]],
                ];
            }
        }
        throw new Exceptions\ValidationRejected("No payload builder for role '{$role}'");
    }

    /** Combined GST/QST code (taxable) or Exempt (zero-tax), cached in state_json. */
    private function resolveTaxCode(bool $taxable): string
    {
        $state = ConnectionStore::getState($this->conn);
        $key   = $taxable ? 'tax_code_id' : 'exempt_code_id';
        if (!empty($state[$key])) {
            return (string) $state[$key];
        }
        $rows = $this->client->query($this->realm(), 'select Id, Name from TaxCode', $this->token())['TaxCode'] ?? [];
        foreach ($rows as $r) {
            $n = (string) ($r['Name'] ?? '');
            if (empty($state['tax_code_id']) && preg_match('/(GST|TPS).*(QST|TVQ)/i', $n)) {
                $state['tax_code_id'] = (string) $r['Id'];
            }
            if (empty($state['exempt_code_id']) && preg_match('/exempt|exon[eé]r/i', $n)) {
                $state['exempt_code_id'] = (string) $r['Id'];
            }
        }
        if (empty($state[$key])) {
            throw new Exceptions\ValidationRejected($taxable
                ? "No combined GST/QST tax code found in this QuickBooks company — create one (Taxes → Sales tax) or set state_json.tax_code_id on acct_connection"
                : "No Exempt tax code found — set state_json.exempt_code_id on acct_connection");
        }
        ConnectionStore::setState($this->conn, $state);
        return (string) $state[$key];
    }

    /** The single generic "Services" item invoice lines ride on (v1: no catalog sync). */
    private function resolveServiceItem(): string
    {
        $state = ConnectionStore::getState($this->conn);
        if (!empty($state['service_item_id'])) {
            return (string) $state['service_item_id'];
        }
        $rows = $this->client->query($this->realm(), "select Id from Item where Name = 'Services'", $this->token())['Item'] ?? [];
        if (!$rows) {
            $inc = $this->client->query($this->realm(), "select Id from Account where AccountType = 'Income' maxresults 1", $this->token())['Account'] ?? [];
            if (!$inc) {
                throw new Exceptions\ValidationRejected('No Income account in QuickBooks to attach the generic Services item to');
            }
            $res  = $this->client->api($this->realm(), 'POST', 'item', [
                'Name' => 'Services', 'Type' => 'Service', 'IncomeAccountRef' => ['value' => (string) $inc[0]['Id']],
            ], $this->token());
            $rows = [['Id' => (string) ($res['Item']['Id'] ?? '')]];
        }
        $state['service_item_id'] = (string) $rows[0]['Id'];
        ConnectionStore::setState($this->conn, $state);
        return $state['service_item_id'];
    }

    public function pullPayments(?string $cursor): array
    {
        $since     = $cursor ?: date('c', time() - 86400);
        $newCursor = date('c'); // captured BEFORE the call so overlapping changes are never missed
        $res = $this->client->api($this->realm(), 'GET', 'cdc?entities=Payment&changedSince=' . rawurlencode($since), null, $this->token());
        $payments = [];
        foreach (($res['CDCResponse'][0]['QueryResponse'] ?? []) as $qr) {
            foreach (($qr['Payment'] ?? []) as $p) {
                if (($p['status'] ?? '') === 'Deleted') {
                    continue;
                }
                $invoiceId = null;
                foreach (($p['Line'] ?? []) as $line) {
                    foreach (($line['LinkedTxn'] ?? []) as $lt) {
                        if (($lt['TxnType'] ?? '') === 'Invoice') {
                            $invoiceId = (string) $lt['TxnId'];
                            break 2;
                        }
                    }
                }
                if ($invoiceId === null) {
                    continue;
                }
                $payments[] = [
                    'remote_id'         => (string) $p['Id'],
                    'invoice_remote_id' => $invoiceId,
                    'amount'            => (string) ($p['TotalAmt'] ?? '0'),
                    'date'              => (string) ($p['TxnDate'] ?? date('Y-m-d')),
                    'method'            => null,
                    'reference'         => (string) ($p['PaymentRefNum'] ?? ''),
                ];
            }
        }
        return ['payments' => $payments, 'cursor' => $newCursor];
    }
}
