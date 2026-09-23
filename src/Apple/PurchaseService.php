<?php

namespace ApiGoat\Apple;

/**
 * Claims verified App Store purchases for a client row and records them in
 * the with_apple_iap tables. Projects call this from their own endpoints —
 * they know which payable row a purchase pays for; this class knows how to
 * prove the purchase is real, unused and made by that client.
 */
final class PurchaseService
{
    public function __construct(private ?SignedDataVerifier $verifier = null)
    {
        $this->verifier ??= new SignedDataVerifier();
    }

    /** The active apple_product row for an App Store product id, or null. */
    public static function product(string $productId): ?object
    {
        $q = AppleIap::query('AppleProduct');
        return $q::create()->filterByProductId($productId)->filterByIsActive(true)->findOne();
    }

    /**
     * Consumable (boost, extension…): verify, bind to $payable, flip its
     * paid flag. Idempotent: re-posting the same transaction for the same
     * payable returns the existing ledger row (StoreKit re-delivers
     * unfinished transactions on every launch); posting it for a DIFFERENT
     * payable is refused — one purchase pays for one thing.
     *
     * @return array{transaction: object, product: object, replay: bool}
     */
    public function claimConsumable(string $jws, string $clientTable, int $clientId, string $payableTable, object $payable): array
    {
        $entry = AppleIap::payable($payableTable);
        if ($entry === null) {
            throw new \RuntimeException('not-payable');
        }
        $tx = $this->verifiedTransaction($jws, TransactionRules::CONSUMABLE, $clientTable, $clientId);
        $product = self::product((string) $tx['productId']);
        if ($product === null || (string) $product->getType() !== 'consumable'
            || \strtolower((string) $product->getPayableTable()) !== \strtolower($payableTable)) {
            throw new \RuntimeException('unknown-product');
        }

        $payableId = (int) $payable->getPrimaryKey();
        $existing = $this->ledgerRow((string) $tx['transactionId']);
        if ($existing !== null) {
            if (\strtolower((string) $existing->getPayableTable()) !== \strtolower($payableTable)
                || (int) $existing->getPayableId() !== $payableId) {
                throw new \RuntimeException('already-claimed');
            }
            return ['transaction' => $existing, 'product' => $product, 'replay' => true];
        }

        $row = $this->newLedgerRow($tx, $clientTable, $clientId);
        $row->setPayableTable(\strtolower($payableTable));
        $row->setPayableId($payableId);
        $row->save();

        if (!empty($entry['paid_flag_setter'])) {
            $payable->{$entry['paid_flag_setter']}(1);
            $payable->save();
        }
        return ['transaction' => $row, 'product' => $product, 'replay' => false];
    }

    /**
     * Auto-renewable subscription: verify and upsert the apple_subscription
     * row keyed by originalTransactionId. A subscription belongs to the first
     * client that claims it — another account presenting the same Apple
     * subscription (shared Apple ID, a second app login) is refused rather
     * than silently moving the entitlement.
     */
    public function claimSubscription(string $jws, string $clientTable, int $clientId, ?string $renewalJws = null): object
    {
        $tx = $this->verifiedTransaction($jws, TransactionRules::SUBSCRIPTION, $clientTable, $clientId);
        $product = self::product((string) $tx['productId']);
        if ($product === null || (string) $product->getType() !== 'auto_renewable') {
            throw new \RuntimeException('unknown-product');
        }
        $renewal = $renewalJws !== null && $renewalJws !== '' ? $this->verifier->verify($renewalJws) : null;

        $sub = $this->subscriptionRow((string) $tx['originalTransactionId']);
        if ($sub !== null && ((string) $sub->getClientTable() !== \strtolower($clientTable) || (int) $sub->getClientId() !== $clientId)) {
            throw new \RuntimeException('already-claimed');
        }
        if ($this->ledgerRow((string) $tx['transactionId']) === null) {
            $this->newLedgerRow($tx, $clientTable, $clientId)->save();
        }
        return $this->applySubscription($tx, $renewal, $clientTable, $clientId, $sub);
    }

    /**
     * Upsert a subscription from verified data. Also the notification path,
     * where $sub is the existing row (owner already known).
     */
    public function applySubscription(array $tx, ?array $renewal, string $clientTable, int $clientId, ?object $sub = null): object
    {
        if ($sub === null) {
            $model = AppleIap::model('AppleSubscription');
            $sub = new $model();
            $sub->setOriginalTransactionId((string) $tx['originalTransactionId']);
            $sub->setClientTable(\strtolower($clientTable));
            $sub->setClientId($clientId);
        }
        // Out-of-order delivery: never let an older transaction roll back a
        // newer expiry (a late DID_RENEW after an upgrade, a re-posted
        // restore of last month's receipt).
        $expires = isset($tx['expiresDate']) ? \intdiv((int) $tx['expiresDate'], 1000) : 0;
        if ((int) $sub->getExpiresDate() > $expires && empty($tx['revocationDate'])) {
            return $sub;
        }
        $sub->setProductId((string) $tx['productId']);
        $sub->setLastTransactionId((string) $tx['transactionId']);
        $sub->setEnvironment((string) ($tx['environment'] ?? ''));
        $sub->setExpiresDate($expires);
        if ($renewal !== null && \array_key_exists('autoRenewStatus', $renewal)) {
            $sub->setAutoRenew((int) $renewal['autoRenewStatus'] === 1 ? 1 : 0);
        }
        $sub->setStatus(TransactionRules::subscriptionStatus($tx, $renewal, \time()));
        $sub->save();
        return $sub;
    }

    /** Verify + claim rules; returns the transaction payload. */
    private function verifiedTransaction(string $jws, string $type, string $clientTable, int $clientId): array
    {
        if (!AppleIap::available()) {
            throw new \RuntimeException('iap-disabled');
        }
        try {
            $tx = $this->verifier->verify($jws);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('invalid-signature');
        }
        TransactionRules::assertClaimable($tx, $type, AppleIap::accountToken($clientTable, $clientId),
            AppleIap::bundleId(), AppleIap::sandboxAllowed());
        return $tx;
    }

    public function ledgerRow(string $transactionId): ?object
    {
        $q = AppleIap::query('AppleTransaction');
        return $q::create()->filterByTransactionId($transactionId)->findOne();
    }

    public function subscriptionRow(string $originalTransactionId): ?object
    {
        $q = AppleIap::query('AppleSubscription');
        return $q::create()->filterByOriginalTransactionId($originalTransactionId)->findOne();
    }

    private function newLedgerRow(array $tx, string $clientTable, int $clientId): object
    {
        $model = AppleIap::model('AppleTransaction');
        $row = new $model();
        $row->setTransactionId((string) $tx['transactionId']);
        $row->setOriginalTransactionId((string) ($tx['originalTransactionId'] ?? $tx['transactionId']));
        $row->setProductId((string) $tx['productId']);
        $row->setClientTable(\strtolower($clientTable));
        $row->setClientId($clientId);
        $row->setEnvironment((string) ($tx['environment'] ?? ''));
        $row->setPurchaseDate(isset($tx['purchaseDate']) ? \intdiv((int) $tx['purchaseDate'], 1000) : \time());
        if (isset($tx['expiresDate'])) {
            $row->setExpiresDate(\intdiv((int) $tx['expiresDate'], 1000));
        }
        // Informational: what the buyer paid, in milliunits (Apple's field).
        if (isset($tx['price'])) {
            $row->setPriceMilli((int) $tx['price']);
            $row->setCurrency((string) ($tx['currency'] ?? ''));
        }
        $row->setStatus('verified');
        return $row;
    }
}
