<?php

namespace ApiGoat\Stripe;

/**
 * Creates Stripe Checkout Sessions for payable records. Amount/currency are
 * ALWAYS re-read from the record server-side — nothing money-related comes
 * from the client. Writes the pending stripe_payment ledger row + pay token.
 */
final class CheckoutService
{
    /**
     * @param object $rec   the payable Propel record (already ACL/tenant-loaded by the caller)
     * @param string $table payable table plain name (manifest key)
     * @param array  $opts  ['price_id' => int] → subscription mode using that stripe_price row
     * @return array{url: string, pay_url: string, payment_id: int}
     */
    public static function createForRecord(object $rec, string $table, array $opts = []): array
    {
        $entry = StripeManifest::payable($table);
        if ($entry === null) {
            throw new \RuntimeException("Table {$table} is not in the Stripe manifest — run gc build");
        }
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured in the project .env');
        }

        $customer = self::customerForRecord($rec, $entry, $gw);

        $tok   = PayTokens::mint();
        $built = self::buildSessionParams($rec, $entry, $table, $customer, $opts, $tok['token']);

        $session = $gw->client()->checkout->sessions->create(
            $built['params'],
            ['idempotency_key' => 'gc-co-' . $table . '-' . $rec->getPrimaryKey() . '-' . $tok['hash']]
        );

        $baseUrl = \defined('_SITE_URL') ? _SITE_URL : '';
        $model   = StripeDb::model('StripePayment');
        $pay     = new $model();
        $pay->setIdStripeCustomer($customer->getPrimaryKey());
        $pay->setPayableTable($table);
        $pay->setPayableId((int) $rec->getPrimaryKey());
        $pay->setStripeCheckoutSessionId($session->id);
        $pay->setAmount($built['amount']);
        $pay->setCurrency($built['currency']);
        $pay->setStatus('pending');
        $pay->setPayTokenHash($tok['hash']);
        $pay->setPayTokenExpires(\strtotime('+7 days'));
        $pay->setLivemode(StripeManifest::livemode() ? 1 : 0);
        $pay->save();

        return [
            'url'        => (string) $session->url,
            'pay_url'    => $baseUrl . 'stripe/pay/' . $tok['token'],
            'payment_id' => (int) $pay->getPrimaryKey(),
        ];
    }

    /**
     * Regenerate ONLY the Stripe Checkout session for an existing ledger row
     * (the stored session is no longer 'open') and update the row in place —
     * no new stripe_payment row, no new pay token. The row keeps its hash so
     * previously-shared pay-page URLs stay valid; the raw token is required
     * to rebuild success/cancel URLs since the row only ever stores the hash.
     *
     * @param object $payRow  the existing stripe_payment ledger row (already resolved by its token hash)
     * @param string $rawToken the raw pay token (PayPage::render's $token argument — never derivable from $payRow)
     * @param object|null $oldSession an already-retrieved Checkout Session for $payRow's stored session id
     *        (avoids a second Stripe API round trip when the caller already fetched it); if it lacks
     *        expanded line_items and the original session turns out to be subscription-mode, it is
     *        re-retrieved once with ['expand' => ['line_items']].
     * @return string the fresh Checkout session URL
     */
    public static function refreshSessionFor(object $payRow, string $rawToken, ?object $oldSession = null): string
    {
        $table = (string) $payRow->getPayableTable();
        $entry = StripeManifest::payable($table);
        if ($entry === null) {
            throw new \RuntimeException("Table {$table} is not in the Stripe manifest — run gc build");
        }
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured in the project .env');
        }

        $q   = StripeDb::query($entry['entity']);
        $rec = $q::create()->findPk((int) $payRow->getPayableId());
        if ($rec === null) {
            throw new \RuntimeException('Payable record not found for checkout refresh');
        }

        $customer = self::customerForRecord($rec, $entry, $gw);

        // The stored session carries the ORIGINAL mode (payment vs subscription).
        // A ledger row has no mode/price column, so we must consult Stripe —
        // rebuilding blind as 'payment' silently turns subscription links into
        // one-time charges (or throws when the payable's amount is <= 0).
        $sessionId = (string) $payRow->getStripeCheckoutSessionId();
        $old       = $oldSession;
        try {
            if ($old === null || $old->id !== $sessionId) {
                $old = $gw->client()->checkout->sessions->retrieve($sessionId, ['expand' => ['line_items']]);
            } elseif (($old->mode ?? '') === 'subscription' && empty($old->line_items->data ?? null)) {
                // Caller handed us a session without expanded line items — re-fetch.
                $old = $gw->client()->checkout->sessions->retrieve($sessionId, ['expand' => ['line_items']]);
            }
        } catch (\Throwable $e) {
            // Original session is gone. Only safe to fall back to a fresh
            // payment-mode session when the record's amount actually supports
            // one — otherwise rethrow so the caller shows "unavailable"
            // rather than silently building a wrong (or impossible) charge.
            $amount = StripeGateway::minorUnits((float) $rec->{$entry['amount_getter']}());
            if ($amount <= 0) {
                throw $e;
            }
            $old = null;
        }

        $opts = [];
        if ($old !== null && ($old->mode ?? '') === 'subscription') {
            $priceId = $old->line_items->data[0]->price->id ?? null;
            if ($priceId === null) {
                throw new \RuntimeException('Original subscription session has no line items to rebuild from');
            }
            $priceRow = StripeDb::query('StripePrice')::create()->filterByStripePriceId($priceId)->findOne();
            $opts = $priceRow !== null
                ? ['price_id' => $priceRow->getPrimaryKey()]
                : ['price_id' => null, 'stripe_price_id' => $priceId];
        }

        $built = self::buildSessionParams($rec, $entry, $table, $customer, $opts, $rawToken);

        $session = $gw->client()->checkout->sessions->create(
            $built['params'],
            ['idempotency_key' => 'gc-co-refresh-' . $table . '-' . $rec->getPrimaryKey() . '-'
                . $payRow->getPrimaryKey() . '-' . \bin2hex(\random_bytes(8))]
        );

        // Save the new session id BEFORE returning the URL — the webhook
        // matches incoming events by session id.
        $payRow->setStripeCheckoutSessionId($session->id);
        if ($built['mode'] !== 'subscription') {
            // Payment-mode refresh: amount/currency come straight from the
            // record, as always. Subscription-mode refresh keeps the row's
            // existing amount/currency (set at original checkout creation)
            // untouched — buildSessionParams doesn't price subscriptions.
            $payRow->setAmount($built['amount']);
            $payRow->setCurrency($built['currency']);
        }
        $payRow->save();

        return (string) $session->url;
    }

    private static function customerForRecord(object $rec, array $entry, StripeGateway $gw): object
    {
        $clientQ  = StripeDb::query($entry['client_entity']);
        $clientId = (int) $rec->{$entry['client_id_getter']}();
        $client   = $clientQ::create()->findPk($clientId);
        if ($client === null) {
            throw new \RuntimeException("Client record {$clientId} not found for checkout");
        }
        return StripeDb::customerFor($client, $entry, $gw);
    }

    /**
     * Shared Checkout Session param-array construction (createForRecord +
     * refreshSessionFor) — one place amount/currency/line-items are computed
     * from the record, so both entry points stay in lockstep.
     *
     * $opts['price_id'] selects a local StripePrice row (int, may be present-but-null
     * when no local row matched — see $opts['stripe_price_id']); $opts['stripe_price_id']
     * is a raw Stripe price id to use directly when no local StripePrice row exists for it
     * (subscription refresh from an original session Stripe still knows about).
     *
     * @return array{params: array, mode: string, amount: int, currency: string}
     */
    private static function buildSessionParams(object $rec, array $entry, string $table, object $customer, array $opts, string $rawToken): array
    {
        $isSubscription = \array_key_exists('price_id', $opts) || isset($opts['stripe_price_id']);

        $currency = $entry['currency_getter'] !== null
            ? \strtolower((string) $rec->{$entry['currency_getter']}())
            : (string) $entry['currency'];
        $amount = StripeGateway::minorUnits((float) $rec->{$entry['amount_getter']}());
        if ($amount <= 0 && !$isSubscription) {
            throw new \RuntimeException('Amount must be positive to request a payment');
        }
        $desc = $entry['description_getter'] !== null
            ? (string) $rec->{$entry['description_getter']}()
            : ($entry['entity'] . ' #' . $rec->getPrimaryKey());

        $mode    = $isSubscription ? 'subscription' : 'payment';
        $baseUrl = \defined('_SITE_URL') ? _SITE_URL : '';
        $params  = [
            'mode'        => $mode,
            'customer'    => $customer->getStripeCustomerId(),
            'success_url' => $baseUrl . 'stripe/return/' . $rawToken . '?s=success&sid={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $baseUrl . 'stripe/return/' . $rawToken . '?s=cancel',
            'metadata'    => ['gc_payable_table' => $table, 'gc_payable_id' => (string) $rec->getPrimaryKey()],
        ];
        if ($mode === 'payment') {
            $params['line_items'] = [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => $amount,
                    'product_data' => ['name' => $desc !== '' ? $desc : 'Payment'],
                ],
            ]];
            $params['payment_intent_data'] = [
                'setup_future_usage' => 'off_session',
                'metadata'           => $params['metadata'],
            ];
        } elseif (isset($opts['stripe_price_id'])) {
            // Refreshing a subscription session whose original price has no
            // matching local StripePrice row (e.g. price managed directly in
            // Stripe) — rebuild line_items from the raw Stripe price id
            // instead of failing the refresh.
            $params['line_items'] = [['quantity' => 1, 'price' => (string) $opts['stripe_price_id']]];
        } else {
            $priceQ = StripeDb::query('StripePrice');
            $price  = $priceQ::create()->findPk((int) $opts['price_id']);
            if ($price === null || (string) $price->getStripePriceId() === '') {
                throw new \RuntimeException('Price not found or not pushed to Stripe — use the Prices screen first');
            }
            $params['line_items'] = [['quantity' => 1, 'price' => $price->getStripePriceId()]];
        }

        return ['params' => $params, 'mode' => $mode, 'amount' => $amount, 'currency' => $currency];
    }
}
