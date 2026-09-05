<?php

declare(strict_types=1);

namespace ApiGoat\Orm;

/**
 * Pre-delete referential-integrity check for the generated services.
 *
 * The emitter used to unroll one `if ($obj->count<Rel>()) { … die(…) }` block
 * per RESTRICT referrer. On a hub table that is a lot of blocks (121 on
 * `authy` in one project), and every one of them is a full `SELECT COUNT(*)`
 * — the whole set runs to completion on the common case where the delete is
 * allowed.
 *
 * This runs the same checks as a one-column `LIMIT 1` probe and returns as
 * soon as one referrer has a row, so a blocked delete stops at the first
 * blocker and an allowed delete never counts anything.
 *
 * The probe is deliberately UNSCOPED (no ACL / tenant filter), exactly like the
 * `count<Rel>()` calls it replaces: this is a database-integrity question, not
 * an authorization one — a row the caller cannot see still blocks the delete.
 */
class ReferentialGuard
{
    /**
     * Return the label of the first relation that still references $obj.
     *
     * @param object $obj       The Propel row about to be deleted.
     * @param array  $relations Ordered list of referrers, each:
     *                          [
     *                            'q'     => 'InvoiceQuery',   // child query class (App namespace)
     *                            'f'     => 'filterByCustomer', // child-side FK filter
     *                            'pk'    => 'IdInvoice',      // child PK column php name
     *                            'rel'   => 'Invoices',       // parent-side count<Rel>() fallback
     *                            'label' => 'Invoice',        // human table description
     *                          ]
     *
     * @return string|null the 'label' of the first blocking relation, or null
     *                     when nothing references $obj.
     */
    public static function firstBlocker($obj, array $relations): ?string
    {
        foreach ($relations as $rel) {
            if (self::hasReferrer($obj, $rel)) {
                return (string) ($rel['label'] ?? '');
            }
        }

        return null;
    }

    /**
     * Name the table that blocked a delete InnoDB refused (F5, review #13).
     *
     * A39 deliberately leaves the AUDIT foreign keys (id_creation /
     * id_modification / id_group_creation) out of the pre-delete probe list —
     * probing them on every delete costs a query per hub relation for a case
     * that almost never fires. The price is that a row referenced ONLY from an
     * audit trail is refused by the database instead of by firstBlocker(), and
     * the emitted service could then only answer "still referenced by other
     * records". MySQL does say which table it was:
     *
     *   SQLSTATE[23000]: Integrity constraint violation: 1451 Cannot delete or
     *   update a parent row: a foreign key constraint fails (`db`.`audit_log`,
     *   CONSTRAINT `audit_log_FK_1` FOREIGN KEY (`id_creation`) REFERENCES …)
     *
     * so parse the child table out of it and map it to the same human label the
     * guard would have returned. Returns null when the message is not a
     * recognisable FK violation or the table is not in $labels — the caller then
     * keeps the generic wording rather than showing a raw table name.
     *
     * MySQL 8 renames a table under DDL to `#sql-…`/`#sql2-…`; such a name is
     * never a useful label and is rejected.
     *
     * @param string               $message exception message (with any previous
     *                                      exception messages appended)
     * @param array<string,string> $labels  child table name => human description
     */
    public static function blockerFromMessage(string $message, array $labels = []): ?string
    {
        // `db`.`child` (both quoted, db optional) directly before CONSTRAINT.
        if (! preg_match(
            '/foreign key constraint fails\s*\(\s*(?:`[^`]*`\.)?`([^`]+)`\s*,\s*CONSTRAINT/i',
            $message,
            $m
        )) {
            return null;
        }
        $table = $m[1];
        if ($table === '' || strpos($table, '#sql') === 0) {
            return null;
        }
        $label = (string) ($labels[$table] ?? '');

        return $label === '' ? null : $label;
    }

    /**
     * One referrer probe: `SELECT <pk> FROM child WHERE fk = … LIMIT 1`.
     *
     * Falls back to the generated `count<Rel>()` when the query class / filter
     * is not resolvable (a hand-edited model, a renamed relation) so the guard
     * can never silently stop blocking.
     */
    private static function hasReferrer($obj, array $rel): bool
    {
        $queryClass = (string) ($rel['q'] ?? '');
        $filter     = (string) ($rel['f'] ?? '');
        $pk         = (string) ($rel['pk'] ?? '');

        if ($queryClass !== '' && $filter !== '' && $pk !== '') {
            $fqcn = (strpos($queryClass, '\\') === 0) ? $queryClass : '\\App\\' . $queryClass;
            if (class_exists($fqcn)) {
                $q = $fqcn::create();
                if (method_exists($q, $filter)) {
                    // select() MUST get a 1-element array: Propel 1's select()
                    // count()s its argument and count('string') is a fatal
                    // TypeError on PHP 8. A single column yields the raw scalar.
                    $hit = $q->$filter($obj)->select([$pk])->limit(1)->findOne();

                    return $hit !== null && $hit !== false;
                }
            }
        }

        $countMethod = 'count' . (string) ($rel['rel'] ?? '');
        if ($countMethod !== 'count' && method_exists($obj, $countMethod)) {
            return (bool) $obj->$countMethod();
        }

        return false;
    }
}
