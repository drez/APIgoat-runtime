<?php

namespace ApiGoat\Stripe;

/**
 * Single-purpose public pay-page tokens. Only the sha256 hash is stored
 * (stripe_payment.pay_token_hash); the raw token appears once, in the link
 * handed to the payer.
 */
final class PayTokens
{
    public static function mint(): array
    {
        $token = \bin2hex(\random_bytes(32));
        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return \hash('sha256', $token);
    }
}
