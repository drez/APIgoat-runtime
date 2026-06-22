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
            // Tenant hard partition.
            if ($this->get('id_tenant') && method_exists($q, 'filterByIdTenant')) {
                $q->filterByIdTenant($this->get('id_tenant'));
            }
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
     * on a mis-modelled table). A non-array $scope (true = unrestricted,
     * false = no grant) carries no Owner/Group narrowing; model-level access is
     * gated by the caller.
     *
     * @param object $query A Propel ModelCriteria (by reference semantics via the object)
     * @param mixed  $scope hasRights() result: true, an array of ACL groups, or false
     * @return object the same query
     */
    public function applyOwnerGroupScope($query, $scope)
    {
        if (!is_array($scope)) {
            return $query;
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
            foreach ($rows as $row) {
                $group = \App\AuthyGroupQuery::create()->findPk($row->getIdAuthyGroup());
                if ($group) {
                    if ($group->getAdmin() === 'Yes') {
                        $this->group = 'Admin';
                    }
                    $this->Groups[] = $row->getIdAuthyGroup();
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

    function isSecure()
    {
        if ($this->ip == $_SERVER['REMOTE_ADDR'] && $this->sess_id == md5(session_id()))
            return true;
        else
            return false;
    }

    public function isConnected()
    {
        if ($this->isConnected == 'YES')
            return true;
        else
            return false;
    }
}