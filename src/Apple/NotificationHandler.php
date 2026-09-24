<?php

namespace ApiGoat\Apple;

/**
 * Applies one verified App Store Server Notification v2. Returns false when
 * the notification carries nothing to apply (TEST, a subscription we have
 * never seen claimed, a consumable refund for an unknown transaction).
 */
final class NotificationHandler
{
    /** Types that (re)state a subscription's current period / renewal state. */
    private const SUBSCRIPTION_STATE = [
        'SUBSCRIBED', 'DID_RENEW', 'DID_CHANGE_RENEWAL_STATUS', 'DID_CHANGE_RENEWAL_PREF',
        'DID_FAIL_TO_RENEW', 'GRACE_PERIOD_EXPIRED', 'EXPIRED', 'OFFER_REDEEMED',
        'RENEWAL_EXTENDED', 'REFUND_REVERSED',
    ];

    /** @param callable[] $listeners */
    public function __construct(
        private SignedDataVerifier $verifier,
        private PurchaseService $purchases,
        private array $listeners = []
    ) {
    }

    public function process(array $n): bool
    {
        $type = (string) ($n['notificationType'] ?? '');
        $data = $n['data'] ?? [];
        if ($type === 'TEST' || !\is_array($data) || empty($data['signedTransactionInfo'])) {
            return false;
        }
        $tx = $this->verifier->verify((string) $data['signedTransactionInfo']);
        $renewal = !empty($data['signedRenewalInfo']) ? $this->verifier->verify((string) $data['signedRenewalInfo']) : null;
        // SECURITY: only renewal info bound to this transaction's subscription counts.
        $renewal = TransactionRules::boundRenewal($tx, $renewal);

        if (($tx['type'] ?? '') === TransactionRules::SUBSCRIPTION
            && (\in_array($type, self::SUBSCRIPTION_STATE, true) || $type === 'REFUND' || $type === 'REVOKE')) {
            $sub = $this->purchases->subscriptionRow((string) ($tx['originalTransactionId'] ?? ''));
            if ($sub === null) {
                // Never claimed through the app yet: the app posts it on its
                // next launch (StoreKit re-delivers unfinished transactions),
                // which creates the row with a proven owner.
                return false;
            }
            $sub = $this->purchases->applySubscription($tx, $renewal, (string) $sub->getClientTable(), (int) $sub->getClientId(), $sub);
            if (($type === 'REFUND' || $type === 'REVOKE') && ($ledger = $this->purchases->ledgerRow((string) $tx['transactionId'])) !== null) {
                $ledger->setStatus($type === 'REFUND' ? 'refunded' : 'revoked');
                $ledger->setRevocationDate(\intdiv((int) ($tx['revocationDate'] ?? \time() * 1000), 1000));
                $ledger->save();
            }
            $this->emit('subscription', (string) $sub->getClientTable(), (int) $sub->getClientId(), $tx, $sub);
            return true;
        }

        if ($type === 'REFUND' || $type === 'REVOKE') {
            $ledger = $this->purchases->ledgerRow((string) ($tx['transactionId'] ?? ''));
            if ($ledger === null) {
                return false;
            }
            $ledger->setStatus($type === 'REFUND' ? 'refunded' : 'revoked');
            $ledger->setRevocationDate(\intdiv((int) ($tx['revocationDate'] ?? \time() * 1000), 1000));
            $ledger->save();
            $this->resetPaidFlag($ledger);
            $this->emit('refund', (string) $ledger->getClientTable(), (int) $ledger->getClientId(), $tx, $ledger);
            return true;
        }
        return false;
    }

    /**
     * Same rule as the Stripe webhook's resetPaidFlag: a grant that was
     * never applied (flag still 1) goes back to 0; one already consumed
     * (the project's reconciler moved it on, e.g. to 2) is left for the
     * project's refund listener to claw back.
     */
    private function resetPaidFlag(object $ledger): void
    {
        $table = (string) $ledger->getPayableTable();
        $entry = $table !== '' ? AppleIap::payable($table) : null;
        if ($entry === null || empty($entry['paid_flag_setter'])) {
            return;
        }
        $q = AppleIap::query($entry['entity']);
        $rec = $q::create()->findPk((int) $ledger->getPayableId());
        $getter = 'g' . \substr($entry['paid_flag_setter'], 1);
        if ($rec !== null && (int) $rec->{$getter}() === 1) {
            $rec->{$entry['paid_flag_setter']}(0);
            $rec->save();
        }
    }

    private function emit(string $kind, string $clientTable, int $clientId, array $tx, object $row): void
    {
        foreach ($this->listeners as $fn) {
            $fn(['kind' => $kind, 'client_table' => $clientTable, 'client_id' => $clientId, 'transaction' => $tx, 'row' => $row]);
        }
    }
}
