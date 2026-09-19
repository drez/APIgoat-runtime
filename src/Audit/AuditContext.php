<?php
// /var/www/gc/vendor/apigoat/runtime/src/Audit/AuditContext.php
namespace ApiGoat\Audit;

/**
 * Actor / source resolution + old-value diffing for the `add_audit` behavior
 * (goatcheese Parameters/add_audit.php).
 *
 * A table that declares `add_audit` gets a generated `<table>_audit` child and
 * two script blocks spliced into its Propel base object's save():
 *
 *   preSave  — when at least one AUDITED column is modified, read the row's
 *              current DB values and diff() them against the pending ones.
 *   postSave — persist one `<table>_audit` row per changed column, stamped
 *              with actor() and source().
 *
 * Called from generated code on every write of an audited table, so:
 *   - nothing here may throw on a missing session, a CLI SAPI or a daemon;
 *   - diff() must not cost a query when nothing audited changed (the emitted
 *     isColumnModified() gate short-circuits before diff() is ever reached).
 *
 * Why raw PDO for the read: Propel 1 never re-hydrates a pooled object, so
 * `Query::findPk()` would hand back the very instance being saved (its NEW
 * values) instead of what is on disk. The read therefore goes straight to the
 * connection — the same reason BudgetGuard / MarketStore bypass the pool.
 *
 * Why the $kinds map: Propel stores ENUM columns as TINYINT ORDINALS and
 * BOOLEAN as TINYINT, while the model getters return the label / bool. A naive
 * string compare of `3` against `'Live'` would report a change on every save,
 * so the emitter passes each audited column's kind (and, for enums, its value
 * set) and normalize() brings both sides into the same shape.
 */
final class AuditContext
{
    /** The `source` enum value set — must match Parameters/add_audit.php. */
    public const SOURCES = ['gui', 'api', 'mcp', 'cli', 'daemon'];

    /**
     * Set by an entry point that knows what it is: the emitted MCP route sets
     * 'mcp', the emitted JSON-API route sets 'api', a daemon calls asDaemon().
     * Unset, source() infers 'gui' (a web session) or 'cli'.
     */
    public static ?string $source = null;

    /** Mark this process a long-running background worker. */
    public static function asDaemon(): void
    {
        self::$source = 'daemon';
    }

    /**
     * Resolved write source, always one of SOURCES. An entry point's explicit
     * value wins; otherwise a web SAPI is 'gui' and everything else is 'cli'.
     */
    public static function source(): string
    {
        if (\is_string(self::$source) && \in_array(self::$source, self::SOURCES, true)) {
            return self::$source;
        }

        return \PHP_SAPI === 'cli' ? 'cli' : 'gui';
    }

    /**
     * The authenticated user's login, or null when there is no session (CLI,
     * cron, daemon). Defensive at every step: `_AUTH_VAR` may be undefined and
     * `$_SESSION` may not exist at all under CLI — the same guard the
     * add_tablestamp audit columns learned to carry.
     */
    public static function actor(): ?string
    {
        try {
            if (!\defined('_AUTH_VAR') || !isset($_SESSION) || !\is_array($_SESSION)) {
                return null;
            }
            $auth = $_SESSION[\_AUTH_VAR] ?? null;
            if (!\is_object($auth) || !\method_exists($auth, 'get')) {
                return null;
            }
            foreach (['username', 'email'] as $field) {
                $value = $auth->get($field);
                if (\is_string($value) && $value !== '') {
                    return \substr($value, 0, 128);
                }
            }
            if (\method_exists($auth, 'getIdAuthy')) {
                $id = $auth->getIdAuthy();
                if (!empty($id)) {
                    return '#' . $id;
                }
            }
        } catch (\Throwable $e) {
            // Resolving who did it can never be allowed to fail the write.
        }

        return null;
    }

    /**
     * Render one value as the text stored in value_from / value_to.
     *
     * $kind is the emitter's classification of the column:
     *   enum  — a DB-side ordinal is mapped back through $valueSet; a label
     *           (what the getter returns) passes through unchanged.
     *   bool  — '1' / '0'.
     *   num   — the numeric literal, trailing-zero noise left as given.
     *   date  — 'Y-m-d H:i:s'.
     *   text  — cast to string.
     * null stays null (SQL NULL), never the empty string.
     */
    public static function normalize($value, string $kind = 'text', array $valueSet = []): ?string
    {
        if ($value === null) {
            return null;
        }

        switch ($kind) {
            case 'enum':
                if ($valueSet !== [] && (\is_int($value) || (\is_string($value) && \ctype_digit($value)))) {
                    $ordinal = (int) $value;
                    if (\array_key_exists($ordinal, $valueSet)) {
                        return (string) $valueSet[$ordinal];
                    }
                }
                return (string) $value;

            case 'bool':
                if (\is_string($value) && $value !== '' && !\ctype_digit($value)) {
                    // 'true' / 'false' / 'yes' — anything but a falsy literal is true.
                    return \in_array(\strtolower($value), ['false', 'no', 'off'], true) ? '0' : '1';
                }
                return $value ? '1' : '0';

            case 'date':
                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d H:i:s');
                }
                return (string) $value;

            case 'num':
            case 'text':
            default:
                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d H:i:s');
                }
                if (\is_bool($value)) {
                    return $value ? '1' : '0';
                }
                return (string) $value;
        }
    }

    /**
     * True when two normalized values differ. Numeric columns compare by
     * VALUE, so DECIMAL(18,8)'s '100.00000000' on disk and the getter's '100'
     * are the same number and not a change; everything else compares by text.
     */
    public static function changed(?string $from, ?string $to, string $kind = 'text'): bool
    {
        if ($from === null || $to === null) {
            return $from !== $to;
        }
        if ($kind === 'num' && \is_numeric($from) && \is_numeric($to)) {
            return (float) $from !== (float) $to;
        }

        return $from !== $to;
    }

    /**
     * Compare the row's CURRENT DB values against the pending ones and return
     * one entry per changed column:
     *   ['field' => <column>, 'value_from' => ?string, 'value_to' => ?string]
     *
     * $newValues: column name => the model getter's value.
     * $kinds:     column name => [kind, valueSet] (see normalize()).
     *
     * Returns [] when the row is gone — an update that matches nothing has no
     * history to record.
     *
     * @param array<string, mixed>              $newValues
     * @param array<string, array{0:string,1:array}> $kinds
     * @return list<array{field:string, value_from:?string, value_to:?string}>
     */
    public static function diff(
        \PDO $con,
        string $table,
        string $pkColumn,
        $pkValue,
        array $newValues,
        array $kinds = []
    ): array {
        if ($newValues === [] || $pkValue === null || $pkValue === '') {
            return [];
        }

        $columns = \array_keys($newValues);
        self::assertIdentifier($table);
        self::assertIdentifier($pkColumn);
        foreach ($columns as $column) {
            self::assertIdentifier($column);
        }

        $select = '`' . \implode('`, `', $columns) . '`';
        $stmt = $con->prepare(
            'SELECT ' . $select . ' FROM `' . $table . '` WHERE `' . $pkColumn . '` = ? LIMIT 1'
        );
        $stmt->execute([$pkValue]);
        $old = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!\is_array($old)) {
            return [];
        }

        $rows = [];
        foreach ($newValues as $column => $newValue) {
            $kind     = (string) ($kinds[$column][0] ?? 'text');
            $valueSet = (array) ($kinds[$column][1] ?? []);
            $from = self::normalize($old[$column] ?? null, $kind, $valueSet);
            $to   = self::normalize($newValue, $kind, $valueSet);
            if (self::changed($from, $to, $kind)) {
                $rows[] = ['field' => $column, 'value_from' => $from, 'value_to' => $to];
            }
        }

        return $rows;
    }

    /** The single row an INSERT records: the whole record came into being. */
    public static function createdRow(): array
    {
        return [['field' => '*', 'value_from' => null, 'value_to' => 'created']];
    }

    /**
     * Schema identifiers only ever come from the emitter, but a column name is
     * interpolated into SQL here — so it is checked rather than trusted.
     */
    private static function assertIdentifier(string $name): void
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('AuditContext: invalid identifier "' . $name . '"');
        }
    }
}
