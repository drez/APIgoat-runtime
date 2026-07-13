<?php

namespace ApiGoat\Sync;

/**
 * Pulls provider payments since the stored cursor and materializes them as
 * local payment rows on linked invoices, then recomputes the invoice rollup
 * (amount_paid / balance / status) when the invoice map declares one.
 * Loop-safe: pulled payments are linked immediately, and pushed payments are
 * skipped by their existing link (findByRemote).
 */
final class PaymentPuller
{
    /** @var callable(string,int):?array */
    private $loadRecord;
    /** @var callable(string,array):int */
    private $insertRow;
    /** @var callable(string,int,array):void */
    private $updateRow;

    public function __construct(
        private AccountingProvider $provider,
        private LinkStore $links,
        private array $map,
        callable $loadRecord,
        callable $insertRow,
        callable $updateRow
    ) {
        $this->loadRecord = $loadRecord;
        $this->insertRow  = $insertRow;
        $this->updateRow  = $updateRow;
    }

    /** @return array{inserted:int, cursor:?string} */
    public function pull(?string $cursor): array
    {
        $payTables = SyncMap::tablesByRole($this->map, 'payment');
        if (!$payTables) {
            return ['inserted' => 0, 'cursor' => $cursor];
        }
        $payTable = array_key_first($payTables);
        $cfg      = $payTables[$payTable];
        $invCol   = is_array($cfg['invoice']) ? $cfg['invoice']['column'] : $cfg['invoice'];

        $batch    = $this->provider->pullPayments($cursor);
        $inserted = 0;
        foreach ($batch['payments'] as $p) {
            if ($this->links->findByRemote('payment', $p['remote_id'])) {
                continue; // already known — pushed by us, or pulled before
            }
            $inv = $this->links->findByRemote('invoice', $p['invoice_remote_id']);
            if (!$inv) {
                continue; // a QBO payment on an invoice we don't manage
            }
            $values = [$invCol => $inv['pk']];
            foreach ($cfg['fields'] as $canonical => $col) {
                $v = match ($canonical) {
                    'amount'    => $p['amount'],
                    'txn_date'  => $p['date'],
                    'reference' => $p['reference'],
                    'method'    => $p['method'],
                    default     => null,
                };
                if ($v !== null) {
                    $values[$col] = $v;
                }
            }
            $pk = ($this->insertRow)($payTable, $values);
            $this->links->save('payment', $payTable, $pk, [
                'remote_id' => $p['remote_id'], 'synctoken' => null, 'hash' => null,
                'status' => 'Synced', 'last_error' => null,
            ]);
            $this->rollup((string) $inv['table'], (int) $inv['pk']);
            $inserted++;
        }
        return ['inserted' => $inserted, 'cursor' => $batch['cursor']];
    }

    /** Recompute paid/balance/status on the invoice from its payment rows. */
    private function rollup(string $invTable, int $invPk): void
    {
        $invCfg = SyncMap::tableConfig($this->map, $invTable);
        $r      = $invCfg['rollup'] ?? null;
        if (!$r) {
            return;
        }
        $payTables = SyncMap::tablesByRole($this->map, 'payment');
        $payTable  = array_key_first($payTables);
        $payCfg    = $payTables[$payTable];
        $invCol    = is_array($payCfg['invoice']) ? $payCfg['invoice']['column'] : $payCfg['invoice'];
        $amountCol = (string) ($r['amount_field'] ?? ($payCfg['fields']['amount'] ?? 'amount'));

        $paid = $this->sumPayments($payTable, $invCol, $invPk, $amountCol);

        $row = ($this->loadRecord)($invTable, $invPk);
        if ($row === null) {
            return;
        }
        $total = (float) ($row[$r['total_field']] ?? 0);
        ($this->updateRow)($invTable, $invPk, [
            $r['paid_field']    => $paid,
            $r['balance_field'] => max(0.0, round($total - $paid, 2)),
            $r['status_field']  => $paid >= $total - 0.001 ? $r['paid_value'] : $r['partial_value'],
        ]);
    }

    /** Overridable seam: SyncRuntime wires a SQL SUM; tests can subclass or rely on insertRow bookkeeping. */
    public $sumPayments = null; // ?callable(string $payTable, string $invCol, int $invPk, string $amountCol): float

    private function sumPayments(string $payTable, string $invCol, int $invPk, string $amountCol): float
    {
        if ($this->sumPayments !== null) {
            return (float) ($this->sumPayments)($payTable, $invCol, $invPk, $amountCol);
        }
        $st = \Propel::getConnection()->prepare("SELECT COALESCE(SUM({$amountCol}), 0) FROM {$payTable} WHERE {$invCol} = ?");
        $st->execute([$invPk]);
        return (float) $st->fetchColumn();
    }
}
