<?php

namespace ApiGoat\Middlewares;

/**
 * Pure-PHP re-implementation of RbacMiddleware's api_rbac rule matching, so
 * the whole (small) ruleset can be cached in MicroCache and evaluated without
 * the per-request SELECT / JSON_CONTAINS scan.
 *
 * Fidelity contract: given the same ruleset rows and request args, these
 * methods MUST select the same rule as the SQL in
 * RbacMiddleware::authorizePublicRequest() / findBestMatch(). The MySQL
 * behaviors mirrored here (verified against the SQL, covered by unit tests):
 *
 *  - WHERE is the AND of one clause per body key; a rule whose body is
 *    NULL / empty / non-JSON fails every JSON clause (SQL NULL), so it is
 *    excluded whenever at least one clause exists — and matches trivially
 *    when none do.
 *  - JSON_CONTAINS is SUBSET-based and order-insensitive: candidate array
 *    elements each need only be contained in SOME target element, so the
 *    filter triple ["col","val"] matches a stored ["col","val","ne"].
 *  - JSON_VALUE returns the scalar at the path (NULL for arrays/objects or
 *    a missing path); comparison is string-based, booleans read 'true'/'false'.
 *  - Score counts EXACT clause matches only (wildcard '*' passes the WHERE
 *    but scores 0); best score wins, ties resolve to the lowest id (matches
 *    the un-ORDER-BY'd LIMIT 1 table-order behavior in practice).
 *
 * Rows are arrays with keys: id, model, action, method, body (raw JSON
 * string|null), rule, scope, date_creation (string|null). Method values are
 * the hydrated PHP enum values, so they compare directly to args['method'].
 */
final class RbacRuleMatcher
{
    /**
     * The empty-body lookup: model/action/method + body ''|NULL, earliest
     * date_creation first (MySQL sorts NULLs first ASC), ties by lowest id.
     *
     * @param array<int, array<string, mixed>> $rules
     * @return array<string, mixed>|null
     */
    public static function emptyBodyMatch(array $rules, string $model, string $action, string $method): ?array
    {
        $candidates = [];
        foreach ($rules as $row) {
            if ($row['model'] == $model && $row['action'] == $action && $row['method'] == $method
                && ($row['body'] === '' || $row['body'] === null)) {
                $candidates[] = $row;
            }
        }
        if (!$candidates) {
            return null;
        }
        \usort($candidates, static function ($a, $b) {
            $da = $a['date_creation'] ?? '';
            $db = $b['date_creation'] ?? '';
            return ($da <=> $db) ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        });
        return $candidates[0];
    }

    /**
     * The findBestMatch() equivalent: AND of per-body-key clauses, score =
     * number of exact (non-wildcard) matches, best score wins.
     *
     * @param array<int, array<string, mixed>> $rules
     * @param mixed $data the (normalized, exclude-filtered) request body
     * @return array<string, mixed>|null
     */
    public static function bestMatch(array $rules, string $model, string $action, string $method, $data): ?array
    {
        $clauses = self::buildClauses($data);

        $best = null;
        $bestScore = -1;
        foreach ($rules as $row) {
            if ($row['model'] != $model || $row['action'] != $action || $row['method'] != $method) {
                continue;
            }
            $body = \json_decode((string) ($row['body'] ?? ''), true);
            $score = self::scoreRow(\is_array($body) ? $body : null, $clauses);
            if ($score === null) {
                continue;
            }
            if ($score > $bestScore
                || ($score === $bestScore && $best !== null && ($row['id'] ?? 0) < ($best['id'] ?? 0))) {
                $bestScore = $score;
                $best = $row;
            }
        }
        return $best;
    }

    /**
     * One clause per body key, mirroring findBestMatch()'s SQL generation:
     * query.select containment, one clause per filter triple, scalar
     * comparison for every other top-level key.
     *
     * @return array<int, array{path: string, kind: string, value: mixed}>
     */
    private static function buildClauses($data): array
    {
        $clauses = [];
        if (!\is_array($data)) {
            return $clauses; // SQL: WHERE 1 — any rule of the tuple matches
        }
        foreach ($data as $key => $val) {
            if ($key == 'query') {
                if (!empty($data['query']['select'])) {
                    $clauses[] = ['path' => 'query.select', 'kind' => 'select', 'value' => $data['query']['select']];
                }
                if (\is_array($data['query']['filter'] ?? null)) {
                    foreach ($data['query']['filter'] as $fModel => $filters) {
                        if (\is_array($filters)) {
                            foreach ($filters as $f) {
                                $clauses[] = ['path' => 'query.filter.' . $fModel, 'kind' => 'filter', 'value' => $f];
                            }
                        }
                    }
                }
            } else {
                $clauses[] = [
                    'path'  => (string) $key,
                    'kind'  => 'scalar',
                    'value' => \is_scalar($val) ? (string) $val : \json_encode($val),
                ];
            }
        }
        return $clauses;
    }

    /** @return int|null exact-match count, or null when the WHERE fails */
    private static function scoreRow(?array $body, array $clauses): ?int
    {
        $score = 0;
        foreach ($clauses as $c) {
            if ($body === null) {
                return null; // SQL: JSON fn on NULL body → NULL → clause false
            }
            $exact = false;
            $pass  = false;
            $target = self::valueAtPath($body, $c['path']);
            if ($c['kind'] === 'select') {
                $exact = self::jsonContains($target, $c['value']);
                $pass  = $exact || self::scalarAtPath($body, $c['path']) === '*';
            } elseif ($c['kind'] === 'filter') {
                $exact = self::jsonContains($target, [$c['value']]);
                $star  = self::jsonContains($target, [[$c['value'][0] ?? null, '*']]);
                $pass  = $exact || $star || self::scalarAtPath($body, $c['path']) === '*';
            } else {
                $v = self::scalarAtPath($body, $c['path']);
                $exact = ($v !== null && $v === $c['value']);
                $pass  = $exact || $v === '*';
            }
            if (!$pass) {
                return null;
            }
            if ($exact) {
                $score++;
            }
        }
        return $score;
    }

    /**
     * Decoded value at a dot path ('query.filter.Client'), null when missing.
     * Mirrors '$.'-path traversal — dots split segments in both worlds.
     */
    private static function valueAtPath(array $body, string $path)
    {
        $node = $body;
        foreach (\explode('.', $path) as $seg) {
            if (!\is_array($node) || !\array_key_exists($seg, $node)) {
                return null;
            }
            $node = $node[$seg];
        }
        return $node;
    }

    /**
     * JSON_VALUE equivalent: the scalar at the path as a string, null for
     * missing paths and array/object values. Booleans read 'true'/'false'
     * like MySQL prints them.
     */
    private static function scalarAtPath(array $body, string $path): ?string
    {
        $v = self::valueAtPath($body, $path);
        if ($v === null || !\is_scalar($v)) {
            return null;
        }
        if (\is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return (string) $v;
    }

    /**
     * MySQL JSON_CONTAINS(target, candidate) semantics:
     *  - array target:  candidate array → every element contained in SOME
     *    target element (subset, order-insensitive); candidate non-array →
     *    contained in some element.
     *  - object target: candidate object → every key present with contained
     *    value; anything else → false.
     *  - scalar target: JSON-equal scalar (numbers numeric, strings strict).
     * Note: json_decode collapses {} to [] so an empty object is treated as
     * an empty array — rule bodies never rely on that distinction.
     */
    private static function jsonContains($target, $candidate): bool
    {
        if (\is_array($target) && \array_is_list($target)) {
            if (\is_array($candidate) && \array_is_list($candidate)) {
                foreach ($candidate as $c) {
                    if (!self::containedInSomeElement($target, $c)) {
                        return false;
                    }
                }
                return true;
            }
            return self::containedInSomeElement($target, $candidate);
        }
        if (\is_array($target)) { // assoc = JSON object
            if (\is_array($candidate) && !\array_is_list($candidate)) {
                foreach ($candidate as $k => $v) {
                    if (!\array_key_exists($k, $target) || !self::jsonContains($target[$k], $v)) {
                        return false;
                    }
                }
                return true;
            }
            return false;
        }
        if (\is_array($candidate)) {
            return false;
        }
        return self::jsonScalarEquals($target, $candidate);
    }

    private static function containedInSomeElement(array $target, $candidate): bool
    {
        foreach ($target as $el) {
            if (self::jsonContains($el, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** JSON equality: numbers compare numerically across int/float; no string↔number juggling. */
    private static function jsonScalarEquals($a, $b): bool
    {
        if (\is_int($a) || \is_float($a)) {
            return (\is_int($b) || \is_float($b)) && (float) $a === (float) $b;
        }
        return $a === $b;
    }
}
