<?php

namespace ApiGoat\Stripe;

use ApiGoat\Utility\MicroCache;
use ApiGoat\Utility\TableVersion;

final class PaymentService
{
    /** Off-session charge of the client's saved default payment method. */
    public static function chargeSaved(object $rec, string $table): array
    {
        $entry = StripeManifest::payable($table);
        if ($entry === null) {
            throw new \RuntimeException("Table {$table} is not in the Stripe manifest");
        }
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured');
        }
        $clientQ  = StripeDb::query($entry['client_entity']);
        $client   = $clientQ::create()->findPk((int) $rec->{$entry['client_id_getter']}());
        if ($client === null) {
            throw new \RuntimeException('Client record not found');
        }
        $customer = StripeDb::customerFor($client, $entry, $gw);
        $method   = (string) $customer->getDefaultPaymentMethod();
        if ($method === '') {
            throw new \RuntimeException('No saved payment method for this client — request a payment link first');
        }

        $currency = $entry['currency_getter'] !== null
            ? \strtolower((string) $rec->{$entry['currency_getter']}())
            : (string) $entry['currency'];
        $amount = StripeGateway::minorUnits((float) $rec->{$entry['amount_getter']}());
        if ($amount <= 0) {
            throw new \RuntimeException('Amount must be positive');
        }

        // Double-click / concurrent-request guard (review-3 #6). Three layers:
        // an APCu lock serializes requests for this payable, the ledger +
        // paid flag refuse a payable already paid or mid-charge, and the
        // Stripe idempotency key is DETERMINISTIC per (payable, amount,
        // attempt) — two requests that slip past both still get ONE intent.
        $payableId = (int) $rec->getPrimaryKey();
        $lockKey   = self::chargeLockKey($table, $payableId);
        if (!MicroCache::add($lockKey, self::CHARGE_LOCK_SECONDS, 1)) {
            throw new \RuntimeException('A payment for this record is already in progress');
        }
        try {
            self::assertChargeable($rec, $entry, $table, $payableId);
            $attempt = self::chargeAttempt($table, $payableId);
            // A saved-card charge replaces any outstanding Checkout link.
            CheckoutService::expireOpenSessionsFor($table, $payableId, $gw);

            $model = StripeDb::model('StripePayment');
            $pay = new $model();
            $pay->setIdStripeCustomer($customer->getPrimaryKey());
            $pay->setPayableTable($table);
            $pay->setPayableId($payableId);
            $pay->setAmount($amount);
            $pay->setCurrency($currency);
            $pay->setStatus('processing');
            $pay->setLivemode(StripeManifest::livemode() ? 1 : 0);
            $pay->save();

            try {
                $intent = $gw->client()->paymentIntents->create([
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'customer'       => $customer->getStripeCustomerId(),
                    'payment_method' => $method,
                    'off_session'    => true,
                    'confirm'        => true,
                    'metadata'       => ['gc_payable_table' => $table, 'gc_payable_id' => (string) $payableId],
                ], ['idempotency_key' => self::chargeIdempotencyKey($table, $payableId, $amount, $currency, $attempt)]);
                $pay->setStripePaymentIntentId($intent->id);
                $ledger = self::intentStatusToLedger((string) $intent->status);
                if ($ledger === 'failed' || $ledger === 'canceled') {
                    // A synchronous decline is final. Everything else stays
                    // `processing` (it blocks a second charge) until the
                    // webhook lands — succeeded flips the paid flag there,
                    // after the amount check.
                    $pay->setStatus($ledger);
                }
                $pay->save();
                // final status (succeeded / requires_action / failed) lands via webhook
                return ['status' => (string) $intent->status, 'payment_id' => (int) $pay->getPrimaryKey(), 'message' => ''];
            } catch (\Throwable $e) {
                $pay->setStatus('failed');
                $pay->setErrorMessage(\substr($e->getMessage(), 0, 500));
                $pay->save();
                return ['status' => 'failed', 'payment_id' => (int) $pay->getPrimaryKey(), 'message' => $e->getMessage()];
            }
        } finally {
            MicroCache::forget($lockKey);
        }
    }

    public const CHARGE_LOCK_SECONDS = 60;

    /** Per-project (APCu is shared by every project in the pool) lock key. Pure. */
    public static function chargeLockKey(string $table, int $payableId): string
    {
        return 'gc:' . TableVersion::ns() . ':stripe-charge:' . \strtolower($table) . ':' . $payableId;
    }

    /**
     * Stripe idempotency key for an off-session charge: the same payable,
     * amount, currency and attempt always yield the same key, so a repeated
     * request returns the intent Stripe already created instead of charging
     * again. `attempt` moves on only after a failed/canceled charge. Pure.
     */
    public static function chargeIdempotencyKey(string $table, int $payableId, int $amount, string $currency, int $attempt): string
    {
        return 'gc-ch-' . \strtolower($table) . '-' . $payableId . '-' . $amount . \strtolower($currency) . '-a' . $attempt;
    }

    /** Refuse a payable already paid (flag set) or with a charge in flight / completed. */
    private static function assertChargeable(object $rec, array $entry, string $table, int $payableId): void
    {
        if (!empty($entry['paid_flag_setter'])) {
            $getter = WebhookHandler::getterFor((string) $entry['paid_flag_setter']);
            if (\method_exists($rec, $getter) && (int) $rec->{$getter}() !== 0) {
                throw new \RuntimeException('This record is already paid');
            }
        }
        $busy = StripeDb::query('StripePayment')::create()
            ->filterByPayableTable($table)
            ->filterByPayableId($payableId)
            ->filterByStatus(['processing', 'succeeded'])
            ->count();
        // A charge polled back to `pending` (3-D Secure) is still live: the
        // customer may complete it, so it blocks a new charge as well.
        $awaiting = StripeDb::query('StripePayment')::create()
            ->filterByPayableTable($table)
            ->filterByPayableId($payableId)
            ->filterByStatus('pending')
            ->filterByStripePaymentIntentId('', \Criteria::NOT_EQUAL)
            ->count();
        if ((int) $busy > 0 || (int) $awaiting > 0) {
            throw new \RuntimeException('A payment for this record is already in progress or completed');
        }
    }

    /** 1 + the number of finished-unsuccessful charges for this payable. */
    private static function chargeAttempt(string $table, int $payableId): int
    {
        return 1 + (int) StripeDb::query('StripePayment')::create()
            ->filterByPayableTable($table)
            ->filterByPayableId($payableId)
            ->filterByStatus(['failed', 'canceled'])
            ->count();
    }

    /**
     * Ledger status for a PaymentIntent status, or null to leave the row as
     * is. Only a definite outcome moves it: `processing`, `requires_action`
     * (3-D Secure), `requires_confirmation` and `requires_capture` are still
     * in flight — they are pending, never failed (review-3 #6). Pure.
     */
    public static function intentStatusToLedger(string $intentStatus): ?string
    {
        switch ($intentStatus) {
            case 'succeeded':
                return 'succeeded';
            case 'canceled':
                return 'canceled';
            case 'requires_payment_method':
                return 'failed';
            case 'processing':
                return 'processing';
            case 'requires_action':
            case 'requires_confirmation':
            case 'requires_capture':
                return 'pending';
            default:
                return null;
        }
    }

    /** The webhook event type a polled intent status replays, or null (still in flight). Pure. */
    public static function intentStatusEvent(string $intentStatus): ?string
    {
        switch ($intentStatus) {
            case 'succeeded':
                return 'payment_intent.succeeded';
            case 'canceled':
                return 'payment_intent.canceled';
            case 'requires_payment_method':
                return 'payment_intent.payment_failed';
            default:
                return null;
        }
    }

    /** Manual fallback when webhooks are delayed: re-pull intent state. */
    public static function refreshStatus(object $paymentRow): object
    {
        $gw = StripeGateway::fromEnv();
        $intentId = (string) $paymentRow->getStripePaymentIntentId();
        if ($gw === null || $intentId === '') {
            return $paymentRow;
        }
        $intent = $gw->client()->paymentIntents->retrieve($intentId);
        $ledger = self::intentStatusToLedger((string) $intent->status);
        $event  = self::intentStatusEvent((string) $intent->status);
        if ($event !== null) {
            // A definite outcome: apply it through the webhook path (amount
            // check + conditional paid-flag flip on success).
            WebhookHandler::process(['id' => 'manual', 'type' => $event, 'data' => ['object' => $intent->toArray()]]);
            $paymentRow->setStatus($ledger);
        } elseif ($ledger !== null) {
            // Still in flight (3-D Secure, processing): pending, not failed.
            $paymentRow->setStatus($ledger);
            $paymentRow->save();
        }
        return $paymentRow;
    }

    public static function refund(object $paymentRow, ?int $amountMinor, string $reason = ''): object
    {
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured');
        }
        $intentId = (string) $paymentRow->getStripePaymentIntentId();
        if ($intentId === '' || $paymentRow->getStatus() === 'pending') {
            throw new \RuntimeException('Payment has no captured charge to refund');
        }
        $params = ['payment_intent' => $intentId];
        if ($amountMinor !== null && $amountMinor > 0) {
            $params['amount'] = $amountMinor;
        }
        if ($reason !== '') {
            $params['reason'] = $reason;
        }
        $refund = $gw->client()->refunds->create($params);

        $model = StripeDb::model('StripeRefund');
        $row = new $model();
        $row->setIdStripePayment($paymentRow->getPrimaryKey());
        $row->setStripeRefundId($refund->id);
        $row->setAmount((int) $refund->amount);
        $row->setStatus((string) $refund->status);
        $row->setReason($reason);
        $row->setIsDispute(0);
        $row->save();
        // final refunded/partially_refunded ledger status lands via charge.refunded webhook
        return $row;
    }
}
