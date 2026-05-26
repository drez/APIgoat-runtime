<?php

namespace ApiGoat\Storage\Backed;

use ApiGoat\Storage\FileStorageInterface;

/**
 * Propel-compatible model surface for a headless entity backed by a
 * FileStorageInterface implementation. The GoatCheese emitter generates
 * Form classes that extend this when `is_drive_backed` is declared on an
 * HJSON entity, so the same emit shape (`$dataObj->getX()`, `$e->save()`,
 * `$e->delete()`, etc.) works without a MySQL table behind it.
 *
 * Method surface mirrored from Propel BaseObject so the emitter's existing
 * call sites — `new <Class>()`, `fromArray()`, `validate()`, `save()`,
 * `delete()`, `getPrimaryKey()`, `isNew()`, dynamic `get<Col>` / `set<Col>` —
 * resolve transparently. Subclasses (one per drive-backed table, emitted by
 * the generator) declare the schema (column map, PK, scope, scopes) by
 * overriding the static `columnMap()`, `primaryKeyColumn()`, etc.
 */
abstract class BackedEntity
{
    /** Set by the Service / DI before the entity is used. */
    protected ?FileStorageInterface $storage = null;

    /** Folder path / scope this row lives in. Set by the Service per request. */
    protected string $scope = '';

    /** Current row data, keyed by HJSON column name (snake_case). */
    protected array $data = [];

    /** Tracks whether the row has been persisted yet. */
    protected bool $isNew = true;

    /**
     * Column -> storage-key map, e.g.:
     *   ['id_drive_file' => 'id', 'file_name' => 'name', 'mime_type' => 'mimeType']
     * Override in the generated subclass. Public so BackedQuery can translate
     * column-based filters into storage-key filters at materialization time.
     *
     * @return array<string,string>
     */
    abstract public static function columnMap(): array;

    /**
     * Alias for the runtime BackedQuery so it doesn't import the static via
     * a `$class::columnMap()` ambiguity when $class is dynamic.
     *
     * @return array<string,string>
     */
    public static function columnMapPublic(): array
    {
        return static::columnMap();
    }

    /**
     * HJSON column that holds the storage `id` (e.g. 'id_drive_file').
     * Override in the generated subclass.
     */
    abstract public static function primaryKeyColumn(): string;

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

    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Hydrate from a key=>value array (Propel BaseObject::fromArray contract).
     * Keys may use either HJSON column names OR PhpName form ('IdDriveFile').
     */
    public function fromArray(array $arr, $keyType = null): self
    {
        foreach ($arr as $k => $v) {
            $col = static::keyToColumn($k);
            if ($col !== null) {
                $this->data[$col] = $v;
            }
        }
        $pk = static::primaryKeyColumn();
        if (!empty($this->data[$pk] ?? null)) {
            $this->isNew = false;
        }
        return $this;
    }

    /**
     * Hydrate from a storage-side metadata array (the shape FileStorageInterface
     * returns from list/get/upload/update).
     *
     * @param array<string,mixed> $storageArr
     */
    public function fromStorageArray(array $storageArr): self
    {
        foreach (static::columnMap() as $col => $storageKey) {
            if (array_key_exists($storageKey, $storageArr)) {
                $this->data[$col] = $storageArr[$storageKey];
            }
        }
        $pk = static::primaryKeyColumn();
        if (!empty($this->data[$pk] ?? null)) {
            $this->isNew = false;
        }
        return $this;
    }

    public function toArray($keyType = null, $includeLazyLoadColumns = true): array
    {
        return $this->data;
    }

    /** Propel-style validation hook. Generated subclasses can override. */
    public function validate(): bool
    {
        return true;
    }

    /**
     * Persist. For NEW entities, upload bytes (the BackedEntity assumes
     * `__bytes` and `__mime` keys were stashed via setBytes() from the
     * Service's upload handler). For EXISTING entities, PATCH metadata.
     */
    public function save(): bool
    {
        $this->requireStorage();
        $map = static::columnMap();

        if ($this->isNew) {
            $bytes = $this->data['__bytes'] ?? null;
            $name  = $this->data[array_search('name', $map, true) ?: 'file_name'] ?? '';
            $mime  = $this->data['__mime'] ?? ($this->data[array_search('mimeType', $map, true) ?: 'mime_type'] ?? 'application/octet-stream');
            if (!is_string($bytes) || $bytes === '' || $name === '') {
                throw new \LogicException(
                    'BackedEntity::save() on a new entity requires bytes — call setBytes($content, $mime) first '
                    . '(this is normally done by the auto-emitted upload action).'
                );
            }
            $created = $this->storage->upload($this->scope, $name, $bytes, $mime);
            $this->fromStorageArray($created);
            unset($this->data['__bytes'], $this->data['__mime']);
            return true;
        }

        // EXISTING entity → metadata patch.
        $patch = [];
        foreach ($map as $col => $storageKey) {
            if (array_key_exists($col, $this->data)) {
                $patch[$storageKey] = $this->data[$col];
            }
        }
        // Don't send the id as part of the patch.
        unset($patch[$map[static::primaryKeyColumn()] ?? 'id']);
        if (empty($patch)) {
            return true; // nothing to patch
        }
        $updated = $this->storage->update((string) $this->getPrimaryKey(), $patch);
        $this->fromStorageArray($updated);
        return true;
    }

    public function delete(): bool
    {
        $this->requireStorage();
        $pk = $this->getPrimaryKey();
        if (!$pk) {
            return true; // nothing to delete
        }
        return $this->storage->delete((string) $pk);
    }

    public function getPrimaryKey()
    {
        return $this->data[static::primaryKeyColumn()] ?? null;
    }

    public function setPrimaryKey($v): self
    {
        $this->data[static::primaryKeyColumn()] = $v;
        if (!empty($v)) {
            $this->isNew = false;
        }
        return $this;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function setNew(bool $b): self
    {
        $this->isNew = $b;
        return $this;
    }

    /**
     * Stash a byte payload on the entity so the next save() can upload it.
     * Called by the upload handler the emitter generates.
     */
    public function setBytes(string $bytes, string $mime = 'application/octet-stream'): self
    {
        $this->data['__bytes'] = $bytes;
        $this->data['__mime']  = $mime;
        return $this;
    }

    /**
     * Dynamic get<Phpname>() / set<Phpname>($v) so the emitted Form/Service
     * code can keep using Propel-style accessors verbatim.
     */
    public function __call(string $method, array $args)
    {
        if (strlen($method) > 3 && str_starts_with($method, 'get')) {
            $col = static::phpNameToColumn(substr($method, 3));
            return $this->data[$col] ?? null;
        }
        if (strlen($method) > 3 && str_starts_with($method, 'set')) {
            $col = static::phpNameToColumn(substr($method, 3));
            $this->data[$col] = $args[0] ?? null;
            return $this;
        }
        throw new \BadMethodCallException(static::class . " has no method '{$method}'");
    }

    protected function requireStorage(): void
    {
        if (!$this->storage) {
            throw new \LogicException(
                static::class . ' needs a FileStorageInterface — call $entity->setStorage($storage) '
                . 'before save()/delete() (normally injected by the generated Service).'
            );
        }
    }

    /**
     * PhpName ("FileName", "IdDriveFile") → HJSON column ("file_name", "id_drive_file").
     */
    protected static function phpNameToColumn(string $phpName): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $phpName));
    }

    /**
     * Map a user-provided key (HJSON column, PhpName, or storage key) to the
     * canonical HJSON column name. Returns null for unknown keys.
     */
    protected static function keyToColumn(string $key): ?string
    {
        $map = static::columnMap();
        if (isset($map[$key])) {
            return $key;
        }
        // PhpName -> snake_case
        if (preg_match('/^[A-Z]/', $key)) {
            $snake = static::phpNameToColumn($key);
            if (isset($map[$snake])) {
                return $snake;
            }
        }
        // Storage-key reverse lookup
        $rev = array_flip($map);
        return $rev[$key] ?? null;
    }
}
