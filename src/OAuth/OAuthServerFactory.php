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

        $authCode = new AuthCodeGrant($this->authCodes, $this->refreshTokens, new \DateInterval('PT10M'));
        $authCode->setRefreshTokenTTL(new \DateInterval('P30D'));
        // PKCE is required by default for public clients; do NOT disable it.
        // S256-only enforcement is done in the controller (Task 8).
        $server->enableGrantType($authCode, new \DateInterval('PT1H'));

        $refresh = new RefreshTokenGrant($this->refreshTokens);
        $refresh->setRefreshTokenTTL(new \DateInterval('P30D'));
        $server->enableGrantType($refresh, new \DateInterval('PT1H'));

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
