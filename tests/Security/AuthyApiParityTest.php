<?php
// Review 2026-09-23 follow-up: the generated HTML path refuses these writes
// (goatcheese AuthyPrivilege / FkScopeGuard); the generic JSON API (setJson /
// deleteJson — also the MCP crm_* path) must refuse them too:
//   (a) a non-admin touching a root / system / Admin user, or writing the
//       privilege columns (IdAuthyGroup, Deactivate, group Admin/DefaultGroup);
//   (b) a non-admin writing or deleting a group membership (authy_group_x);
//   (c) a posted FK out of the caller's scope (the Form's gcFkScopeOk());
//   (d) a create without 'a' — and a "create" that names an existing PK is an
//       update, so it needs 'w'.

namespace App {

    /** Stored users, keyed by id. */
    final class RowGuardStore
    {
        /** @var array<int,RowGuardUser> */
        public static array $users = [];
        /** @var array<int,string> group id => Admin */
        public static array $groups = [];
        /** @var array<int,int[]> user id => member group ids */
        public static array $members = [];
        /** @var object[] */
        public static array $saved = [];
        /** @var object[] */
        public static array $deleted = [];
    }

    class RowGuardUser
    {
        public array $set = [];
        public function __construct(public int $id, public int $group, public string $root = 'No', public string $system = 'No') {}
        public function getPrimaryKey() { return $this->id; }
        public function getIdAuthyGroup() { return $this->group; }
        public function getIsRoot() { return $this->root; }
        public function getIsSystem() { return $this->system; }
        public function __call($name, $args)
        {
            if (strncmp($name, 'set', 3) === 0) {
                $this->set[substr($name, 3)] = $args[0] ?? null;
                return $this;
            }
            throw new \BadMethodCallException($name);
        }
        public function setEmail($v) { $this->set['Email'] = $v; return $this; }
        public function setUsername($v) { $this->set['Username'] = $v; return $this; }
        public function setIdAuthyGroup($v) { $this->set['IdAuthyGroup'] = $v; return $this; }
        public function validate($cols = null) { return true; }
        public function save() { RowGuardStore::$saved[] = $this; }
        public function delete() { RowGuardStore::$deleted[] = $this; }
        public function isDeleted() { return in_array($this, RowGuardStore::$deleted, true); }
    }

    class AuthyPeer
    {
        public static function getFieldNames() { return ['IdAuthy', 'Username', 'Email', 'IdAuthyGroup', 'Deactivate', 'PasswdHash', 'IsRoot']; }
    }

    /** Resolves the update target (injected as Api::$queryObjName). */
    class RowGuardAuthyQuery
    {
        private $pk;
        public static function create() { return new self(); }
        public function filterByPrimaryKey($pk) { $this->pk = $pk; return $this; }
        public function findOne() { return RowGuardStore::$users[(int) $this->pk] ?? null; }
    }

    class AuthyGroupQuery
    {
        private array $ids = [];
        public static function create() { return new self(); }
        public function filterByPrimaryKeys(array $ids) { $this->ids = $ids; return $this; }
        public function find()
        {
            $out = [];
            foreach ($this->ids as $id) {
                if (isset(RowGuardStore::$groups[$id])) {
                    $admin = RowGuardStore::$groups[$id];
                    $out[] = new class ($admin) { public function __construct(private string $a) {} public function getAdmin() { return $this->a; } };
                }
            }
            return $out;
        }
    }

    class AuthyGroupXQuery
    {
        private int $user = 0;
        public static function create() { return new self(); }
        public function filterByIdAuthy($id) { $this->user = (int) $id; return $this; }
        public function find()
        {
            return array_map(fn ($g) => new class ($g) { public function __construct(private int $g) {} public function getIdAuthyGroup() { return $this->g; } },
                RowGuardStore::$members[$this->user] ?? []);
        }
    }

    class AuthyGroupXPeer
    {
        public static function getFieldNames() { return ['IdAuthy', 'IdAuthyGroup']; }
    }

    class WidgetPeer
    {
        public static function getFieldNames() { return ['IdWidget', 'Name', 'IdOwner']; }
    }

    class Widget extends RowGuardUser
    {
        public function __construct() { parent::__construct(0, 0); }
        public function setNew($b) { return $this; }
        public function setName($v) { $this->set['Name'] = $v; return $this; }
        public function setIdOwner($v) { $this->set['IdOwner'] = $v; return $this; }
    }
}

namespace ApiGoat\Tests\Security {

    use ApiGoat\ACL\AuthyRowGuard;
    use ApiGoat\Api\Api;
    use App\RowGuardStore;
    use App\RowGuardUser;
    use PHPUnit\Framework\TestCase;

    if (!function_exists('camelize')) {
        require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php';
    }
    require_once __DIR__ . '/../../src/ACL/AuthyACL.php';
    require_once __DIR__ . '/../../src/ACL/AuthyRowGuard.php';
    require_once __DIR__ . '/../../src/Api/Message.php';
    require_once __DIR__ . '/../../src/Api/Api.php';

    /** Session stand-in: root / admin flags + a per-right grant list. */
    final class RowGuardSession
    {
        public array $config = [];
        public $aclGroup;
        public function __construct(public bool $root = false, public bool $admin = false, public array $rights = ['r', 'w', 'a', 'd']) {}
        public function isRoot() { return $this->root; }
        public function isAdmin() { return $this->admin; }
        public function hasRights($model = '', $right = '') { return $this->admin || in_array($right, $this->rights, true); }
        public function get($k) { return $k === 'isRoot' ? $this->root : null; }
        public function applyOwnerGroupScope($q, $scope) { return $q; }
    }

    final class RowGuardService
    {
        public $Form;
        public function __construct(?object $form = null) { $this->Form = $form; }
    }

    final class RowGuardApi extends Api
    {
        public function __construct(string $table, $service, ?array $fields = null)
        {
            parent::__construct($table, $service, $fields);
            if ($table === 'authy') {
                $this->queryObjName = '\\App\\RowGuardAuthyQuery';
            }
        }
    }

    final class AuthyApiParityTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!defined('_AUTH_VAR')) {
                define('_AUTH_VAR', 'gc_test_auth');
            }
            RowGuardStore::$groups = [1 => 'Yes', 2 => 'No'];
            RowGuardStore::$members = [21 => [1]];
            RowGuardStore::$users = [
                10 => new RowGuardUser(10, 2),              // plain user
                11 => new RowGuardUser(11, 1),              // Admin by primary group
                21 => new RowGuardUser(21, 2),              // Admin by membership
                12 => new RowGuardUser(12, 2, 'Yes'),       // root
                13 => new RowGuardUser(13, 2, 'No', 'Yes'), // system
            ];
            RowGuardStore::$saved = [];
            RowGuardStore::$deleted = [];
        }

        private function as(RowGuardSession $s): void
        {
            $_SESSION[_AUTH_VAR] = $s;
        }

        /**
         * Update by body PK (the PUT/PATCH-by-id shape): setEntry resolves the
         * stored row through the ACL filter. (action 'update' additionally runs
         * a QueryBuilder selection; its collection branch pre-checks every row
         * with the same AuthyRowGuard::rowLocked() before writing any.)
         */
        private function update(int $id, array $data): array
        {
            $api = new RowGuardApi('authy', new RowGuardService());
            return $api->setJson(['action' => 'patch', 'data' => ['id_authy' => $id] + $data]);
        }

        // ---- (a) row guard ----------------------------------------------------

        public function testNonAdminCannotTouchAdminRootOrSystemUsers(): void
        {
            $this->as(new RowGuardSession());
            foreach ([11, 21, 12, 13] as $id) {
                $r = $this->update($id, ['email' => 'evil@example.com']);
                self::assertSame('Permission denied', $r['error'] ?? null, "user $id");
            }
            self::assertSame([], RowGuardStore::$saved);
        }

        public function testNonAdminStillEditsAPlainUser(): void
        {
            $this->as(new RowGuardSession());
            $r = $this->update(10, ['email' => 'new@example.com']);
            self::assertSame('success', $r['status']);
            self::assertSame(['Email' => 'new@example.com'], RowGuardStore::$users[10]->set);
        }

        public function testAdminEditsAdminsButNotRootOrSystem(): void
        {
            $this->as(new RowGuardSession(false, true));
            self::assertSame('success', $this->update(11, ['email' => 'a@example.com'])['status']);
            self::assertSame('success', $this->update(21, ['email' => 'b@example.com'])['status']);
            self::assertSame('Permission denied', $this->update(12, ['email' => 'c@example.com'])['error'] ?? null);
            self::assertSame('Permission denied', $this->update(13, ['email' => 'd@example.com'])['error'] ?? null);
        }

        public function testRootEditsEveryone(): void
        {
            $this->as(new RowGuardSession(true));
            foreach ([10, 11, 12, 13] as $id) {
                self::assertSame('success', $this->update($id, ['email' => "u$id@example.com"])['status'], "user $id");
            }
        }

        public function testPrivilegedColumnsAreAdminOnly(): void
        {
            $this->as(new RowGuardSession());
            $this->update(10, ['email' => 'x@example.com', 'id_authy_group' => 1, 'deactivate' => 'No']);
            self::assertSame(['Email' => 'x@example.com'], RowGuardStore::$users[10]->set, 'a non-admin cannot promote');

            $this->as(new RowGuardSession(false, true));
            $this->update(10, ['id_authy_group' => 1]);
            self::assertSame(1, (int) RowGuardStore::$users[10]->set['IdAuthyGroup']);

            self::assertTrue(AuthyRowGuard::columnDenied('AuthyGroup', 'Admin', new RowGuardSession()));
            self::assertTrue(AuthyRowGuard::columnDenied('AuthyGroup', 'default_group', new RowGuardSession()));
            self::assertFalse(AuthyRowGuard::columnDenied('AuthyGroup', 'Admin', new RowGuardSession(false, true)));
            self::assertFalse(AuthyRowGuard::columnDenied('Widget', 'Admin', new RowGuardSession()));
        }

        public function testNonAdminCannotDeleteAnAdmin(): void
        {
            $this->as(new RowGuardSession());
            $qb = new class ([RowGuardStore::$users[10], RowGuardStore::$users[11]]) {
                public $debug = false;
                public function __construct(private array $rows) {}
                public function getDataObj() { return $this->rows; }
                public function getMessages() { return []; }
            };
            $r = (new RowGuardApi('authy', new RowGuardService()))->deleteJson([], $qb);
            self::assertSame('Permission denied', $r['error'] ?? null);
            self::assertSame([], RowGuardStore::$deleted, 'all or nothing: the plain user is not deleted either');

            $this->as(new RowGuardSession(false, true));
            $r = (new RowGuardApi('authy', new RowGuardService()))->deleteJson([], $qb);
            self::assertSame(2, $r['count']);
        }

        public function testRowGuardFailsClosed(): void
        {
            // No session at all: nothing is privileged.
            $prev = $_SESSION[_AUTH_VAR] ?? null;
            unset($_SESSION[_AUTH_VAR]);
            try {
                self::assertFalse(AuthyRowGuard::isPrivilegedCaller());
                self::assertTrue(AuthyRowGuard::rowLocked('Authy', RowGuardStore::$users[11]));
            } finally {
                $_SESSION[_AUTH_VAR] = $prev;
            }
            // A group that does not load counts as Admin.
            self::assertTrue(AuthyRowGuard::rowLocked('Authy', new RowGuardUser(30, 99), new RowGuardSession()));
            // Only the auth table has locked rows.
            self::assertFalse(AuthyRowGuard::rowLocked('Widget', RowGuardStore::$users[12], new RowGuardSession()));
        }

        // ---- (b) membership ---------------------------------------------------

        public function testMembershipWritesAreAdminOnly(): void
        {
            $this->as(new RowGuardSession());
            $api = new RowGuardApi('authy_group_x', new RowGuardService());
            $r = $api->setJson(['action' => 'create', 'data' => ['id_authy' => 10, 'id_authy_group' => 1]]);
            self::assertSame('Permission denied', $r['error'] ?? null);
            $r = (new RowGuardApi('authy_group_x', new RowGuardService()))->deleteJson([]);
            self::assertSame('Permission denied', $r['error'] ?? null);

            self::assertFalse(AuthyRowGuard::membershipWriteDenied('AuthyGroupX', new RowGuardSession(false, true)));
            self::assertFalse(AuthyRowGuard::membershipWriteDenied('AuthyGroupX', new RowGuardSession(true)));
        }

        // ---- (c) FK scope -----------------------------------------------------

        public function testFormFkScopeCheckGatesTheSave(): void
        {
            $this->as(new RowGuardSession());
            $deny = new class { public function gcFkScopeOk($e): bool { return false; } };
            $r = (new RowGuardApi('widget', new RowGuardService($deny)))->setJson(['action' => 'create', 'data' => ['name' => 'w', 'id_owner' => 999]]);
            self::assertSame('Permission denied', $r['error'] ?? null);
            self::assertSame([], RowGuardStore::$saved);

            $allow = new class { public function gcFkScopeOk($e): bool { return true; } };
            $r = (new RowGuardApi('widget', new RowGuardService($allow)))->setJson(['action' => 'create', 'data' => ['name' => 'w', 'id_owner' => 5]]);
            self::assertSame('success', $r['status']);

            // Older project: no gcFkScopeOk() on its Form → unchanged behaviour.
            $r = (new RowGuardApi('widget', new RowGuardService(new \stdClass())))->setJson(['action' => 'create', 'data' => ['name' => 'w']]);
            self::assertSame('success', $r['status']);
        }

        // ---- (d) create needs 'a' ---------------------------------------------

        public function testCreateNeedsAddRight(): void
        {
            $this->as(new RowGuardSession(false, false, ['r', 'w']));
            $r = (new RowGuardApi('widget', new RowGuardService()))->setJson(['action' => 'create', 'data' => ['name' => 'w']]);
            self::assertSame('Permission denied', $r['error'] ?? null);
            self::assertSame([], RowGuardStore::$saved);

            $this->as(new RowGuardSession(false, false, ['r', 'a']));
            $r = (new RowGuardApi('widget', new RowGuardService()))->setJson(['action' => 'create', 'data' => ['name' => 'w']]);
            self::assertSame('success', $r['status']);
        }

        public function testCreateNamingAnExistingPkNeedsWriteRight(): void
        {
            $this->as(new RowGuardSession(false, false, ['r', 'a']));
            $api = new RowGuardApi('authy', new RowGuardService());
            $r = $api->setJson(['action' => 'create', 'data' => ['id_authy' => 10, 'email' => 'hijack@example.com']]);
            self::assertSame('Permission denied', $r['error'] ?? null);
            self::assertSame([], RowGuardStore::$users[10]->set);
        }
    }
}
