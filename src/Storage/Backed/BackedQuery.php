<?php

namespace ApiGoat\Storage\Backed;

use ApiGoat\Storage\FileStorageInterface;

/**
 * Propel-Query-compatible facade over a FileStorageInterface so the GoatCheese
 * emitter's existing query chains (`SomeQuery::create()->filterByX($v)->find()`,
 * `->paginate()`, `->findPk()`, `->findOne()`) work against a headless entity.
 *
 * Filters are accumulated and translated into a single FileStorageInterface::list()
 * call when the query is materialized. Filter semantics are intentionally narrow
 * for v1: equality / "starts with" on direct columns. Anything that would require
 * a JOIN against another model (set_label_link FK traversal, list-via-foreign-table)
 * is unsupported and the emitter must skip those paths for drive-backed entities.
 */
class BackedQuery
{
    /** @var class-string<BackedEntity> */
    private string $entityClass;
    private FileStorageInterface $storage;
    private string $scope = '';

    /** @var array<int, array{column: string, value: mixed, op: string}> */
    private array $filters = [];

    /** @var ?array{column: string, dir: string} */
    private ?array $orderBy = null;

    private ?int $limit = null;

    public function __construct(string $entityClass, FileStorageInterface $storage, string $scope = '')
    {
        if (!is_a($entityClass, BackedEntity::class, true)) {
            throw new \InvalidArgumentException(
                "BackedQuery: entity class '{$entityClass}' must extend " . BackedEntity::class
            );
        }
        $this->entityClass = $entityClass;
        $this->storage = $storage;
        $this->scope = $scope;
    }

    /**
     * Propel-style factory. The emitter calls this with a class name (the
     * generated Form/Entity class). Storage and scope are injected by the
     * Service before the chain is materialized — emit pattern:
     *   $q = SomeQuery::create()->setStorage($this->storage)->setScope($this->driveScope());
     */
    public static function create(string $entityClass, ?FileStorageInterface $storage = null, string $scope = ''): self
    {
        // Storage may be null when emit needs to construct the query
        // before the Service has injected; defer the check until execution.
        $q = new self($entityClass, $storage ?? new NullStorage(), $scope);
        if (!$storage) {
            $q->storage = null; // sentinel
        }
        return $q;
    }

    public function setStorage(FileStorageInterface $storage): self
    {
        $this->storage = $storage;
        return $this;
    }

    public function setScope(string $scope): self
    {
        $this->scope = $scope;
        return $this;
    }

    /**
     * Generic equality filter — `filterByX($v)` resolves to filterBy('X', $v)
     * through __call below.
     */
    public function filterBy(string $phpName, $value, ?string $op = '='): self
    {
        $col = BackedEntityHelper::phpNameToColumn($phpName);
        $this->filters[] = ['column' => $col, 'value' => $value, 'op' => $op ?? '='];
        return $this;
    }

    /**
     * Dynamic filterBy<Column>($value [, $criteria]) so emitted code keeps
     * working unchanged.
     */
    public function __call(string $method, array $args)
    {
        if (strlen($method) > 8 && str_starts_with($method, 'filterBy')) {
            $phpName = substr($method, 8);
            $value = $args[0] ?? null;
            $op = $args[1] ?? '=';
            return $this->filterBy($phpName, $value, is_string($op) ? $op : '=');
        }
        if ($method === 'orderBy' || str_starts_with($method, 'orderBy')) {
            $col = $method === 'orderBy'
                ? (string) ($args[0] ?? '')
                : BackedEntityHelper::phpNameToColumn(substr($method, 7));
            $dir = (string) ($args[1] ?? $args[0] ?? 'ASC');
            if (in_array(strtoupper($dir), ['ASC', 'DESC'], true)) {
                $this->orderBy = ['column' => $col, 'dir' => strtoupper($dir)];
            }
            return $this;
        }
        throw new \BadMethodCallException("BackedQuery has no method '{$method}'");
    }

    public function limit(int $n): self
    {
        $this->limit = max(0, $n);
        return $this;
    }

    /**
     * Materialize: return all matching entities.
     *
     * @return array<int, BackedEntity>
     */
    public function find(): array
    {
        $this->requireStorage();
        $payload = $this->storage->list($this->scope, $this->buildListFilters());
        $items = $payload['items'] ?? [];
        return array_map(fn($row) => $this->hydrate($row), $items);
    }

    public function findOne(): ?BackedEntity
    {
        $this->limit = 1;
        $res = $this->find();
        return $res[0] ?? null;
    }

    public function findPk($id): ?BackedEntity
    {
        $this->requireStorage();
        try {
            $row = $this->storage->get((string) $id);
        } catch (\Throwable $e) {
            return null;
        }
        return $row ? $this->hydrate($row) : null;
    }

    public function count(): int
    {
        $payload = $this->storage->list($this->scope, $this->buildListFilters());
        return (int) ($payload['total'] ?? count($payload['items'] ?? []));
    }

    /**
     * Propel pager-compatible: returns a BackedPager mirroring
     * PropelModelPager's surface (getNbResults, getResults, haveToPaginate,
     * getPage, …).
     */
    public function paginate(int $page = 1, int $perPage = 20): BackedPager
    {
        return new BackedPager($this, $page, $perPage);
    }

    /**
     * Used by BackedPager to fetch one page slice.
     *
     * @return array<int, BackedEntity>
     */
    public function paginateFetch(int $page, int $perPage): array
    {
        $filters = $this->buildListFilters();
        $filters['limit'] = $perPage;
        if ($page > 1 && isset($this->_pageToken)) {
            $filters['page_token'] = $this->_pageToken;
        }
        $payload = $this->storage->list($this->scope, $filters);
        $this->_pageToken = $payload['page_token'] ?? null;
        $items = $payload['items'] ?? [];
        return array_map(fn($row) => $this->hydrate($row), $items);
    }

    /** @internal */
    public ?string $_pageToken = null;

    /**
     * Translate accumulated filters into the FileStorageInterface::list() shape.
     * Backends understand at minimum 'name_starts_with', 'mime_type', 'limit'.
     */
    private function buildListFilters(): array
    {
        $out = [];
        foreach ($this->filters as $f) {
            // The HJSON column 'file_name' maps to storage key 'name'.
            $map = ($this->entityClass)::columnMapPublic();
            $storageKey = $map[$f['column']] ?? $f['column'];
            $val = $f['value'];

            if (is_string($val) && str_ends_with($val, '%')) {
                // Propel pattern: `filterByName('foo%')` → starts-with.
                $val = rtrim($val, '%');
                $key = $storageKey === 'name'
                    ? 'name_starts_with'
                    : ($storageKey . '_starts_with');
                $out[$key] = $val;
            } else {
                $out[$storageKey] = $val;
            }
        }
        if ($this->orderBy) {
            $out['order_by'] = $this->orderBy['column'];
            $out['order_dir'] = $this->orderBy['dir'];
        }
        if ($this->limit !== null) {
            $out['limit'] = $this->limit;
        }
        return $out;
    }

    private function hydrate(array $row): BackedEntity
    {
        /** @var BackedEntity $entity */
        $entity = new ($this->entityClass)();
        $entity->setStorage($this->storage);
        if ($this->scope) {
            $entity->setScope($this->scope);
        }
        $entity->fromStorageArray($row);
        return $entity;
    }

    private function requireStorage(): void
    {
        if (!$this->storage) {
            throw new \LogicException(
                'BackedQuery: no FileStorageInterface set — emit pattern is '
                . 'Q::create($entity)->setStorage($this->storage)->setScope($scope).'
            );
        }
    }
}

/**
 * Small helper so the Entity and Query share a single phpName-to-column rule
 * without forcing BackedEntity::phpNameToColumn to be public.
 *
 * @internal
 */
final class BackedEntityHelper
{
    public static function phpNameToColumn(string $phpName): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $phpName));
    }
}

/**
 * Sentinel returned by BackedQuery::create() before a real storage is
 * injected. Throws on any call so misuse is caught loudly.
 *
 * @internal
 */
final class NullStorage implements FileStorageInterface
{
    public function list(string $scope, array $filters = []): array
    {
        throw new \LogicException('BackedQuery has no storage attached — call setStorage() before materializing.');
    }
    public function get(string $id): array { return $this->list(''); }
    public function upload(string $scope, string $name, string $bytes, string $mimeType): array { return $this->list(''); }
    public function update(string $id, array $patch): array { return $this->list(''); }
    public function delete(string $id): bool { $this->list(''); return false; }
    public function share(string $id, string $level): string { $this->list(''); return ''; }
}
