<?php

namespace ApiGoat\Stripe;

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

        $model = StripeDb::model('StripePayment');
        $pay = new $model();
        $pay->setIdStripeCustomer($customer->getPrimaryKey());
        $pay->setPayableTable($table);
        $pay->setPayableId((int) $rec->getPrimaryKey());
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
                'metadata'       => ['gc_payable_table' => $table, 'gc_payable_id' => (string) $rec->getPrimaryKey()],
            ], ['idempotency_key' => 'gc-ch-' . $table . '-' . $rec->getPrimaryKey() . '-' . $pay->getPrimaryKey()]);
            $pay->setStripePaymentIntentId($intent->id);
            $pay->save();
            // final status (succeeded / requires_action / failed) lands via webhook
            return ['status' => (string) $intent->status, 'payment_id' => (int) $pay->getPrimaryKey(), 'message' => ''];
        } catch (\Throwable $e) {
            $pay->setStatus('failed');
            $pay->setErrorMessage(\substr($e->getMessage(), 0, 500));
            $pay->save();
            return ['status' => 'failed', 'payment_id' => (int) $pay->getPrimaryKey(), 'message' => $e->getMessage()];
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
        $map = ['succeeded' => 'succeeded', 'canceled' => 'canceled', 'processing' => 'processing',
                'requires_payment_method' => 'failed'];
        if (isset($map[$intent->status])) {
            $paymentRow->setStatus($map[$intent->status]);
            $paymentRow->save();
        }
        WebhookHandler::process(['id' => 'manual', 'type' => 'payment_intent.' . ($intent->status === 'succeeded' ? 'succeeded' : 'payment_failed'),
            'data' => ['object' => $intent->toArray()]]);
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
