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

        $clientQ  = StripeDb::query($entry['client_entity']);
        $clientId = (int) $rec->{$entry['client_id_getter']}();
        $client   = $clientQ::create()->findPk($clientId);
        if ($client === null) {
            throw new \RuntimeException("Client record {$clientId} not found for checkout");
        }
        $customer = StripeDb::customerFor($client, $entry, $gw);

        $currency = $entry['currency_getter'] !== null
            ? \strtolower((string) $rec->{$entry['currency_getter']}())
            : (string) $entry['currency'];
        $amount = StripeGateway::minorUnits((float) $rec->{$entry['amount_getter']}());
        if ($amount <= 0 && !isset($opts['price_id'])) {
            throw new \RuntimeException('Amount must be positive to request a payment');
        }
        $desc = $entry['description_getter'] !== null
            ? (string) $rec->{$entry['description_getter']}()
            : ($entry['entity'] . ' #' . $rec->getPrimaryKey());

        $tok      = PayTokens::mint();
        $mode     = isset($opts['price_id']) ? 'subscription' : 'payment';
        $baseUrl  = \defined('_SITE_URL') ? _SITE_URL : '';
        $params   = [
            'mode'        => $mode,
            'customer'    => $customer->getStripeCustomerId(),
            'success_url' => $baseUrl . 'stripe/return/' . $tok['token'] . '?s=success&sid={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $baseUrl . 'stripe/return/' . $tok['token'] . '?s=cancel',
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
        } else {
            $priceQ = StripeDb::query('StripePrice');
            $price  = $priceQ::create()->findPk((int) $opts['price_id']);
            if ($price === null || (string) $price->getStripePriceId() === '') {
                throw new \RuntimeException('Price not found or not pushed to Stripe — use the Prices screen first');
            }
            $params['line_items'] = [['quantity' => 1, 'price' => $price->getStripePriceId()]];
        }

        $session = $gw->client()->checkout->sessions->create(
            $params,
            ['idempotency_key' => 'gc-co-' . $table . '-' . $rec->getPrimaryKey() . '-' . $tok['hash']]
        );

        $model = StripeDb::model('StripePayment');
        $pay = new $model();
        $pay->setIdStripeCustomer($customer->getPrimaryKey());
        $pay->setPayableTable($table);
        $pay->setPayableId((int) $rec->getPrimaryKey());
        $pay->setStripeCheckoutSessionId($session->id);
        $pay->setAmount($amount);
        $pay->setCurrency($currency);
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
}
