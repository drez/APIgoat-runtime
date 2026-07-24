<?php

namespace ApiGoat\OAuth;

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
            $this->encryptionKey
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
