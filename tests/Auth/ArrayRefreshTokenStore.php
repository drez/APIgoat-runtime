<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\RefreshTokenStore;

/** In-memory store for deterministic unit tests. */
final class ArrayRefreshTokenStore implements RefreshTokenStore
{
    /** @var array<int,array> */
    public array $rows = [];
    private int $seq = 0;
    /** @var array<int,array{ip:string,family:string,at:int}> */
    public array $attempts = [];

    public function insert(array $row): void
    {
        $row['id'] = ++$this->seq;
        $row['revoked'] = 'No';
        $row['last_used_at'] = null;
        $this->rows[$row['id']] = $row;
    }

    public function findByHash(string $hash): ?array
    {
        foreach ($this->rows as $r) {
            if ($r['token_hash'] === $hash) {
                return $r;
            }
        }
        return null;
    }

    public function markRevoked(int $id, int $lastUsedAt): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['revoked'] = 'Yes';
            $this->rows[$id]['last_used_at'] = $lastUsedAt;
        }
    }

    public function revokeFamily(string $familyId): void
    {
        foreach ($this->rows as $id => $r) {
            if ($r['family_id'] === $familyId && $r['revoked'] === 'No') {
                $this->rows[$id]['revoked'] = 'Yes';
            }
        }
    }

    public function revokeAllForUser(int $idAuthy): void
    {
        foreach ($this->rows as $id => $r) {
            if ($r['id_authy'] === $idAuthy && $r['revoked'] === 'No') {
                $this->rows[$id]['revoked'] = 'Yes';
            }
        }
    }

    public function recentAttemptCount(string $ip, string $familyId, int $since): int
    {
        $n = 0;
        foreach ($this->attempts as $a) {
            if ($a['at'] >= $since && ($a['ip'] === $ip || ($familyId !== '' && $a['family'] === $familyId))) {
                $n++;
            }
        }
        return $n;
    }

    public function recordAttempt(string $ip, string $familyId, int $at): void
    {
        $this->attempts[] = ['ip' => $ip, 'family' => $familyId, 'at' => $at];
    }

    /** test helper */
    public function liveCountForFamily(string $familyId): int
    {
        $n = 0;
        foreach ($this->rows as $r) {
            if ($r['family_id'] === $familyId && $r['revoked'] === 'No') {
                $n++;
            }
        }
        return $n;
    }
}
