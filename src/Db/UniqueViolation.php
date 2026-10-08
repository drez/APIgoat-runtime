<?php

declare(strict_types=1);

namespace ApiGoat\Db;

/**
 * Turns a MySQL duplicate-key failure (SQLSTATE 23000 / errno 1062) raised out
 * of a Propel save() into a field validation error, so a unique-index
 * collision is a form / API 400 instead of a raw 500.
 *
 * The generated <Model>Service carries `UNIQUE_KEYS` (index name => columns,
 * Propel naming `<table>_U_<n>`); both the admin save and Api::setEntry hand
 * the caught exception and that map here.
 */
final class UniqueViolation
{
    /** Columns that scope or audit a row; never the user-facing culprit. */
    public const SYSTEM_COLUMNS = ['id_tenant', 'id_mailbox_group', 'id_owner', 'id_creation', 'id_modification', 'id_group_creation'];

    /**
     * @param array<string,string[]> $uniqueKeys index name => column names
     * @return array<string,string>|null [column => message], null when $e is not a
     *                                   1062 on a known unique index
     */
    public static function errors(\Throwable $e, array $uniqueKeys): ?array
    {
        $message = self::duplicateMessage($e);
        if ($message === null || !preg_match("/for key '([^']+)'/", $message, $m)) {
            return null;
        }
        $key = $m[1];
        // MySQL 8.0.19+ qualifies the name: 'client.client_U_1'.
        if (($dot = strrpos($key, '.')) !== false) {
            $key = substr($key, $dot + 1);
        }
        if (empty($uniqueKeys[$key])) {
            return null;
        }
        $cols = array_values(array_diff($uniqueKeys[$key], self::SYSTEM_COLUMNS));
        if ($cols === []) {
            $cols = [reset($uniqueKeys[$key])];
        }
        $text = function_exists('_') ? _('Already exists') : 'Already exists';
        return array_fill_keys($cols, $text);
    }

    /** Shape the generated admin save feeds PropelErrorHandler: [message => ['fields' => cols]]. */
    public static function asExtValidation(array $errors): array
    {
        $out = [];
        foreach ($errors as $col => $msg) {
            $out[$msg]['fields'][] = $col;
        }
        return $out;
    }

    private static function duplicateMessage(\Throwable $e): ?string
    {
        for ($i = 0; $e !== null && $i < 6; $i++, $e = $e->getPrevious()) {
            $info = $e instanceof \PDOException ? $e->errorInfo : null;
            $isDup = (is_array($info) && (int) ($info[1] ?? 0) === 1062)
                || (str_contains($e->getMessage(), '1062') && str_contains($e->getMessage(), 'Duplicate entry'))
                || ($e instanceof \PDOException && str_contains($e->getMessage(), 'Duplicate entry'));
            if ($isDup) {
                return $e->getMessage();
            }
        }
        return null;
    }
}
