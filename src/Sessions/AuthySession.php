<?php

namespace ApiGoat\Sessions;


/**
 * AuthySession class
 *
 */

class AuthySession
{

    public $omMap;
    public $isConnected;
    /** Tenant the logged-in user belongs to (authy.id_tenant); drives row scoping. */
    public $idTenant;
    public $lang;
    private $group;
    private $Group;
    private $Groups;
    private $userRights;
    public $sessVar = [];
    # config cache
    public $config = [];
    public $config_time;
    public $csrf = null;
    public $configdb = null;
    public $email = null;
    public $isRoot = null;
    public $passHash = null;
    public $authyId = null;
    public $config_changed = null;
    public $lastMsg = null;
    public $username = null;
    public $IdPrimaryGroup = null;
    public $menuAccess = null;
    public $aclGroup  = null;
    # Per-model ACL grants ({model: {group: rights}}), populated by setRights()
    # and read by hasRights() (the canonical rights decision, #18). Declared so
    # PHP 8.4 doesn't emit a dynamic-property deprecation on the auth hot path.
    public $accessControl = [];
    # User identity attributes set via set()/setSession; declared explicitly so
    # PHP 8.4 doesn't emit a dynamic-property deprecation on the login hot path.
    public $firstname = null;
    public $lastname = null;
    public $fullname = null;
    public $key = null;
    public $ip = null;
    public $sess_id = null;


    function __construct()
    {
        //require _BASE_DIR . "config/permissions.php";
        //$this->omMap = $omMap;

        if ($this->isConnected != 'YES') {
            $this->isConnected = 'NO';
        }
    }

    public function isAdmin()
    {
        if ($this->group === 'Admin') {
            return true;
        }
        return false;
    }

    public function hasRights($model = '', $needeRight = '')
    {
        // Admin bypass Rights
        if ($this->group === 'Admin') {
            return true;
        }

        // Init before the loop: $model may be absent from accessControl (no
        // grant) — previously left $groupAccess undefined (PHP 8 warning) and
        // relied on is_array(null)===false. This is the single canonical copy
        // now (#18: AuthyACL::hasRights delegates here).
        $groupAccess = [];
        if (isset($this->accessControl[$model]) && is_array($this->accessControl[$model])) {
            foreach ($this->accessControl[$model] as $group => $right) {
                if (strstr($right, $needeRight)) {
                    // collect acl group that contains the proper access
                    $groupAccess[] = $group;
                }
            }
        }

        // Priorize the acl group. Order All, Group, Owner
        if ($groupAccess !== []) {
            // if All, unrestricted access
            if (in_array('All', $groupAccess)) {
                return true;
            } else {
                // return the acl group for filtering
                return $groupAccess;
            }
        }
        return false;
    }

    /**
     * Load one row by primary key, scoped to this user's ACL — the airtight
     * counterpart of the list/API row filters, for the privileged findPk loads
     * (edit-form load, saveUpdate, bulk update, delete).
     *
     * It deliberately goes through filterByPrimaryKey()->findOne() rather than
     * findPk(): findPk() on a simple PK uses findPkSimple()/the instance pool,
     * which build raw SQL and BYPASS query filters (and the GoatCheese tenant
     * behavior). findOne() runs through doSelect(), so the tenant partition and
     * the model's Owner/Group row scope for $right both apply.
     *
     * Returns the row, or null when it does not exist OR the current user is not
     * allowed to reach it — callers MUST null-check. Root sees every row (still
     * PK-scoped). Mirrors AuthyACL::setAclFilter's Owner/Group grouping so the
     * _or() pair ANDs cleanly with the tenant filter.
     *
     * @param string $queryClass Fully-qualified Propel Query class (pass FooQuery::class)
     * @param mixed  $pk         Primary key (scalar, or array for a composite PK)
     * @param string $model      RBAC model name for the rights lookup ('' = tenant only)
     * @param string $right      Right to scope by ('r', 'w', 'd', …)
     * @return mixed The model object, or null
     */
    public function loadPkScoped($queryClass, $pk, $model = '', $right = 'r')
    {
        $q = $queryClass::create()->filterByPrimaryKey($pk);

        if (! $this->isRoot()) {
            // Tenant hard partition (fail-closed on an empty session tenant).
            $this->applyTenantScope($q);
            // Owner/Group row scope for this model + right. Shared with
            // AuthyACL::setAclFilter via applyOwnerGroupScope (#18) — one copy
            // of the security-critical row filter, fail-closed.
            if ($model !== '') {
                $this->applyOwnerGroupScope($q, $this->hasRights($model, $right));
            }
        }

        return $q->findOne();
    }

    /**
     * Plural loadPkScoped(): load MANY rows by primary key in ONE scoped query.
     *
     * Same ACL/tenant semantics as loadPkScoped() — a row the caller may not
     * reach is simply absent from the result — but the bulk-edit / mass-action
     * loops no longer issue one SELECT per selected row.
     *
     * Composite primary keys fall back to a loadPkScoped() loop: Propel 1's
     * filterByPrimaryKeys() cannot express an IN over a composite key, and
     * silently narrowing there would be a correctness bug, not a slow path.
     *
     * @param string $queryClass Fully-qualified Propel Query class (FooQuery::class)
     * @param array  $pks        Primary keys (scalars, or arrays for composite PKs)
     * @param string $model      RBAC model name ('' = tenant scope only)
     * @param string $right      Right to scope by ('r', 'w', 'd', …)
     * @return array key => row, for every row the caller may reach. The key is
     *               the primary key cast to string (json_encode()d for a
     *               composite PK) so callers can look a row up from the raw
     *               value they sent, whatever its PHP type.
     */
    public function loadPksScoped($queryClass, array $pks, $model = '', $right = 'r')
    {
        if (!$pks) {
            return [];
        }

        foreach ($pks as $pk) {
            if (is_array($pk)) {
                $out = [];
                foreach ($pks as $one) {
                    $row = $this->loadPkScoped($queryClass, $one, $model, $right);
                    if ($row !== null) {
                        $out[self::pkKey($row->getPrimaryKey())] = $row;
                    }
                }
                return $out;
            }
        }

        $q = $queryClass::create();
        if (!method_exists($q, 'filterByPrimaryKeys')) {
            $out = [];
            foreach ($pks as $one) {
                $row = $this->loadPkScoped($queryClass, $one, $model, $right);
                if ($row !== null) {
                    $out[self::pkKey($row->getPrimaryKey())] = $row;
                }
            }
            return $out;
        }
        $q->filterByPrimaryKeys(array_values($pks));

        if (! $this->isRoot()) {
            $this->applyTenantScope($q);
            if ($model !== '') {
                $this->applyOwnerGroupScope($q, $this->hasRights($model, $right));
            }
        }

        $out = [];
        foreach ($q->find() as $row) {
            $out[self::pkKey($row->getPrimaryKey())] = $row;
        }

        return $out;
    }

    /**
     * Stable array key for a primary key value, shared by loadPksScoped() and
     * its callers: scalars key by their string form (so int 7 and '7' agree),
     * composite keys by their JSON form.
     *
     * @param mixed $pk
     * @return string
     */
    public static function pkKey($pk)
    {
        return is_array($pk) ? (string) json_encode($pk) : (string) $pk;
    }

    /**
     * Apply the Owner/Group row scope from a hasRights() result to a query.
     *
     * The single source of the Owner/Group filter, shared by AuthyACL::
     * setAclFilter (the list/API chokepoint) and loadPkScoped (privileged PK
     * loads) so both narrow identically (#18). For correctly-modelled tables the
     * behaviour is unchanged: scope 'Owner' → rows the user created; 'Owner'+
     * 'Group' → those OR rows owned by the user's groups (the _or() pair groups
     * cleanly under any leading tenant AND); 'Group' alone → group-owned rows.
     *
     * Fail-CLOSED: when $scope demands a filter the model can't satisfy (no
     * filterByIdCreation / filterByIdGroupCreation column) it forces an empty
     * result via where('1 = 0') instead of returning every row. Previously
     * setAclFilter fataled on the missing method while loadPkScoped's
     * method_exists guard fell through to NO filter (fail-open — a narrow IDOR
     * on a mis-modelled table). $scope === true (unrestricted) carries no
     * narrowing. Any other non-array $scope (false = no grant) now FAILS CLOSED
     * too (review 2026-09-23): it used to return the query unscoped, so a
     * caller that forgot to gate on the grant handed out every row. Root is
     * the exception — hasRights() only short-circuits the Admin group, so a
     * root user outside it can hold no grant and still sees every row, as
     * everywhere else (loadPkScoped skips scoping for root).
     *
     * @param object $query A Propel ModelCriteria (by reference semantics via the object)
     * @param mixed  $scope hasRights() result: true, an array of ACL groups, or false
     * @return object the same query
     */
    public function applyOwnerGroupScope($query, $scope)
    {
        if ($scope === true) {
            return $query;
        }
        if (!is_array($scope)) {
            return $this->isRoot() ? $query : $query->where('1 = 0');
        }

        $wantOwner = in_array('Owner', $scope);
        $wantGroup = in_array('Group', $scope);

        if ($wantOwner) {
            if (!method_exists($query, 'filterByIdCreation')) {
                return $query->where('1 = 0'); // fail-closed: cannot owner-scope
            }
            $query->filterByIdCreation($this->getIdAuthy());
            if ($wantGroup && method_exists($query, 'filterByIdGroupCreation')) {
                $query->_or()->filterByIdGroupCreation($this->getGroups(), \Criteria::IN);
            }
        } elseif ($wantGroup) {
            if (!method_exists($query, 'filterByIdGroupCreation')) {
                return $query->where('1 = 0'); // fail-closed: cannot group-scope
            }
            $query->filterByIdGroupCreation($this->getGroups(), \Criteria::IN);
        }

        return $query;
    }

    /**
     * Tenant hard partition for a query on a model with an id_tenant column
     * (the single copy used by setAclFilter, loadPk(s)Scoped, ChildLink,
     * DateCascadeDelete and autocomplete).
     *
     * - root, or a model without filterByIdTenant: untouched;
     * - a session tenant: filterByIdTenant(tenant);
     * - a CONNECTED non-root user with an EMPTY tenant: fail closed
     *   (where 1 = 0) — the old `get('id_tenant') && ...` guard silently
     *   dropped the partition and exposed every tenant's rows;
     * - not connected (anonymous public reads, login flow, CLI): untouched —
     *   authorization of those paths happens before any query is built.
     *
     * @param object $query A Propel ModelCriteria
     * @return object the same query
     */
    public function applyTenantScope($query)
    {
        if ($this->isRoot() || !method_exists($query, 'filterByIdTenant')) {
            return $query;
        }
        $tenant = $this->get('id_tenant');
        if ($tenant) {
            return $query->filterByIdTenant($tenant);
        }
        if ($this->get('connected') == 'YES') {
            return $query->where('1 = 0');
        }
        return $query;
    }

    /**
     * Reference access (owner decision 2026-09-23): may this user pick rows
     * of OTHER models as lookup values on a $formModel record? True when the
     * user holds any 'w' or 'a' grant on the form's own model (Owner/Group
     * scoped grants count — the row-level check of the record itself happens
     * on its own load/save path). Root always may.
     */
    public function canReferenceFrom(string $formModel): bool
    {
        if ($this->isRoot()) {
            return true;
        }
        if ($formModel === '') {
            return false;
        }
        return $this->hasRights($formModel, 'w') !== false || $this->hasRights($formModel, 'a') !== false;
    }

    /**
     * The row scope of a LOOKUP (FK dropdown option list, and the FK save
     * guard that must accept exactly what the dropdown offers). Id + display
     * label only — full-row reads (lists, API, edit/parent loads) keep going
     * through setAclFilter / loadPkScoped and stay fail-closed.
     *
     *  - root: untouched;
     *  - always tenant-partitioned (applyTenantScope, fail-closed on an empty
     *    session tenant);
     *  - $targetModel '' (a target without ownership columns): tenant only;
     *  - 'r' on the target: its own scope — All = every row, Owner/Group =
     *    that narrowing is KEPT even when reference access would allow more;
     *  - no 'r' on the target: reference access — every (tenant) row when
     *    $referenceAllowed and the user can write the form's model
     *    (canReferenceFrom), else fail closed (where 1 = 0).
     *
     * $referenceAllowed is decided at build time by the emitter: false for an
     * FK to the auth table (is_auth_table) or its group table (is_group_table)
     * unless the column opts in (set_input_options {col: {pick_users: true}}).
     *
     * @param object $query A Propel ModelCriteria on the target model
     * @return object the same query
     */
    public function applyReferenceScope($query, string $targetModel, string $formModel = '', bool $referenceAllowed = true)
    {
        if ($this->isRoot()) {
            return $query;
        }
        $this->applyTenantScope($query);
        if ($targetModel === '') {
            return $query;
        }
        $scope = $this->hasRights($targetModel, 'r');
        if ($scope !== false) {
            return $this->applyOwnerGroupScope($query, $scope);
        }
        if ($referenceAllowed && $this->canReferenceFrom($formModel)) {
            return $query;
        }
        return $query->where('1 = 0');
    }

    /**
     * loadPkScoped() under the lookup rule (applyReferenceScope): would the
     * FK dropdown of a $formModel record offer this $targetModel row? Used by
     * the emitted FK save guard (gcFkScopeOk) so a posted FK value is accepted
     * exactly when the dropdown could have listed it. Returns the row or null.
     */
    public function loadReferenceScoped($queryClass, $pk, string $targetModel, string $formModel = '', bool $referenceAllowed = true)
    {
        $q = $queryClass::create()->filterByPrimaryKey($pk);
        $this->applyReferenceScope($q, $targetModel, $formModel, $referenceAllowed);
        return $q->findOne();
    }

    /**
     * Whether a new row of a tenant-scoped model may be written by this
     * session: false for a connected non-root user with no tenant (there is
     * no tenant to stamp, and an unstamped row would leak across tenants).
     */
    public function canStampTenant(): bool
    {
        return $this->isRoot() || $this->get('connected') != 'YES' || (bool) $this->get('id_tenant');
    }

    public function getIdPrimaryGroup()
    {
        return $this->IdPrimaryGroup;
    }

    public function getGroup()
    {
        return $this->group;
    }

    public function getGroups()
    {
        return $this->Groups;
    }

    public function getIdAuthy()
    {
        return $this->authyId;
    }

    public function getCsrf()
    {
        return $this->csrf;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getFirstname()
    {
        return $this->firstname;
    }

    public function getFullname()
    {
        return $this->firstname . " " . $this->lastname;
    }

    public function isRoot()
    {
        return ($this->isRoot === true) ? true : false;
    }

    /**
     * Legacy get/set
     */
    public function get($val)
    {
        switch ($val) {
            case 'isConnected':
                return $this->isConnected;
                break;
            case 'connected':
                return $this->isConnected;
                break;
            case 'group':
                return $this->group;
                break;
            case 'firstname':
                return $this->firstname;
                break;
            case 'lastname':
                return $this->lastname;
                break;
            case 'username':
                return $this->username;
                break;
            case 'id':
                return $this->authyId;
                break;
            case 'fullname':
                return $this->fullname;
                break;
            case 'key':
                return $this->key;
                break;
            case 'email':
                return $this->email;
                break;
            case 'passHash':
                return $this->passHash;
                break;
            case 'lang':
                return $this->lang;
                break;
            case 'ip':
                return $this->ip;
                break;
            case 'lastMsg':
                return $this->lastMsg;
                break;
            case 'rights':
                return $this->userRights;
                break;
            case 'isRoot':
                return $this->isRoot;
                break;
            case 'id_tenant':
                return $this->idTenant;
                break;
        }
    }

    public function set($val, $value)
    {
        switch ($val) {
            case 'isConnected':
                $this->isConnected = $value;
                break;
            case 'connected':
                $this->isConnected = $value;
                break;
            case 'firstname':
                $this->firstname = $value;
                break;
            case 'lastname':
                $this->lastname = $value;
                break;
            case 'username':
                $this->username = $value;
                break;
            case 'id':
                $this->authyId = $value;
                break;
            case 'fullname':
                $this->fullname = $value;
                break;
            case 'key':
                $this->key = $value;
                break;
            case 'email':
                $this->email = $value;
                break;
            case 'passHash':
                $this->passHash = $value;
                break;
            case 'lang':
                $this->lang = $value;
                break;
            case 'ip':
                $this->ip = $value;
                break;
            case 'lastMsg':
                $this->lastMsg = $value;
                break;
            case 'isRoot':
                $this->isRoot = $value;
                break;
            case 'id_tenant':
                $this->idTenant = $value;
                break;
        }
    }

    public function setEmail($val)
    {
        return $this->email = $val;
    }

    public function setCsrf($val)
    {
        return $this->csrf = $val;
    }

    public function setRights(array $rights)
    {
        include_once _BASE_DIR . "config/permissions.php";
        foreach ($rights as $group => $acls) {
            if (is_array($acls)) {
                foreach ($acls as $model => $acl) {
                    $parentMenu = $this->hasParentMenu($omMap, $model);
                    if ($parentMenu) {
                        $this->menuAccess[] = $parentMenu;
                    }
                    $this->menuAccess[] = $model;
                    $this->accessControl[$model][$group] = $acl;
                }
            }
        }

        if (is_array($this->menuAccess)) {
            $this->menuAccess = array_unique($this->menuAccess);
        }
    }

    private function hasParentMenu(array $map, string $model)
    {
        foreach ($map as $modelMap) {
            if ($modelMap['name'] == $model) {
                return $modelMap['parent_menu'];
            }
        }
    }

    public function hasMenu($model)
    {
        if (is_array($this->menuAccess)) {
            if (in_array($model, $this->menuAccess)) {
                return true;
            }
        }

        return false;
    }

    public function setPrimaryGroup(int $group, string $isAdmin)
    {
        $this->IdPrimaryGroup = $group;
        if ($isAdmin === 'Yes') {
            $this->group = 'Admin';
        } else {
            $this->group = 'User';
        }
    }

    public function setGroups()
    {
        // authy_group_x's relation to authy_group is named differently across
        // project schema vintages: the bare 'AuthyGroup' (single membership FK)
        // on older projects, or 'AuthyGroupRelatedByIdAuthyGroup' when
        // add_tablestamp adds a second authy_group FK (id_group_creation). This
        // runtime is shared across every project, so we must not hard-code
        // either relation name — resolve the membership group by its id
        // directly, which works regardless of how many FKs the table carries.
        $rows = \App\AuthyGroupXQuery::create()
            ->filterByIdAuthy($_SESSION[\_AUTH_VAR]->getIdAuthy())
            ->find();

        if ($rows) {
            $memberIds = [];
            foreach ($rows as $row) {
                $memberIds[] = $row->getIdAuthyGroup();
            }
            if ($memberIds) {
                $groups = \App\AuthyGroupQuery::create()
                    ->filterByIdAuthyGroup($memberIds, \Criteria::IN)
                    ->find();
                foreach ($groups as $group) {
                    if ($group->getAdmin() === 'Yes') {
                        $this->group = 'Admin';
                    }
                    $this->Groups[] = $group->getIdAuthyGroup();
                }
            }
        }

        $this->Groups[] = $_SESSION[\_AUTH_VAR]->getIdPrimaryGroup();
    }

    public function resetRights()
    {
        $Authy = \App\AuthyQuery::create()->findPk($this->authyId);
        if ($Authy) {
            $rightsGroup = array(
                'All' => 'RightsAll',
                'Owner' => 'RightsOwner',
                'Group' => 'RightsGroup',
            );
            if (is_array($rightsGroup)) {
                foreach ($rightsGroup as $group => $columnName) {
                    $getColumn = "get{$columnName}";
                    $userRightsAr[$group] = json_decode($Authy->$getColumn(), true);
                }
                $this->setRights($userRightsAr);
            }
        }
    }

    public function isConnected()
    {
        if ($this->isConnected == 'YES')
            return true;
        else
            return false;
    }
}