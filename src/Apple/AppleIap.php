<?php

namespace ApiGoat\Apple;

/**
 * with_apple_iap runtime entry point: manifest, config, model resolution and
 * the per-user appAccountToken.
 *
 * Env (project .env):
 *   APPLE_IAP_BUNDLE_ID    required — every signed payload must carry it
 *   APPLE_IAP_APP_APPLE_ID numeric App Store app id; checked on Production
 *                          notifications when set
 *   APPLE_IAP_SANDBOX      "0" refuses Sandbox payloads. Default accepts them:
 *                          TestFlight AND App Review both buy in Sandbox
 *                          against the production server, and rejecting
 *                          those purchases is a guideline 2.1 rejection.
 */
final class AppleIap
{
    private static ?array $manifest = null;

    public static function manifest(): array
    {
        if (self::$manifest === null) {
            self::$manifest = [];
            if (\defined('_BASE_DIR') && \is_file(_BASE_DIR . 'config/Built/apple_iap.php')) {
                $m = require _BASE_DIR . 'config/Built/apple_iap.php';
                if (\is_array($m)) {
                    self::$manifest = $m;
                }
            }
        }
        return self::$manifest;
    }

    public static function available(): bool
    {
        return (self::manifest()['payables'] ?? []) !== [];
    }

    public static function payable(string $table): ?array
    {
        return self::manifest()['payables'][\strtolower($table)] ?? null;
    }

    public static function bundleId(): string
    {
        return (string) self::env('APPLE_IAP_BUNDLE_ID');
    }

    public static function appAppleId(): ?int
    {
        $v = self::env('APPLE_IAP_APP_APPLE_ID');
        return \is_string($v) && \ctype_digit($v) ? (int) $v : null;
    }

    public static function sandboxAllowed(): bool
    {
        return self::env('APPLE_IAP_SANDBOX') !== '0';
    }

    /**
     * The UUID the app passes to StoreKit as appAccountToken, and which every
     * claimed transaction must carry. Deterministic (UUIDv5-style over the
     * bundle id + client row), so it needs no column and survives reinstalls.
     * It is not a secret: it only proves a purchase was MADE FOR this user —
     * someone else's transaction carries someone else's token and is refused.
     */
    public static function accountToken(string $clientTable, int $clientId): string
    {
        $h = \sha1('gc-apple-iap:' . self::bundleId() . ':' . \strtolower($clientTable) . ':' . $clientId);
        return \sprintf('%s-%s-5%s-%x%s-%s',
            \substr($h, 0, 8), \substr($h, 8, 4), \substr($h, 13, 3),
            (\hexdec($h[16]) & 0x3) | 0x8, \substr($h, 17, 3), \substr($h, 20, 12));
    }

    public static function query(string $entity): string
    {
        return self::resolve($entity . 'Query');
    }

    public static function model(string $entity): string
    {
        return self::resolve($entity);
    }

    private static function resolve(string $class): string
    {
        if (!\preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $class)) {
            throw new \InvalidArgumentException("Invalid entity name: {$class}");
        }
        $fqcn = "\\App\\{$class}";
        if (!\class_exists($fqcn)) {
            throw new \RuntimeException("{$class} not found — is with_apple_iap built in this project? (run gc build)");
        }
        return $fqcn;
    }

    private static function env(string $key): ?string
    {
        $v = \function_exists('env') ? env($key) : \getenv($key);
        return \is_string($v) && $v !== '' ? $v : null;
    }

    /** Test seam. */
    public static function reset(?array $manifest = null): void
    {
        self::$manifest = $manifest;
    }
}
