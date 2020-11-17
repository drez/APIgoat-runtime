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

    const LIMIT = 100;

    public $debug = false;
    private $info = [];
    private $Query;
    private $data;
    private $objectName;
    private $Data;
    private $message;

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

        $this->setDebug($request['debug']);
        $this->setQueryObject($query);

        $this->buildQuery();
        $this->runQuery();
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
        return $this->DataObj;
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

    private function setSelect(array $selectRequest)
    {
        foreach ($selectRequest as $select) {
            if (is_array($select)) {
                // Foreign column
                if (count($select) !== 2) {
                    throw new InvalidArgumentException("QueryBuilder: Select parameters incorrect.");
                }

                $this->Query->withColumn($select[0], $select[1]);
                $selectVal[] = $select[1];
                $this->selectKey[] = $select[1];
            } else {

                //$this->Query->withColumn(\camelize($select), unCamelize($select));
                $selectVal[] = $select;
                $this->selectKey[] = $select;
            }
        }

        if (is_array($selectVal)) {
            $this->selectSet = true;
            $this->Query->select($selectVal);
        }
    }

    private function setFilters(array $filtersRequest)
    {
        $singleFilter = false;
        foreach ($filtersRequest as $table => $filters) {

            $useQuery = '';

            $Table = \camelize($table, true);
            $Class = "App\\" . $Table;

            if ($Class != $this->modelName) {
                $useQuery = "use" . $Table . "Query";
            }

            if (empty($useQuery) || method_exists($this->Query, $useQuery)) {
                foreach ($filters as $filter) {
                    $addOr = false;
                    $filter[1] = ($filter[1] == 'null') ? null : $filter[1];
                    $filterStr = "filterBy" . \camelize($filter[0], true);

                    if (\strstr($filter[1], "%")) {
                        $criteria = \Criteria::LIKE;
                    }

                    if (method_exists($this->Query, $filterStr)) {
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

                        if ($Class != $this->modelName) {
                            $this->Query->$useQuery()
                                ->$filterStr($filter[1], $criteria)
                                ->endUse();
                        } else {
                            $this->Query->$filterStr($filter[1], $criteria);
                            if ($addOr) {
                                $this->Query->_or();
                            }
                        }
                    } else {
                        $this->messages[] = "Field ({$filter[0]}) not found";
                    }
                }
            } else {
                $this->messages[] = "Table ({$table}) not found";
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

    private function setJoins(array $joinsRequest)
    {
        if ($joinsRequest) {
            foreach ($joinsRequest as $join) {
                if (is_array($join)) {
                    if (count($join) !== 2) {
                        throw new InvalidArgumentException("QueryBuilder: Join parameters incorrect.");
                    }
                    $criteria = \Criteria::LEFT_JOIN;
                    switch ($join[1]) {
                        case 'LEFT':
                            $criteria = \Criteria::LEFT_JOIN;
                            break;
                        case 'RIGHT':
                            $criteria = \Criteria::RIGHT_JOIN;
                            break;
                    }
                    $this->Query->join(\camelize($join[0], true), $criteria);
                } else {
                    if (!empty($join)) {
                        $this->Query->leftJoin(\camelize($join, true));
                    }
                }
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
        if ($this->request['select']) {
            $this->setSelect($this->request['select']);
        }

        if ($this->primaryKey) {
            $this->Query->filterByPrimaryKey($this->primaryKey);
        }

        if ($this->request['filter']) {
            $this->setFilters($this->request['filter']);
        }

        if ($this->request['join']) {
            $this->setJoins($this->request['join']);
        }

        if ($this->request['f']) {
            $filterStr = "filterBy" . $this->request['f'];
            if (method_exists($this->Query, $filterStr)) {
                if ($this->request['fo']) {
                    switch ($this->request['fo']) {
                        case 'NE':
                            $this->Query->$filterStr($this->request['i'], \Criteria::NOT_EQUAL);
                            break;
                        case 'LT':
                            $this->Query->$filterStr($this->request['i'], \Criteria::LESS_THAN);
                            break;
                        case 'GT':
                            $this->Query->$filterStr($this->request['i'], \Criteria::GREATER_THAN);
                            break;
                    }
                } else
                    $this->Query->$filterStr($this->request['i']);
            }
        }

        if ($this->request['order']) {
            foreach ($this->request['order'] as $order) {
                if ($order[1]) {
                    $this->Query->orderBy($order[0], $order[1]);
                }
            }
        }

        if ($this->debug) {
            $this->info['query'] = $this->Query->toString();
        }

        if ($this->request['limit']) {
            $this->request['limit'] = $this->validateLimit($this->request['limit']);
            $this->Query->limit($this->request['limit']);
        }
    }

    /**
     * Run the preset query, find or paginate
     *
     * @return Array
     */
    private function runQuery()
    {
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

        return $this->Data;
    }

    /**
     * Clean the Data array from unwanted key
     * and set the ENUM values
     *
     * @return void
     */
    private function correctData()
    {
        if (is_object($this->Data) && get_class($this->Data) == 'PropelObjectCollection') {
            foreach ($this->Data as $obj) {
                $data[] = $obj->toArray(\BasePeer::TYPE_FIELDNAME);
            }
            $this->Data = $data;
        } elseif (!is_array($this->Data)) {
            $this->Data = $this->Data->toArray(\BasePeer::TYPE_FIELDNAME);
        }

        if ($this->selectIsSet() && is_array($this->Data)) {

            // for each Selected column
            foreach ($this->Query->getSelect() as $phpName) {
                $col = $this->getColumnFromName($this->Query, $phpName);
                if (isset($col) && $col->getType() == 'ENUM') {
                    // collect required ENUM valueSet
                    $enumVal[uncamelize($phpName)] = $col->getValueSet();
                }
            }

            foreach ($this->Data as &$row) {
                foreach ($row as $key => &$value) {
                    if (!in_array($key, $this->selectKey)) {
                        // remove unwanted Key
                        unset($row[$key]);
                    } elseif (isset($enumVal[$key])) {
                        // Set the ENUM value
                        $value = $enumVal[$key][$value];
                    }
                }
            }
        }
    }

    private function validateLimit($value)
    {
        if (v::alnum()->noWhitespace()->length(1, 100)->validate($value)) {
            return $value;
        } else {
            return LIMIT;
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
            throw new \PropelException(sprintf('Unknown column "%s" on model, alias or table "%s"', $phpName, $prefix));
        }
    }
}
