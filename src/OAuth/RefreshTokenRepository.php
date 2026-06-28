<?php
namespace ApiGoat\OAuth;

use ApiGoat\OAuth\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $rt): void
    {
        $access = $rt->getAccessToken();
        if ($access === null) {
            throw new \LogicException('RefreshTokenEntity must have an access token before persisting');
        }
        $this->persistNewRefreshTokenForUser(
            $rt,
            (string) $access->getIdentifier(),
            (int) $access->getUserIdentifier(),
            (string) $access->getClient()->getIdentifier()
        );
    }

    /** Test/explicit helper: persist with the user/client made explicit. */
    public function persistNewRefreshTokenForUser(RefreshTokenEntityInterface $rt, string $accessTokenId, int $idAuthy, string $clientId): void
    {
        $row = new \App\OauthRefreshToken();
        $row->setTokenId($rt->getIdentifier());
        $row->setAccessTokenId($accessTokenId ?: null);
        $row->setIdAuthy($idAuthy);
        $row->setClientId($clientId);
        $row->setExpires($rt->getExpiryDateTime()->getTimestamp());
        $row->setRevoked('No');
        $row->setCreatedAt(new \DateTime());
        $row->save();
    }

    public function revokeRefreshToken($tokenId): void
    {
        $row = \App\OauthRefreshTokenQuery::create()->filterByTokenId((string) $tokenId)->findOne();
        if ($row) {
            $row->setRevoked('Yes');
            $row->save();
        }
    }

    public function isRefreshTokenRevoked($tokenId): bool
    {
        $row = \App\OauthRefreshTokenQuery::create()->filterByTokenId((string) $tokenId)->findOne();
        return !$row || $row->getRevoked() === 'Yes' || $row->getExpires() < time();
    }

    /** Revoke every outstanding OAuth refresh token for a user (password-reset hook). */
    public function revokeAllForUser(int $idAuthy): void
    {
        // The `revoked` column is tinyint (0=No, 1=Yes); bulk update() bypasses
        // Propel's ENUM-to-int conversion, so we pass the integer directly.
        \App\OauthRefreshTokenQuery::create()
            ->filterByIdAuthy($idAuthy)
            ->update(['Revoked' => 1]);
        // also revoke their access tokens so the jti list rejects them
        \App\OauthAccessTokenQuery::create()
            ->filterByIdAuthy($idAuthy)
            ->update(['Revoked' => 1]);
    }
}
