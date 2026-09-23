<?php
namespace ApiGoat\OAuth;

use ApiGoat\OAuth\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
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

    /**
     * Benign-retry window (seconds). Mobile clients fire parallel refreshes
     * or retry after a network drop; the second presentation of a JUST
     * rotated token is rejected but must not log the user out.
     */
    public const ROTATION_GRACE = 30;

    /**
     * Rotation claim (review-3 #7). league's RefreshTokenGrant calls this
     * AFTER isRefreshTokenRevoked() and every other validation, but BEFORE it
     * issues the new access/refresh pair — so it is the atomic
     * check-and-revoke: a conditional UPDATE (… WHERE token_id=? AND
     * revoked=0) whose affected-row count is the claim. Of N concurrent
     * redeems of the same token that all passed the read-only revoked check,
     * exactly one flips the row; every loser gets an OAuth error here, no
     * tokens are issued for it, and it does NOT family-revoke (a concurrent
     * double refresh is the benign mobile case). The winner records a
     * short-lived rotation marker so a retry inside ROTATION_GRACE is also
     * rejected without a family revoke.
     */
    public function revokeRefreshToken($tokenId): void
    {
        if (!self::claimRow(\App\OauthRefreshTokenQuery::class, 'filterByTokenId', (string) $tokenId)) {
            throw OAuthServerException::invalidRefreshToken('Token has been revoked');
        }
        \ApiGoat\Utility\MicroCache::add(self::rotationKey((string) $tokenId), self::ROTATION_GRACE, 1);
    }

    /**
     * Read-only check (the claim is revokeRefreshToken). A token that is
     * present but ALREADY revoked is a replay: RFC 9700 §4.14.2 reuse
     * detection — revoke every access + refresh token of the same
     * user+client ("family" without a family column) and log it — UNLESS it
     * was rotated less than ROTATION_GRACE seconds ago (MicroCache marker):
     * then it is a benign retry, rejected without the family revoke. No
     * marker (window passed, APCu absent, revoked by logout/family revoke)
     * keeps the family revoke. Unknown or merely expired tokens are rejected
     * without a family revoke.
     */
    public function isRefreshTokenRevoked($tokenId): bool
    {
        $row = \App\OauthRefreshTokenQuery::create()->filterByTokenId((string) $tokenId)->findOne();
        if (!$row) {
            return true;
        }
        if ($row->getRevoked() === 'Yes') {
            if (\ApiGoat\Utility\MicroCache::get(self::rotationKey((string) $tokenId)) !== null) {
                \error_log(sprintf(
                    'OAuth info: just-rotated refresh token re-presented within %ds (user %d, client %s) — rejected, no family revoke',
                    self::ROTATION_GRACE,
                    (int) $row->getIdAuthy(),
                    (string) $row->getClientId()
                ));
                return true;
            }
            self::revokeFamily((int) $row->getIdAuthy(), (string) $row->getClientId(), 'refresh token reuse', (string) $tokenId);
            return true;
        }
        return $row->getExpires() < time();
    }

    /** Per-project MicroCache key of a rotation marker (token id hashed, never stored raw). */
    public static function rotationKey(string $tokenId): string
    {
        return 'gc:oauth:rt-rotated:' . \ApiGoat\Utility\TableVersion::ns() . ':' . hash('sha256', $tokenId);
    }

    /**
     * Atomic 0→1 revoke of one row by its token/code id. True for exactly
     * one caller. DB-agnostic: a single UPDATE … WHERE id=? AND revoked=0
     * through Propel; the affected-row count is the claim.
     * `revoked` is a tinyint ENUM (0=No, 1=Yes) — bulk update() bypasses the
     * ENUM conversion, so the integer is passed directly.
     *
     * @param class-string $queryClass   \App\Oauth{RefreshToken,AuthCode}Query
     * @param string       $filterMethod filterByTokenId | filterByCodeId
     */
    public static function claimRow(string $queryClass, string $filterMethod, string $id): bool
    {
        if ($id === '') {
            return false;
        }
        $affected = $queryClass::create()
            ->{$filterMethod}($id)
            ->filterByRevoked('No')
            ->update(['Revoked' => 1]);
        return (int) $affected === 1;
    }

    /**
     * Family revocation (no family column): every access + refresh token of
     * this user for this client. Called on refresh-token or auth-code reuse.
     */
    public static function revokeFamily(int $idAuthy, string $clientId, string $reason, string $presentedId = ''): void
    {
        if ($idAuthy <= 0 || $clientId === '') {
            return;
        }
        \App\OauthRefreshTokenQuery::create()
            ->filterByIdAuthy($idAuthy)->filterByClientId($clientId)
            ->filterByRevoked('No')
            ->update(['Revoked' => 1]);
        \App\OauthAccessTokenQuery::create()
            ->filterByIdAuthy($idAuthy)->filterByClientId($clientId)
            ->filterByRevoked('No')
            ->update(['Revoked' => 1]);
        \error_log(sprintf(
            'OAuth security: %s — revoked all tokens of user %d for client %s (presented id %s)',
            $reason,
            $idAuthy,
            $clientId,
            $presentedId !== '' ? substr(hash('sha256', $presentedId), 0, 12) : '-'
        ));
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
