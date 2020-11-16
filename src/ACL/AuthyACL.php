<?php

namespace ApiGoat\ACL;

trait AuthyACL
{

    private $aclGroup;
    private $modelName;

    public function __construct(string $modelName = null)
    {
        $this->modelName = $modelName;
    }

    /**
     * Authorize an action on $model for the $acl
     *
     * @param string $modelName
     * @param string $acls
     * @return true|false|array 
     */
    public function authorize(string $modelName, string $acls)
    {
        if ($_SESSION[\_AUTH_VAR]->isAdmin()) {
            return true;
        }

        if (strstr($acls, "|")) {
            // require one of multiple acl, return true or false
            $acls = \explode("|", $acls);
            foreach ($acls as $acl) {
                $aclAcccess = $this->hasRights($modelName, $acl);
                if ($aclAcccess) {
                    $this->aclGroup = true;
                }
            }
        } elseif (strstr($acls, "&")) {
            // require all of multiple acl, return true or false
            $acls = \explode("&", $acls);
            foreach ($acls as $acl) {
                $aclAcccess = $this->hasRights($modelName, $acl);
                if (!$aclAcccess) {
                    $this->aclGroup = false;
                    break;
                }
            }
        } else {
            $this->aclGroup = $this->hasRights($modelName, $acls);
        }
        $_SESSION[\_AUTH_VAR]->aclGroup = $this->aclGroup;

        if ($this->aclGroup != false) {
            return true;
        }

        return false;
    }

    public function hasRights($model = '', $needeRight = '')
    {
        // Admin bypass Rights
        if ($_SESSION[\_AUTH_VAR]->getGroup() === 'Admin') {
            return true;
        }

        if (is_array($_SESSION[\_AUTH_VAR]->accessControl[$model])) {
            foreach ($_SESSION[\_AUTH_VAR]->accessControl[$model] as $group => $right) {
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

    /**
     * Applies filter to a PropelPDO to filter By ownership 
     *
     * @param PropelPDO $QueryObj
     * @return PropelPDO
     */
    public function setAclFilter(&$QueryObj)
    {
        if (isset($this->aclGroup) && $this->aclGroup !== false) {
            if (!is_array($this->aclGroup)) {
                $this->aclGroup = $_SESSION[\_AUTH_VAR]->aclGroup;
            } else {
                if (in_array('Owner', $this->aclGroup)) {
                    $QueryObj->filterByIdCreation($_SESSION[_AUTH_VAR]->getIdAuthy());
                    if (in_array('Group', $this->aclGroup)) {
                        $QueryObj->_or()->filterByIdGroupCreation($_SESSION[_AUTH_VAR]->getGroups(), \Criteria::IN);
                    }
                } elseif (in_array('Group', $this->aclGroup)) {
                    $QueryObj->filterByIdGroupCreation($_SESSION[_AUTH_VAR]->getGroups(), \Criteria::IN);
                }
            }
        }

        return $QueryObj;
    }

    public function getFormatedError()
    {
    }
}
