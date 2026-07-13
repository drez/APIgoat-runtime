<?php

namespace ApiGoat\Sync;

/**
 * Reads config/Built/sync.map.php — the field map emitted on `gc build` by the
 * with_accounting_sync GoatCheese parameter from the tables' sync_accounting
 * HJSON declarations — and answers role/table questions about it.
 */
final class SyncMap
{
    /** Roles whose records enqueue themselves on save; party roles (customer/
     *  vendor) only sync as dependencies or once already linked. */
    public const PRIMARY_ROLES = ['invoice', 'expense', 'payment'];

    public static function load(): ?array
    {
        if (!defined('_BASE_DIR')) {
            return null;
        }
        $file = _BASE_DIR . 'config/Built/sync.map.php';
        if (!is_file($file)) {
            return null;
        }
        $map = require $file;
        return (is_array($map) && !empty($map['tables'])) ? $map : null;
    }

    public static function tableConfig(array $map, string $table): ?array
    {
        return $map['tables'][$table] ?? null;
    }

    /** Normalized [role => roleOpts] whether the config used 'role' or 'roles'. */
    public static function rolesOf(array $cfg): array
    {
        if (isset($cfg['roles']) && is_array($cfg['roles'])) {
            return array_map(fn ($o) => is_array($o) ? $o : [], $cfg['roles']);
        }
        return isset($cfg['role']) ? [(string) $cfg['role'] => []] : [];
    }

    /** [table => cfg] of every table declaring $role. */
    public static function tablesByRole(array $map, string $role): array
    {
        $out = [];
        foreach (($map['tables'] ?? []) as $t => $cfg) {
            if (array_key_exists($role, self::rolesOf($cfg))) {
                $out[$t] = $cfg;
            }
        }
        return $out;
    }

    /**
     * True when $row satisfies a role's gate. Each 'when' entry must match and
     * each 'when_not' entry must NOT match. A value may be a scalar (equality)
     * or an array (in_array membership).
     */
    public static function whenPasses(array $roleOpts, array $row): bool
    {
        foreach (($roleOpts['when'] ?? []) as $col => $val) {
            if (!self::valueMatches($row[$col] ?? null, $val)) {
                return false;
            }
        }
        foreach (($roleOpts['when_not'] ?? []) as $col => $val) {
            if (self::valueMatches($row[$col] ?? null, $val)) {
                return false;
            }
        }
        return true;
    }

    /** Scalar → string equality; array → membership. */
    private static function valueMatches($actual, $expected): bool
    {
        $a = (string) ($actual ?? '');
        if (is_array($expected)) {
            foreach ($expected as $e) {
                if ($a === (string) $e) {
                    return true;
                }
            }
            return false;
        }
        return $a === (string) $expected;
    }
}
