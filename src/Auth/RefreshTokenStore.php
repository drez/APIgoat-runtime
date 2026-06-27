<?php

declare(strict_types=1);

namespace ApiGoat\Auth;

/**
 * Persistence + throttle seam for RefreshTokenService.
 * Production: PropelRefreshTokenStore. Tests: ArrayRefreshTokenStore.
 */
interface RefreshTokenStore
{
    /** @param array{id_authy:int,family_id:string,token_hash:string,expires:int,family_expires:int} $row */
    public function insert(array $row): void;

    /** @return array{id:int,id_authy:int,family_id:string,token_hash:string,expires:int,family_expires:int,revoked:string}|null */
    public function findByHash(string $hash): ?array;

    public function markRevoked(int $id, int $lastUsedAt): void;

    public function revokeFamily(string $familyId): void;

    public function revokeAllForUser(int $idAuthy): void;

    /** Count redeem attempts for this ip OR family since $since (unix ts). */
    public function recentAttemptCount(string $ip, string $familyId, int $since): int;

    public function recordAttempt(string $ip, string $familyId, int $at): void;
}
