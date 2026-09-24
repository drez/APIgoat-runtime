<?php
// Run: php tests/RbacRuleMatcherTest.php   (from the runtime repo root)
//
// RbacRuleMatcher parity suite: the PHP matcher must select the same rule as
// RbacMiddleware's SQL (findBestMatch / the empty-body findOne). Each case
// documents the MySQL behavior it mirrors: subset-based JSON_CONTAINS,
// JSON_VALUE '*' wildcards, exact-only scoring, NULL-body exclusion,
// date_creation ordering, lowest-id ties.

require __DIR__ . '/../src/Middlewares/RbacRuleMatcher.php';

use ApiGoat\Middlewares\RbacRuleMatcher;

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    if ($got === $want) {
        echo "  ok  $label\n";
    } else {
        $fail++;
        fwrite(STDERR, "FAIL  $label\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n");
    }
}

function rule(int $id, ?string $body, string $ruleVal = 'Allow', string $scope = 'Private', ?string $date = null, string $model = 'Client', string $action = 'list', string $method = 'GET'): array
{
    return [
        'id' => $id, 'model' => $model, 'action' => $action, 'method' => $method,
        'body' => $body, 'rule' => $ruleVal, 'scope' => $scope, 'date_creation' => $date,
    ];
}

function matchedId(?array $row): ?int
{
    return $row['id'] ?? null;
}

// ---------------------------------------------------------------- emptyBodyMatch

$rules = [
    rule(1, '{"x":1}'),
    rule(2, null, 'Allow', 'Private', '2026-02-01 00:00:00'),
    rule(3, '', 'Deny', 'Public', '2026-01-01 00:00:00'),
    rule(4, null, 'Allow', 'Private', null), // NULL date sorts first (MySQL ASC)
    rule(5, null, 'Allow', 'Private', '2026-01-01 00:00:00', 'Other'),
];
check('emptyBody: earliest date_creation wins, NULL date first',
    matchedId(RbacRuleMatcher::emptyBodyMatch($rules, 'Client', 'list', 'GET')), 4);
check('emptyBody: model mismatch → null',
    RbacRuleMatcher::emptyBodyMatch($rules, 'Nope', 'list', 'GET'), null);
check('emptyBody: non-empty-body rules ignored',
    matchedId(RbacRuleMatcher::emptyBodyMatch([rule(1, '{"x":1}')], 'Client', 'list', 'GET')), null);
check('emptyBody: date tie → lowest id',
    matchedId(RbacRuleMatcher::emptyBodyMatch([
        rule(7, null, 'Allow', 'Private', '2026-01-01 00:00:00'),
        rule(6, '', 'Allow', 'Private', '2026-01-01 00:00:00'),
    ], 'Client', 'list', 'GET')), 6);

// ---------------------------------------------------------------- bestMatch: clause-free data

// Non-array data → SQL WHERE 1: any tuple-matching rule, table (id) order.
check('bestMatch: non-array data matches first rule by id (even NULL body)',
    matchedId(RbacRuleMatcher::bestMatch([rule(2, null), rule(3, '{"a":1}')], 'Client', 'list', 'GET', 'rawstring')), 2);
// A raw-string 'query' value (undecoded GET ?query=<json>) contributes no
// clause — must match clause-free, not fatal (parity with the guarded SQL).
check('bestMatch: string query value contributes no clause',
    matchedId(RbacRuleMatcher::bestMatch([rule(2, null), rule(3, '{"a":1}')], 'Client', 'list', 'GET', ['query' => '{"filter":{"Client":[["name","x"]]}}'])), 2);
check('bestMatch: method mismatch excluded',
    RbacRuleMatcher::bestMatch([rule(1, null, 'Allow', 'Private', null, 'Client', 'list', 'POST')], 'Client', 'list', 'GET', 'x'), null);

// ---------------------------------------------------------------- bestMatch: filter clauses

$dataFilter = ['query' => ['filter' => ['Client' => [['name', 'acme']]]]];
$exactBody  = json_encode(['query' => ['filter' => ['Client' => [['name', 'acme']]]]]);
$starBody   = json_encode(['query' => ['filter' => ['Client' => [['name', '*']]]]]);
$pathStar   = json_encode(['query' => ['filter' => ['Client' => '*']]]);

check('filter: exact triple beats [col,"*"] wildcard (score 1 vs 0)',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $starBody), rule(2, $exactBody)], 'Client', 'list', 'GET', $dataFilter)), 2);
check('filter: [col,"*"] wildcard passes WHERE when no exact rule',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $starBody)], 'Client', 'list', 'GET', $dataFilter)), 1);
check('filter: path value "*" passes WHERE',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $pathStar)], 'Client', 'list', 'GET', $dataFilter)), 1);
check('filter: NULL-body rule excluded when clauses exist',
    RbacRuleMatcher::bestMatch([rule(1, null)], 'Client', 'list', 'GET', $dataFilter), null);
check('filter: non-matching body excluded',
    RbacRuleMatcher::bestMatch([rule(1, json_encode(['query' => ['filter' => ['Client' => [['other', 'x']]]]]))], 'Client', 'list', 'GET', $dataFilter), null);

// MySQL JSON_CONTAINS is subset-based: candidate ["name","acme"] is contained
// in a stored triple ["name","acme","ne"].
$supersetBody = json_encode(['query' => ['filter' => ['Client' => [['name', 'acme', 'ne']]]]]);
check('filter: subset containment — stored triple with extra op still matches',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $supersetBody)], 'Client', 'list', 'GET', $dataFilter)), 1);

// Order-insensitivity within the stored filter array.
$multiBody = json_encode(['query' => ['filter' => ['Client' => [['zzz', '1'], ['name', 'acme']]]]]);
check('filter: triple found anywhere in the stored array',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $multiBody)], 'Client', 'list', 'GET', $dataFilter)), 1);

// Two filter triples requested → both clauses must pass (AND).
$dataTwo = ['query' => ['filter' => ['Client' => [['name', 'acme'], ['city', 'mtl']]]]];
check('filter: AND of clauses — rule holding only one triple fails',
    RbacRuleMatcher::bestMatch([rule(1, $exactBody)], 'Client', 'list', 'GET', $dataTwo), null);
check('filter: AND of clauses — rule holding both passes',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, json_encode(['query' => ['filter' => ['Client' => [['city', 'mtl'], ['name', 'acme']]]]]))], 'Client', 'list', 'GET', $dataTwo)), 1);

// ---------------------------------------------------------------- bestMatch: select clause

$dataSelect = ['query' => ['select' => ['name', 'city']]];
check('select: stored superset contains requested list',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, json_encode(['query' => ['select' => ['city', 'name', 'extra']]]))], 'Client', 'list', 'GET', $dataSelect)), 1);
check('select: stored subset does NOT contain requested list',
    RbacRuleMatcher::bestMatch([rule(1, json_encode(['query' => ['select' => ['name']]]))], 'Client', 'list', 'GET', $dataSelect), null);
check('select: path "*" wildcard passes',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, json_encode(['query' => ['select' => '*']]))], 'Client', 'list', 'GET', $dataSelect)), 1);

// ---------------------------------------------------------------- bestMatch: scalar keys

$dataScalar = ['name' => 'acme', 'active' => 1];
check('scalar: exact values match (numbers compare as strings, JSON_VALUE-style)',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, json_encode(['name' => 'acme', 'active' => 1]))], 'Client', 'list', 'GET', $dataScalar)), 1);
check('scalar: "*" wildcard passes but scores 0 — exact rule preferred',
    matchedId(RbacRuleMatcher::bestMatch([
        rule(1, json_encode(['name' => '*', 'active' => '*'])),
        rule(2, json_encode(['name' => 'acme', 'active' => '*'])),
    ], 'Client', 'list', 'GET', $dataScalar)), 2);
check('scalar: mismatching value fails the WHERE',
    RbacRuleMatcher::bestMatch([rule(1, json_encode(['name' => 'other', 'active' => 1]))], 'Client', 'list', 'GET', $dataScalar), null);
check('scalar: score tie → lowest id wins',
    matchedId(RbacRuleMatcher::bestMatch([
        rule(4, json_encode(['name' => 'acme', 'active' => 1])),
        rule(3, json_encode(['name' => 'acme', 'active' => 1])),
    ], 'Client', 'list', 'GET', $dataScalar)), 3);

// Mixed: exact filter + wildcard scalar vs all-wildcard — higher score wins.
$dataMixed = ['who' => 'me', 'query' => ['filter' => ['Client' => [['name', 'acme']]]]];
check('mixed: more exact clauses outrank fewer',
    matchedId(RbacRuleMatcher::bestMatch([
        rule(1, json_encode(['who' => '*', 'query' => ['filter' => ['Client' => '*']]])),
        rule(2, json_encode(['who' => 'me', 'query' => ['filter' => ['Client' => [['name', 'acme']]]]])),
    ], 'Client', 'list', 'GET', $dataMixed)), 2);

// ------------------------------------------- review-3 Wave 3: every query key covered
$sel = ['query' => ['select' => [['name', 'n']], 'filter' => ['Client' => [['name', 'acme']]]]];
$selRule = json_encode($sel);
$withJoin = $sel;
$withJoin['query']['join'] = ['AuthyRelatedByIdCreation'];
check('a rule naming select+filter does NOT cover an extra join',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $selRule)], 'Client', 'list', 'GET', $withJoin)), null);
$withOrder = $sel;
$withOrder['query']['order'] = [['name', 'asc']];
check('... nor an extra order',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $selRule)], 'Client', 'list', 'GET', $withOrder)), null);
$withGroup = $sel;
$withGroup['query']['groupby'] = ['name'];
check('... nor an extra groupby',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $selRule)], 'Client', 'list', 'GET', $withGroup)), null);
$paged = $sel;
$paged['query'] += ['limit' => 50, 'page' => 2, 'max_page' => 10];
check('paging keys (limit/page/max_page) need no coverage',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $selRule)], 'Client', 'list', 'GET', $paged)), 1);
$ruleJoin = $sel;
$ruleJoin['query']['join'] = ['Tag', 'AuthyRelatedByIdCreation'];
check('a rule listing the join covers it (subset)',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, json_encode($ruleJoin))], 'Client', 'list', 'GET', $withJoin)), 1);
$ruleStar = $sel;
$ruleStar['query']['order'] = '*';
check("query.order '*' covers any order",
    matchedId(RbacRuleMatcher::bestMatch([rule(1, json_encode($ruleStar))], 'Client', 'list', 'GET', $withOrder)), 1);
check("query '*' covers any extra key",
    matchedId(RbacRuleMatcher::bestMatch([rule(1, json_encode(['query' => '*']))], 'Client', 'list', 'GET', ['query' => ['order' => [['name', 'asc']]]])), 1);
check('a non-identifier query key is never covered',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, json_encode(['query' => '*']))], 'Client', 'list', 'GET', ['query' => ['a.b' => 1]])), null);
putenv('GC_RBAC_LEGACY_BODY_MATCH=1');
check('GC_RBAC_LEGACY_BODY_MATCH=1 restores select/filter-only matching',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $selRule)], 'Client', 'list', 'GET', $withJoin)), 1);
putenv('GC_RBAC_LEGACY_BODY_MATCH');

// ---------------------------------------------------------------- select allowlist cannot be skipped

// A Public+Allow rule restricting select must not match a request that sends
// no select — QueryBuilder then returns every column (select bypass).
$pubSel = json_encode(['query' => ['select' => ['Name', 'Price']]]);
check('select rule: paging-only body does NOT match (was WHERE 1)',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $pubSel, 'Allow', 'Public')], 'Client', 'list', 'GET', ['query' => ['limit' => 500]])), null);
check('select rule: non-array body does NOT match',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $pubSel, 'Allow', 'Public')], 'Client', 'list', 'GET', 'raw')), null);
check('select rule: filter-only body does NOT match',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, json_encode(['query' => ['select' => ['Name'], 'filter' => ['Client' => [['name', '*']]]]]))], 'Client', 'list', 'GET',
        ['query' => ['filter' => ['Client' => [['name', 'x']]]]])), null);
check('select rule: contained select still matches',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $pubSel, 'Allow', 'Public')], 'Client', 'list', 'GET', ['query' => ['select' => ['Name'], 'limit' => 5]])), 1);
check('select rule: select outside the allowlist does not match',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $pubSel, 'Allow', 'Public')], 'Client', 'list', 'GET', ['query' => ['select' => ['Name', 'Cost']]])), null);
check('no-select request falls to an unrestricted rule instead',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, $pubSel, 'Allow', 'Public'), rule(2, json_encode(['query' => ['limit' => '*']]), 'Allow', 'Private')], 'Client', 'list', 'GET', ['query' => ['limit' => 5]])), 2);
foreach ([['select "*"', ['query' => ['select' => '*']]], ['query "*"', ['query' => '*']], ['empty select', ['query' => ['select' => []]]], ['null select', ['query' => ['select' => null]]]] as [$lbl, $b]) {
    check("unrestricted rule ($lbl) still matches a no-select request",
        matchedId(RbacRuleMatcher::bestMatch([rule(1, json_encode($b))], 'Client', 'list', 'GET', ['query' => ['limit' => 5]])), 1);
}
check('NULL-body rule still matches a clause-free request',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, null)], 'Client', 'list', 'GET', ['query' => ['limit' => 5]])), 1);

// An undecodable filter[Model] string (normalizeFilter keeps it) matches no rule.
check('undecodable string filter matches no rule',
    matchedId(RbacRuleMatcher::bestMatch([rule(1, null), rule(2, json_encode(['query' => ['filter' => '*']]))], 'Client', 'list', 'GET',
        ['query' => ['filter' => ['Client' => 'not json']]])), null);

// ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\n$fail failure(s)\n");
    exit(1);
}
echo "\nAll RbacRuleMatcher checks passed\n";
