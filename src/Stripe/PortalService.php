<?php

namespace ApiGoat\Stripe;

/**
 * Stripe Customer Portal session. The portal is configured (Stripe dashboard)
 * with subscription plan-switching DISABLED — vidifye does plan changes in-app
 * (SubscriptionService::changeImmediate/scheduleDowngrade); the portal is only
 * cancel / update card / invoices.
 */
final class PortalService
{
    public static function createSession(object $customer, string $returnUrl): string
    {
        $gw = StripeGateway::fromEnv();
        if ($gw === null) {
            throw new \RuntimeException('STRIPE_SECRET_KEY is not configured');
        }
        $session = $gw->client()->billingPortal->sessions->create([
            'customer'   => (string) $customer->getStripeCustomerId(),
            'return_url' => $returnUrl,
        ]);
        return (string) $session->url;
    }
}
