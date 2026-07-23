<?php

namespace ApiGoat\Stripe;

final class SubscriptionService
{
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
}
