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

    /**
     * The `source` value as the DB stores it: Propel materializes an ENUM
     * column as a TINYINT holding the value set's ORDINAL, so a raw INSERT
     * must write the index, not the label. SOURCES is the emitted value set
     * (Parameters/add_audit.php asserts the two agree), so the ordinal is
     * this array's key.
     */
    public static function sourceOrdinal(): int
    {
        $ordinal = \array_search(self::source(), self::SOURCES, true);

        return $ordinal === false ? 0 : (int) $ordinal;
    }

    /**
     * Persist the diff() entries as `<table>_audit` rows on the connection the
     * parent is being saved on, stamped with actor() and source().
     *
     * WHY A RAW INSERT AND NOT `new <T>Audit()->save($con)`:
     * the caller is the parent's postSave, which runs INSIDE the parent's open
     * transaction. A Propel model save() opens a NESTED transaction, and
     * PropelPDO::rollBack() at depth > 1 does not roll anything back — it sets
     * `isUncommitable = true` and returns (runtime/lib/connection/PropelPDO.php).
     * The parent's own outer commit() then throws
     * "Cannot commit because a nested transaction was rolled back" — from
     * inside save(), OUTSIDE the caller's catch. So a failed audit insert
     * would roll the PARENT row back and surface a PropelException: exactly
     * the state the guard exists for (the key adopted, the database not yet
     * rebuilt), and any FK or shape violation besides.
     *
     * A failed PDOStatement::execute() throws without ever touching
     * nestedTransactionCount or isUncommitable, so the caller's catch really
     * does contain it and the parent row still commits. On MySQL a statement
     * error rolls back the statement, not the transaction.
     *
     * Returns the number of rows written; throws only what the caller catches.
     *
     * @param list<array{field:string, value_from:?string, value_to:?string}> $rows
     * @param array<string, mixed> $extra       extra column => value, bound as-is
     *                                          (the parent's id_tenant, so the
     *                                          history stays inside its tenant)
     * @param string[]             $actorStamps owner columns to stamp from the
     *                                          session (see actorStampValues())
     */
    public static function write(
        \PDO $con,
        string $auditTable,
        string $fkColumn,
        $fkValue,
        array $rows,
        array $extra = [],
        array $actorStamps = []
    ): int {
        if ($rows === []) {
            return 0;
        }
        self::assertIdentifier($auditTable);
        self::assertIdentifier($fkColumn);

        // Extra columns, all decided by the emitter at build time from the
        // audit table's real shape (so an older table never sees a column it
        // does not have): $extra carries the parent's id_tenant, $actorStamps
        // names the add_tablestamp owner columns to fill from the session.
        $extra = $extra + self::actorStampValues($actorStamps);
        foreach (\array_keys($extra) as $column) {
            self::assertIdentifier((string) $column);
        }
        $extraCols = $extra === [] ? '' : ', `' . \implode('`, `', \array_keys($extra)) . '`';
        $extraMarks = \str_repeat(', ?', \count($extra));

        $actor  = self::actor();
        $source = self::sourceOrdinal();
        // date_creation / date_modification are the add_tablestamp columns.
        // id_creation / id_group_creation are stamped from the same session
        // when the emitter names them in $actorStamps: `actor` stays the
        // human-readable attribution, but the Owner/Group row scope filters on
        // those two columns, and NULL stamps hid every history row from an
        // Owner-scoped reader. id_modification stays NULL (rows are append-only).
        //
        // WHY PHP's CLOCK AND NOT MySQL's NOW(): every other table in a
        // generated project is stamped by the emitted add_tablestamp hook with
        // PHP's time(), and a history row is only useful NEXT TO the rows it
        // describes — "did this status change happen before or after that
        // event / that heartbeat?" is the question the table exists to answer.
        // NOW() is the DATABASE server's wall clock, which routinely differs
        // from the writer's: a project's web SAPI may run on the operator's
        // timezone while its CLI runs on UTC, and the db server on a third
        // (observed: PHP UTC, MySQL SYSTEM = -0400, a 4 h gap). Binding the
        // same clock add_tablestamp uses makes the comparison meaningful
        // instead of making every reader compensate.
        $stamp  = \date('Y-m-d H:i:s');

        $stmt = $con->prepare(
            'INSERT INTO `' . $auditTable . '`'
            . ' (`' . $fkColumn . '`, `field`, `value_from`, `value_to`, `actor`, `source`, `date_creation`, `date_modification`'
            . $extraCols . ')'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?' . $extraMarks . ')'
        );
        $extraValues = \array_values($extra);

        $written = 0;
        foreach ($rows as $row) {
            $stmt->execute(\array_merge([
                $fkValue,
                (string) ($row['field'] ?? ''),
                $row['value_from'] ?? null,
                $row['value_to'] ?? null,
                $actor,
                $source,
                $stamp,
                $stamp,
            ], $extraValues));
            $written++;
        }
        $stmt->closeCursor();

        return $written;
    }

    /** The add_tablestamp owner columns write() may stamp, and their session getter. */
    private const ACTOR_STAMPS = [
        'id_creation'       => 'getIdAuthy',
        'id_group_creation' => 'getIdPrimaryGroup',
    ];

    /**
     * column => value for the requested owner stamps, from the same session
     * actor() reads — the ids add_tablestamp itself would have written on a
     * model save(). No session (CLI, cron, daemon) → NULL, like actor(). An
     * unknown column name is ignored rather than trusted.
     *
     * @param string[] $columns
     * @return array<string, int|null>
     */
    public static function actorStampValues(array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            if (!\is_string($column) || !isset(self::ACTOR_STAMPS[$column])) {
                continue;
            }
            $value = null;
            try {
                if (\defined('_AUTH_VAR') && isset($_SESSION) && \is_array($_SESSION)) {
                    $auth   = $_SESSION[\_AUTH_VAR] ?? null;
                    $getter = self::ACTOR_STAMPS[$column];
                    if (\is_object($auth) && \method_exists($auth, $getter)) {
                        $id = $auth->$getter();
                        $value = (\is_numeric($id) && (int) $id > 0) ? (int) $id : null;
                    }
                }
            } catch (\Throwable $e) {
                // Resolving who did it can never be allowed to fail the write.
            }
            $out[$column] = $value;
        }

        return $out;
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
