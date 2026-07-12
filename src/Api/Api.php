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
    public $queryObjName;
    /**
     * Response array
     *
     * @var array
     */
    private $response;
    private $ServiceWrapper;
    private $colsToValidate;
    /**
     * Per-form editable-column allowlist (camelized PhpNames) emitted by the
     * code generator. null = no allowlist available (fall back to the
     * denylist-only behavior). Prevents mass-assignment of columns the form
     * never exposes.
     *
     * @var array|null
     */
    private $editableFields;
    /**
     * Columns that must never be set via the generic API body, regardless of
     * the editable allowlist (system flag + audit-stamp columns). Single source
     * of truth — read by MetaCatalog so the `writable` flag cannot drift from
     * the enforcement point.
     *
     * @var string[]
     */
    public const SYSTEM_COLUMNS = [
        'IsSystem',
        'IsRoot', // privilege flag — never settable via the generic API body
        'IdCreation', 'IdModification', 'IdGroupCreation',
        'DateCreation', 'DateModification',
        'IdTenant', // tenant is assigned server-side on create, never client-set
    ];

    /** @var string[] */
    protected $denyColumns = self::SYSTEM_COLUMNS;

    /**
     * Memo for i18nColumns() — phpNames of add_i18n columns proxied onto this
     * model (null until first use).
     *
     * @var string[]|null
     */
    private $i18nColumnsMemo;

    /**
     * Per-request locale for i18n writes (the request's 'lang' key, set by
     * the MCP crm_update/crm_create tools). null = write every supported
     * locale (the locale-less default).
     *
     * @var string|null
     */
    private $i18nWriteLocale;

    /**
     * Columns that must never appear in a generic-API JSON response, regardless
     * of the caller's `select` or read rights (review M1). Credential hashes and
     * single-use tokens — a user with mere `:r` on Authy could otherwise read
     * every account's password hash / reset token. Matched case-insensitively,
     * underscores ignored (covers both PhpName and snake_case keys).
     *
     * @var array
     */
    protected $outputDenyColumns = ['passwdhash', 'resettokenhash', 'validationkey', 'googlesub'];

    /**
     * Strip outputDenyColumns from an API result set (array of row arrays, or a
     * single row array). Defense in depth — independent of RBAC / select.
     */
    protected function stripSensitiveOutput($rows)
    {
        if (!is_array($rows)) {
            return $rows;
        }
        $isSingle = !isset($rows[0]) || !is_array($rows[0]);
        $list = $isSingle ? [$rows] : $rows;
        foreach ($list as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $k) {
                if (in_array(strtolower(str_replace('_', '', (string) $k)), $this->outputDenyColumns, true)) {
                    unset($list[$i][$k]);
                }
            }
        }
        return $isSingle ? $list[0] : $list;
    }

    /**
     * Set the basic variables
     *
     * @param string $tablename
     * @param string|object $ServiceWrapper
     * @param array|null $editableFields per-form editable column allowlist
     */
    public function __construct(string $tablename, string|object $ServiceWrapper = null, array $editableFields = null)
    {
        $this->tablename = \camelize($tablename, true);
        $this->queryObjName = "\App\\" . $this->tablename . "Query";
        if ($ServiceWrapper) {
            $this->ServiceWrapper = $ServiceWrapper;
        }
        $this->editableFields = $editableFields;
    }

    /**
     * Convert query pameters into array with only the existing column name
     *
     * @param iterable $request
     * @param boolean $isMultiple
     * @return array
     */
    private function filterRequest(iterable $request, $isMultiple = false)
    {
        $peerClass = "\App\\" . $this->tablename . "Peer";
        $fieldsName = $peerClass::getFieldNames();

        $return = [];

        if ($isMultiple) {

            foreach ($request as $fieldList) {
                foreach ($fieldList as $key => $val) {
                    $cam = \camelize($key, true);
                    if ($this->isWritableColumn($cam, $fieldsName)) {
                        $return[$cam] = $val;
                    }
                }
            }
        } else {
            foreach ($request as $key => $val) {
                $cam = \camelize($key, true);
                if ($this->isWritableColumn($cam, $fieldsName)) {
                    $return[$cam] = $val;
                }
            }
        }

        /*if ($request['i']) {
            $return['Id' . $this->tablename] = $request['i'];
        }*/

        // The PK identifies the update target (re-attached in setJson and
        // resolved through the ACL filter in setEntry); it must never be a
        // writable column value here (prevents PK-reassignment / IDOR).
        unset($return['Id' . $this->tablename]);

        return $return;
    }

    /**
     * PhpNames of add_i18n columns proxied onto this model (e.g. Quote::Terms,
     * which physically lives in quote_i18n). Propel's i18n behavior moves the
     * column out of the main table map, so Peer::getFieldNames() no longer
     * lists it — but the proxy get/set methods on the model remain, and the
     * admin form still writes it. Without this, an API/MCP body key for an
     * i18n column is silently dropped by filterRequest.
     *
     * @return string[]
     */
    private function i18nColumns()
    {
        if ($this->i18nColumnsMemo !== null) {
            return $this->i18nColumnsMemo;
        }
        $this->i18nColumnsMemo = [];
        $peer = "\\App\\{$this->tablename}I18nPeer";
        if (class_exists($peer) && method_exists($peer, 'getFieldNames')) {
            foreach ($peer::getFieldNames() as $phpName) {
                // Skip the translation bookkeeping columns (FK to the parent +
                // locale discriminator, both part of the i18n composite PK).
                if ($phpName === 'Locale' || $phpName === 'Id' . $this->tablename) {
                    continue;
                }
                $this->i18nColumnsMemo[] = $phpName;
            }
        }
        return $this->i18nColumnsMemo;
    }

    /**
     * Decide whether a (camelized) request key may be written: it must be a
     * real column (or an add_i18n proxy column), not on the sensitive-column
     * denylist, and — when the generator supplied a per-form allowlist —
     * present on that allowlist.
     *
     * @param string $cam camelized column name
     * @param array $fieldsName Peer::getFieldNames()
     * @return boolean
     */
    private function isWritableColumn($cam, $fieldsName)
    {
        $isI18n = !in_array($cam, $fieldsName) && in_array($cam, $this->i18nColumns(), true);
        if (!$isI18n && !in_array($cam, $fieldsName)) {
            return false;
        }
        if (in_array($cam, $this->denyColumns, true)) {
            return false;
        }
        if ($this->editableFields !== null && !in_array($cam, $this->editableFields, true)) {
            if (!$isI18n) {
                return false;
            }
            // The generator lists i18n columns on the form allowlist as
            // per-locale keys ({Table}I18n_{Col}_{locale}); a plain-name write
            // is allowed iff the form exposes the column in some locale.
            $prefix = $this->tablename . 'I18n_' . $cam . '_';
            $onForm = false;
            foreach ($this->editableFields as $f) {
                if (strncmp((string) $f, $prefix, strlen($prefix)) === 0) {
                    $onForm = true;
                    break;
                }
            }
            if (!$onForm) {
                return false;
            }
        }
        return true;
    }

    /**
     * Update/create one or multiple entry
     *
     * @param array $request
     * @param QueryBuilder $QueryBuilder
     * @return array
     */
    public function setJson($request, $QueryBuilder = null)
    {

        $this->response = [];
        $this->response['status'] = 'failure';

        // Optional per-request locale for i18n columns: scope the write to one
        // language instead of fanning out to every supported locale. The raw
        // HTTP API merges client query/body keys into $request (RouteHelper),
        // so an unvalidated value would let any authenticated writer create
        // translation rows under arbitrary locale strings — reject it here,
        // not only in the MCP tools.
        $this->i18nWriteLocale = isset($request['lang']) && $request['lang'] !== ''
            ? (string) $request['lang']
            : null;
        if ($this->i18nWriteLocale !== null
            && !self::isAllowedI18nLocale($this->i18nWriteLocale, $_SESSION[_AUTH_VAR]->config['locale']['supported_locale'] ?? null)) {
            $this->response['error'] = "Unsupported lang '{$this->i18nWriteLocale}'";
            return $this->response;
        }

        #one entry, or multiple with querybuilder

        $data = $this->filterRequest($request['data']);

        // Re-attach the body PK (stripped by filterRequest) for update-target
        // resolution only — setEntry resolves it through the ACL filter and
        // setColumn never writes it. The body key may be raw (id_<table>) or
        // already camelized; match on its camelized form.
        $pkKey = 'Id' . $this->tablename;
        if (is_array($request['data'])) {
            foreach ($request['data'] as $k => $v) {
                if (\camelize($k, true) === $pkKey) {
                    $data[$pkKey] = $v;
                    break;
                }
            }
        }

        if (empty($data)) {
            $this->response['error'] = "Wrong input 1007, nothing found to update";
            return $this->response;
        }

        // SECURITY: write operations ALWAYS run authorize(), regardless of
        // rbac_public. A Public+Allow api_rbac rule (e.g. the public Authy/auth
        // login) may waive owner/tenant ACL on READS only — it must never waive
        // authorization on create/update/delete. The router dispatches
        // Authy/auth/<id> to generic CRUD (setJson) inheriting rbac_public=='passed';
        // without this an unauthenticated caller could create/overwrite rows.
        if ($request["action"] == 'update' || ($QueryBuilder !== null || ($QueryBuilder === null && is_array($request['data']['query'] ?? null)))) {
            $acl = $this->authorize($this->tablename, 'w');
        } else {
            $acl = $this->authorize($this->tablename, 'a');
        }

        if (!$acl) {
            $this->response['error'] = "Permission denied";
            return $this->response;
        }

        $DataObj = null;
        if ($QueryBuilder !== null || ($QueryBuilder === null && is_array($request['data']['query'] ?? null)) || $request["action"] == 'update') {
            if (!empty($request['data']['query']['select'])) {
                $this->response['error'] = "Do not use 'select' when updating";
                return $this->response;
            }
            // Use Query Builder
            $ModelQuery = $this->queryObjName::create();
            $ModelQuery = $this->setAclFilter($ModelQuery);
            $QueryBuilder = new \ApiGoat\Api\QueryBuilder($ModelQuery, $request);
            $DataObj = $QueryBuilder->getDataObj();
            if ($QueryBuilder->getMessages()) {
                $this->response['messages'][] = $QueryBuilder->getMessages();
            }

            if ($QueryBuilder->debug) {
                $this->response['debug'] = $QueryBuilder->getDebug();
            }
        }

        if (!empty($this->response['messages'])) {
            $this->response['messages'][] = "Query builder warning are blocking the create/update";
            return $this->response;
        }

        if (is_array($request)) {

            if ($DataObj instanceof PropelCollection) {
                $count = $DataObj->count();
                if ($count > 0) {
                    foreach ($DataObj as $Obj) {
                        if (!empty($data)) {
                            $data["Id{$this->tablename}"] = $Obj->getPrimaryKey();
                            $this->setEntry($data, $Obj);
                        } else {
                            $this->response['error'] = "Wrong input 1004, nothing found to update";
                        }
                    }
                } else {
                    $this->response['messages'][] = "Found no result for this query";
                }
            } else {
                if (!empty($data)) {
                    $this->setEntry($data, $DataObj);
                } else {
                    $this->response['error'] = "Wrong input 1003, nothing found to update";
                    $this->response['messages'][] = $data;
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
     * @return array
     */
    public function getJson($data, $QueryBuilder = null)
    {

        if ($data['rbac_public'] != 'passed') {
            $acls = $this->authorize($this->tablename, 'r');
            if (!$acls) {
                $this->response['status'] = "failure";
                $this->response['error'] = "Permission denied";
                return $this->response;
            }
        }

        if ($QueryBuilder === null) {
            $ModelQuery = $this->queryObjName::create();
            $ModelQuery = $this->setAclFilter($ModelQuery);
            $QueryBuilder = new \ApiGoat\Api\QueryBuilder($ModelQuery, $data);
        }

        try {
            // Add a global settings permit whole object
            /* if (!$QueryBuilder->selectIsSet()) {
                $ret['status'] = 'failure';
                $ret['error'] = "Invalid parameter: Select * are not allowed. Use 'select' key in your query parameter to select some columns. ";
                return $ret;
            }*/
            if ($QueryBuilder->getDataObj()) {
                $Data = $QueryBuilder->getData();
            }

            $ret['messages'][] = $QueryBuilder->getMessages();
        } catch (\Exception $x) {
            $ret['status'] = 'failure';
            $ret['error'] = "Invalid parameter 4: " . $x->getMessage();
            return $ret;
        }

        try {
            if (is_array($Data) && count($Data) == 0) {
                $ret['status'] = 'success';
                $ret['data'] = [];
                $ret['count'] = 0;
            } elseif (is_array($Data)) {
                $ret['status'] = 'success';
                // if select is set, lower case field name is ok
                $ret['data'] = $this->stripSensitiveOutput($Data); // review M1
                $ret['count'] = count($Data);
            } else {
                $ret['status'] = 'failure';
                $ret['error'] = $QueryBuilder->getMessages();
            }
        } catch (\Exception $x) {
            $ret['status'] = 'failure';
            $ret['error'] = "Invalid parameter 1: " . $x->getMessage();
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
     * @return array
     */
    public function getOneJson($data)
    {

        if ($data['rbac_public'] != 'passed') {
            $acls = $this->authorize($this->tablename, 'r');
            if (!$acls) {
                $ret['status'] = "failure";
                $ret['error'] = "Permission denied";
                return $ret;
            }
        }

        try {
            // ACL-filter the single-row read so it can't reach rows outside the
            // caller's tenant / Owner / Group scope (IDOR guard), matching
            // getJson/deleteJson.
            $QueryBuilder = new \ApiGoat\Api\QueryBuilder($this->setAclFilter($this->queryObjName::create()), $data);


            $obj = $QueryBuilder->Query->findOne();
            if (!$obj) {
                $ret['status'] = 'success';
                $ret['data'] = [];
            } else {
                $ret['status'] = 'data';
                $ret['data'] = $this->stripSensitiveOutput($obj->toArray()); // review M1
            }
            return $ret;
        } catch (\Exception $x) {
            $ret['status'] = 'failure';
            $ret['error'] = "Invalid parameter 2: " . $x->getMessage();
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

        // SECURITY: delete ALWAYS runs authorize(), regardless of rbac_public.
        // Public status may waive owner/tenant ACL on READS only, never on
        // create/update/delete (see setJson).
        $acls = $this->authorize($this->tablename, 'd');

        if (!$acls) {
            $this->response['error'] = "Permission denied";
            return $this->response;
        }

        try {
            if ($QueryBuilder === null) {
                $ModelQuery = $this->queryObjName::create();
                $ModelQuery = $this->setAclFilter($ModelQuery);
                $QueryBuilder = new \ApiGoat\Api\QueryBuilder($ModelQuery, $data);
            }
            $obj = $QueryBuilder->getDataObj();
            if ($QueryBuilder->getMessages()) {
                $ret['error'] = $QueryBuilder->getMessages();
            }


            if (!$obj) {
                $ret['status'] = 'failure';
                $ret['error'] = 'Entry not found';
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
                $ret['error'] = "Some not deleted";
            } else {
                $ret['status'] = 'failure';
                $ret['error'] = "Invalid parameter 3: " . $x->getMessage();
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
     * @param string or Object $DataObj
     * @return void
     */
    private function setEntry($data, $DataObj = null)
    {
        $isNew = false;
        $extValidationError = [];
        $error = [];

        if (!isset($data["Id{$this->tablename}"])) {
            $this->response['debug'][] = "Create {$this->tablename}";
            $className = "App\\" . $this->tablename;
            $obj = new $className;
            $obj->setNew(true);
            $isNew = true;
            // Tenant-scoped models: a non-root user always creates rows in their
            // own tenant (IdTenant is denylisted from the body, so this can't be
            // overridden by mass-assignment).
            if (method_exists($obj, 'setIdTenant')
                && ! $_SESSION[_AUTH_VAR]->get('isRoot')
                && $_SESSION[_AUTH_VAR]->get('id_tenant')) {
                $obj->setIdTenant($_SESSION[_AUTH_VAR]->get('id_tenant'));
            }
        } elseif (!($DataObj instanceof PropelCollection)) {
            $this->response['debug'][] = "Update {$this->tablename}";
            // ACL-filter the target resolution: a body-supplied PK resolves
            // only to rows the caller is allowed to access (Owner/Group ACL +
            // tenant), closing the cross-row/cross-tenant overwrite (write-IDOR).
            // Must go through filterByPrimaryKey()->findOne(), NOT findPk():
            // findPk() on a simple PK uses findPkSimple()/the instance pool,
            // which build raw SQL and BYPASS the conditions setAclFilter() just
            // added (and the GoatCheese tenant behavior). findOne() runs through
            // doSelect() so the ACL/tenant filters actually apply. See
            // AuthySession::loadPkScoped for the same reasoning.
            $obj = $this->setAclFilter($this->queryObjName::create())
                ->filterByPrimaryKey($data["Id{$this->tablename}"])
                ->findOne();
        } else {
            $this->response['debug'][] = "Update {$this->tablename}";
            $obj = $DataObj;
        }

        if ($obj) {

            /**
             * Hook method for ApiGoat.
             *
             * Signature MUST match the generated service convention
             * (see Built/{Model}Service::saveUpdate):
             *   beforeSave($obj, &$data, $isNew, &$messages, &$extValidationErr, &$error)
             * The API path used to pass only 5 args, so EVERY wrapper following
             * the generated 6-param convention fataled with ArgumentCountError —
             * surfacing as an opaque -32603 on all crm_update/crm_create MCP
             * calls for entities with a beforeSave hook. $hookError is carried
             * for signature parity; the API envelope reports errors through
             * $extValidationError / exceptions, same as before.
             */
            $hookError = null;
            if (!isset($this->response['messages'])) {
                $this->response['messages'] = null;
            }
            if (\method_exists($this->ServiceWrapper, 'beforeSave')) {
                $this->ServiceWrapper->beforeSave($obj, $data, $isNew, $this->response['messages'], $extValidationError, $hookError);
            }

            if (!$extValidationError) {
                // add_i18n proxy columns are applied per-locale (and kept out
                // of colsToValidate — they are not columns of the main map).
                $i18nData = array_intersect_key($data, array_flip($this->i18nColumns()));
                $this->setColumn($obj, array_diff_key($data, $i18nData));
                $this->applyI18n($obj, $i18nData);

                if (!$this->validateSave($obj)) {
                    return false;
                }
            } else {
                $PropelErrorHandler = new PropelErrorHandler($obj);
                $PropelErrorHandler->setExtendedValidationFailures($extValidationError);
                $validationErrors = $PropelErrorHandler->getValidationErrorsArray();
                $this->response['messages'] = $validationErrors['messages'];
                $this->response['error'] = "Validation error";
                $this->response['data'] = $validationErrors['columns'];
                $this->response['status'] = 'failure';
                return false;
            }

            /**
             * Hook method for ApiGoat — same generated convention:
             *   afterSave($obj, &$data, $isNew, &$messages, &$extValidationErr, &$error)
             * The old call shuffled the tail args (error into the messages slot,
             * the request data into extValidationErr, an always-empty array as
             * $data), so wrappers reading $data in afterSave saw [] on the API
             * path and messages/error never round-tripped.
             */
            if (\method_exists($this->ServiceWrapper, 'afterSave')) {
                $this->ServiceWrapper->afterSave($obj, $data, $isNew, $this->response['messages'], $extValidationError, $hookError);
            }
        } else {
            $this->response['error'] = "Entry not found";
            return false;
        }
        return $obj;
    }

    /**
     * Whether $lang may scope an i18n read/write: a member of the configured
     * supported_locale list, or — when the project carries no locale config —
     * a well-formed ll_CC tag (never an arbitrary string into setLocale()).
     *
     * @param string $lang
     * @param mixed $supported config['locale']['supported_locale'] or null
     * @return bool
     */
    public static function isAllowedI18nLocale($lang, $supported)
    {
        if (is_array($supported) && $supported !== []) {
            return in_array($lang, $supported, true);
        }
        return (bool) preg_match('/^[a-z]{2}_[A-Z]{2}$/', (string) $lang);
    }

    /**
     * Write add_i18n proxy columns across every supported locale, mirroring
     * the generated form path (updatei18n) and DetailCopier: text supplied
     * through the locale-less API/MCP surface must print whatever language
     * the document renders in. Per-locale content stays the admin form's
     * job (its per-locale {Table}I18n_{Col}_{locale} keys). The translation
     * rows persist with the main object's save() (Propel i18n behavior).
     *
     * @param object $obj Propel model with the i18n behavior
     * @param array $i18nData camelized i18n column => value
     * @return void
     */
    private function applyI18n($obj, $i18nData)
    {
        if (!$i18nData || !method_exists($obj, 'setLocale')) {
            return;
        }
        if ($this->i18nWriteLocale !== null) {
            $locales = [$this->i18nWriteLocale]; // per-request locale-scoped write
        } else {
            $locales = $_SESSION[_AUTH_VAR]->config['locale']['supported_locale'] ?? null;
            if (!is_array($locales) || $locales === []) {
                $locales = [null]; // no locale config: write the behavior's default locale
            }
        }
        $origLocale = method_exists($obj, 'getLocale') ? $obj->getLocale() : null;
        foreach ($locales as $locale) {
            if ($locale !== null) {
                $obj->setLocale((string) $locale);
            }
            foreach ($i18nData as $col => $val) {
                $setter = 'set' . $col;
                if (method_exists($obj, $setter)) {
                    $obj->$setter($val);
                } else {
                    $this->response['messages'][] = 'Unknown column ' . $col;
                }
            }
        }
        if ($origLocale !== null) {
            $obj->setLocale($origLocale);
        }
    }

    /**
     * Set the value of a column
     *
     * @param PropelCollection object classe $obj
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
                $this->response['messages'][] = 'Unknown column ' . $columns;
            else {
                $obj->$setStr($value);
                $this->colsToValidate[] = $columns;
                $ret['count']++;
            }
        } else {
            foreach ($columns as $key => $val) {
                // Defensive: never write the PK as a column value even if it
                // slips through (it is only an update-target selector).
                if ($key === 'Id' . $this->tablename) {
                    continue;
                }
                if ($key) {
                    $setStr = "set" . $key;
                    if (!method_exists($obj, $setStr)) {
                        $this->response['messages'][] = 'Unknown column ' . $key;
                    } else {
                        $obj->$setStr($val);
                        $this->colsToValidate[] = $key;
                        $this->response['status'] = 'success';
                    }
                } else {
                    $this->response['messages'][] = 'Missing value for column';
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
        if ($obj->validate($this->colsToValidate)) {
            $obj->save();
            $this->response['ids'][] = $obj->getPrimaryKey();
            $this->response['status'] = 'success';
            return true;
        } else {
            # exception
            $PropelErrorHandler = new PropelErrorHandler($obj);
            $validationErrors = $PropelErrorHandler->getValidationErrorsArray();
            $this->response['messages'] = $validationErrors['messages'];
            $this->response['data'] = $validationErrors['columns'];
            $this->response['error'] = "Validation error";
            $this->response['status'] = 'failure';
            return false;
        }
    }
}
