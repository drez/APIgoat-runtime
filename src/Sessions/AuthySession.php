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

        if (is_array($this->accessControl[$model])) {
            foreach ($this->accessControl[$model] as $group => $right) {
                if (strstr($right, $needeRight)) {
                    // collect acl group that contains the proper access
                    $groupAccess[] = $group;
                }
            }
        }

        // Priorize the acl group. Order All, Group, Owner
        if (is_array($groupAccess)) {
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
        $AuthyGroupX = \App\AuthyGroupXQuery::create()
            ->leftJoin('AuthyGroup')
            ->filterByIdAuthy($_SESSION[\_AUTH_VAR]->getIdAuthy())
            ->find();

        if ($AuthyGroupX) {
            foreach ($AuthyGroupX as $AuthyGroup) {
                if ($AuthyGroup->getAuthyGroup()) {
                    if ($AuthyGroup->getAuthyGroup()->getAdmin() === 'Yes') {
                        $this->group = 'Admin';
                    }
                    $this->Groups[] = $AuthyGroup->getIdAuthyGroup();
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