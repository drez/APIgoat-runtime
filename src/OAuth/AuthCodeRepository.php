<?php
namespace ApiGoat\OAuth;

use ApiGoat\OAuth\Entities\AuthCodeEntity;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCode): void
    {
        $row = new \App\OauthAuthCode();
        $row->setCodeId($authCode->getIdentifier());
        $row->setIdAuthy((int) $authCode->getUserIdentifier());
        $row->setClientId($authCode->getClient()->getIdentifier());
        $row->setScopes(implode(' ', array_map(fn ($s) => $s->getIdentifier(), $authCode->getScopes())));
        $row->setExpires($authCode->getExpiryDateTime()->getTimestamp());
        $row->setRevoked('No');
        $row->setCreatedAt(new \DateTime());
        $row->save();
    }

    public function revokeAuthCode($codeId): void
    {
        $row = \App\OauthAuthCodeQuery::create()->filterByCodeId((string) $codeId)->findOne();
        if ($row) {
            $row->setRevoked('Yes');
            $row->save();
        }
    }

    public function isAuthCodeRevoked($codeId): bool
    {
        $row = \App\OauthAuthCodeQuery::create()->filterByCodeId((string) $codeId)->findOne();
        return !$row || $row->getRevoked() === 'Yes' || $row->getExpires() < time();
    }
}
