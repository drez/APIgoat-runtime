<?php
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


namespace ApiGoat\Api;

use Criteria;
use Exception;
use Respect\Validation\Validator as v;
use Psr\Log\InvalidArgumentException;

/**
 * Set the specified Propel Query object 
 *
 * @author sysadmin
 */
class QueryBuilder
{
    const _DEFAULT_LIMIT = 30;
    public $debug = false;
    private $info = [];
    public $Query;
    private $data;
    private $objectName;
    private $modelName;
    private $Data;
    private $message;
    private $isInfo = false;
    private $tableAliases = [];
    private $request;
    private $primaryKey;
    private $messages;
    private $DataObj;
    private $selectKeyMap;

    /**
     * Request Select key
     *
     * @var array
     */
    private $selectKey;

    /**
     * fields selection is required for get
     *
     * @var bool
     */
    private $selectSet = false;


    public function __construct(Object $query, $request)
    {
        $this->modelName = \str_replace("Query", '', \get_class($query));
        $this->primaryKey = $request['i'];
        // Composite primary key (M2M cross-ref tables): the REST id segment
        // carries the pk parts either as a JSON array ([2,1]) or comma-joined
        // (2,1 — URL-benign: encoded brackets never reach PHP behind
        // mod_proxy_fcgi). Decode so filterByPrimaryKey() receives the array
        // Propel expects; a scalar id keeps its original string form.
        if (\is_string($this->primaryKey) && $this->primaryKey !== '') {
            if ($this->primaryKey[0] === '[') {
                $decoded = \json_decode($this->primaryKey, true);
                if (\is_array($decoded)) {
                    $this->primaryKey = $decoded;
                }
            } elseif (\strpos($this->primaryKey, ',') !== false) {
                $this->primaryKey = \explode(',', $this->primaryKey);
            }
        }
        $this->setRequest($request);

        if (isset($request['data']['debug'])) {
            $this->setDebug($request['data']['debug']);
        }

        $this->setQueryObject($query);

        if ($this->buildQuery() === false) {
            $this->runQuery();
        }
    }

    public function selectIsSet()
    {
        return $this->selectSet;
    }

    function getMessages()
    {
        return $this->messages;
    }

    public function setDebug($debug)
    {
        // SECURITY (review R2): a client-supplied debug flag echoes the rendered
        // SQL (including the id_tenant value) back in the response. Only honor it
        // when the app is in dev mode; ignore it in prod (fail-closed).
        if ($debug && \defined('app_status') && \app_status === 'dev') {
            $this->debug = true;
        }
    }

    public function setRequest(array $request)
    {
        # validate and sanitize
        if (is_array($request['normalized_query'] ?? null)) {
            $this->request = $request['normalized_query'];
        } else {
            $this->request = $request['query'] ?? [];
        }
    }

    public function getResultingQuery()
    {
        return $this->Query;
    }

    public function getData()
    {
        return $this->Data;
    }

    public function getDataObj()
    {
        return ($this->DataObj) ? $this->DataObj : $this->isInfo();
    }

    public function setQueryObject($query)
    {
        if (\is_object($query)) {
            $this->objectName = get_class($query);
            $this->Query = $query;
        } else {
            throw new Exception("Bad object");
        }
    }

    private function setGroupby($groupbys)
    {
        foreach ($groupbys as $groupby) {
            if (strpos($groupby, '.') !== false) {
                $part = explode('.', $groupby);
                if (array_key_exists($part[0], $this->tableAliases)) {
                    $groupby = $part[0] . "." . $groupby = camelize($part[1], true);
                } else {
                    $groupby = camelize($groupby, true);
                }

                $this->Query->groupBy($groupby);
            }
        }
    }

    /**
     * Use passed request parameters Query to build a SQL query
     *
     * @return bool
     */
    public function buildQuery()
    {

        if (!empty($this->request['info'])) {
            if ($this->setInfo()) {
                return true;
            }
        }

        if (\is_numeric($this->primaryKey)) {
            $this->Query->filterByPrimaryKey($this->primaryKey);
            return false;
        }

        if (!empty($this->request['join'])) {
            if ($this->setJoins($this->request['join'])) {
                return true;
            }
        }

        if (!empty($this->request['select'])) {
            // From a query string the select arrives as its raw JSON text — and,
            // behind a rewrite that re-escapes the query, still percent-encoded
            // (see decodeJsonParam). A silently-dropped select turned the
            // dashboard's SUM() aggregates into unfiltered full-table reads.
            $select = self::decodeJsonParam($this->request['select']);
            if (\is_array($select) && $this->setSelect($select)) {
                return true;
            }
        }

        if ($this->primaryKey) {
            $this->Query->filterByPrimaryKey($this->primaryKey);
        }

        if (!empty($this->request['filter'])) {
            $this->setFilters($this->request['filter']);
        }

        if (!empty($this->request['groupby'])) {
            if ($this->setGroupby($this->request['groupby'])) {
                return true;
            }
        }

        if (!empty($this->request['order'])) {
            foreach ($this->request['order'] as $order) {
                if ($order[1]) {
                    if (strpos($order[0], '.') !== false) {
                        $order[0] = camelize($order[0], true);
                    }
                    $this->Query->orderBy($order[0], $order[1]);
                }
            }
        }

        if ($this->debug) {
            $this->info['query'] = $this->Query->toString();
        }

        $this->request['limit'] = $this->validateLimit($this->request['limit'] ?? null);
        $this->Query->limit($this->request['limit']);

        if (!empty($this->request['dontrun'])) {
            return true;
        }

        return false;
    }

    /**
     * Run the preset query, find or paginate
     *
     * @return array
     */
    private function runQuery()
    {
        if (!$this->isInfo()) {
            if (!empty($this->request['page'])) {
                $this->request['max_page'] = !empty($this->request['max_page']) ? $this->request['max_page'] : 50;
                $pmpo = $this->Query->paginate($this->request['page'], $this->request['max_page']);
                // Symmetry with the find() branch below: expose the result collection via
                // DataObj too. Api::getJson() gates data extraction on getDataObj(), so
                // without this every paginated list fell through to a "failure" response
                // (getDataObj() was empty for paginated queries). correctData() then
                // processes $this->Data as usual.
                $this->DataObj = $pmpo->getResults();
                $this->Data = $this->DataObj;
            } else {
                $this->DataObj = $this->Query->find();
                if (!$this->selectSet && !$this->primaryKey
                    && $this->DataObj instanceof \PropelObjectCollection) {
                    // Hand the hydrated collection straight to correctData()'s
                    // single-pass fast path — toArray() here would collapse it to a
                    // PhpName-keyed array and force the legacy remap second pass.
                    $this->Data = $this->DataObj;
                } elseif (!is_array($this->DataObj)) {
                    $this->Data = $this->DataObj->toArray();
                }
            }

            $this->correctData();
        }


        return $this->Data;
    }

    private function isInfo()
    {
        return $this->isInfo;
    }

    private function setInfo()
    {
        $this->isInfo = true;
        $peerName = $this->modelName . "Peer";
        $TableMap = $peerName::getTableMap();
        $relations = $TableMap->getRelations();

        $this->Data = [$this->modelName => [
            "relations" => $relations
        ]];
        return true;
    }

    /**
     * Allowlist a client-supplied select-clause expression before it reaches
     * Propel withColumn() (which emits unrecognized tokens as raw SELECT SQL).
     * Accepts a bare/qualified identifier or a single safe aggregate over one;
     * rejects subqueries, stacked queries, comments, functions, and '*'.
     *
     * @param string $clause
     * @return boolean
     */
    public static function isSafeSelectClause($clause)
    {
        $clause = trim((string) $clause);
        if ($clause === '') {
            return false;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $clause)) {
            return true;
        }
        if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\(\s*(DISTINCT\s+)?[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?\s*\)$/i', $clause)) {
            return true;
        }
        return false;
    }

    private function setSelect(array $selectRequest)
    {
        foreach ($selectRequest as $select) {
            if (is_array($select)) {
                // Foreign column
                if (count($select) !== 2) {
                    $this->messages[] = "Select: Parameters incorrect.";
                    return true;
                }

                if (!self::isSafeSelectClause($select[0])) {
                    $this->messages[] = "Select: column expression not allowed.";
                    return true;
                }
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $select[1])) {
                    $this->messages[] = "Select: alias not allowed.";
                    return true;
                }

                if (strpos($select[0], '.') !== false) {
                    $select[0] = camelize($select[0], true);
                }

                $this->Query->withColumn($select[0], $select[1]);
                $selectVal[] = $select[1];
                $this->selectKey[] = $select[1];
            } else {

                $orig = $select;
                if (strpos($select, '.') !== false) {
                    $part = explode('.', $select);
                    if (array_key_exists($part[0], $this->tableAliases)) {
                        $select = $part[0] . "." . $select = camelize($part[1], true);
                    } else {
                        $select = camelize($select, true);
                    }

                    $this->selectKeyMap[$select] = $orig;
                }
                $selectVal[] = $select;
                $this->selectKey[] = $select;
            }
        }

        if (is_array($selectVal)) {
            $this->selectSet = true;
            $this->Query->select($selectVal);
        }
        return false;
    }

    private function getUseClause($Class, $Table, $table)
    {
        $useQuery = '';
        if ($Class != $this->modelName) {
            if (array_key_exists($table, $this->tableAliases)) {
                $useQuery = "use" .  $this->tableAliases[$table] . "Query";
            } else {
                $useQuery = "use" . $Table . "Query";
            }
        }

        return $useQuery;
    }

    /**
     * Decode a JSON query param (filter / select), or NULL when it cannot be read.
     *
     * A web server may hand PHP the param STILL PERCENT-ENCODED: prod's root
     * .htaccess rewrites `^(.*)$ -> /.admin/$1` and the internal redirect escaped
     * the query string a second time, so `filter[Project]` arrived as the literal
     * text '%5B%5B%22state%22...' instead of '[["state",...]]'. json_decode()
     * returned null, the caller coerced that to [[]] — a filter row with NO column
     * — and Propel threw "Unknown column  in model App\Project": a 500 HTML page
     * for every filtered request (dashboard KPIs, list search, every child list),
     * which the mobile client could only report as "JSON Parse error: Unexpected
     * character: <". Dev has no such rewrite, so dev never reproduced it.
     *
     * Retry once through urldecode() so the API survives any such proxy, and
     * return NULL (never an empty row) when the param is genuinely unreadable.
     */
    public static function decodeJsonParam($raw)
    {
        if (is_array($raw)) {
            return $raw ?: null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Only a re-escaped payload reaches here; a valid JSON string decoded above.
            $decoded = json_decode(urldecode($raw), true);
        }
        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    private function setFilters(array $filtersRequest)
    {
        foreach ($filtersRequest as $table => $filters) {

            $Table = \camelize($table, true);
            $Class = "App\\" . $Table;

            $useQuery = null;
            $lastUseQuery = null;
            //$useQueryDefault = $this->getUseClause($Class, $Table, $table);

            if (empty($useQuery) || method_exists($this->Query, $useQuery)) {

                // GET path: PHP delivers filter[Model] as a JSON string (possibly
                // re-escaped by the web server — see decodeJsonParam).
                if (is_string($filters)) {
                    $filters = self::decodeJsonParam($filters);
                }

                // Unreadable filter: say so and move on. Coercing it into [[]] built
                // a column-less filterBy() and fataled the whole request.
                if (!is_array($filters) || $filters === []) {
                    $this->messages[] = "Filter: could not read filters for ({$table})";
                    continue;
                }

                if (!is_array($filters[0])) {
                    $filters = [$filters];
                }

                foreach ($filters as $filter) {

                    // A row without a column can only produce filterBy('') → a
                    // PropelException that 500s the request. Skip it.
                    if (!isset($filter[0]) || !is_string($filter[0]) || $filter[0] === '') {
                        $this->messages[] = "Filter: skipped a filter with no column on ({$table})";
                        continue;
                    }

                    $addOr = false;
                    $filter[1] = ($filter[1] ?? null) == 'null' ? null : ($filter[1] ?? null);
                    if (strpos($filter[0], '.') === false) {
                        $filterStr = "filterBy" . \camelize($filter[0], true);
                    } else {
                        // $prefix could be either class name or table name
                        list($prefix, $column) = explode('.', $filter[0]);
                        $filterStr = "filterBy" . \camelize($column, true);

                        $fTable = \camelize($prefix, true);
                        $fClass = "App\\" . $fTable;
                        $useQuery = $this->getUseClause($fClass, $fTable, $table);
                    }

                    $criteria = Criteria::EQUAL;
                    if (\is_string($filter[1]) && \strstr($filter[1], "%")) {
                        $criteria = Criteria::LIKE;
                    }

                    if (method_exists($this->Query, $filterStr) || !empty($useQuery)) {
                        switch ($filter[2] ?? null) {
                            case 'ne':
                                $criteria = Criteria::NOT_EQUAL;
                                if (is_array($filter[1])) {
                                    $criteria = Criteria::NOT_IN;
                                }
                                if (\strstr($filter[1], "%")) {
                                    $criteria = Criteria::NOT_LIKE;
                                }
                                break;
                            case 'lt':
                                $criteria = Criteria::LESS_THAN;
                                break;
                            case 'gt':
                                $criteria = Criteria::GREATER_THAN;
                                break;
                            case 'or':
                                $addOr = true;
                                break;
                            case 'like':
                                $criteria = Criteria::LIKE;
                                break;
                            case 'in':
                                $criteria = Criteria::IN;
                                if (is_string($filter[1])) {
                                    $filter[1] = explode(',', $filter[1]);
                                }
                                break;
                            case 'not_in':
                                $criteria = Criteria::NOT_IN;
                                if (is_string($filter[1])) {
                                    $filter[1] = explode(',', $filter[1]);
                                }
                                break;
                            case '>=':
                                $criteria = Criteria::GREATER_EQUAL;
                                break;
                            case '<=':
                                $criteria = Criteria::LESS_EQUAL;
                                break;
                        }

                        $filter[1] = $this->setVariablesValue($filter[1]);

                        if ($lastUseQuery && $lastUseQuery != $useQuery) {
                            $this->Query->endUse();
                            $lastUseQuery = null;
                        }

                        if ($useQuery && !$lastUseQuery) {
                            $this->Query = $this->Query->$useQuery();
                            $this->Query->$filterStr($filter[1], $criteria);
                            $lastUseQuery = $useQuery;
                        } else {
                            $this->Query
                                ->$filterStr($filter[1], $criteria);
                        }

                        if ($addOr) {
                            $this->Query->_or();
                        }
                    } else {
                        $this->messages[] = "Filter: Field ({$filter[0]}) not found";
                    }
                }

                if ($lastUseQuery) {
                    $this->Query = $this->Query->endUse();
                }
            } else {
                $this->messages[] = "Filter: Table ({$table}) not found";
            }
        }
    }

    private function setVariablesValue($filter)
    {
        if (is_string($filter)) {
            if (\preg_match('/^(\$[a-zA-Z]*).([a-zA-Z]*)/', $filter, $matches)) {
                switch ($matches[1]) {
                    case '$session':
                        $filter = $_SESSION[_AUTH_VAR]->sessVar[$matches[2]];
                        break;
                    case '$now':
                        $filter = time();
                        break;
                    case '$id':
                        $filter = $_SESSION[_AUTH_VAR]->getIdAuthy();
                        break;
                }
            }
        }
        return $filter;
    }

    /**
     * Create joins request
     * @param array $joinsRequest
     *  [join, [join, alias, type]]
     * @return bool
     */
    private function setJoins(array $joinsRequest)
    {
        if ($joinsRequest) {
            foreach ($joinsRequest as $join) {
                $alias = null;
                $joinType = Criteria::LEFT_JOIN;

                if (is_array($join)) {
                    if ($join[2] == 'right') {
                        $joinType = Criteria::RIGHT_JOIN;
                    }

                    if ($join[1]) {
                        $alias = $join[1];
                        $this->tableAliases[$alias] = $join[0];
                    }

                    $joinName = "join" . \camelize($join[0], true);
                    $this->Query->$joinName($alias);
                } else {
                    $this->Query->leftJoin(\camelize($join, true));
                }
            }
        }
        return false;
    }

    /**
     * Clean the Data array from unwanted key
     * and set the ENUM values
     *
     * @return void
     */
    private function correctData()
    {
        $collection = false;

        // Fast path — paginated, no-select: $this->Data is a PropelObjectCollection.
        // Build the final field-name-keyed rows in ONE pass over the hydrated objects.
        // This (a) fixes the prior all-null result (the collection branch keyed rows by
        // TYPE_FIELDNAME while the remap read them by PhpName), and (b) skips the
        // toArray()+re-map second pass. getter values equal the toArray() values used
        // by the legacy remap, so output is identical to the (now-correct) find() path.
        if (!$this->selectSet && !$this->primaryKey
            && is_object($this->Data) && $this->Data instanceof \PropelObjectCollection) {
            $cols = $this->Query->getTableMap()->getColumns();
            $enumVal = [];
            $rows = [];
            foreach ($this->Data as $obj) {
                $row = [];
                foreach ($cols as $Column) {
                    $getter = 'get' . $Column->getPhpName();
                    if ($Column->getType() == 'ENUM') {
                        if (!isset($enumVal[$Column->getName()])) {
                            $enumVal[$Column->getName()] = $Column->getValueSet();
                        }
                        $row[$Column->getName()] = $enumVal[$Column->getName()][$obj->$getter()] ?? $obj->$getter();
                    } else {
                        $row[$Column->getName()] = $obj->$getter();
                    }
                }
                $rows[] = $row;
            }
            $this->Data = $rows;
            return;
        }

        if (is_object($this->Data) && get_class($this->Data) == 'PropelObjectCollection') {
            foreach ($this->Data as $obj) {
                $data[] = $obj->toArray(\BasePeer::TYPE_FIELDNAME);
            }
            $this->Data = $data;
            $collection = true;
        } elseif (!is_array($this->Data)) {
            // A non-array result object here is a multi-row collection (e.g. a
            // paginated select => PropelArrayCollection). Use the default toArray()
            // (row list) — toArray(TYPE_FIELDNAME) on a PropelArrayCollection collapses
            // every row into one keyed by '' (last-row-wins). Flag it as a collection
            // so the select logic below uses the per-row path; without this it took
            // the single-row branch and unset every row, yielding an empty result.
            $this->Data = $this->Data->toArray();
            $collection = true;
        } elseif (!empty($this->Data[0])) {
            $collection = true;
        }

        if ($this->primaryKey) {
            $this->Data = $this->Data[0] ?? [];
            $collection = false;
        }

        $tableMap = $this->Query->getTableMap()->getColumns();
        $enumVal = [];
        $i = 0;
        $data = [];
        if ($this->selectIsSet() && is_array($this->Data)) {

            // for each Selected column
            foreach ($this->Query->getSelect() as $phpName) {
                $col = $this->getColumnFromName($this->Query, $phpName);
                if (isset($col) && $col->getType() == 'ENUM') {
                    // collect required ENUM valueSet
                    $enumVal[uncamelize($phpName)] = $col->getValueSet();
                }
            }

            if ($collection) {
                foreach ($this->Data as &$row) {
                    foreach ($row as $key => &$value) {
                        if (!in_array($key, $this->selectKey)) {
                            // remove unwanted Key
                            unset($row[$key]);
                        } elseif (isset($enumVal[$key])) {
                            // Set the ENUM value
                            $value = $enumVal[$key][$value];
                        }

                        if (!empty($this->selectKeyMap[$key])) {
                            $row[$this->selectKeyMap[$key]] = $row[$key];
                            unset($row[$key]);
                            $this->selectKey[] = $this->selectKeyMap[$key];
                        }
                    }
                }
            } else {
                foreach ($this->Data as $key => &$value) {
                    if (!in_array($key, $this->selectKey)) {
                        // remove unwanted Key
                        unset($this->Data[$key]);
                    } elseif (isset($enumVal[$key])) {
                        // Set the ENUM value
                        $value = $enumVal[$key][$value];
                    }

                    if (!empty($this->selectKeyMap[$key])) {
                        $this->Data[$this->selectKeyMap[$key]] = $this->Data[$key];
                        unset($this->Data[$key]);
                        $this->selectKey[] = $this->selectKeyMap[$key];
                    }
                }
            }
        } else {
            if ($collection) {

                foreach ($this->Data as $record) {

                    foreach ($tableMap as $Column) {
                        if ($Column->getType() == 'ENUM') {
                            if (!is_array($enumVal[$Column->getName()] ?? null)) {
                                $enumVal[$Column->getName()] = $Column->getValueSet();
                            }
                            $data[$i][$Column->getName()] = $enumVal[$Column->getName()][$record[$Column->getPhpName()] ?? null] ?? ($record[$Column->getPhpName()] ?? null);
                        } else {
                            $data[$i][$Column->getName()] = $record[$Column->getPhpName()] ?? null;
                        }
                    }
                    $i++;
                }
            } else {
                foreach ($tableMap as $Column) {
                    $data[$Column->getName()] = $this->Data[$Column->getPhpName()] ?? null;
                }
            }
            $this->Data = $data;
        }
    }

    private function validateLimit($value)
    {
        if (v::alnum()->noWhitespace()->length(1, 100)->validate($value)) {
            return $value;
        } else {
            return self::_DEFAULT_LIMIT;
        }
    }

    public function getDebug()
    {
        return $this->info;
    }

    /**
     * Finds a column and a SQL translation for a pseudo SQL column name
     * Respects table aliases previously registered in a join() or addAlias()
     * 
     * @param \ModelCriteria $q
     * @param string $phpName
     * @param boolean $failSilently
     * @return \ColumnMap|[]
     * 
     * @throws PropelException
     */
    private function getColumnFromName(\ModelCriteria $q, string $phpName, $failSilently = true)
    {
        if (strpos($phpName, '.') === false) {
            $prefix = $q->getModelAliasOrName();
        } else {
            // $prefix could be either class name or table name
            list($prefix, $phpName) = explode('.', $phpName);
        }

        if ($prefix == $q->getModelAliasOrName() || $prefix == $q->getTableMap()->getName()) {
            // column of the Criteria's model, or column name from Criteria's peer
            $tableMap = $q->getTableMap();
        } elseif (isset($q->joins[$prefix])) {
            // column of a relations's model
            $tableMap = $q->joins[$prefix]->getTableMap();
        } elseif ($q->hasSelectQuery($prefix)) {
            return $q->getColumnFromSubQuery($prefix, $phpName, $failSilently);
        } elseif ($failSilently) {
            return array(null, null);
        } else {
            throw new \PropelException(sprintf('Unknown model, alias or table "%s"', $prefix));
        }

        if ($tableMap->hasColumnByPhpName($phpName)) {
            $column = $tableMap->getColumnByPhpName($phpName);
            if (isset($q->aliases[$prefix])) {
                $q->currentAlias = $prefix;
            }

            return $column;
        } elseif ($tableMap->hasColumn($phpName, false)) {
            $column = $tableMap->getColumn($phpName, false);

            return $column;
        } elseif (isset($q->asColumns[$phpName])) {
            // aliased column
            return array(null, $phpName);
        } elseif ($tableMap->hasColumnByInsensitiveCase($phpName)) {
            $column = $tableMap->getColumnByInsensitiveCase($phpName);

            return $column;
        } elseif ($failSilently) {
            return null;
        } else {
            throw new \PropelException(sprintf('Normalize: Unknown column "%s" on model, alias or table "%s"', $phpName, $prefix));
        }
    }
}
