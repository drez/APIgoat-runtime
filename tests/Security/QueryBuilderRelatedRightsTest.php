<?php
// QueryBuilder took the caller's `r` on the BASE entity as licence to read any
// related one: a join on AuthyRelatedByIdCreation returned Authy columns, a
// dotted filter LIKE-probed passwd_hash, and the string form of `select`
// skipped the grammar gate its aliased form applies (SQL injection through
// Propel's backticked column alias). Fakes only — no Propel, no database; the
// query is built with `dontrun` and the recorded calls are inspected.
namespace App {
    if (!class_exists(QbrMap::class, false)) {
        class QbrMap
        {
            public function __construct(private string $phpName, private array $cols, private array $relations = []) {}
            public function getPhpName() { return $this->phpName; }
            public function getColumns() { return array_fill_keys($this->cols, null); }
            public function hasRelation($n) { return isset($this->relations[$n]); }
            public function getRelation($n) { return new QbrRelation($this->relations[$n]); }
        }
        class QbrRelation
        {
            public function __construct(private QbrMap $right) {}
            public function getRightTable() { return $this->right; }
        }
        class QbrBaseQuery
        {
            public array $calls = [];
            public function getTableMap()
            {
                return new QbrMap('QbrBase', ['name', 'id_creation'], [
                    'AuthyRelatedByIdCreation' => new QbrMap('Authy', ['username', 'passwd_hash']),
                    'QbrTag' => new QbrMap('QbrTag', ['label'], ['Owner' => new QbrMap('Authy', ['passwd_hash'])]),
                ]);
            }
            public function filterByName(...$a) { $this->calls[] = 'filterByName'; return $this; }
            public function filterByIdCreation(...$a) { $this->calls[] = 'filterByIdCreation'; return $this; }
            public function __call($n, $a) { $this->calls[] = $n; return $this; }
        }
    }
}

namespace ApiGoat\Tests\Security {

use ApiGoat\Api\QueryBuilder;
use PHPUnit\Framework\TestCase;

if (!\function_exists('camelize')) {
    require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php';
}
if (!\defined('_AUTH_VAR')) {
    \define('_AUTH_VAR', 'qbr_auth');
}
// THIS checkout's QueryBuilder/Api, not the runtime clone of whichever project
// lent the phpunit binary (see tests/Mcp/ToolCallAuthorizationTest.php).
require_once __DIR__ . '/../../src/Api/QueryBuilder.php';

final class QbrSession
{
    public function __construct(private bool $admin, private array $rights) {}
    public function isAdmin() { return $this->admin; }
    public function get($k) { return null; }
    public function hasRights($m = '', $r = '') { return $this->rights[$m] ?? false; }
}

final class QueryBuilderRelatedRightsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\class_exists(\ApiGoat\Api\Api::class)) {
            $this->markTestSkipped('ApiGoat\Api\Api not loadable here');
        }
        $_SESSION[\_AUTH_VAR] = new QbrSession(false, ['QbrBase' => true]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION[\_AUTH_VAR]);
    }

    /** @return array{0: array, 1: array} [messages, calls] */
    private function build(array $query, array $extra = []): array
    {
        $q = new \App\QbrBaseQuery();
        $qb = new QueryBuilder($q, $extra + ['i' => '', 'normalized_query' => $query + ['dontrun' => 1]]);
        return [(array) $qb->getMessages(), $q->calls];
    }

    public function test_string_select_goes_through_the_grammar_gate(): void
    {
        [$msgs, $calls] = $this->build(['select' => ['App\\QbrBase.Name.`,(SELECT(version()))AS`y']]);
        $this->assertSame(['Select: column expression not allowed.'], $msgs);
        $this->assertNotContains('select', $calls);

        foreach ([['Name'], ['QbrTag.Label'], ['qbr_base.name'], ['COUNT(name)'], [['SUM(QbrTag.Label)', 'agg']]] as $ok) {
            [$msgs, $calls] = $this->build(['select' => $ok]);
            $this->assertSame([], $msgs, json_encode($ok));
        }
        [$msgs] = $this->build(['select' => [['x']]]);
        $this->assertNotSame([], $msgs);
        [$msgs] = $this->build(['select' => [12]]);
        $this->assertSame(['Select: column expression not allowed.'], $msgs);
    }

    public function test_join_needs_read_rights_on_the_joined_model(): void
    {
        [$msgs, $calls] = $this->build(['join' => ['AuthyRelatedByIdCreation']]);
        $this->assertSame(['Join: Permission denied on (AuthyRelatedByIdCreation)'], $msgs);
        $this->assertNotContains('leftJoin', $calls);

        [$msgs, $calls] = $this->build(['join' => [['AuthyRelatedByIdCreation', 'au', 'left']]]);
        $this->assertSame(['Join: Permission denied on (AuthyRelatedByIdCreation)'], $msgs);
        $this->assertNotContains('joinAuthyRelatedByIdCreation', $calls);

        // an unknown relation is refused, never passed through
        [$msgs] = $this->build(['join' => ['Nope']]);
        $this->assertSame(['Join: Permission denied on (Nope)'], $msgs);
    }

    public function test_legitimate_joins_still_work(): void
    {
        $_SESSION[\_AUTH_VAR] = new QbrSession(false, ['QbrBase' => true, 'QbrTag' => ['Owner']]);
        [$msgs, $calls] = $this->build(['join' => ['qbr_tag'], 'select' => ['QbrTag.Label', 'Name']]);
        $this->assertSame([], $msgs);
        $this->assertContains('leftJoin', $calls);

        [$msgs, $calls] = $this->build(['join' => [['QbrTag', 't', 'left']]]);
        $this->assertSame([], $msgs);
        $this->assertContains('joinQbrTag', $calls);

        // chained: the second hop is checked against ITS target (Authy)
        [$msgs] = $this->build(['join' => ['QbrTag', 'QbrTag.Owner']]);
        $this->assertSame(['Join: Permission denied on (QbrTag.Owner)'], $msgs);

        $_SESSION[\_AUTH_VAR] = new QbrSession(true, []);
        [$msgs] = $this->build(['join' => ['QbrTag', 'QbrTag.Owner', 'AuthyRelatedByIdCreation']]);
        $this->assertSame([], $msgs, 'Admin passes');
    }

    public function test_join_names_and_aliases_are_identifiers(): void
    {
        $_SESSION[\_AUTH_VAR] = new QbrSession(true, []);
        [$msgs] = $this->build(['join' => [['QbrTag', 't ON 1=1 --', 'left']]]);
        $this->assertSame(['Join: alias not allowed.'], $msgs);
        [$msgs] = $this->build(['join' => ['QbrTag`x']]);
        $this->assertSame(['Join: relation name not allowed.'], $msgs);
        [$msgs] = $this->build(['join' => [[null]]]);
        $this->assertSame(['Join: Parameters incorrect.'], $msgs);
    }

    public function test_dotted_filter_needs_read_rights_on_the_related_model(): void
    {
        [$msgs, $calls] = $this->build(['filter' => ['QbrBase' => [['AuthyRelatedByIdCreation.username', 'a%']]]]);
        $this->assertSame(['Filter: Permission denied on (AuthyRelatedByIdCreation)'], $msgs);
        $this->assertNotContains('useAuthyRelatedByIdCreationQuery', $calls);
        $this->assertNotContains('limit', $calls, 'the request is refused, not run unfiltered');

        $_SESSION[\_AUTH_VAR] = new QbrSession(false, ['QbrBase' => true, 'QbrTag' => true]);
        [$msgs, $calls] = $this->build(['filter' => ['QbrBase' => [['QbrTag.label', 'x'], ['name', 'y']]]]);
        $this->assertSame([], $msgs);
        $this->assertSame(['useQbrTagQuery', 'filterByLabel', 'endUse', 'filterByName', 'limit'], $calls);
    }

    public function test_credential_columns_cannot_be_filtered_ordered_or_grouped(): void
    {
        $_SESSION[\_AUTH_VAR] = new QbrSession(true, []);   // even Admin: never an oracle
        foreach (['AuthyRelatedByIdCreation.passwd_hash', 'AuthyRelatedByIdCreation.PasswdHash', 'AuthyRelatedByIdCreation.passwd-hash', 'passwd_hash', 'x.reset_token_hash.y'] as $col) {
            [$msgs, $calls] = $this->build(['filter' => ['QbrBase' => [[$col, '$2y$%']]]]);
            $this->assertSame(['Filter: column is not filterable.'], $msgs, $col);
            $this->assertNotContains('limit', $calls, $col);
        }
        [$msgs, $calls] = $this->build(['join' => ['AuthyRelatedByIdCreation'], 'order' => [['AuthyRelatedByIdCreation.passwd_hash', 'asc']]]);
        $this->assertSame(['Order: column is not allowed.'], $msgs);
        $this->assertNotContains('orderBy', $calls);

        [$msgs, $calls] = $this->build(['groupby' => ['AuthyRelatedByIdCreation.google_sub']]);
        $this->assertSame(['Groupby: column is not allowed.'], $msgs);
        $this->assertNotContains('groupBy', $calls);

        [$msgs, $calls] = $this->build(['order' => [['name', 'asc']], 'groupby' => ['QbrBase.name']]);
        $this->assertSame([], $msgs);
        $this->assertContains('orderBy', $calls);
    }

    public function test_public_read_waives_ordinary_joins_but_never_a_credential_table(): void
    {
        unset($_SESSION[\_AUTH_VAR]);   // anonymous
        [$msgs] = $this->build(['join' => ['QbrTag']], ['rbac_public' => 'passed']);
        $this->assertSame([], $msgs);
        [$msgs] = $this->build(['join' => ['AuthyRelatedByIdCreation']], ['rbac_public' => 'passed']);
        $this->assertSame(['Join: Permission denied on (AuthyRelatedByIdCreation)'], $msgs);
        [$msgs] = $this->build(['filter' => ['QbrBase' => [['AuthyRelatedByIdCreation.username', 'a%']]]], ['rbac_public' => 'passed']);
        $this->assertSame(['Filter: Permission denied on (AuthyRelatedByIdCreation)'], $msgs);
        // no public rule, no session: nothing related is readable
        [$msgs] = $this->build(['join' => ['QbrTag']]);
        $this->assertSame(['Join: Permission denied on (QbrTag)'], $msgs);
    }

    public function test_acl_column_guard_covers_a_base_prefixed_filter(): void
    {
        [$msgs, $calls] = $this->build(['filter' => ['QbrBase' => [['QbrBase.id_creation', 1, 'or'], ['name', 'x']]]]);
        $this->assertStringContainsString('access-controlled', $msgs[0] ?? '');
        $this->assertNotContains('filterByIdCreation', $calls);
    }
}
}
