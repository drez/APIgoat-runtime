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
    private $Query;
    private $data;
    private $objectName;
    private $Data;
    private $message;
    private $isInfo = false;
    private $tableAliases = [];

    /**
     * Request Select key
     *
     * @var Array
     */
    private $selectKey;

    /**
     * fields selection is required for get
     *
     * @var Boolean
     */
    private $selectSet = false;


    public function __construct(Object $query, $request)
    {
        $this->modelName = \str_replace("Query", '', \get_class($query));
        $this->primaryKey = $request['i'];
        $this->setRequest($request);

        if(isset($request['data']['debug'])){
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
        if ($debug) {
            $this->debug = true;
        }
    }

    public function setRequest(array $request)
    {
        # validate and sanitize
        if (is_array($request['normalized_query'])) {
            $this->request = $request['normalized_query'];
        } else {
            $this->request = $request['query'];
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
        return ($this->DataObj || $this->isInfo());
    }

    public function setQueryObject($query)
    {
        if (\is_object($query)) {
            $this->objectName = get_class($query);
            $this->Query = $query;
        } else {
            throw \Exception("Bad object");
        }
    }

    private function setGroupby($groupbys){
        foreach($groupbys as $groupby){
            if (strpos($groupby, '.') !== false){
            $part = explode('.', $groupby);
            if(array_key_exists($part[0], $this->tableAliases)){
                $groupby = $part[0].".".$groupby = camelize($part[1], true);
            }else{
                $groupby = camelize($groupby, true);
            }
            
            $this->Query->groupBy($groupby);
        }
        }
    }

    /**
     * Use passed request parameters Query to build a SQL query
     *
     * @return void
     */
    public function buildQuery()
    {

        if ($this->request['info']) {
            if ($this->setInfo()) {
                return true;
            }
        }

        if (\is_numeric($this->primaryKey)) {
            $this->Query->filterByPrimaryKey($this->primaryKey);
            return false;
        }

        if ($this->request['join']) {
            if ($this->setJoins($this->request['join'])) {
                return true;
            }
        }

        if ($this->request['select']) {
            if ($this->setSelect($this->request['select'])) {
                return true;
            }
        }

        if ($this->primaryKey) {
            $this->Query->filterByPrimaryKey($this->primaryKey);
        }

        if ($this->request['filter']) {
            $this->setFilters($this->request['filter']);
        }

        if ($this->request['groupby']) {
            if ($this->setGroupby($this->request['groupby'])) {
                return true;
            }
        }
        
        if ($this->request['order']) {
            foreach ($this->request['order'] as $order) {
                if ($order[1]) {
                    if (strpos($order[0], '.') !== false){
                        $order[0] = camelize($order[0], true);
                    }
                    $this->Query->orderBy($order[0], $order[1]);
                }
            }
        }

        if ($this->debug) {
            $this->info['query'] = $this->Query->toString();
        }

        $this->request['limit'] = $this->validateLimit($this->request['limit']);
        $this->Query->limit($this->request['limit']);

        return false;
    }

    /**
     * Run the preset query, find or paginate
     *
     * @return Array
     */
    private function runQuery()
    {
        if(!$this->isInfo()){
            if ($this->request['page']) {
                $this->request['max_page'] = ($this->request['max_page']) ? $this->request['max_page'] : 50;
                $pmpo = $this->Query->paginate($this->request['page'], $this->request['max_page']);
                $this->Data = $pmpo->getResults();
            } else {
                $this->DataObj = $this->Query->find();
                if (!is_array($this->DataObj)) {
                    $this->Data = $this->DataObj->toArray();
                }
            }

            $this->correctData();
        }
        

        return $this->Data;
    }

    private function isInfo(){
        return $this->isInfo;
    }

    private function setInfo(){
        $this->isInfo = true;
        $peerName = $this->modelName."Peer";
        $TableMap = $peerName::getTableMap();
        $relations = $TableMap->getRelations();
        
        $this->Data = [$this->modelName => [
           "relations" => $relations
        ]];
        return true;
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

                if (strpos($select[0], '.') !== false){
                    $select[0] = camelize($select[0], true);
                }

                $this->Query->withColumn($select[0], $select[1]);
                $selectVal[] = $select[1];
                $this->selectKey[] = $select[1];
            } else {
                
                $orig = $select;
                if (strpos($select, '.') !== false){
                    $part = explode('.', $select);
                    if(array_key_exists($part[0], $this->tableAliases)){
                        $select = $part[0].".".$select = camelize($part[1], true);
                    }else{
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

    private function getUseClause($Class, $Table, $table){
        $useQuery = '';
        if ($Class != $this->modelName) {
            if(array_key_exists ($table, $this->tableAliases)){
                $useQuery = "use" .  $this->tableAliases[$table] . "Query";
            }else{
                $useQuery = "use" . $Table . "Query";
            }  
        }

        return $useQuery;
    }

    private function setFilters(array $filtersRequest)
    {
        $singleFilter = false;

        foreach ($filtersRequest as $table => $filters) {

            $Table = \camelize($table, true);
            $Class = "App\\" . $Table;
            
            $useQueryDefault = $this->getUseClause($Class, $Table, $table);

            if (empty($useQuery) || method_exists($this->Query, $useQuery)) {
                if(!is_array($filters[0])){
                    $filters = [$filters];
                }
                foreach ($filters as $filter) {
                    $useQuery = $useQueryDefault;
                    $addOr = false;
                    $filter[1] = ($filter[1] == 'null') ? null : $filter[1];
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
                    

                    $criteria = \Criteria::EQUAL;
                    if (\strstr($filter[1], "%")) {
                        $criteria = \Criteria::LIKE;
                    }

                    if (method_exists($this->Query, $filterStr) || !empty($useQuery)) {
                        switch ($filter[2]) {
                            case 'ne':
                                $criteria = \Criteria::NOT_EQUAL;
                                if (is_array($filter[1])) {
                                    $criteria = \Criteria::NOT_IN;
                                }
                                if (\strstr($filter[1], "%")) {
                                    $criteria = \Criteria::NOT_LIKE;
                                }
                                break;
                            case 'lt':
                                $criteria = \Criteria::LESS_THAN;
                                break;
                            case 'gt':
                                $criteria = \Criteria::GREATER_THAN;
                                break;
                            case 'or':
                                $addOr = true;
                                break;
                        }

                        $filter[1] = $this->setVariablesValue($filter[1]);

                        if ($useQuery) {
                            $this->Query->$useQuery()
                                ->$filterStr($filter[1], $criteria)
                                ->endUse();
                            $useQuery = $useQueryDefault;
                        } else {
                            $this->Query->$filterStr($filter[1], $criteria);
                            if ($addOr) {
                                $this->Query->_or();
                            }
                        }
                    } else {
                        $this->messages[] = "Filter: Field ({$filter[0]}) not found";
                    }
                }
            } else {
                $this->messages[] = "Filter: Table ({$table}) not found";
            }
        }
    }

    private function setVariablesValue($filter)
    {
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
        return $filter;
    }

    /**
     * Create joins request
     * @param array $joinsRequest
     *  [join, [join, alias, type]]
     * @return void
     */
    private function setJoins(array $joinsRequest)
    {
        if ($joinsRequest) {
            foreach ($joinsRequest as $join) {
                $alias = null;
                $joinType = \Criteria::LEFT_JOIN;

                if(is_array($join)){
                    if($join[2] == 'right'){
                        $joinType = \Criteria::RIGHT_JOIN;
                    }
                    
                    if($join[1]){
                        $alias = $join[1];
                        $this->tableAliases[$alias] = $join[0];
                    }

                    $joinName = "join".\camelize($join[0], true);
                    $this->Query->$joinName($alias, $criteria);
                }else{
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

        if (is_object($this->Data) && get_class($this->Data) == 'PropelObjectCollection') {
            foreach ($this->Data as $obj) {
                $data[] = $obj->toArray(\BasePeer::TYPE_FIELDNAME);
            }
            $this->Data = $data;
            $collection = true;
        } elseif (!is_array($this->Data)) {
            $this->Data = $this->Data->toArray(\BasePeer::TYPE_FIELDNAME);
        } elseif (!empty($this->Data[0])) {
            $collection = true;
        }

        if ($this->primaryKey) {
            $this->Data = $this->Data[0];
            $collection = false;
        }

        $tableMap = $this->Query->getTableMap()->getColumns();
        $enumVal = [];
        $i=0;$data = [];
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

                        if($this->selectKeyMap[$key]){
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

                    if($this->selectKeyMap[$key]){
                        $this->Data[$this->selectKeyMap[$key]] = $this->Data[$key];
                        unset($this->Data[$key]);
                        $this->selectKey[] = $this->selectKeyMap[$key];
                    }
                }
            }
        } else{
            if ($collection) {
                
                foreach ($this->Data as $record) {
                    
                    foreach ($tableMap as $Column) {
                        if ($Column->getType() == 'ENUM') {
                            if (!is_array($enumVal[$Column->getName()])) {
                                $enumVal[$Column->getName()] = $Column->getValueSet();
                            }
                            $data[$i][$Column->getName()] = $enumVal[$Column->getName()][$record[$Column->getPhpName()]];
                        } else {
                            $data[$i][$Column->getName()] = $record[$Column->getPhpName()];
                        }
                    }
                    $i++;
                }
            } else {
                foreach ($tableMap as $Column) {
                    $data[$Column->getName()] = $this->Data[$Column->getPhpName()];
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
     * @param String $phpName
     * @param boolean $failSilently
     * @return \Column
     * 
     * @throws PropelException
     */
    private function getColumnFromName(\ModelCriteria $q, String $phpName, $failSilently = true)
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
