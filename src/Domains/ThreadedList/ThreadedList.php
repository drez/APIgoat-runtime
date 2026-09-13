<?php

namespace ApiGoat\Domains\ThreadedList;

/**
 * Collapse a list's rows into one row per conversation.
 *
 * TWO PHASES, and the split is the whole design:
 *
 *   Phase 1 decides MEMBERSHIP. It reuses the list's already-filtered criteria
 *   — every filter, join, tenant scope and search clause the emitter built —
 *   and groups it. Because the filters sit in that WHERE, a thread survives if
 *   ANY of its messages matches, which is the required semantic, for free.
 *
 *   Phase 2 DESCRIBES each thread on the page, deliberately UNFILTERED (tenant
 *   scope still applies via the query's own basePreSelect — every generated
 *   Query's basePreSelect() re-applies filterByIdTenant() from the session on
 *   every find(), independent of any Criteria the caller built). It supplies
 *   the newest message and the thread's full size. Filtering here would
 *   report a conversation as smaller than it is.
 *
 *   Row-level Owner/Group ACL scope is a SEPARATE thing from tenant scope: it
 *   lives in AuthyACL::setAclFilter() / AuthySession::applyOwnerGroupScope(),
 *   is NOT part of basePreSelect, and is applied to phase 1's $filtered
 *   criteria only by the caller (getList.php, before this class ever sees it).
 *   Phase 2 builds a FRESH query, so that scope does not carry over on its
 *   own — page() accepts the caller's already-computed $aclGroup (the same
 *   value AuthyACL::authorize() left on $this->aclGroup) and re-applies it to
 *   phase 2's query the same way ChildLink does for its own fresh queries.
 *   When $aclGroup is not supplied (or no session ACL object is present, e.g.
 *   plain unit tests), phase 2 runs with tenant scope only — describe() below
 *   documents exactly what that means.
 *
 * The group key is an expression, not a bare column: a row with a null or empty
 * thread column becomes its own thread of one, keyed by its primary key, so
 * nothing drops out of the list.
 *
 * Every element of $cfg is a BUILD-TIME constant taken from the schema and
 * already validated as an identifier. No request value may reach it — a
 * request-chosen column would let a caller pivot the list on anything.
 */
final class ThreadedList
{
    public const KEY_ALIAS   = 'gc_thread_key';
    public const ORDER_ALIAS = 'gc_thread_order';

    /**
     * @param \ModelCriteria $filtered the list's criteria, filters already applied
     * @param array{table:string,thread_col:string,pk_col:string,date_col:string,sort_col:?string,sort_dir:string} $cfg
     * @param mixed $aclGroup the caller's already-computed Owner/Group ACL scope
     *   (AuthyACL::$aclGroup after authorize()) — true (unrestricted), false (no
     *   grant, though the caller would not reach getList() at all in that case),
     *   or an array of scope names ('Owner'/'Group'). Re-applied to phase 2's
     *   fresh query; see the class docblock.
     */
    public static function page(\ModelCriteria $filtered, array $cfg, int $page, int $perPage, $aclGroup = null): ThreadPage
    {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);
        $cfg     = self::sanitizeSortColumn($cfg, $filtered);
        $keyExpr = self::keyExpression($cfg);

        // ---- phase 1: which threads are on this page -----------------------
        $keys  = self::pageKeys(clone $filtered, $cfg, $keyExpr, $page, $perPage);
        $total = self::countThreads(clone $filtered, $keyExpr);

        if ($keys === []) {
            return new ThreadPage([], [], [], $total, $page, $perPage);
        }

        // ---- phase 2: describe them (unfiltered) ---------------------------
        [$rep, $counts] = self::describe($filtered, $cfg, $keyExpr, $keys, $aclGroup);

        return new ThreadPage($keys, $rep, $counts, $total, $page, $perPage);
    }

    /**
     * I3: sort_col reaches here request-derived — setOrderVar()/getList.php's
     * "first truthy sens" walk constrains it to identifier SHAPE (the regex
     * that guards $gcSortCol upstream) but not to a REAL column: a request can
     * set order[bogus_column]=1 and bogus_column is a plain identifier that
     * does not exist on this table. orderExpression() would then build
     * "<table>.<bogus_column>" — a SQL error, and unlike the flat list's
     * try/catch around orderBy(), this path has nothing to catch it with.
     *
     * Falls back to null (which orderExpression() already treats as "no sort,
     * use the date column") unless the column is a plain identifier AND a real
     * column on this model, checked against the query's own TableMap so the
     * whitelist can never drift from the generated schema. Any exception while
     * checking is treated the same as "not found" — this function must never
     * itself be the thing that throws.
     */
    private static function sanitizeSortColumn(array $cfg, \ModelCriteria $filtered): array
    {
        $col = $cfg['sort_col'] ?? null;
        if ($col === null || $col === '' || str_contains($col, '.')) {
            return $cfg; // already falls back below in orderExpression()
        }
        try {
            $known = $filtered->getTableMap()->hasColumn($col);
        } catch (\Throwable $e) {
            $known = false;
        }
        if (!$known) {
            $cfg['sort_col'] = null;
        }
        return $cfg;
    }

    /**
     * COALESCE(NULLIF(thread,''), CONCAT('pk:', pk)) — keyless rows become
     * threads of one. Both branches are prefixed ('t:' for a real thread key,
     * 'pk:' for a synthetic one) so a real provider thread id that happened to
     * read as "pk:<some row's pk>" can never collide with — and silently
     * merge into — an unrelated keyless row's synthetic key.
     */
    private static function keyExpression(array $cfg): string
    {
        $t = $cfg['table'];
        return "COALESCE(CONCAT('t:', NULLIF({$t}.{$cfg['thread_col']}, '')), CONCAT('pk:', {$t}.{$cfg['pk_col']}))";
    }

    /**
     * Ordering value per thread. A date column can use MAX() — that really is
     * the newest. Any other column must come from the REPRESENTATIVE row:
     * MAX() on a varchar is alphabetical, so it would sort a conversation under
     * a sender that is not the one displayed.
     *
     * CONTRACT: ordering uses only the first 255 characters of the newest
     * message's value. Each concatenated value is bounded with LEFT(..., 255)
     * before GROUP_CONCAT so that bound — not the server's group_concat_max_len
     * (default 1024 bytes, but configurable, and this project's server is set
     * to 1MB) — decides where a long value gets cut. Without it, a sort_col
     * value longer than the server's actual limit would still be truncated
     * (MySQL truncates from the tail, so the newest value survives regardless
     * of how long any other message's value is) — but the CUT POINT would vary
     * by server config, and truncating two threads' keys to a common length
     * can only ever collapse a true strict order into a TIE, never invert it.
     * So the bound does not fix a wrong-order bug; it trades that
     * config-dependent tie risk for a fixed, documented, portable one. Do not
     * read removing it as reintroducing a sort inversion.
     *
     * LIMITATION: ordering a threaded list by a joined column (a dotted
     * `Relation.Column` / `Relation.Column.locale` sort key — the shape
     * setOrderVar() accepts for the flat list) is not supported. `$col` would
     * land in identifier position against THIS table's own alias
     * (`{$t}.{$col}`), and a dotted name there is not a valid identifier —
     * MySQL rejects it. Falls back to the date column (newest activity
     * first) instead of emitting broken SQL.
     */
    private static function orderExpression(array $cfg): string
    {
        $t = $cfg['table'];
        $col = $cfg['sort_col'];
        if ($col === null || $col === $cfg['date_col'] || str_contains($col, '.')) {
            return "MAX({$t}.{$cfg['date_col']})";
        }
        return "SUBSTRING_INDEX(GROUP_CONCAT(LEFT({$t}.{$col}, 255) ORDER BY {$t}.{$cfg['date_col']} DESC SEPARATOR 0x1D), 0x1D, 1)";
    }

    /** @return string[] */
    private static function pageKeys(\ModelCriteria $q, array $cfg, string $keyExpr, int $page, int $perPage): array
    {
        // sort_dir is null when the list has no active sort (getList.php's
        // resolved-ordering snippet leaves $gcSortDir null in that case) —
        // default to 'desc' at this boundary rather than deprecation-warn on
        // strtolower(null).
        $dir = strtolower((string) ($cfg['sort_dir'] ?? 'desc')) === 'asc' ? \Criteria::ASC : \Criteria::DESC;
        // I2: getListSearch() applies $q->orderBy(<list column>, $sens) for the
        // flat list's own column-sort UI, and Propel 1's select() does NOT
        // clear it the way count() does (add_total clears it explicitly for
        // exactly this reason — see add_total.php). Left alone, that inherited
        // ORDER BY lands FIRST, ahead of gc_thread_order, sorting off an
        // arbitrary group member under GROUP BY — and under ONLY_FULL_GROUP_BY
        // (MySQL 8 default) a non-aggregated, non-grouped ORDER BY column is a
        // hard SQL error. setWith([]) drops any eager-loaded joinWith() the
        // same way add_total does, for the same reason: a joined collection
        // has no place in a grouped, aliased-column select.
        $rows = $q->clearOrderByColumns()
            ->setWith([])
            ->withColumn($keyExpr, self::KEY_ALIAS)
            ->withColumn(self::orderExpression($cfg), self::ORDER_ALIAS)
            ->select([self::KEY_ALIAS, self::ORDER_ALIAS])
            ->groupBy(self::KEY_ALIAS)
            ->orderBy(self::ORDER_ALIAS, $dir)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->find();

        $keys = [];
        foreach ($rows as $r) {
            $keys[] = (string) $r[self::KEY_ALIAS];
        }
        return $keys;
    }

    private static function countThreads(\ModelCriteria $q, string $keyExpr): int
    {
        // select() with a SINGLE column name makes Propel 1 return plain
        // scalars (one per row), not associative rows — unlike pageKeys()'s
        // two-column select just below, which does return assoc arrays.
        // Same I2 reasoning as pageKeys(): clear the inherited ORDER BY and any
        // eager-loaded joinWith() before this aliased, ungrouped-select count.
        $rows = $q->clearOrderByColumns()
            ->setWith([])
            ->withColumn("COUNT(DISTINCT {$keyExpr})", 'gc_thread_total')
            ->select(['gc_thread_total'])
            ->find();
        foreach ($rows as $r) {
            return (int) $r;
        }
        return 0;
    }

    /**
     * @param string[] $keys
     * @param mixed $aclGroup see page()'s docblock
     * @return array{0: array<string,object>, 1: array<string,int>}
     */
    private static function describe(\ModelCriteria $filtered, array $cfg, string $keyExpr, array $keys, $aclGroup = null): array
    {
        // Two things Propel 1 rejects here, in order:
        //  1. ModelCriteria::where('alias IN ?', $keys) — the alias is not a
        //     real model column, so it falls through to Criteria::RAW, which
        //     only supports a single scalar placeholder; given an array it
        //     binds the literal string "Array" (a MariaDB syntax error).
        //  2. Criteria::IN via the base Criteria::add() puts the condition in
        //     WHERE — but a SELECT-list alias is not visible to WHERE in SQL
        //     ("Unknown column"), only to HAVING/GROUP BY/ORDER BY.
        // addHaving() with an explicit Criteria::IN comparison sidesteps both:
        // it goes through the base Criteria (no clause re-parsing) and lands
        // in HAVING, where the alias resolves — one bound placeholder per key,
        // $keys is never interpolated into SQL text.
        $cls = get_class($filtered);
        $q   = $cls::create()
            ->withColumn($keyExpr, self::KEY_ALIAS)
            ->addHaving(self::KEY_ALIAS, $keys, \Criteria::IN)
            ->orderBy($cfg['table'] . '.' . $cfg['date_col'], \Criteria::DESC);

        // I1: this fresh query has phase 1's tenant scope back for free (every
        // generated Query's basePreSelect() re-applies filterByIdTenant() from
        // the session), but NOT phase 1's row-level Owner/Group scope — that
        // lives in AuthyACL::setAclFilter()/AuthySession::applyOwnerGroupScope()
        // and was applied to $filtered by the caller, not to this new $q.
        // Without this, a representative row (RENDERED) or a thread's count
        // could include a message the viewer has no Owner/Group right to see.
        // Re-apply the same scope the caller already computed, the same way
        // ChildLink re-applies it to its own fresh queries.
        if (is_array($aclGroup)
            && defined('_AUTH_VAR')
            && isset($_SESSION[\_AUTH_VAR])
            && is_object($_SESSION[\_AUTH_VAR])
            && method_exists($_SESSION[\_AUTH_VAR], 'applyOwnerGroupScope')) {
            $_SESSION[\_AUTH_VAR]->applyOwnerGroupScope($q, $aclGroup);
        }

        $rep    = [];
        $counts = [];
        foreach ($q->find() as $row) {
            $k = (string) $row->getVirtualColumn(self::KEY_ALIAS);
            $counts[$k] = ($counts[$k] ?? 0) + 1;
            if (!isset($rep[$k])) {
                $rep[$k] = $row; // ordered newest-first, so the first wins
            }
        }
        return [$rep, $counts];
    }
}
