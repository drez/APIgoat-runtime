<?php
namespace ApiGoat\OAuth;

use ApiGoat\OAuth\Entities\AuthCodeEntity;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
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

    /**
     * Single-use claim (review-3 #7). league's AuthCodeGrant issues and
     * persists the access + refresh tokens FIRST and calls this LAST, so a
     * plain find+save let two concurrent redeems of one code both succeed.
     * Now it is an atomic conditional UPDATE (… WHERE code_id=? AND
     * revoked=0): the one caller that flips the row keeps its tokens. A
     * loser has already persisted tokens, so it revokes the whole
     * user+client family (RFC 6749 §4.1.2: a code used more than once →
     * deny and revoke the tokens issued from it — this includes the
     * winner's) and throws, so its response is never sent.
     *
     * The claim sits here (after PKCE, client and redirect_uri checks)
     * rather than in isAuthCodeRevoked() so a party holding an intercepted
     * code but not the verifier cannot burn it before validation.
     */
    public function revokeAuthCode($codeId): void
    {
        if (RefreshTokenRepository::claimRow(\App\OauthAuthCodeQuery::class, 'filterByCodeId', (string) $codeId)) {
            return;
        }
        $row = \App\OauthAuthCodeQuery::create()->filterByCodeId((string) $codeId)->findOne();
        if ($row) {
            RefreshTokenRepository::revokeFamily((int) $row->getIdAuthy(), (string) $row->getClientId(), 'concurrent auth code redeem', (string) $codeId);
        }
        throw OAuthServerException::invalidRequest('code', 'Authorization code has been revoked');
    }

    /**
     * Read-only check (the claim is revokeAuthCode). A code that exists but
     * is already revoked is a replay → revoke the user+client token family
     * (RFC 6749 §4.1.2) and log it.
     */
    public function isAuthCodeRevoked($codeId): bool
    {
        $row = \App\OauthAuthCodeQuery::create()->filterByCodeId((string) $codeId)->findOne();
        if (!$row) {
            return true;
        }
        if ($row->getRevoked() === 'Yes') {
            RefreshTokenRepository::revokeFamily((int) $row->getIdAuthy(), (string) $row->getClientId(), 'auth code reuse', (string) $codeId);
            return true;
        }
        return $row->getExpires() < time();
    }
}
