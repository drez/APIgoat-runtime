<?php

declare(strict_types=1);

namespace ApiGoat\Auth;

/**
 * Propel-backed RefreshTokenStore. Operates on the generated
 * \App\AuthyRefreshToken model; throttle attempts are logged to authy_log
 * (result='refresh') mirroring AuthyService login throttling.
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
            ->update(['Revoked' => 1]);  // 1 = 'Yes' in tinyint ENUM; 'Yes' string silently becomes 0 via MySQL cast
    }

    public function revokeAllForUser(int $idAuthy): void
    {
        \App\AuthyRefreshTokenQuery::create()
            ->filterByIdAuthy($idAuthy)
            ->filterByRevoked('No')
            ->update(['Revoked' => 1]);  // 1 = 'Yes' in tinyint ENUM
    }

    public function recentAttemptCount(string $ip, string $familyId, int $since): int
    {
        // authy_log columns used here:
        //   event   varchar(64) — tagged 'refresh' to isolate from login throttle (result='w')
        //   timestamp integer() — unix int (NOT a datetime column)
        //   ip / login — address and family id respectively
        $q = \App\AuthyLogQuery::create()
            ->filterByEvent('refresh')
            ->filterByTimestamp($since, \Criteria::GREATER_EQUAL);
        if ($familyId !== '') {
            $q->condition('byIp', \App\AuthyLogPeer::IP . ' = ?', $ip)
              ->condition('byFam', \App\AuthyLogPeer::LOGIN . ' = ?', $familyId)
              ->where(['byIp', 'byFam'], \Criteria::LOGICAL_OR);
        } else {
            $q->filterByIp($ip);
        }
        return (int) $q->count();
    }

    public function recordAttempt(string $ip, string $familyId, int $at): void
    {
        $log = new \App\AuthyLog();
        $log->setEvent('refresh');   // varchar(64) — isolates refresh rows from the login throttle
        $log->setResult('');         // authy_log.result is NOT NULL; unused for refresh (cf. OAuthRegisterService)
        $log->setIp($ip);
        $log->setLogin($familyId);
        $log->setTimestamp($at);     // integer() column — raw unix int, not a DateTime
        $log->save();
    }
}
