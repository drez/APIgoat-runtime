<?php
// Run: php tests/Security/SelectClauseGuardTest.php
// isSafeSelectClause is a pure static guard (preg_match only), so we load the
// class file directly rather than booting Propel via composer autoload.
require __DIR__ . '/../../src/Api/QueryBuilder.php';
use ApiGoat\Api\QueryBuilder;

$fail = 0;
function check($l, $g, $w)
{
    global $fail;
    if ($g === $w) { echo "PASS  $l\n"; } else { echo "FAIL  $l (got " . var_export($g, true) . ")\n"; $GLOBALS['fail']++; }
}

check('plain column',       QueryBuilder::isSafeSelectClause('id'), true);
check('qualified',          QueryBuilder::isSafeSelectClause('Book.Price'), true);
check('aggregate',          QueryBuilder::isSafeSelectClause('SUM(Book.Price)'), true);
check('agg distinct',       QueryBuilder::isSafeSelectClause('COUNT(DISTINCT Book.id)'), true);
check('subquery rejected',  QueryBuilder::isSafeSelectClause('(SELECT pwd FROM authy)'), false);
check('stacked rejected',   QueryBuilder::isSafeSelectClause('id),(SELECT 1'), false);
check('comment rejected',   QueryBuilder::isSafeSelectClause('price /* x */'), false);
check('func-inj rejected',  QueryBuilder::isSafeSelectClause('price);DROP TABLE authy;--'), false);
check('star rejected',      QueryBuilder::isSafeSelectClause('* FROM authy'), false);
check('sleep rejected',     QueryBuilder::isSafeSelectClause('SLEEP(5)'), false);

echo $fail ? "\n$fail FAILURES\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
