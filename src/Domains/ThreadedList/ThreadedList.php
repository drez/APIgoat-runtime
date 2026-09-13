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
 *   scope still applies via the query's own basePreSelect). It supplies the
 *   newest message and the thread's full size. Filtering here would report a
 *   conversation as smaller than it is.
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
     */
    public static function page(\ModelCriteria $filtered, array $cfg, int $page, int $perPage): ThreadPage
    {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);
        $keyExpr = self::keyExpression($cfg);

        // ---- phase 1: which threads are on this page -----------------------
        $keys  = self::pageKeys(clone $filtered, $cfg, $keyExpr, $page, $perPage);
        $total = self::countThreads(clone $filtered, $keyExpr);

        if ($keys === []) {
            return new ThreadPage([], [], [], $total, $page, $perPage);
        }

        // ---- phase 2: describe them (unfiltered) ---------------------------
        [$rep, $counts] = self::describe($filtered, $cfg, $keyExpr, $keys);

        return new ThreadPage($keys, $rep, $counts, $total, $page, $perPage);
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
        $rows = $q->withColumn($keyExpr, self::KEY_ALIAS)
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
        $rows = $q->withColumn("COUNT(DISTINCT {$keyExpr})", 'gc_thread_total')
            ->select(['gc_thread_total'])
            ->find();
        foreach ($rows as $r) {
            return (int) $r;
        }
        return 0;
    }

    /**
     * @param string[] $keys
     * @return array{0: array<string,object>, 1: array<string,int>}
     */
    private static function describe(\ModelCriteria $filtered, array $cfg, string $keyExpr, array $keys): array
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
