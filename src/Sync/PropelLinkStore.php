<?php

namespace ApiGoat\Sync;

/** LinkStore over the emitted acct_link table. */
final class PropelLinkStore implements LinkStore
{
    public function __construct(private string $provider = 'quickbooks')
    {
    }

    public function find(string $role, string $table, int $pk): ?array
    {
        $l = \App\AcctLinkQuery::create()
            ->filterByProvider($this->provider)->filterByRole($role)
            ->filterByLocalTable($table)->filterByLocalPk($pk)->findOne();
        if (!$l) {
            return null;
        }
        return [
            'remote_id' => (string) $l->getRemoteId(),
            'synctoken' => $l->getRemoteSynctoken(),
            'hash'      => $l->getContentHash(),
            'status'    => (string) $l->getStatus(),
        ];
    }

    public function save(string $role, string $table, int $pk, array $link): void
    {
        $l = \App\AcctLinkQuery::create()
            ->filterByProvider($this->provider)->filterByRole($role)
            ->filterByLocalTable($table)->filterByLocalPk($pk)->findOne() ?: new \App\AcctLink();
        $l->setProvider($this->provider);
        $l->setRole($role);
        $l->setLocalTable($table);
        $l->setLocalPk($pk);
        $l->setRemoteId((string) $link['remote_id']);
        $l->setRemoteSynctoken($link['synctoken'] ?? null);
        $l->setContentHash($link['hash'] ?? null);
        $l->setStatus($link['status'] ?? 'Synced');
        $l->setLastError($link['last_error'] ?? null);
        $l->setSyncedAt(date('Y-m-d H:i:s'));
        $l->save();
    }

    public function findByRemote(string $role, string $remoteId): ?array
    {
        $l = \App\AcctLinkQuery::create()
            ->filterByProvider($this->provider)->filterByRole($role)
            ->filterByRemoteId($remoteId)->findOne();
        return $l ? ['table' => (string) $l->getLocalTable(), 'pk' => (int) $l->getLocalPk()] : null;
    }

    public function markDeleted(string $table, int $pk): void
    {
        $links = \App\AcctLinkQuery::create()
            ->filterByProvider($this->provider)
            ->filterByLocalTable($table)->filterByLocalPk($pk)->find();
        foreach ($links as $l) {
            $l->setStatus('LocalDeleted');
            $l->save();
        }
    }
}
