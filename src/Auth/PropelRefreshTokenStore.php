<?php

declare(strict_types=1);

namespace ApiGoat\Auth;

/**
 * Propel-backed RefreshTokenStore. Operates on the generated
 * \App\AuthyRefreshToken model; throttle attempts are logged to authy_log
 * (event='refresh') mirroring AuthyService login throttling.
 */
final class PropelRefreshTokenStore implements RefreshTokenStore
{
    public function insert(array $row): void
    {
        $m = new \App\AuthyRefreshToken();
        $m->setIdAuthy($row['id_authy']);
        $m->setFamilyId($row['family_id']);
        $m->setTokenHash($row['token_hash']);
        $m->setExpires($row['expires']);
        $m->setFamilyExpires($row['family_expires']);
        $m->setRevoked('No');
        $m->setCreatedAt(new \DateTime());
        $m->save();
    }

    public function findByHash(string $hash): ?array
    {
        $m = \App\AuthyRefreshTokenQuery::create()->filterByTokenHash($hash)->findOne();
        if (!$m) {
            return null;
        }
        return [
            'id'             => (int) $m->getIdAuthyRefreshToken(),
            'id_authy'       => (int) $m->getIdAuthy(),
            'family_id'      => (string) $m->getFamilyId(),
            'token_hash'     => (string) $m->getTokenHash(),
            'expires'        => (int) $m->getExpires(),
            'family_expires' => (int) $m->getFamilyExpires(),
            'revoked'        => (string) $m->getRevoked(),
        ];
    }

    public function markRevoked(int $id, int $lastUsedAt): void
    {
        $m = \App\AuthyRefreshTokenQuery::create()->findPk($id);
        if ($m) {
            $m->setRevoked('Yes');
            $m->setLastUsedAt((new \DateTime())->setTimestamp($lastUsedAt));
            $m->save();
        }
    }

    public function revokeFamily(string $familyId): void
    {
        \App\AuthyRefreshTokenQuery::create()
            ->filterByFamilyId($familyId)
            ->filterByRevoked('No')
            ->update(['Revoked' => 'Yes']);
    }

    public function revokeAllForUser(int $idAuthy): void
    {
        \App\AuthyRefreshTokenQuery::create()
            ->filterByIdAuthy($idAuthy)
            ->filterByRevoked('No')
            ->update(['Revoked' => 'Yes']);
    }

    public function recentAttemptCount(string $ip, string $familyId, int $since): int
    {
        $q = \App\AuthyLogQuery::create()
            ->filterByEvent('refresh')
            ->filterByTimestamp($since, \Criteria::GREATER_EQUAL);
        $q->condition('byIp', \App\AuthyLogPeer::IP . ' = ?', $ip);
        if ($familyId !== '') {
            $q->condition('byFam', \App\AuthyLogPeer::LOGIN . ' = ?', $familyId);
            $q->combine(['byIp', 'byFam'], \Criteria::LOGICAL_OR, 'ipOrFam');
            $q->where('ipOrFam');
        } else {
            $q->where('byIp');
        }
        return (int) $q->count();
    }

    public function recordAttempt(string $ip, string $familyId, int $at): void
    {
        $log = new \App\AuthyLog();
        $log->setEvent('refresh');
        $log->setIp($ip);
        $log->setLogin($familyId);
        $log->setTimestamp($at);
        if (method_exists($log, 'setCreatedAt')) {
            $log->setCreatedAt(new \DateTime());
        }
        $log->save();
    }
}
