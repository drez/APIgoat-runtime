<?php

namespace ApiGoat\Api;

use ApiGoat\Handlers\PropelErrorHandler;
use PropelCollection;

/**
 * Handles a server request and produces a response.
 * for Propel Object models
 */
class Api
{

    use \ApiGoat\ACL\AuthyACL;
    use \ApiGoat\Api\Message;
    /**
     *  Base propel entity name
     * 
     * @var string
     */
    private $tablename;
    /**
     *  Propel query class name
     * 
     * @var string
     */
    private $queryObjName;
    /**
     * Response array
     *
     * @var array
     */
    private $response;

    /**
     * Set the basic variables
     *
     * @param string $tablename
     * @param string $ServiceWrapper
     */
    public function __construct($tablename, $ServiceWrapper = null)
    {
        $this->tableName = \camelize($tablename, true);
        $this->queryObjName = "\App\\" . $this->tableName . "Query";
        if ($ServiceWrapper) {
            $this->ServiceWrapper = $ServiceWrapper;
        }
    }

    /**
     * Convert query pameters into array with only the existing column name
     *
     * @param string $request
     * @param boolean $isMultiple
     * @return void
     */
    private function filterRequest(iterable $request, $isMultiple = false)
    {
        $peerClass = "\App\\" . $this->tableName . "Peer";
        $fieldsName = $peerClass::getFieldNames();

        if ($isMultiple) {

            foreach ($request as $fieldList) {
                foreach ($fieldList as $key => $val) {
                    if (in_array(\camelize($key, true), $fieldsName)) {
                        $return[\camelize($key, true)] = $val;
                    }
                }
            }
        } else {
            foreach ($request as $key => $val) {
                if (in_array(\camelize($key, true), $fieldsName)) {
                    $return[\camelize($key, true)] = $val;
                }
            }
        }

        if ($request['i']) {
            $return['Id' . $this->tableName] = $request['i'];
        }

        return $return;
    }

    /**
     * Update/create one or multiple entry
     *
     * @param array $request
     * @param QueryBuilder $QueryBuilder
     * @return void
     */
    public function setJson($request, $QueryBuilder = null)
    {

        $this->response = [];
        $this->response['status'] = 'failure';

        #one entry, or multiple with querybuilder

        $data = $this->filterRequest($request);

        if (empty($data)) {
            $this->response['errors'][] = "Wrong input 1007, nothing found to update";
            return $this->response;
        }

        if ($request['rbac_public'] != 'passed') {
            if ($data["Id{$this->tableName}"] || ($QueryBuilder !== null || ($QueryBuilder === null && is_array($request['query'])))) {
                $acl = $this->authorize($this->tableName, 'w');
            } else {
                $acl = $this->authorize($this->tableName, 'a');
            }
        } else {
            $acl = true;
        }

        if (!$acl) {
            $this->response['errors'][] = "Permission denied";
            return $this->response;
        }

        if ($QueryBuilder !== null || ($QueryBuilder === null && is_array($request['query']))) {
            // Use Query Builder
            $ModelQuery = $this->setAclFilter($this->queryObjName::create());
            $QueryBuilder = new \ApiGoat\Api\QueryBuilder($ModelQuery, $request);
            $DataObj = $QueryBuilder->getDataObj();
            $this->response['messages'] = $QueryBuilder->getMessages();
            if ($QueryBuilder->debug) {
                $this->response['debug'] = $QueryBuilder->getDebug();
            }
        }

        if (!empty($this->response['messages'])) {
            $this->response['errors'][] = "Query builder warning are blocking the create/update";
            return $this->response;
        }

        if (is_array($request)) {

            if ($DataObj instanceof PropelCollection) {
                foreach ($DataObj as $Obj) {
                    if (!empty($data)) {
                        $data["Id{$this->tableName}"] = $Obj->getPrimaryKey();
                        $this->setEntry($data, $Obj);
                    } else {
                        $this->response['errors'][] = "Wrong input 1004, nothing found to update";
                    }
                }
            } else {
                if (!empty($data)) {
                    $this->setEntry($data);
                } else {
                    $this->response['errors'][] = "Wrong input 1003, nothing found to update";
                    $this->response['errors'][] = $data;
                }
            }
        } else {
            $this->response['messages'][] = "Wrong input 1001";
        }
        return $this->response;
    }

    /**
     * Get multiple entry
     *
     * @param array $data
     * @param QueryBuilder $QueryBuilder
     * @return void
     */
    public function getJson($data, $QueryBuilder = null)
    {

        if ($data['rbac_public'] != 'passed') {
            $acls = $this->authorize($this->tableName, 'r');
            if (!$acls) {
                $this->response['status'] = "failure";
                $this->response['errors'][] = "Permission denied";
                return $this->response;
            }
        }

        if ($QueryBuilder === null) {
            $ModelQuery = $this->setAclFilter($this->queryObjName::create());
            $QueryBuilder = new \ApiGoat\Api\QueryBuilder($ModelQuery, $data);
        }

        try {
            // Add a global settings permit whole object
            if (!$QueryBuilder->selectIsSet()) {
                $ret['status'] = 'failure';
                $ret['errors'][] = "Invalid parameter: Select * are not allowed. Use 'select' key in your query parameter to select some columns. ";
                return $ret;
            }

            $Data = $QueryBuilder->getData();
            $ret['messages'][] = $QueryBuilder->getMessages();
        } catch (\Exception $x) {
            $ret['status'] = 'failure';
            $ret['errors'][] = "Invalid parameter 4: " . $x->getMessage();
            return $ret;
        }

        try {
            if (count($Data) == 0) {
                $ret['status'] = 'success';
                $ret['data'] = [];
                $ret['count'] = 0;
            } else {
                $ret['status'] = 'success';
                // if select is set, lower case field name is ok
                $ret['data'] = $Data;
                $ret['count'] = count($Data);
            }
        } catch (\Exception $x) {
            $ret['status'] = 'failure';
            $ret['errors'][] = "Invalid parameter 1: " . $x->getMessage();
        }

        if ($QueryBuilder->debug) {
            $ret['debug'] = $QueryBuilder->getDebug();
        }
        return $ret;
    }

    /**
     * Get one entry
     *
     * @param array $data
     * @return void
     */
    public function getOneJson($data)
    {

        try {
            $QueryBuilder = new \ApiGoat\Api\QueryBuilder($this->queryObjName::create(), $data);


            $obj = $QueryBuilder->findOne();
            if (!$obj) {
                $ret['status'] = 'success';
                $ret['data'] = [];
            } else {
                $ret['status'] = 'data';
                $ret['data'] = $obj->toArray();
            }
            return $ret;
        } catch (\Exception $x) {
            $ret['status'] = 'failure';
            $ret['errors'][] = "Invalid parameter 2: " . $x->getMessage();
        }
        return $ret;
    }

    /**
     * Delete one or multiple entry
     *
     * @param array $data
     * @param \ApiGoat\Api\QueryBuilder $QueryBuilder
     * @return array
     */
    public function deleteJson($data, $QueryBuilder = null)
    {

        if ($data['rbac_public'] != 'passed') {
            $acls = $this->authorize($this->tableName, 'd');
        } else {
            $acls = true;
        }

        if (!$acls) {
            $this->response['errors'][] = "Permission denied";
            return $this->response;
        }

        try {
            if ($QueryBuilder === null) {
                $ModelQuery = $this->setAclFilter($this->queryObjName::create());
                $QueryBuilder = new \ApiGoat\Api\QueryBuilder($ModelQuery, $data);
            }
            $obj = $QueryBuilder->getDataObj();
            if ($QueryBuilder->getMessages()) {
                $ret['errors'][] = $QueryBuilder->getMessages();
            }


            if (!$obj) {
                $ret['status'] = 'failure';
                $ret['errors'][] = 'Entry not found';
            } else {
                $ret['count'] = 0;
                foreach ($obj as $item) {
                    if (\method_exists($this->ServiceWrapper, 'beforeDelete')) {
                        $this->ServiceWrapper->beforeDelete($item, $data, $this->response['messages']);
                    }

                    $id = $item->getPrimaryKey();
                    $ret['ids'][] = $item->getPrimaryKey();
                    $item->delete();
                    if ($item->isDeleted()) {
                        $ret['count']++;
                        $ret['deleted'][] = $id;
                    } else {
                        $ret['status'] = 'mixed';
                    }
                }
                if (empty($ret['status'])) {
                    $ret['status'] = 'success';
                }
            }
            return $ret;
        } catch (\Exception $x) {
            $ret['status'] = 'failure';
            if (is_array($ret['data']['ids'])) {
                $ret['status'] = 'failure';
                $ret['errors'][] = "Some not deleted";
            } else {
                $ret['status'] = 'failure';
                $ret['errors'][] = "Invalid parameter 3: " . $x->getMessage();
            }
        }
        if ($QueryBuilder->debug) {
            $ret['debug'] = $QueryBuilder->getDebug();
        }
        return $ret;
    }

    /**
     * Prepare for setColumn and Handles ApiGoat\ExtendedValidation errors
     *
     * @param array $data
     * @param String or Object $DataObj
     * @return void
     */
    private function setEntry($data, $DataObj = null)
    {
        $isNew = false;
        $extValidationError = [];
        $error = [];

        if (!isset($data["Id{$this->tableName}"])) {
            $this->response['debug'][] = "Create {$this->tableName}";
            $className = "App\\" . $this->tableName;
            $obj = new $className;
            $obj->setNew(true);
            $isNew = true;
        } elseif (!($DataObj instanceof PropelCollection)) {
            $this->response['debug'][] = "Update {$this->tableName}";
            $obj =  $this->queryObjName::create()->findPk($data["Id{$this->tableName}"]);
        } else {
            $this->response['debug'][] = "Update {$this->tableName}";
            $obj = $DataObj;
        }
        if ($obj) {

            /**
             * Hook method for ApiGoat
             */
            if (\method_exists($this->ServiceWrapper, 'beforeSave')) {
                $this->ServiceWrapper->beforeSave($obj, $data, $isNew, $this->response['messages'], $extValidationError);
            }

            if (!$extValidationError) {
                $this->setColumn($obj, $data);

                if (!$this->validateSave($obj)) {
                    return false;
                }
            } else {
                $PropelErrorHandler = new PropelErrorHandler($obj);
                $PropelErrorHandler->setExtendedValidationFailures($extValidationError);
                $validationErrors = $PropelErrorHandler->getValidationErrorsArray();
                $this->response['messages'] = $validationErrors['messages'];
                $this->response['errors'] = $validationErrors['columns'];
                $this->response['status'] = 'failure';
                return false;
            }

            /**
             * Hook method for ApiGoat
             */
            if (\method_exists($this->ServiceWrapper, 'afterSave')) {
                $dataAr = [];
                $this->ServiceWrapper->afterSave($obj, $dataAr, $isNew, $error, $data, $this->response['messages']);
            }
        } else {
            $this->response['errors'][] = "Entry not found";
            return false;
        }
        return $obj;
    }

    /**
     * Set the value of a column
     *
     * @param Propel object classe $obj
     * @param array $columns
     * @param string $value
     * @return void
     */
    private function setColumn(&$obj, $columns, $value = '')
    {

        $ret['count'] = 0;
        if (!is_array($columns)) {
            $setStr = "set" . $columns;
            if (!method_exists($obj, $setStr))
                $this->response['errors'][] = 'Unknown column ' . $columns;
            else {
                $obj->$setStr($value);
                $ret['count']++;
            }
        } else {
            foreach ($columns as $key => $val) {
                if ($key) {
                    $setStr = "set" . $key;
                    if (!method_exists($obj, $setStr)) {
                        $this->response['errors'][] = 'Unknown column ' . $key;
                    } else {
                        $obj->$setStr($val);
                        $this->response['status'] = 'success';
                    }
                } else {
                    $this->response['errors'][] = 'Missing value for column';
                }
            }
        }
    }

    /**
     * Validate and save
     *
     * @param Propel object classes $obj
     * @return void
     * 
     * @throws PropelException
     */
    private function validateSave($obj)
    {
        if ($obj->validate()) {
            $obj->save();
            $this->response['ids'][] = $obj->getPrimaryKey();
            $this->response['status'] = 'success';
            return true;
        } else {
            # exception
            $PropelErrorHandler = new PropelErrorHandler($obj);
            $validationErrors = $PropelErrorHandler->getValidationErrorsArray();
            $this->response['messages'] = $validationErrors['messages'];
            $this->response['errors'] = $validationErrors['columns'];
            $this->response['status'] = 'failure';
            return false;
        }
    }
}
