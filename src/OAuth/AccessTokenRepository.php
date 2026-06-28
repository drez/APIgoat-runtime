<?php
namespace ApiGoat\OAuth;

use ApiGoat\OAuth\Entities\AccessTokenEntity;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null): AccessTokenEntityInterface
    {
        $t = new AccessTokenEntity();
        $t->setClient($clientEntity);
        foreach ($scopes as $s) {
            $t->addScope($s);
        }
        if ($userIdentifier !== null) {
            $t->setUserIdentifier((string) $userIdentifier);
        }
        return $t;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $token): void
    {
        $row = new \App\OauthAccessToken();
        $row->setTokenId($token->getIdentifier());
        $row->setIdAuthy((int) $token->getUserIdentifier());
        $row->setClientId($token->getClient()->getIdentifier());
        $row->setScopes(implode(' ', array_map(fn ($s) => $s->getIdentifier(), $token->getScopes())));
        $row->setExpires($token->getExpiryDateTime()->getTimestamp());
        $row->setRevoked('No');
        $row->setCreatedAt(new \DateTime());
        $row->save();
    }

    public function revokeAccessToken($tokenId): void
    {
        $row = \App\OauthAccessTokenQuery::create()->filterByTokenId((string) $tokenId)->findOne();
        if ($row) {
            $row->setRevoked('Yes');
            $row->save();
        }
    }

    public function isAccessTokenRevoked($tokenId): bool
    {
        $row = \App\OauthAccessTokenQuery::create()->filterByTokenId((string) $tokenId)->findOne();
        // fail closed: unknown token id is treated as revoked
        return !$row || $row->getRevoked() === 'Yes' || $row->getExpires() < time();
    }
}
