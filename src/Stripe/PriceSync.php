<?php

namespace ApiGoat\Stripe;

/**
 * Pushes an admin-authored stripe_price row to Stripe (product + recurring
 * price). Stripe prices are immutable — a changed amount creates a NEW price
 * and archives the old one.
 */
final class PriceSync
{
    public static function push(object $priceRow): object
    {
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured');
        }
        $client = $gw->client();

        if ((string) $priceRow->getStripeProductId() === '') {
            $product = $client->products->create(['name' => (string) $priceRow->getName()]);
            $priceRow->setStripeProductId($product->id);
        }

        // One-time packages (type='one_time', 2026-07-27) create a Price
        // WITHOUT `recurring`; rows without a type column (older schemas) are
        // recurring, as before.
        $oneTime = \method_exists($priceRow, 'getType')
            && \strtolower((string) $priceRow->getType()) === 'one_time';

        $needsNewPrice = (string) $priceRow->getStripePriceId() === '';
        if (!$needsNewPrice) {
            $existing = $client->prices->retrieve((string) $priceRow->getStripePriceId());
            $existingOneTime = empty($existing->recurring);
            $needsNewPrice = ((int) $existing->unit_amount !== (int) $priceRow->getAmount())
                || $existingOneTime !== $oneTime
                || (!$oneTime && (
                    ($existing->recurring->interval ?? '') !== (string) $priceRow->getIntervalUnit()
                    || (int) ($existing->recurring->interval_count ?? 1) !== (int) $priceRow->getIntervalCount()
                ));
            if ($needsNewPrice) {
                $client->prices->update($existing->id, ['active' => false]);
            }
        }
        if ($needsNewPrice) {
            $params = [
                'product'     => $priceRow->getStripeProductId(),
                'currency'    => \strtolower((string) $priceRow->getCurrency()),
                'unit_amount' => (int) $priceRow->getAmount(),
            ];
            if (!$oneTime) {
                $params['recurring'] = [
                    'interval'       => (string) $priceRow->getIntervalUnit(),
                    'interval_count' => \max(1, (int) $priceRow->getIntervalCount()),
                ];
            }
            $price = $client->prices->create($params);
            $priceRow->setStripePriceId($price->id);
        }
        $priceRow->save();
        return $priceRow;
    }
}
