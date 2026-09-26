<?php

namespace ApiGoat\OAuth;

use Defuse\Crypto\Encoding;
use Defuse\Crypto\Key;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\ResourceServer;

class OAuthServerFactory
{
    public function __construct(
        private ClientRepositoryInterface $clients,
        private AccessTokenRepositoryInterface $accessTokens,
        private AuthCodeRepositoryInterface $authCodes,
        private RefreshTokenRepositoryInterface $refreshTokens,
        private ScopeRepositoryInterface $scopes,
        private string $privateKeyPem,
        private string $publicKeyPem,
        private string $encryptionKey
    ) {}

    public function authorizationServer(): AuthorizationServer
    {
        $server = new AuthorizationServer(
            $this->clients,
            $this->accessTokens,
            $this->scopes,
            new CryptKey($this->privateKeyPem, null, false),
            self::encryptionKeyFor($this->encryptionKey)
        );

        // Session policy for bearer clients (the MCP connector + the mobile app):
        //   access token  PT1H — short-lived; the client silently refreshes it.
        //   refresh token     — the session ceiling, rotated on every use.
        //                       GC_SESSION_MCP_DAYS knob (project .env),
        //                       default 90 days, clamped to 365. (Was a fixed
        //                       P7D; widened 2026-07-24 so MCP connectors and
        //                       the app don't force re-logins.)
        $accessTtl  = new \DateInterval('PT1H');
        $refreshTtl = \ApiGoat\Auth\SessionLifetime::mcpRefreshTtl();

        $authCode = new AuthCodeGrant($this->authCodes, $this->refreshTokens, new \DateInterval('PT10M'));
        $authCode->setRefreshTokenTTL($refreshTtl);
        // PKCE is required by default for public clients; do NOT disable it.
        // S256-only enforcement is done in the controller (Task 8).
        $server->enableGrantType($authCode, $accessTtl);

        $refresh = new RefreshTokenGrant($this->refreshTokens);
        $refresh->setRefreshTokenTTL($refreshTtl);
        $server->enableGrantType($refresh, $accessTtl);

        return $server;
    }

    public function resourceServer(): ResourceServer
    {
        return new ResourceServer(
            $this->accessTokens,
            new CryptKey($this->publicKeyPem, null, false)
        );
    }

    /**
     * The Defuse Key league/oauth2-server encrypts refresh tokens and auth
     * codes with, derived (HKDF-SHA256) from OAUTH_ENCRYPTION_KEY.
     *
     * Handed the raw string, the library used Crypto::*WithPassword: 100,000
     * PBKDF2 rounds per call — ~400 ms of every token refresh on prod
     * (2026-09-26: /oauth/token never under 500 ms). A real Key is ~0.5 ms.
     * Changing the mode makes refresh tokens / codes issued before this
     * change undecryptable: their holders (MCP connectors, the mobile app)
     * sign in again once. An unset secret is passed through unchanged, so a
     * misconfigured project fails exactly as before.
     */
    public static function encryptionKeyFor(string $secret): Key|string
    {
        if ($secret === '') {
            return '';
        }
        $bytes = \hash_hkdf('sha256', $secret, 32, 'apigoat-oauth-defuse-key-v1');
        return Key::loadFromAsciiSafeString(
            Encoding::saveBytesToChecksummedAsciiSafeString(Key::KEY_CURRENT_VERSION, $bytes)
        );
    }

    public static function forProject(): ?self
    {
        if (!class_exists('\App\OauthClient')) {
            return null;
        }
        $settings = new \Selective\Config\Configuration(\ApiGoat\Utility\Settings::load());
        $cfg = $settings->getArray('oauth_server');
        return new self(
            new ClientRepository(),
            new AccessTokenRepository(),
            new AuthCodeRepository(),
            new RefreshTokenRepository(),
            new ScopeRepository(),
            (string) $cfg['private_key'],
            (string) $cfg['public_key'],
            (string) $cfg['encryption_key']
        );
    }
}
