<?php

namespace ApiGoat\Ops;

/**
 * Turns a raw SQL string into a literal-free, size-bounded fingerprint for
 * the ops_query_slow.sql_text/sql_hash columns.
 *
 * This is the ONLY place a slow query's SQL is allowed to be normalized —
 * TimedStatement and SlowQueryBuffer both go through it rather than storing
 * $statement->queryString directly, because that string can (depending on
 * how a caller built the query) still contain literal values: the PII rule
 * for ops_query_slow.sql_text is "normalized, never raw literals".
 */
final class SqlFingerprint
{
    /** Matches the ops_query_slow.sql_text column width. */
    private const MAX_LEN = 1024;

    /**
     * Collapses whitespace, then replaces string and number literals with a
     * single `?`, then truncates to the column width. Existing placeholders
     * (`?`, `:name`) are left untouched — they contain no digits/quotes for
     * the literal patterns below to match.
     */
    public static function normalize(string $sql): string
    {
        $sql = (string) \preg_replace('/\s+/', ' ', $sql);
        $sql = \trim($sql);

        // Quoted string literals: '...' or "...", tolerating a doubled quote
        // ('' / "") or a backslash-escaped quote inside.
        $sql = (string) \preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql);
        $sql = (string) \preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/s', '?', $sql);

        // Unquoted hex literals (0x1A2B); X'..' is already a quoted string.
        $sql = (string) \preg_replace('/(?<![A-Za-z0-9_])0x[0-9A-Fa-f]+(?![A-Za-z0-9_])/i', '?', $sql);

        // Bare numeric literals, with an optional exponent (1.5e10, -2E-3) —
        // not digits that are part of an identifier (e.g. `b100`, `authy2`),
        // which have no word boundary around them.
        $sql = (string) \preg_replace('/(?<![A-Za-z0-9_])-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?(?![A-Za-z0-9_])/', '?', $sql);

        return \substr($sql, 0, self::MAX_LEN);
    }

    /** Matches the ops_query_slow.sql_hash column width (CHAR(40)). */
    public static function hash(string $sql): string
    {
        return \sha1(self::normalize($sql));
    }
}
