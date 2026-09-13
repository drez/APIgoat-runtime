<?php

namespace ApiGoat\Sync;

use ApiGoat\Crypto\SecretBox;
use ApiGoat\Sync\QuickBooks\QboApiClient;

/**
 * acct_connection accessor. Intuit ROTATES the refresh token on every refresh
 * — always persist the returned pair immediately or the connection dies
 * within 24h. $conn is duck-typed (\App\AcctConnection in production).
 *
 * Tokens at rest: sealed with SecretBox (libsodium secretbox — authenticated,
 * random nonce, key derived from APP_SECRET_KEY_V<n>) when it is configured,
 * falling back to the legacy en_de() AES-256-CBC helper otherwise. en_de has
 * no MAC, so its ciphertexts are malleable; these are OAuth tokens to a
 * customer's accounting data, which is the wrong place to keep unauthenticated
 * crypto once an authenticated primitive exists. Reads accept BOTH formats —
 * a 'v<n>:' prefix marks a SecretBox value and ':' cannot occur in en_de's
 * base64 output, so the two can never be confused. Existing rows migrate
 * themselves on the next token refresh (which Intuit forces within 24h).
 */
final class ConnectionStore
{
    public static function available(): bool
    {
        return class_exists('\App\AcctConnection');
    }

    public static function find(string $provider = 'quickbooks')
    {
        return \App\AcctConnectionQuery::create()->filterByProvider($provider)->findOne();
    }

    public static function storeTokens($conn, array $tok): void
    {
        if (empty($tok['access_token']) || empty($tok['refresh_token'])) {
            throw new Exceptions\AuthFailed('QuickBooks token response missing access_token/refresh_token');
        }
        $conn->setAccessTokenEnc(self::seal((string) $tok['access_token']));
        $conn->setRefreshTokenEnc(self::seal((string) $tok['refresh_token']));
        // 60s skew so we refresh before the edge, not after a 401.
        $conn->setAccessExpiresAt(date('Y-m-d H:i:s', time() + (int) ($tok['expires_in'] ?? 3600) - 60));
        $conn->setRefreshExpiresAt(date('Y-m-d H:i:s', time() + (int) ($tok['x_refresh_token_expires_in'] ?? 8640000)));
        $conn->setStatus('Connected');
        $conn->save();
    }

    /** Valid access token, refreshing transparently when expired. */
    public static function accessToken($conn, QboApiClient $client): string
    {
        if (strtotime((string) $conn->getAccessExpiresAt()) > time()) {
            return self::open((string) $conn->getAccessTokenEnc());
        }
        try {
            $tok = $client->refreshToken(self::open((string) $conn->getRefreshTokenEnc()));
            self::storeTokens($conn, $tok);
            return (string) $tok['access_token'];
        } catch (Exceptions\AuthFailed $e) {
            $conn->setStatus('Expired');
            $conn->save();
            throw $e;
        }
    }

    public static function getState($conn): array
    {
        return json_decode((string) $conn->getStateJson(), true) ?: [];
    }

    public static function setState($conn, array $state): void
    {
        $conn->setStateJson((string) json_encode($state));
        $conn->save();
    }

    /**
     * acct_connection is a single per-project provider link, not tenant-scoped
     * data, so it seals under subkey 0.
     */
    private const CRYPTO_TENANT = 0;

    /** Seal a token for storage: SecretBox when configured, else legacy en_de. */
    private static function seal(string $plain): string
    {
        if (SecretBox::available()) {
            return SecretBox::seal($plain, self::CRYPTO_TENANT);
        }

        return (string) en_de('encrypt', $plain);
    }

    /** Open a stored token, accepting both the SecretBox and legacy formats. */
    private static function open(string $cipher): string
    {
        if ($cipher === '') {
            return '';
        }
        if (preg_match('/^v[0-9]{1,3}:/', $cipher)) {
            return SecretBox::open($cipher, self::CRYPTO_TENANT);
        }

        return (string) en_de('decrypt', $cipher);
    }
}
