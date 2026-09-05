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
