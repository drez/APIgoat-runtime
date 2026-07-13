<?php

namespace ApiGoat\Sync;

/** Provider seam — QuickBooks Online is the first adapter (Sync/QuickBooks/). */
interface AccountingProvider
{
    /**
     * Create (existing = null) or sparse-update one record.
     * @param  ?array $existing link array (remote_id/synctoken) when already linked or matched
     * @return array{id:string, synctoken:?string, warning?:string}
     */
    public function push(string $role, array $dto, ?array $existing): array;

    /** Duplicate matching before create (party/account roles only). @return ?array{id:string, synctoken:?string} */
    public function findMatch(string $role, array $dto): ?array;

    /** @return array{payments: list<array{remote_id:string, invoice_remote_id:string, amount:string, date:string, method:?string, reference:?string}>, cursor:string} */
    public function pullPayments(?string $cursor): array;
}
