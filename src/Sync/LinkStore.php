<?php

namespace ApiGoat\Sync;

/** Local↔remote id bridge (acct_link in production, in-memory in tests). */
interface LinkStore
{
    /** @return ?array{remote_id:string, synctoken:?string, hash:?string, status:string} */
    public function find(string $role, string $table, int $pk): ?array;

    /** @param array{remote_id:string, synctoken:?string, hash:?string, status:string, last_error:?string} $link */
    public function save(string $role, string $table, int $pk, array $link): void;

    /** @return ?array{table:string, pk:int} */
    public function findByRemote(string $role, string $remoteId): ?array;

    /** Flag every role-link of a locally-deleted record LocalDeleted (never deletes remotely). */
    public function markDeleted(string $table, int $pk): void;
}
