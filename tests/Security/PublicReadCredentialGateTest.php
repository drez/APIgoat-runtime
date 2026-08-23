<?php
// Run: php tests/Security/PublicReadCredentialGateTest.php
//
// Regression guard for the `rbac_public` read waiver on credential-bearing
// tables.
//
// A Public+Allow api_rbac rule arrives at the generic CRUD layer as
// rbac_public == 'passed', and Api::getJson()/getOneJson() used that to skip
// authorize() outright. Skipping authorize() leaves $this->aclGroup null, so
// AuthyACL::setAclFilter()'s isset($this->aclGroup) guard drops the Owner/Group
// row filter; an anonymous caller has no id_tenant either, so that filter is
// skipped too. Net: ZERO row filtering — the whole table comes back. Reached in
// practice as GET /api/v1/Authy/confirm/<anything>, where the suffix falls past
// the pinned route into the generated catch-all CRUD route and dumps `authy`
// (usernames, emails, rights bitmaps, tenant ids; stripSensitiveOutput removes
// the hashes but nothing else), and as a blind extraction oracle besides, since
// QueryBuilder::setFilters() will filter on passwd_hash and report `count`.
//
// The waiver itself is deliberate and load-bearing — vidifye serves anonymous
// Category/list, Faq/list and Banner/list through getJson — so the fix is
// TABLE-SCOPED, not a blanket read gate: a table is credential-bearing iff its
// TableMap carries a column on Api::CREDENTIAL_COLUMNS. Sibling of commit
// 4c8cce6, which hardened setJson/deleteJson and explicitly preserved the read
// waiver.
//
// Covered here:
//   A. isCredentialTable() — true for a map with passwd_hash, false without,
//      and FAIL-CLOSED (true) whenever the TableMap cannot be resolved.
//   B. getJson AND getOneJson deny an anonymous caller on `authy` even with
//      rbac_public == 'passed', and still SERVE a public read of an ordinary
//      table (the vidifye carve-out this design exists to protect).
//   C. The de-duplicated credential lists (Api::CREDENTIAL_COLUMNS,
//      Api::$outputDenyColumns, QueryBuilder::isSensitiveSelectColumn(),
//      AccountService's self-service filter) all resolve to the values they
//      carried as three hand-kept copies.
//
// This file loads the runtime sources UNDER TEST directly (as
// SelectClauseGuardTest does) rather than booting a consuming project's
// autoloader: every project carries its OWN copy of the runtime under
// .admin/vendor/, and an autoloader-based boot would silently exercise that
// stale copy instead of this checkout. Only third-party deps come from
// composer.

namespace App {

    // ---------------------------------------------------------------------------
    // Propel stand-ins. Api resolves its model as "\App\{Table}Query" and reaches
    // the columns through ::create()->getTableMap()->getColumns(), exactly as
    // QueryBuilder::correctData() does; these classes present that same surface.
    // ---------------------------------------------------------------------------

    class StubColumn
    {
        private $name;
        private $phpName;
        private $type;
        public function __construct($name, $phpName, $type = 'VARCHAR')
        {
            $this->name = $name;
            $this->phpName = $phpName;
            $this->type = $type;
        }
        public function getName() { return $this->name; }
        public function getPhpName() { return $this->phpName; }
        public function getType() { return $this->type; }
        public function getValueSet() { return []; }
    }

    class StubTableMap
    {
        private $columns;
        public function __construct(array $columns) { $this->columns = $columns; }
        public function getColumns() { return $this->columns; }
    }

    /** Result stand-in: QueryBuilder calls ->toArray() on a non-array find(). */
    class StubRows
    {
        private $rows;
        public function __construct(array $rows) { $this->rows = $rows; }
        public function toArray() { return $this->rows; }
    }

    class StubRow
    {
        private $row;
        public function __construct(array $row) { $this->row = $row; }
        public function toArray() { return $this->row; }
    }

    abstract class StubQuery
    {
        public static function create($modelAlias = null, $criteria = null) { return new static(); }
        /** Propel keys getColumns() by physical column name. */
        public function getTableMap()
        {
            $map = [];
            foreach (static::columns() as $Column) {
                $map[strtoupper($Column->getName())] = $Column;
            }
            return new StubTableMap($map);
        }
        /** Swallow the fluent builder calls (limit/orderBy/filterBy...). */
        public function __call($name, $args) { return $this; }
        public function find() { return new StubRows(static::rows()); }
        public function findOne() { return new StubRow(static::rows()[0]); }
        abstract public static function columns();
        abstract public static function rows();
    }

    /** The real `authy` column set (apigTutor AuthyTableMap), abridged. */
    class AuthyQuery extends StubQuery
    {
        public static function columns()
        {
            return [
                new StubColumn('id_authy', 'IdAuthy', 'INTEGER'),
                new StubColumn('username', 'Username'),
                new StubColumn('email', 'Email'),
                new StubColumn('passwd_hash', 'PasswdHash'),
                new StubColumn('validation_key', 'ValidationKey'),
                new StubColumn('reset_token_hash', 'ResetTokenHash'),
                new StubColumn('google_sub', 'GoogleSub'),
                new StubColumn('is_root', 'IsRoot', 'INTEGER'),
                new StubColumn('id_tenant', 'IdTenant', 'INTEGER'),
            ];
        }
        public static function rows()
        {
            return [[
                'IdAuthy' => 1, 'Username' => 'root', 'Email' => 'root@example.com',
                'PasswdHash' => '$2y$10$secret', 'ValidationKey' => 'vk', 'ResetTokenHash' => 'rt',
                'GoogleSub' => 'gs', 'IsRoot' => 1, 'IdTenant' => null,
            ]];
        }
    }

    /** The vidifye carve-out: an ordinary public content table. */
    class CategoryQuery extends StubQuery
    {
        public static function columns()
        {
            return [
                new StubColumn('id_category', 'IdCategory', 'INTEGER'),
                new StubColumn('name', 'Name'),
                new StubColumn('is_active', 'IsActive', 'INTEGER'),
            ];
        }
        public static function rows()
        {
            return [['IdCategory' => 7, 'Name' => 'Books', 'IsActive' => 1]];
        }
    }

    /** A map that only exposes PhpNames — normalization must still match. */
    class PhpNameOnlyQuery extends StubQuery
    {
        public function getTableMap()
        {
            return new StubTableMap([0 => new StubColumn('opaque', 'ResetTokenHash')]);
        }
        public static function columns() { return []; }
        public static function rows() { return [[]]; }
    }

    /** Unresolvable #1: the map builder blows up. */
    class ThrowingMapQuery extends StubQuery
    {
        public function getTableMap() { throw new \RuntimeException('map builder failed'); }
        public static function columns() { return []; }
        public static function rows() { return [[]]; }
    }

    /** Unresolvable #2: create() hands back something that is not a query. */
    class NotAnObjectQuery
    {
        public static function create($modelAlias = null, $criteria = null) { return null; }
    }

    /** Unresolvable #3: a query with no getTableMap() at all. */
    class NoTableMapQuery
    {
        public static function create($modelAlias = null, $criteria = null) { return new self(); }
    }

    // NOTE: \App\GhostQuery is deliberately NEVER defined (fail-closed case).
}

namespace {

    require __DIR__ . '/../../vendor/autoload.php';                  // respect/validation, psr/*
    require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php'; // \camelize()
    require_once __DIR__ . '/../../src/ACL/AuthyACL.php';
    require_once __DIR__ . '/../../src/Api/Message.php';
    require_once __DIR__ . '/../../src/Api/QueryBuilder.php';
    require_once __DIR__ . '/../../src/Api/Api.php';

    use ApiGoat\Api\Api;
    use ApiGoat\Api\QueryBuilder;

    if (!defined('_AUTH_VAR')) {
        define('_AUTH_VAR', 'AUTH');
    }

    $fail = 0;
    function check($label, $got, $want)
    {
        global $fail;
        if ($got === $want) {
            echo "PASS  $label\n";
        } else {
            echo "FAIL  $label (got " . var_export($got, true) . ", want " . var_export($want, true) . ")\n";
            $GLOBALS['fail']++;
        }
    }

    // -----------------------------------------------------------------------
    // Session stand-ins. authorize() reads isAdmin()/hasRights() and writes
    // ->aclGroup; setAclFilter() reads get('isRoot')/get('id_tenant').
    // -----------------------------------------------------------------------
    class StubSession
    {
        public $aclGroup;
        private $admin;
        public function __construct($admin = false) { $this->admin = $admin; }
        public function isAdmin() { return $this->admin; }
        public function hasRights($model = '', $right = '') { return false; }
        public function get($key)
        {
            if ($key === 'isRoot') { return false; }
            return null;   // no id_tenant: an anonymous caller has no tenant scope
        }
        public function applyOwnerGroupScope(&$query, $aclGroup) { return $query; }
    }

    /** The exact anonymous-public request the catch-all CRUD route builds. */
    function publicRequest()
    {
        return ['rbac_public' => 'passed', 'i' => null, 'query' => [], 'data' => []];
    }

    $anonymous = new StubSession(false);
    $admin     = new StubSession(true);

    echo "--- A. isCredentialTable() ---\n";

    $_SESSION[_AUTH_VAR] = $anonymous;

    check('authy (passwd_hash in map) -> credential-bearing',
        (new \ApiGoat\Api\Api('Authy'))->isCredentialTable(), true);
    check('category (no credential column) -> ordinary',
        (new \ApiGoat\Api\Api('Category'))->isCredentialTable(), false);
    check('PhpName-only map (ResetTokenHash) -> credential-bearing',
        (new \ApiGoat\Api\Api('PhpNameOnly'))->isCredentialTable(), true);

    // Fail-closed: an unresolvable TableMap must never earn the waiver.
    check('no such Query class -> fail closed',
        (new \ApiGoat\Api\Api('Ghost'))->isCredentialTable(), true);
    check('getTableMap() throws -> fail closed',
        (new \ApiGoat\Api\Api('ThrowingMap'))->isCredentialTable(), true);
    check('create() returns non-object -> fail closed',
        (new \ApiGoat\Api\Api('NotAnObject'))->isCredentialTable(), true);
    check('query without getTableMap() -> fail closed',
        (new \ApiGoat\Api\Api('NoTableMap'))->isCredentialTable(), true);

    echo "\n--- B. the read gate ---\n";

    // B1/B2 — the hole: anonymous + Public rule on a credential table.
    $_SESSION[_AUTH_VAR] = $anonymous;

    $r = (new \ApiGoat\Api\Api('Authy'))->getJson(publicRequest());
    check('getJson    authy  + rbac_public=passed + anonymous -> denied',
        $r['error'] ?? null, 'Permission denied');
    check('getJson    authy  denial is a failure status',
        $r['status'] ?? null, 'failure');
    check('getJson    authy  returns no rows at all',
        isset($r['data']), false);

    $r = (new \ApiGoat\Api\Api('Authy'))->getOneJson(publicRequest());
    check('getOneJson authy  + rbac_public=passed + anonymous -> denied',
        $r['error'] ?? null, 'Permission denied');
    check('getOneJson authy  denial is a failure status',
        $r['status'] ?? null, 'failure');
    check('getOneJson authy  returns no row at all',
        isset($r['data']), false);

    // B3/B4 — the carve-out that must survive: anonymous public content list.
    $r = (new \ApiGoat\Api\Api('Category'))->getJson(publicRequest());
    check('getJson    category + rbac_public=passed + anonymous -> NOT denied',
        $r['error'] ?? null, null);
    check('getJson    category still serves rows',
        $r['data'] ?? null, [['id_category' => 7, 'name' => 'Books', 'is_active' => 1]]);

    $r = (new \ApiGoat\Api\Api('Category'))->getOneJson(publicRequest());
    check('getOneJson category + rbac_public=passed + anonymous -> NOT denied',
        $r['error'] ?? null, null);
    check('getOneJson category still serves the row',
        $r['status'] ?? null, 'data');

    // B5 — the gate AUTHORIZES, it does not blanket-deny: an admin still reads
    // authy through the same public-flagged request, with hashes stripped.
    $_SESSION[_AUTH_VAR] = $admin;
    $r = (new \ApiGoat\Api\Api('Authy'))->getJson(publicRequest());
    check('getJson    authy  + admin -> allowed', $r['error'] ?? null, null);
    check('getJson    authy  + admin -> hashes still stripped',
        array_keys($r['data'][0] ?? []),
        ['id_authy', 'username', 'email', 'is_root', 'id_tenant']);

    echo "\n--- C. one credential list, no copies ---\n";

    // Asserted as a literal set: a future edit that DROPS an entry from the
    // const silently re-opens every enforcement point below, so it must fail
    // loudly right here.
    $expected = ['passwdhash', 'resettokenhash', 'validationkey', 'googlesub'];
    check('Api::CREDENTIAL_COLUMNS is the historical list',
        \ApiGoat\Api\Api::CREDENTIAL_COLUMNS, $expected);

    $ref  = new ReflectionClass(\ApiGoat\Api\Api::class);
    $prop = $ref->getProperty('outputDenyColumns');
    $prop->setAccessible(true);
    check('Api::$outputDenyColumns resolves to the same list',
        $prop->getValue(new \ApiGoat\Api\Api('Category')), $expected);

    // QueryBuilder::isSensitiveSelectColumn() used to hold its own `static $deny`.
    $qbRef    = new ReflectionClass(\ApiGoat\Api\QueryBuilder::class);
    $sensRef  = $qbRef->getMethod('isSensitiveSelectColumn');
    $sensRef->setAccessible(true);
    $qb       = $qbRef->newInstanceWithoutConstructor();
    $isSens   = function ($clause) use ($sensRef, $qb) { return $sensRef->invoke($qb, $clause); };

    foreach (['passwd_hash', 'reset_token_hash', 'validation_key', 'google_sub'] as $c) {
        check("QueryBuilder rejects select '$c'", $isSens($c), true);
    }
    check('QueryBuilder rejects qualified select', $isSens('authy.passwd_hash'), true);
    check('QueryBuilder rejects aggregated select', $isSens('MAX(authy.PasswdHash)'), true);
    check('QueryBuilder still allows an ordinary select', $isSens('username'), false);

    // AccountService's filter is the third former copy. It is expressed in
    // TYPE_FIELDNAME (snake_case) form and legitimately excludes MORE than the
    // credential list (privilege flags, rights bitmaps, UI state), so it keeps
    // its own extra entries and derives only the credential subset from the
    // const. Assert against the SHIPPED source that the resulting exclusion is a
    // superset of the historical literal — i.e. the payload never widens.
    $svc = file_get_contents(__DIR__ . '/../../src/Services/AccountService.php');
    preg_match('/\$nonSelfServiceColumns = \[(.*?)\];/', $svc, $m);
    $nonSelfService = [];
    if (!empty($m[1])) {
        foreach (explode(',', $m[1]) as $entry) {
            $nonSelfService[] = trim(trim($entry), "'\" ");
        }
    }
    check('AccountService keeps its non-credential exclusions',
        $nonSelfService,
        ['passwd', 'root', 'deactivate', 'rights_all', 'rights_owner', 'rights_group',
         'onglet', 'reset_token_expires', 'google_email']);

    // The pre-refactor literal, verbatim.
    $historical = ['validation_key', 'passwd_hash', 'passwd', 'root', 'deactivate',
        'rights_all', 'rights_owner', 'rights_group', 'onglet', 'reset_token_hash',
        'reset_token_expires', 'google_sub', 'google_email'];

    // Every real authy column, plus the historical entries that are not columns
    // and some case/underscore variants.
    $probe = ['id_authy', 'validation_key', 'username', 'fullname', 'email', 'passwd_hash',
        'expire', 'deactivate', 'language', 'theme', 'google_sub', 'google_email',
        'reset_token_hash', 'reset_token_expires', 'id_tenant', 'location_address',
        'location_lat', 'location_lng', 'is_root', 'is_system', 'rights_all',
        'rights_group', 'rights_owner', 'onglet', 'date_creation', 'date_modification',
        'passwd', 'root', 'PasswdHash', 'passwdhash'];

    $oldReturned = [];
    $newReturned = [];
    foreach ($probe as $column) {
        if (!in_array($column, $historical)) {
            $oldReturned[] = $column;
        }
        $excluded = in_array($column, $nonSelfService)
            || in_array(strtolower(str_replace('_', '', $column)), \ApiGoat\Api\Api::CREDENTIAL_COLUMNS, true);
        if (!$excluded) {
            $newReturned[] = $column;
        }
    }
    check('AccountService payload never widens (nothing newly returned)',
        array_values(array_diff($newReturned, $oldReturned)), []);
    check('AccountService still returns the self-service columns',
        array_values(array_diff($oldReturned, $newReturned)),
        ['PasswdHash', 'passwdhash']); // now also caught, by normalization

    echo $fail ? "\n$fail FAILURES\n" : "\nALL PASS\n";
    exit($fail ? 1 : 0);
}
