<?php

namespace ApiGoat\Api;

/**
 * RBAC-filtered, schema-only catalog of the CRM's Propel entities and fields.
 *
 * Built purely from Propel 1 TableMap introspection + the session's existing
 * rights — no DDL, no new schema, no caching. Drives the `crm_describe` MCP
 * tool and in-process payload validation. The `writable` flag mirrors the
 * generic CRUD write surface via Api::SYSTEM_COLUMNS so the two cannot drift.
 *
 * Note: `writable` here is the *generic* surface (system denylist + PK). The
 * CRUD layer additionally honors a per-form editableFields allowlist that can
 * further restrict columns; the MCP must treat a 400 from create/update as
 * authoritative. Row-level read scope (tenant/Owner/Group) is enforced at query
 * time by setAclFilter and is intentionally not part of this schema catalog.
 */
final class MetaCatalog
{
    /** Propel adapter type => MCP-friendly type. */
    private const TYPE_MAP = [
        'VARCHAR' => 'string', 'LONGVARCHAR' => 'string', 'CHAR' => 'string', 'CLOB' => 'string',
        'INTEGER' => 'integer', 'SMALLINT' => 'integer', 'TINYINT' => 'integer', 'BIGINT' => 'integer',
        'FLOAT' => 'number', 'DOUBLE' => 'number', 'DECIMAL' => 'number', 'REAL' => 'number', 'NUMERIC' => 'number',
        'BOOLEAN' => 'boolean',
        'DATE' => 'datetime', 'TIME' => 'datetime', 'TIMESTAMP' => 'datetime',
        'ENUM' => 'enum',
        'BLOB' => 'binary', 'LONGVARBINARY' => 'binary', 'VARBINARY' => 'binary',
    ];

    private const REL_TYPE = [
        \RelationMap::MANY_TO_ONE => 'many_to_one',
        \RelationMap::ONE_TO_MANY => 'one_to_many',
        \RelationMap::MANY_TO_MANY => 'many_to_many',
    ];

    /** @var callable fn(string $entityName): ?\TableMap */
    private $mapResolver;

    /**
     * @param object        $session     exposes isAdmin() and hasRights($model,$letter)
     * @param callable|null $mapResolver fn(string):?\TableMap; null => \App\{Name}Peer::getTableMap()
     */
    public function __construct(private $session, ?callable $mapResolver = null)
    {
        $this->mapResolver = $mapResolver ?? [$this, 'resolveAppPeerMap'];
    }

    /**
     * @param string[] $entityNames PhpName route keys (json GET keys)
     * @return array
     */
    public function build(array $entityNames): array
    {
        $entities = [];
        foreach ($entityNames as $name) {
            try {
                $map = ($this->mapResolver)($name);
                if (!$map instanceof \TableMap) {
                    // resolver returned null: a service-only route with no Peer, or a name whose
                    // class fails class_exists() / lacks getTableMap(). Just skip it.
                    continue;
                }
                if (!$this->permitted($name, 'r')) {
                    continue; // hide entities the user has no read right on
                }
                $entities[$name] = $this->describe($name, $map);
            } catch (\Throwable) {
                // One bad peer must not 500 the whole catalog. Catch \Throwable (NOT \Exception):
                // a dangling classmap entry makes the autoloader's require fail with a catchable
                // \Error, which a narrower \Exception catch would let escape and 500 /_meta.
                continue;
            }
        }

        return [
            'version'      => $this->apiVersion(),
            'generated_at' => time(),
            'user'         => ['is_admin' => $this->session->isAdmin()],
            'entities'     => $entities,
        ];
    }

    /** The API version string, used for both the catalog header and endpoint URLs. */
    private function apiVersion(): string
    {
        return defined('API_VERSION') ? (string) API_VERSION : '1';
    }

    /** Default resolver: \App\{Name}Peer::getTableMap(), or null when absent. */
    private function resolveAppPeerMap(string $name): ?\TableMap
    {
        $peer = "\\App\\{$name}Peer";
        if (!class_exists($peer) || !method_exists($peer, 'getTableMap')) {
            return null;
        }
        return $peer::getTableMap();
    }

    private function permitted(string $name, string $letter): bool
    {
        return $this->session->isAdmin() || (bool) $this->session->hasRights($name, $letter);
    }

    private function describe(string $name, \TableMap $map): array
    {
        $version = $this->apiVersion();
        $base = "/api/v{$version}/{$name}";

        $fields = [];
        $primaryKey = null;
        foreach ($map->getColumns() as $col) {
            /** @var \ColumnMap $col */
            $phpName = $col->getPhpName();
            if ($col->isPrimaryKey() && $primaryKey === null) {
                $primaryKey = $phpName;
            }
            $fields[$phpName] = $this->describeColumn($col);
        }

        return [
            'label'       => $map->getPhpName(),
            'table'       => $map->getName(),
            'primary_key' => $primaryKey,
            'endpoints'   => [
                'list'   => "GET {$base}",
                'get'    => "GET {$base}/{id}",
                'create' => "POST {$base}",
                'update' => "PATCH {$base}/{id}",
                'delete' => "DELETE {$base}/{id}",
            ],
            'permissions' => [
                'read'   => $this->permitted($name, 'r'),
                'create' => $this->permitted($name, 'a'),
                'update' => $this->permitted($name, 'w'),
                'delete' => $this->permitted($name, 'd'),
            ],
            'fields'          => $fields,
            'relations'       => $this->describeRelations($map),
            'display_columns' => $this->resolveDisplayColumns($map),
        ];
    }

    private function describeColumn(\ColumnMap $col): array
    {
        $rawType = $col->getType();
        $type = self::TYPE_MAP[$rawType] ?? 'string';

        $field = [
            'column'      => $col->getName(),
            'name'        => $col->getPhpName(),
            'type'        => $type,
            'required'    => $col->isNotNull() && !$col->isPrimaryKey() && $col->getDefaultValue() === null,
            'size'        => $col->getSize(),
            'primary_key' => $col->isPrimaryKey(),
            'default'     => $col->getDefaultValue(),
            'writable'    => !$col->isPrimaryKey()
                && !in_array($col->getPhpName(), \ApiGoat\Api\Api::SYSTEM_COLUMNS, true),
        ];

        if ($type === 'enum') {
            $field['enum'] = $col->getValueSet();
        }

        if ($col->isForeignKey()) {
            $field['relation'] = [
                'type'    => 'many_to_one',
                'entity'  => $this->studly($col->getRelatedTableName()),
                'local'   => $col->getName(),
                'foreign' => $col->getRelatedColumnName(),
            ];
        }

        return $field;
    }

    /** @return array<int,array> */
    private function describeRelations(\TableMap $map): array
    {
        $out = [];
        foreach ($map->getRelations() as $rel) {
            /** @var \RelationMap $rel */
            $out[] = [
                'name'   => $rel->getName(),
                'type'   => self::REL_TYPE[$rel->getType()] ?? 'unknown',
                'entity' => $rel->getForeignTable()->getPhpName(),
            ];
        }
        return $out;
    }

    /** Snake_case column names composing a row's display label. */
    private function resolveDisplayColumns(\TableMap $map): array
    {
        $behaviors = method_exists($map, 'getBehaviors') ? $map->getBehaviors() : [];
        $json = $behaviors['GoatCheese']['set_main_label'] ?? null;
        $mainLabel = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);

        $fallback = [];
        foreach ($map->getColumns() as $col) {
            if (!$col->isPrimaryKey()
                && in_array($col->getType(), ['VARCHAR', 'CHAR', 'LONGVARCHAR'], true)) {
                $fallback[] = $col->getName();
            }
        }
        return self::displayColumnsFrom(is_array($mainLabel) ? $mainLabel : null, $fallback);
    }

    /** Pure: main-label columns if any, else the first fallback string column, else []. */
    public static function displayColumnsFrom(?array $mainLabel, array $fallbackStringCols): array
    {
        if (is_array($mainLabel) && $mainLabel !== []) {
            return array_values($mainLabel);
        }
        return $fallbackStringCols !== [] ? [$fallbackStringCols[0]] : [];
    }

    /** "bank_account" => "BankAccount" (dependency-free, no global camelize). */
    private function studly(string $table): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $table)));
    }
}
