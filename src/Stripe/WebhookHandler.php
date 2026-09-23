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

    /** The subscription's current first-item price id (''=none). */
    public static function priceIdFromSub(array $sub): string
    {
        return (string) ($sub['items']['data'][0]['price']['id'] ?? '');
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
                // Record the renewal in the payment ledger (audit I6 2026-07-27):
                // without this, month 2..N charges had no stripe_payment row at
                // all — invisible to revenue reporting and unrefundable through
                // PaymentService::refund (which matches on payment_intent).
                self::recordInvoicePayment($obj);
                break;
            case 'invoice.payment_failed':
                // Reflected via customer.subscription.updated (status past_due).
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
            // Subscription sessions are priced by a server-selected recurring
            // catalog price (trials/coupons/proration make amount_total differ
            // from any stored figure), so only payment-mode sessions are
            // amount-checked against the ledger row written at creation.
            $mismatch = (($session['mode'] ?? 'payment') === 'subscription')
                ? null
                : self::amountMismatch($pay, $session['amount_total'] ?? null, $session['currency'] ?? null);
            if ($mismatch === null) {
                self::flipPaidFlag($pay);
            } else {
                self::refuseFlip($pay, 'checkout ' . ($session['id'] ?? '?'), $mismatch);
            }
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
            // `charges` was removed from PaymentIntent in API 2022-11-15
            // (audit I7): the event carries only `latest_charge` (an id), so
            // receipt/method come from a best-effort charge retrieve.
            $charge = self::retrieveCharge((string) ($intent['latest_charge'] ?? ''));
            if (!empty($charge['receipt_url'])) {
                $pay->setReceiptUrl((string) $charge['receipt_url']);
            }
            if (!empty($charge['payment_method_details']['type'])) {
                $pay->setPaymentMethodType((string) $charge['payment_method_details']['type']);
            }
            $mismatch = self::amountMismatch($pay, $intent['amount_received'] ?? ($intent['amount'] ?? null), $intent['currency'] ?? null);
            if ($mismatch === null) {
                self::flipPaidFlag($pay);
            } else {
                self::refuseFlip($pay, 'intent ' . ($intent['id'] ?? '?'), $mismatch);
            }
        } elseif ($status === 'failed') {
            $pay->setErrorMessage(\substr((string) ($intent['last_payment_error']['message'] ?? 'Payment failed'), 0, 500));
        }
        $pay->save();
    }

    /**
     * Why a paid Stripe object must NOT mark the payable paid, or null when it
     * may: the amount (minor units) and currency Stripe collected must equal
     * what the ledger row recorded as owed when the session / intent was
     * created server-side (review-3 #6). The ledger row is server-owned
     * (set_readonly_columns), so it is the trusted figure. Pure.
     */
    public static function amountMismatch(object $pay, $paidAmount, $paidCurrency): ?string
    {
        $owedAmount   = (int) $pay->getAmount();
        $owedCurrency = \strtolower((string) $pay->getCurrency());
        if ($paidAmount === null || $paidAmount === '' || !\is_numeric($paidAmount)) {
            return 'no amount on the Stripe object';
        }
        if ((int) $paidAmount !== $owedAmount) {
            return 'amount ' . (int) $paidAmount . ' != owed ' . $owedAmount;
        }
        if ($owedCurrency === '' || \strtolower((string) $paidCurrency) !== $owedCurrency) {
            return 'currency ' . \strtolower((string) $paidCurrency) . ' != owed ' . $owedCurrency;
        }
        return null;
    }

    private static function refuseFlip(object $pay, string $what, string $why): void
    {
        \error_log('[stripe] ' . $what . ' for ' . $pay->getPayableTable() . '#' . $pay->getPayableId()
            . ': ' . $why . ' — payable NOT marked paid');
        $pay->setErrorMessage(\substr('Not marked paid: ' . $why, 0, 500));
    }

    /**
     * 0 → 1 on the payable's paid flag as ONE conditional UPDATE (review-3
     * #6): only a row whose flag is still 0 (or NULL) flips, so a duplicate /
     * replayed delivery or a second paid session never re-grants, and a
     * consumed grant (2, the reconcilers' state) is never reset to 1.
     * Returns true when this call flipped it (affected rows == 1).
     */
    public static function flipPaidFlag(object $pay): bool
    {
        $entry = StripeManifest::payable((string) $pay->getPayableTable());
        if ($entry === null || $entry['paid_flag_setter'] === null) {
            return false;
        }
        $pk = (int) $pay->getPayableId();
        if ($pk <= 0) {
            return false;   // renewal ledger rows (payable_id 0) pay for no row
        }
        $col    = \substr((string) $entry['paid_flag_setter'], 3);   // setIsPaid → IsPaid
        $filter = 'filterBy' . $col;
        $q      = StripeDb::query($entry['entity']);
        $affected = (int) $q::create()->filterByPrimaryKey($pk)->{$filter}(0)->update([$col => 1]);
        if ($affected !== 1) {
            $affected = (int) $q::create()->filterByPrimaryKey($pk)->{$filter}(null, \Criteria::ISNULL)->update([$col => 1]);
        }
        return $affected === 1;
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

    /** Public so SubscriptionService::pullAllForCustomer (API self-heal) can reuse it. */
    public static function syncSubscription(array $sub): void
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
        // Re-sync the price on every update (in-place upgrade / scheduled downgrade
        // landing at period end changes items[0].price without recreating the row).
        $priceId = self::priceIdFromSub($sub);
        if ($priceId !== '') {
            $priceRow = StripeDb::query('StripePrice')::create()->filterByStripePriceId($priceId)->findOne();
            if ($priceRow !== null) {
                $row->setIdStripePrice($priceRow->getPrimaryKey());
            }
        }
        $status = (string) ($sub['status'] ?? 'incomplete');
        $row->setStatus(\in_array($status, ['incomplete', 'trialing', 'active', 'past_due', 'canceled', 'unpaid'], true) ? $status : 'incomplete');
        // basil (2025-03-31+) moved current_period_* onto the subscription
        // item; older shapes carry it on the root. Accept both.
        $periodEnd = $sub['current_period_end'] ?? ($sub['items']['data'][0]['current_period_end'] ?? null);
        if (!empty($periodEnd)) {
            $row->setCurrentPeriodEnd((int) $periodEnd);
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
        $fullyRefunded = ((int) ($charge['amount_refunded'] ?? 0)) >= ((int) ($charge['amount'] ?? 0));
        $pay->setStatus($fullyRefunded ? 'refunded' : 'partially_refunded');
        $pay->save();
        // Audit I5: a full refund must revoke what the payment bought — at
        // minimum reset the payable's paid flag so an unconsumed grant can't
        // be applied later. Consumed-state cleanup (e.g. unfeaturing a boosted
        // ad) is project-side: the reconcilers sweep refunded payments.
        if ($fullyRefunded) {
            self::resetPaidFlag($pay);
        }
    }

    private static function resetPaidFlag(object $pay): void
    {
        $entry = StripeManifest::payable((string) $pay->getPayableTable());
        if ($entry === null || $entry['paid_flag_setter'] === null) {
            return;
        }
        $q   = StripeDb::query($entry['entity']);
        $rec = $q::create()->findPk((int) $pay->getPayableId());
        // Only claw back an UNCONSUMED grant (flag still 1) — consumed state
        // (2) is the reconcilers' to unwind with full context.
        $getter = self::getterFor((string) $entry['paid_flag_setter']);
        if ($rec !== null && \method_exists($rec, $getter) && (int) $rec->{$getter}() === 1) {
            $rec->{$entry['paid_flag_setter']}(0);
            $rec->save();
        }
    }

    /**
     * setFoo → getFoo: 'get' + the name past its "set" prefix. The old
     * str_replace('set','get') also rewrote a "set" INSIDE the name
     * (setAssetPaid → getAsgetPaid); method_exists() then failed and a full
     * refund clawed nothing back.
     */
    public static function getterFor(string $setter): string
    {
        return 'get' . \substr($setter, 3);
    }

    /** Best-effort charge retrieve for receipt/method capture — never fails the webhook. */
    private static function retrieveCharge(string $chargeId): array
    {
        if ($chargeId === '') {
            return [];
        }
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            return [];
        }
        try {
            return $gw->client()->charges->retrieve($chargeId)->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Payment intent id of an invoice, tolerant of BOTH API shapes (like
     * current_period_end above): pre-basil `payment_intent` (id, or the
     * expanded object), basil `payments.data[].payment.payment_intent`. A paid
     * entry wins; '' when the invoice carries neither.
     */
    public static function invoicePaymentIntent(array $invoice): string
    {
        $id = static function ($v): string {
            return \is_array($v) ? (string) ($v['id'] ?? '') : (string) ($v ?? '');
        };
        $intent = $id($invoice['payment_intent'] ?? null);
        if ($intent !== '') {
            return $intent;
        }
        $found = '';
        foreach ((array) ($invoice['payments']['data'] ?? []) as $p) {
            $intent = $id($p['payment']['payment_intent'] ?? null);
            if ($intent === '') {
                continue;
            }
            if (($p['status'] ?? '') === 'paid') {
                return $intent;
            }
            $found = $found !== '' ? $found : $intent;
        }
        return $found;
    }

    /** Best-effort invoice retrieve with `payments` expanded — never fails the webhook. */
    private static function retrieveInvoicePayments(string $invoiceId): array
    {
        if ($invoiceId === '') {
            return [];
        }
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            return [];
        }
        try {
            return $gw->client()->invoices->retrieve($invoiceId, ['expand' => ['payments']])->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Ledger row for a subscription-cycle charge (invoice.paid). Idempotent by payment intent. */
    private static function recordInvoicePayment(array $invoice): void
    {
        if ((int) ($invoice['amount_paid'] ?? 0) <= 0) {
            return;
        }
        $intentId = self::invoicePaymentIntent($invoice);
        if ($intentId === '') {
            // The pinned API version (basil) no longer puts payment_intent on
            // the invoice, and `payments` is expandable — a webhook payload
            // does not carry it. Ask for it; best-effort like retrieveCharge().
            $intentId = self::invoicePaymentIntent(self::retrieveInvoicePayments((string) ($invoice['id'] ?? '')));
        }
        if ($intentId === '') {
            \error_log('[stripe] invoice.paid ' . ($invoice['id'] ?? '?') . ': no payment intent found, renewal not recorded');
            return;
        }
        $payQ = StripeDb::query('StripePayment');
        if ($payQ::create()->filterByStripePaymentIntentId($intentId)->findOne() !== null) {
            return; // initial checkout payment (or an earlier delivery) already holds it
        }
        $custQ = StripeDb::query('StripeCustomer');
        $cust  = $custQ::create()->filterByStripeCustomerId((string) ($invoice['customer'] ?? ''))->findOne();
        if ($cust === null) {
            return;
        }
        $model = StripeDb::model('StripePayment');
        $pay = new $model();
        $pay->setIdStripeCustomer($cust->getPrimaryKey());
        $pay->setStripePaymentIntentId($intentId);
        $pay->setAmount((int) $invoice['amount_paid']);
        $pay->setCurrency((string) ($invoice['currency'] ?? ''));
        // Renewal ledger entry — not tied to a specific payable row (the
        // originating checkout payment carries that); payable_id 0 marks it.
        $pay->setPayableTable('membership');
        $pay->setPayableId(0);
        $pay->setStatus('succeeded');
        $pay->setLivemode(StripeManifest::livemode() ? 1 : 0);
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
