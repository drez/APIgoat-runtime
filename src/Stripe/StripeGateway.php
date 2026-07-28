<?php

namespace ApiGoat\Stripe;

/**
 * Single construction point for the Stripe SDK client: key resolution,
 * API-version pinning, idempotency. All network calls in ApiGoat\Stripe go
 * through client(). Keys come from the project .env (never the DB).
 */
final class StripeGateway
{
    // basil (2025-03-31+) is REQUIRED by accounts with Managed Payments
    // (default-on for new accounts — pre-basil requests are refused outright,
    // seen live 2026-07-27). basil also MOVED Subscription.current_period_*
    // onto subscription items, which is why a bare basil pin once broke all
    // downgrades (audit C2): every reader now handles BOTH shapes
    // (SubscriptionService::currentPeriod / WebhookHandler::syncSubscription),
    // so the pin is safe on old and new accounts alike.
    public const API_VERSION = '2025-06-30.basil';

    private ?\Stripe\StripeClient $client = null;

    private function __construct(private readonly string $secretKey)
    {
    }

    public static function fromEnv(): ?self
    {
        $key = \function_exists('env') ? env('STRIPE_SECRET_KEY') : \getenv('STRIPE_SECRET_KEY');
        return (\is_string($key) && $key !== '') ? new self($key) : null;
    }

    public static function webhookSecret(): ?string
    {
        $v = \function_exists('env') ? env('STRIPE_WEBHOOK_SECRET') : \getenv('STRIPE_WEBHOOK_SECRET');
        return (\is_string($v) && $v !== '') ? $v : null;
    }

    public function client(): \Stripe\StripeClient
    {
        if ($this->client === null) {
            $this->client = new \Stripe\StripeClient([
                'api_key'        => $this->secretKey,
                'stripe_version' => self::API_VERSION,
            ]);
        }
        return $this->client;
    }

    /** Two-decimal currencies only (v1). */
    public static function minorUnits(float $amount): int
    {
        return (int) \round($amount * 100);
    }
}
