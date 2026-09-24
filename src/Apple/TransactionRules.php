<?php

namespace ApiGoat\Apple;

/**
 * Pure checks on a VERIFIED StoreKit 2 transaction payload (the JWS body of
 * a signedTransactionInfo). Kept separate from persistence so every refusal
 * reason is unit-testable without a database.
 */
final class TransactionRules
{
    public const CONSUMABLE   = 'Consumable';
    public const SUBSCRIPTION = 'Auto-Renewable Subscription';

    /**
     * Throws \RuntimeException with a short reason code when the transaction
     * must not grant anything to $expectedToken's owner.
     */
    public static function assertClaimable(
        array $tx,
        string $expectedType,
        string $expectedToken,
        string $bundleId,
        bool $sandboxAllowed
    ): void {
        if ($bundleId === '' || ($tx['bundleId'] ?? null) !== $bundleId) {
            throw new \RuntimeException('bundle-mismatch');
        }
        $env = (string) ($tx['environment'] ?? '');
        if ($env !== 'Production' && !($env === 'Sandbox' && $sandboxAllowed)) {
            throw new \RuntimeException('environment-refused');
        }
        if (($tx['type'] ?? null) !== $expectedType) {
            throw new \RuntimeException('type-mismatch');
        }
        // A missing token means the purchase was made without binding it to
        // an account (an old client, or a crafted request) — never guess.
        if (\strtolower((string) ($tx['appAccountToken'] ?? '')) !== \strtolower($expectedToken)) {
            throw new \RuntimeException('account-mismatch');
        }
        if (!empty($tx['revocationDate'])) {
            throw new \RuntimeException('revoked');
        }
        if ((string) ($tx['transactionId'] ?? '') === '' || (string) ($tx['productId'] ?? '') === '') {
            throw new \RuntimeException('incomplete-transaction');
        }
    }

    /**
     * SECURITY: renewal info is Apple-signed but separately supplied — only
     * trust it when it describes THIS transaction's subscription (same
     * originalTransactionId, and same environment / bundleId when the renewal
     * carries them). Anything else is treated as absent, so one account's
     * grace-period renewal info cannot extend another's expired subscription.
     */
    public static function boundRenewal(array $tx, ?array $renewal): ?array
    {
        if ($renewal === null) {
            return null;
        }
        $orig = (string) ($tx['originalTransactionId'] ?? '');
        if ($orig === '' || (string) ($renewal['originalTransactionId'] ?? '') !== $orig) {
            return null;
        }
        foreach (['environment', 'bundleId'] as $k) {
            if (isset($renewal[$k]) && (string) $renewal[$k] !== (string) ($tx[$k] ?? '')) {
                return null;
            }
        }
        return $renewal;
    }

    /** Subscription state from the latest verified transaction (+ renewal info when known). */
    public static function subscriptionStatus(array $tx, ?array $renewal, int $now): string
    {
        if (!empty($tx['revocationDate'])) {
            return 'revoked';
        }
        $expires = isset($tx['expiresDate']) ? \intdiv((int) $tx['expiresDate'], 1000) : 0;
        if ($expires > $now) {
            return 'active';
        }
        // Apple keeps entitlement during a billing grace period.
        $grace = isset($renewal['gracePeriodExpiresDate']) ? \intdiv((int) $renewal['gracePeriodExpiresDate'], 1000) : 0;
        if ($grace > $now) {
            return 'grace';
        }
        return !empty($renewal['isInBillingRetryPeriod']) ? 'billing_retry' : 'expired';
    }

    /** Whether a status still grants the entitlement. */
    public static function entitles(string $status): bool
    {
        return $status === 'active' || $status === 'grace';
    }
}
