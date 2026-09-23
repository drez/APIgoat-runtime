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

    /**
     * Atomic compare-and-swap rotation claim: flip the row to revoked and
     * stamp last_used_at ONLY if it is still live. Returns true for exactly
     * one of any number of concurrent callers (conditional UPDATE, checked
     * by affected-row count) — the winner mints the successor.
     */
    public function claimRotation(int $id, int $at): bool;

    public function revokeFamily(string $familyId): void;

    public function revokeAllForUser(int $idAuthy): void;

    /** Count redeem attempts for this ip OR family since $since (unix ts). */
    public function recentAttemptCount(string $ip, string $familyId, int $since): int;

    public function recordAttempt(string $ip, string $familyId, int $at): void;
}
