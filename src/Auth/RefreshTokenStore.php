<?php

declare(strict_types=1);

namespace ApiGoat\Auth;

/**
 * Persistence + throttle seam for RefreshTokenService.
 * Production: PropelRefreshTokenStore. Tests: ArrayRefreshTokenStore.
 */
interface RefreshTokenStore
{
    /** @param array{id_authy:int,family_id:string,token_hash:string,expires:int,family_expires:int,user_agent?:?string,ip?:?string} $row */
    public function insert(array $row): void;

    /** @return array{id:int,id_authy:int,family_id:string,token_hash:string,expires:int,family_expires:int,revoked:string,last_used_at?:?int,user_agent?:?string,ip?:?string}|null */
    public function findByHash(string $hash): ?array;

    public function markRevoked(int $id, int $lastUsedAt): void;

    /**
     * Atomic compare-and-swap rotation claim: flip the row to revoked and
     * stamp last_used_at ONLY if it is still live. Returns true for exactly
     * one of any number of concurrent callers (conditional UPDATE, checked
     * by affected-row count) — the winner mints the successor.
     */
    public function claimRotation(int $id, int $at): bool;

    /**
     * Revoke every row of the family AND set family_expires = 0 on all of
     * them, already revoked rows included (the tombstone RefreshTokenService
     * re-checks after inserting a successor). revokeAllForUser() likewise.
     */
    public function revokeFamily(string $familyId): void;

    public function revokeAllForUser(int $idAuthy): void;

    /** Count redeem attempts for this ip OR family since $since (unix ts). */
    public function recentAttemptCount(string $ip, string $familyId, int $since): int;

    public function recordAttempt(string $ip, string $familyId, int $at): void;

    /**
     * One entry per LIVE family of the user (a family with a non-revoked,
     * unexpired row, family not expired), newest-first order is the caller's
     * job. Keys: id (smallest row id of the family), family_id, created (unix,
     * earliest row), last_used (unix|null, latest rotation), expires (family
     * expiry, unix), user_agent / ip (of the live row = the latest request).
     *
     * @return list<array{id:int,family_id:string,created:int,last_used:?int,expires:int,user_agent:?string,ip:?string}>
     */
    public function liveFamilies(int $idAuthy, int $now): array;

    /**
     * Revoke + tombstone (like revokeFamily) every family of the user except
     * $keepFamilyId. Rows of an already revoked family are re-stamped too.
     */
    public function revokeAllForUserExcept(int $idAuthy, string $keepFamilyId): void;
}
