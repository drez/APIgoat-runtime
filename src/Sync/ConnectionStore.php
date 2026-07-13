<?php

namespace ApiGoat\Sync;

use ApiGoat\Sync\QuickBooks\QboApiClient;

/**
 * acct_connection accessor. Tokens are encrypted at rest with the project's
 * en_de() AES helper (_CRYPT_KEY). Intuit ROTATES the refresh token on every
 * refresh — always persist the returned pair immediately or the connection
 * dies within 24h. $conn is duck-typed (\App\AcctConnection in production).
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
        $conn->setAccessTokenEnc(en_de('encrypt', (string) $tok['access_token']));
        $conn->setRefreshTokenEnc(en_de('encrypt', (string) $tok['refresh_token']));
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
            return (string) en_de('decrypt', (string) $conn->getAccessTokenEnc());
        }
        try {
            $tok = $client->refreshToken((string) en_de('decrypt', (string) $conn->getRefreshTokenEnc()));
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
}
