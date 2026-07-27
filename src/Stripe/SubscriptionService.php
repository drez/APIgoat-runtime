<?php

namespace ApiGoat\Stripe;

final class SubscriptionService
{
    /**
     * Self-heal from the Stripe API (audit C4): pull ALL of a customer's
     * subscriptions from Stripe and upsert the local stripe_subscription rows
     * — the repair path for a lost/crashed webhook, called by the project's
     * refresh endpoint before reconciling. Returns how many were synced;
     * failures return 0 (refresh then proceeds on local state as before).
     */
    public static function pullAllForCustomer(object $custRow): int
    {
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            return 0;
        }
        try {
            $subs = $gw->client()->subscriptions->all([
                'customer' => (string) $custRow->getStripeCustomerId(),
                'status'   => 'all',
                'limit'    => 20,
            ]);
        } catch (\Throwable $e) {
            return 0;
        }
        $n = 0;
        foreach ($subs->data as $sub) {
            try {
                WebhookHandler::syncSubscription($sub->toArray());
                $n++;
            } catch (\Throwable $e) {
                // one bad row must not block the rest
            }
        }
        return $n;
    }

    public static function cancel(object $subRow, bool $atPeriodEnd = true): object
    {
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured');
        }
        $id = (string) $subRow->getStripeSubscriptionId();
        if ($atPeriodEnd) {
            $gw->client()->subscriptions->update($id, ['cancel_at_period_end' => true]);
            $subRow->setCancelAtPeriodEnd(1);
        } else {
            $gw->client()->subscriptions->cancel($id);
            $subRow->setStatus('canceled');
            $subRow->setCanceledAt(\time());
        }
        $subRow->save();
        return $subRow;
    }

    /** Immediate prorated switch to a new price (upgrade / interval-up). */
    public static function changeImmediate(object $subRow, string $newStripePriceId): object
    {
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured');
        }
        $client = $gw->client();
        $sub    = $client->subscriptions->retrieve((string) $subRow->getStripeSubscriptionId());
        $itemId = $sub->items->data[0]->id;
        return $client->subscriptions->update((string) $sub->id, [
            'items'              => [['id' => $itemId, 'price' => $newStripePriceId]],
            'proration_behavior' => 'always_invoice',
            'payment_behavior'   => 'error_if_incomplete',
        ]);
    }

    /** Schedule a switch to a cheaper price at period end (monthly downgrade). */
    public static function scheduleDowngrade(object $subRow, string $newStripePriceId): object
    {
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured');
        }
        $client   = $gw->client();
        $subId    = (string) $subRow->getStripeSubscriptionId();
        $sub      = $client->subscriptions->retrieve($subId);
        $curPrice = (string) $sub->items->data[0]->price->id;
        $schedule = $client->subscriptionSchedules->create(['from_subscription' => $subId]);
        return $client->subscriptionSchedules->update($schedule->id, [
            'end_behavior' => 'release',
            'phases'       => [
                [
                    'items'      => [['price' => $curPrice, 'quantity' => 1]],
                    'start_date' => $sub->current_period_start,
                    'end_date'   => $sub->current_period_end,
                ],
                [
                    'items'      => [['price' => $newStripePriceId, 'quantity' => 1]],
                    'start_date' => $sub->current_period_end,
                ],
            ],
        ]);
    }
}
