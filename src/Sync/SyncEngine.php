<?php

namespace ApiGoat\Sync;

/**
 * Push pipeline: loads a local record through injected closures, maps it to a
 * canonical DTO per the sync map, resolves references depth-first (customer
 * before its invoice, vendor/account/category before their expense), skips
 * no-op pushes by content hash, and records remote ids in the LinkStore.
 * Pure of Propel/HTTP — everything external is injected (see SyncRuntime).
 */
final class SyncEngine
{
    private const REF_ROLES = ['customer', 'vendor', 'invoice', 'account', 'category'];

    /** @var callable(string,int):?array */
    private $loadRecord;
    /** @var callable(string,string,int):array */
    private $loadChildren;
    private AccountingProvider $provider;
    private LinkStore $links;
    private array $map;

    public function __construct(AccountingProvider $provider, LinkStore $links, callable $loadRecord, callable $loadChildren, array $map)
    {
        $this->provider     = $provider;
        $this->links        = $links;
        $this->loadRecord   = $loadRecord;
        $this->loadChildren = $loadChildren;
        $this->map          = $map;
    }

    /** @return string 'synced' | 'skipped' | 'missing' */
    public function pushRecord(string $table, int $pk): string
    {
        $cfg = SyncMap::tableConfig($this->map, $table);
        if (!$cfg) {
            return 'missing';
        }
        $row = ($this->loadRecord)($table, $pk);
        if ($row === null) {
            return 'missing';
        }
        $result = 'skipped';
        $firstError = null;
        foreach (SyncMap::rolesOf($cfg) as $role => $opts) {
            if (!SyncMap::whenPasses($opts, $row)) {
                continue;
            }
            // A party role (customer/vendor — not a primary role) is created only
            // through a document's ensureRef dependency path. Directly pushing an
            // unlinked party would attempt a create (e.g. editing a vendor-only
            // company must not spawn a Customer), so skip it until it's linked.
            if (!in_array($role, SyncMap::PRIMARY_ROLES, true) && !$this->links->find($role, $table, $pk)) {
                continue;
            }
            // Iterate every when-passing role even if one throws: land the other
            // roles' updates, keep the first error, and rethrow it so the job still
            // fails/retries.
            try {
                if ($this->pushRole($role, $table, $cfg, $pk, $row)) {
                    $result = 'synced';
                }
            } catch (\Throwable $e) {
                $firstError = $firstError ?? $e;
            }
        }
        if ($firstError !== null) {
            throw $firstError;
        }
        return $result;
    }

    /** True when an API push happened (false = content-hash skip). */
    private function pushRole(string $role, string $table, array $cfg, int $pk, array $row): bool
    {
        $dto  = $this->buildDto($role, $table, $cfg, $pk, $row);
        $hash = md5((string) json_encode($dto));
        $link = $this->links->find($role, $table, $pk);
        if ($link && $link['status'] === 'Synced' && $link['hash'] === $hash) {
            return false;
        }
        if (!$link) {
            $match = $this->provider->findMatch($role, $dto);
            if ($match) {
                $link = ['remote_id' => $match['id'], 'synctoken' => $match['synctoken'], 'hash' => null, 'status' => 'Pending'];
            }
        }
        $remote = $this->provider->push($role, $dto, $link);
        $this->links->save($role, $table, $pk, [
            'remote_id'  => $remote['id'],
            'synctoken'  => $remote['synctoken'] ?? null,
            'hash'       => $hash,
            'status'     => isset($remote['warning']) ? 'Error' : 'Synced',
            'last_error' => $remote['warning'] ?? null,
        ]);
        return true;
    }

    public function buildDto(string $role, string $table, array $cfg, int $pk, array $row): array
    {
        $dto = ['table' => $table, 'pk' => $pk, 'role' => $role, 'fields' => [], 'refs' => []];
        foreach (($cfg['fields'] ?? []) as $canonical => $col) {
            $dto['fields'][$canonical] = $row[$col] ?? null;
        }
        foreach (self::REF_ROLES as $refRole) {
            if (!isset($cfg[$refRole])) {
                continue;
            }
            $spec  = is_array($cfg[$refRole]) ? $cfg[$refRole] : ['column' => $cfg[$refRole]];
            $refPk = (int) ($row[$spec['column']] ?? 0);
            if ($refPk > 0) {
                $dto['refs'][$refRole] = $this->ensureRef($refRole, $refPk, $spec);
            }
        }
        if (isset($cfg['lines']['table'])) {
            $fkCol = $cfg['lines']['fk'] ?? ('id_' . $table);
            $dto['lines'] = [];
            foreach (($this->loadChildren)($cfg['lines']['table'], $fkCol, $pk) as $child) {
                $line = [];
                foreach ($cfg['lines']['fields'] as $canonical => $col) {
                    $line[$canonical] = $child[$col] ?? null;
                }
                $dto['lines'][] = $line;
            }
        }
        if (isset($cfg['taxes'])) {
            $dto['taxes'] = [];
            foreach ($cfg['taxes'] as $canonical => $col) {
                $dto['taxes'][$canonical] = $row[$col] ?? null;
            }
        }
        // A payment payload needs the customer of its linked invoice.
        if ($role === 'payment' && isset($dto['refs']['invoice'])) {
            $dto['refs'] += $this->customerOfInvoice($dto['refs']['invoice']);
        }
        return $dto;
    }

    /** @return array{}|array{customer: array} */
    private function customerOfInvoice(array $invRef): array
    {
        $invCfg = SyncMap::tableConfig($this->map, $invRef['table']);
        $invRow = ($this->loadRecord)($invRef['table'], $invRef['pk']);
        if (!$invCfg || $invRow === null || !isset($invCfg['customer'])) {
            return [];
        }
        $spec   = is_array($invCfg['customer']) ? $invCfg['customer'] : ['column' => $invCfg['customer']];
        $custPk = (int) ($invRow[$spec['column']] ?? 0);
        return $custPk > 0 ? ['customer' => $this->ensureRef('customer', $custPk, $spec)] : [];
    }

    /**
     * Resolve a reference to its remote id, pushing the referenced record first
     * when it isn't linked yet. Mapped roles (a table declares them) get a full
     * pushRole; unmapped roles (account/category) are name-matched only — the
     * provider refuses to create them.
     */
    private function ensureRef(string $role, int $pk, array $spec): array
    {
        $tables = SyncMap::tablesByRole($this->map, $role);
        if ($tables) {
            $table = $spec['table'] ?? array_key_first($tables);
            $cfg   = $tables[$table] ?? $tables[array_key_first($tables)];
            $link  = $this->links->find($role, $table, $pk);
            if (!$link || $link['remote_id'] === '' ) {
                $row = ($this->loadRecord)($table, $pk);
                if ($row === null) {
                    throw new Exceptions\ValidationRejected("Referenced {$role} #{$pk} not found in {$table}");
                }
                $this->pushRole($role, $table, $cfg, $pk, $row);
                $link = $this->links->find($role, $table, $pk);
            }
            return ['role' => $role, 'table' => $table, 'pk' => $pk, 'remote_id' => $link['remote_id']];
        }

        $table = (string) ($spec['table'] ?? '');
        $label = (string) ($spec['label'] ?? 'name');
        if ($table === '') {
            throw new Exceptions\ValidationRejected("Ref role '{$role}' needs {column, table, label} in sync_accounting");
        }
        $link = $this->links->find($role, $table, $pk);
        if (!$link) {
            $row = ($this->loadRecord)($table, $pk);
            if ($row === null) {
                throw new Exceptions\ValidationRejected("Referenced {$role} #{$pk} not found in {$table}");
            }
            $dto    = ['table' => $table, 'pk' => $pk, 'role' => $role, 'fields' => ['display_name' => (string) ($row[$label] ?? '')], 'refs' => []];
            $match  = $this->provider->findMatch($role, $dto);
            $remote = $this->provider->push($role, $dto, $match ? ['remote_id' => $match['id'], 'synctoken' => $match['synctoken']] : null);
            $this->links->save($role, $table, $pk, ['remote_id' => $remote['id'], 'synctoken' => $remote['synctoken'] ?? null, 'hash' => null, 'status' => 'Synced', 'last_error' => null]);
            $link = $this->links->find($role, $table, $pk);
        }
        return ['role' => $role, 'table' => $table, 'pk' => $pk, 'remote_id' => $link['remote_id']];
    }
}
