<?php
// Run: php vendor/apigoat/runtime/tests/QueryBuilderPagingTest.php
// crm_list paging was "unreliable": with page=N the page size was max_page
// (hidden default 50) and `limit` was ignored, validateLimit was a string-
// length check, and no total/last_page came back. Pure helpers only here;
// the DB-backed behaviour is smoke-tested against a project.
if (!function_exists('camelize')) { function camelize($s) { return $s; } }
require __DIR__ . '/../src/Api/QueryBuilder.php';
require __DIR__ . '/../src/Mcp/McpTool.php';
require __DIR__ . '/../src/Mcp/ToolError.php';
if (!class_exists('ApiGoat\Sessions\AuthySession')) { eval('namespace ApiGoat\Sessions; class AuthySession {}'); }
require __DIR__ . '/../src/Mcp/Tools/AbstractCrmTool.php';
require __DIR__ . '/../src/Mcp/Tools/CrmList.php';

use ApiGoat\Api\QueryBuilder;
use ApiGoat\Mcp\Tools\CrmList;

function assertEq($a, $b, string $m): void { if ($a !== $b) { fwrite(STDERR, "FAIL: $m (" . json_encode($a) . " !== " . json_encode($b) . ")\n"); exit(1); } }

// normalizeLimit: positive ints only, everything else → default
assertEq(QueryBuilder::normalizeLimit(5), 5, 'int kept');
assertEq(QueryBuilder::normalizeLimit('5'), 5, 'numeric string kept');
assertEq(QueryBuilder::normalizeLimit(' 12 '), 12, 'padded numeric string kept');
assertEq(QueryBuilder::normalizeLimit('abc'), 30, 'alnum garbage → default (was accepted before)');
assertEq(QueryBuilder::normalizeLimit(0), 30, '0 → default');
assertEq(QueryBuilder::normalizeLimit(-1), 30, 'negative → default');
assertEq(QueryBuilder::normalizeLimit(null), 30, 'null → default');
assertEq(QueryBuilder::normalizeLimit('1.5'), 30, 'float string → default');
assertEq(QueryBuilder::normalizeLimit(null, 0), 0, 'custom default');

// pageSize: limit is the page size; max_page only as an explicit positive alias
assertEq(QueryBuilder::pageSize(null, 3), 3, 'page size = limit (was hidden 50)');
assertEq(QueryBuilder::pageSize('', 3), 3, 'blank max_page → limit');
assertEq(QueryBuilder::pageSize(0, 3), 3, 'max_page 0 → limit');
assertEq(QueryBuilder::pageSize(7, 3), 7, 'explicit max_page wins as alias');
assertEq(QueryBuilder::pageSize(null, 0), 1, 'never below 1');

// shapePaged: paged envelopes wrap, unpaged stay bare
$paged = ['status' => 'success', 'data' => [['id' => 1]], 'count' => 1, 'page' => ['page' => 2, 'per_page' => 1, 'total' => 5, 'last_page' => 5]];
$out = CrmList::shapePaged($paged);
assertEq($out['data'], ['rows' => [['id' => 1]], 'page' => 2, 'per_page' => 1, 'total' => 5, 'last_page' => 5], 'paged → {rows,+meta}');
$bare = ['status' => 'success', 'data' => [['id' => 1]], 'count' => 1];
assertEq(CrmList::shapePaged($bare)['data'], [['id' => 1]], 'unpaged → bare rows');
$empty = ['status' => 'success', 'data' => [], 'count' => 0, 'page' => ['page' => 9, 'per_page' => 10, 'total' => 5, 'last_page' => 1]];
assertEq(CrmList::shapePaged($empty)['data']['rows'], [], 'past the end → empty rows with meta');
$fail = ['status' => 'failure', 'error' => 'x', 'page' => ['page' => 1]];
assertEq(isset(CrmList::shapePaged($fail)['data']), false, 'failure untouched');

echo "OK QueryBuilderPagingTest\n";
