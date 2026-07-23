<?php

namespace ApiGoat\Stripe;

/**
 * Reader for the build-emitted Stripe manifest (config/Built/stripe.php).
 * Mirror of ApiGoat\Pdf\PdfManifest: everything Stripe is gated on available().
 */
final class StripeManifest
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            if (\defined('_BASE_DIR') && \is_file(_BASE_DIR . 'config/Built/stripe.php')) {
                $m = require _BASE_DIR . 'config/Built/stripe.php';
                if (\is_array($m)) {
                    self::$cache = $m;
                }
            }
        }
        return self::$cache;
    }

    public static function payable(string $table): ?array
    {
        return self::all()['payables'][\strtolower($table)] ?? null;
    }

    public static function available(): bool
    {
        return (self::all()['payables'] ?? []) !== [];
    }

    public static function livemode(): bool
    {
        $key = \function_exists('env') ? env('STRIPE_SECRET_KEY') : \getenv('STRIPE_SECRET_KEY');
        return \is_string($key) && \str_starts_with($key, 'sk_live_');
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
