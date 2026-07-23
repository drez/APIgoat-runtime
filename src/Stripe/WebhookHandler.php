<?php

namespace ApiGoat\Stripe;

/**
 * Applies a verified Stripe event to the companion tables and the payable
 * record. The webhook is the source of truth for paid state.
 */
final class WebhookHandler
{
    private const HANDLED = [
        'checkout.session.completed',
        'payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled',
        'invoice.paid', 'invoice.payment_failed',
        'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
        'charge.refunded',
        'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed',
    ];

    public static function wasIgnored(string $type): bool
    {
        return !\in_array($type, self::HANDLED, true);
    }

    public static function process(array $event): void
    {
        $obj = $event['data']['object'] ?? [];
        switch ($event['type']) {
            case 'checkout.session.completed':
                self::onCheckoutCompleted($obj);
                break;
            case 'payment_intent.succeeded':
                self::updatePaymentByIntent($obj, 'succeeded');
                break;
            case 'payment_intent.payment_failed':
                self::updatePaymentByIntent($obj, 'failed');
                break;
            case 'payment_intent.canceled':
                self::updatePaymentByIntent($obj, 'canceled');
                break;
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                self::syncSubscription($obj);
                break;
            case 'invoice.paid':
            case 'invoice.payment_failed':
                // Subscription cycle result — reflected via customer.subscription.updated
                // (status past_due/active); nothing extra to record in v1.
                break;
            case 'charge.refunded':
                self::onChargeRefunded($obj);
                break;
            case 'charge.dispute.created':
            case 'charge.dispute.updated':
            case 'charge.dispute.closed':
                self::onDispute($obj);
                break;
            default:
                // ignored — recorded as such by WebhookEndpoint
                break;
        }
    }

    private static function onCheckoutCompleted(array $session): void
    {
        $payQ = StripeDb::query('StripePayment');
        $pay  = $payQ::create()->filterByStripeCheckoutSessionId((string) ($session['id'] ?? ''))->findOne();
        if ($pay === null) {
            return; // session not initiated by us (e.g. another integration) — ignore
        }
        if (!empty($session['payment_intent'])) {
            $pay->setStripePaymentIntentId((string) $session['payment_intent']);
        }
        if (($session['payment_status'] ?? '') === 'paid') {
            $pay->setStatus('succeeded');
            self::flipPaidFlag($pay);
        }
        $pay->save();
        self::captureDefaultMethod($session);
    }

    private static function updatePaymentByIntent(array $intent, string $status): void
    {
        $payQ = StripeDb::query('StripePayment');
        $pay  = $payQ::create()->filterByStripePaymentIntentId((string) ($intent['id'] ?? ''))->findOne();
        if ($pay === null) {
            return;
        }
        $pay->setStatus($status);
        if ($status === 'succeeded') {
            $charge = $intent['charges']['data'][0] ?? [];
            if (!empty($charge['receipt_url'])) {
                $pay->setReceiptUrl((string) $charge['receipt_url']);
            }
            if (!empty($charge['payment_method_details']['type'])) {
                $pay->setPaymentMethodType((string) $charge['payment_method_details']['type']);
            }
            self::flipPaidFlag($pay);
        } elseif ($status === 'failed') {
            $pay->setErrorMessage(\substr((string) ($intent['last_payment_error']['message'] ?? 'Payment failed'), 0, 500));
        }
        $pay->save();
    }

    private static function flipPaidFlag(object $pay): void
    {
        $entry = StripeManifest::payable((string) $pay->getPayableTable());
        if ($entry === null || $entry['paid_flag_setter'] === null) {
            return;
        }
        $q   = StripeDb::query($entry['entity']);
        $rec = $q::create()->findPk((int) $pay->getPayableId());
        if ($rec !== null) {
            $rec->{$entry['paid_flag_setter']}(1);
            $rec->save();
        }
    }

    private static function captureDefaultMethod(array $session): void
    {
        // setup_future_usage=off_session saves the method on the customer; store
        // the intent's method as the customer's default for chargeSaved().
        if (empty($session['customer']) || empty($session['payment_intent'])) {
            return;
        }
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            return;
        }
        try {
            $intent = $gw->client()->paymentIntents->retrieve((string) $session['payment_intent']);
            $method = (string) ($intent->payment_method ?? '');
        } catch (\Throwable $e) {
            return; // best-effort — saved-method capture must not fail the webhook
        }
        if ($method === '') {
            return;
        }
        $custQ = StripeDb::query('StripeCustomer');
        $cust  = $custQ::create()->filterByStripeCustomerId((string) $session['customer'])->findOne();
        if ($cust !== null) {
            $cust->setDefaultPaymentMethod($method);
            $cust->save();
        }
    }

    private static function syncSubscription(array $sub): void
    {
        $custQ = StripeDb::query('StripeCustomer');
        $cust  = $custQ::create()->filterByStripeCustomerId((string) ($sub['customer'] ?? ''))->findOne();
        if ($cust === null) {
            return;
        }
        $subQ = StripeDb::query('StripeSubscription');
        $row  = $subQ::create()->filterByStripeSubscriptionId((string) ($sub['id'] ?? ''))->findOne();
        if ($row === null) {
            $priceId = (string) ($sub['items']['data'][0]['price']['id'] ?? '');
            $priceQ  = StripeDb::query('StripePrice');
            $price   = $priceId !== '' ? $priceQ::create()->filterByStripePriceId($priceId)->findOne() : null;
            $model = StripeDb::model('StripeSubscription');
            $row = new $model();
            $row->setStripeSubscriptionId((string) $sub['id']);
            $row->setIdStripeCustomer($cust->getPrimaryKey());
            if ($price !== null) {
                $row->setIdStripePrice($price->getPrimaryKey());
            }
            $row->setLivemode(StripeManifest::livemode() ? 1 : 0);
        }
        $status = (string) ($sub['status'] ?? 'incomplete');
        $row->setStatus(\in_array($status, ['incomplete', 'trialing', 'active', 'past_due', 'canceled', 'unpaid'], true) ? $status : 'incomplete');
        if (!empty($sub['current_period_end'])) {
            $row->setCurrentPeriodEnd((int) $sub['current_period_end']);
        }
        $row->setCancelAtPeriodEnd(!empty($sub['cancel_at_period_end']) ? 1 : 0);
        if (!empty($sub['canceled_at'])) {
            $row->setCanceledAt((int) $sub['canceled_at']);
        }
        $row->save();
    }

    private static function onChargeRefunded(array $charge): void
    {
        $payQ = StripeDb::query('StripePayment');
        $pay  = $payQ::create()->filterByStripePaymentIntentId((string) ($charge['payment_intent'] ?? ''))->findOne();
        if ($pay === null) {
            return;
        }
        $refQ  = StripeDb::query('StripeRefund');
        foreach (($charge['refunds']['data'] ?? []) as $r) {
            if ($refQ::create()->filterByStripeRefundId((string) $r['id'])->findOne() !== null) {
                continue;
            }
            $model = StripeDb::model('StripeRefund');
            $row = new $model();
            $row->setIdStripePayment($pay->getPrimaryKey());
            $row->setStripeRefundId((string) $r['id']);
            $row->setAmount((int) $r['amount']);
            $row->setStatus((string) ($r['status'] ?? ''));
            $row->setReason((string) ($r['reason'] ?? ''));
            $row->setIsDispute(0);
            $row->save();
        }
        $pay->setStatus(((int) ($charge['amount_refunded'] ?? 0)) >= ((int) ($charge['amount'] ?? 0)) ? 'refunded' : 'partially_refunded');
        $pay->save();
    }

    private static function onDispute(array $dispute): void
    {
        $payQ = StripeDb::query('StripePayment');
        $pay  = $payQ::create()->filterByStripePaymentIntentId((string) ($dispute['payment_intent'] ?? ''))->findOne();
        if ($pay === null) {
            return;
        }
        $refQ = StripeDb::query('StripeRefund');
        $row  = $refQ::create()->filterByStripeRefundId((string) ($dispute['id'] ?? ''))->findOne();
        if ($row === null) {
            $model = StripeDb::model('StripeRefund');
            $row = new $model();
            $row->setIdStripePayment($pay->getPrimaryKey());
            $row->setStripeRefundId((string) $dispute['id']);   // dispute id in the same unique column
            $row->setAmount((int) ($dispute['amount'] ?? 0));
            $row->setIsDispute(1);
        }
        $row->setStatus('dispute');
        $row->setDisputeStatus((string) ($dispute['status'] ?? ''));
        if (!empty($dispute['evidence_details']['due_by'])) {
            $row->setDisputeDueBy((int) $dispute['evidence_details']['due_by']);
        }
        $row->save();
    }
}
