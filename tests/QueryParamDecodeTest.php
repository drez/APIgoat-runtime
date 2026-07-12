<?php
// Run: php tests/QueryParamDecodeTest.php   (from the runtime repo root)
//
// QueryBuilder::decodeJsonParam — filter/select params survive a web server that
// RE-ESCAPES the query string.
//
// Real incident: prod's root .htaccess rewrites `^(.*)$ -> /.admin/$1`, and the
// internal redirect escaped the query string a second time, so PHP received
//     filter[Project] = '%5B%5B%22state%22...'      (a literal, still-encoded string)
// instead of
//     filter[Project] = '[["state","New,Approved","in"]]'
// json_decode() returned null, setFilters() coerced that to [[]] — one filter row
// with NO column — and Propel threw "Unknown column  in model App\Project" (500,
// an HTML error page). Every filtered request died: dashboard KPIs, list search
// and every child list, all reported by the user as "empty". Dev never rewrote,
// so dev never reproduced it.

require __DIR__ . '/../src/Api/QueryBuilder.php';

use ApiGoat\Api\QueryBuilder;

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    if ($got === $want) {
        echo "  ok  {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$label}\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n";
}

$json = '[["state","New,Approved","in"]]';
$want = [['state', 'New,Approved', 'in']];

// 1. The normal case: PHP already decoded the param once.
check('plain JSON string', QueryBuilder::decodeJsonParam($json), $want);

// 2. The prod case: the server escaped it a second time, so PHP handed us the
//    percent-encoded text. Decode it rather than crashing on it.
check('re-escaped by the web server', QueryBuilder::decodeJsonParam(rawurlencode($json)), $want);

// 3. Already an array (POST/body path) — passed through untouched.
check('already an array', QueryBuilder::decodeJsonParam($want), $want);

// 4. Genuinely undecodable input yields NULL — the caller must skip the filter,
//    never invent an empty-column one (that is what produced filterBy('')).
check('garbage -> null', QueryBuilder::decodeJsonParam('not json at all'), null);
check('empty string -> null', QueryBuilder::decodeJsonParam(''), null);
check('empty JSON array -> null', QueryBuilder::decodeJsonParam('[]'), null);

// 5. A LIKE filter carrying a literal % must not be mangled by the retry decode
//    (urldecode('%25ll%25') is fine, but '%ll%' is not valid escaping — the
//    first decode succeeds, so the retry must never run).
check(
    'LIKE value with literal %',
    QueryBuilder::decodeJsonParam('[["name","%ll-teq%","like"]]'),
    [['name', '%ll-teq%', 'like']]
);

echo $fail === 0 ? "PASS: query param decode OK\n" : "FAILED: {$fail}\n";
exit($fail === 0 ? 0 : 1);
