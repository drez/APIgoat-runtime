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
        // Reset per-call: aclGroup is reused by setAclFilter() to build the
        // Owner/Group row filter. Without this reset, an object that authorizes
        // two models in one request would carry the first model's group scope
        // into the second's filter.
        $this->aclGroup = null;

        if ($_SESSION[\_AUTH_VAR]->isAdmin()) {
            return true;
        }

        if (strstr($acls, "|")) {
            // require one of multiple acl, return true or false
            $acls = \explode("|", $acls);
            foreach ($acls as $acl) {
                $aclAcccess = $this->hasRights($modelName, $acl);
                if ($aclAcccess === true) {
                    $this->aclGroup = true;
                } elseif (is_array($aclAcccess) && $this->aclGroup !== true) {
                    $this->aclGroup = is_array($this->aclGroup)
                        ? array_values(array_unique(array_merge($this->aclGroup, $aclAcccess)))
                        : $aclAcccess;
                }
            }
        } elseif (strstr($acls, "&")) {
            // require all of multiple acl, return true or false
            $acls = \explode("&", $acls);
            foreach ($acls as $acl) {
                $aclAcccess = $this->hasRights($modelName, $acl);
                if (! $aclAcccess) {
                    $this->aclGroup = false;
                    break;
                }
                $this->aclGroup = $aclAcccess;
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
        // Tenant row-scoping (independent of Owner/Group RBAC): a non-root user
        // bound to a tenant only ever sees rows of their own tenant, on any model
        // that has an id_tenant column. Added as a leading AND — Propel groups the
        // Owner/Group _or() below, so the tenant filter ANDs cleanly with it (no
        // (tenant AND owner) OR group leak; verified). Root users see all tenants.
        if (! $_SESSION[\_AUTH_VAR]->get('isRoot')
            && $_SESSION[\_AUTH_VAR]->get('id_tenant')
            && method_exists($QueryObj, 'filterByIdTenant')) {
            $QueryObj->filterByIdTenant($_SESSION[\_AUTH_VAR]->get('id_tenant'));
        }

        if (isset($this->aclGroup) && $this->aclGroup !== false) {
            if (! is_array($this->aclGroup)) {
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
