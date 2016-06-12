<?php

/**
 * PDODb Class
 *
 * @category  Database Access
 * @package   PDODb
 * @author    Jeffery Way <jeffrey@jeffrey-way.com>
 * @author    Josh Campbell <jcampbell@ajillion.com>
 * @author    Alexander V. Butenko <a.butenka@gmail.com>
 * @author    Vasiliy A. Ulin <ulin.vasiliy@gmail.com>
 * @copyright Copyright (c) 2010-2016
 * @license   http://opensource.org/licenses/lgpl-3.0.html The GNU Lesser General Public License, version 3.0
 * @link      http://github.com/joshcam/PHP-MySQLi-Database-Class
 * @version   1.1.0
 */
class PDODb
{
    /**
     * Database credentials
     * @var string
     */
    private $connectionParams = [
        'type' => 'mysql',
        'host' => null,
        'username' => null,
        'password' => null,
        'dbname' => null,
        'port' => null,
        'charset' => null
    ];

    /**
     * FOR UPDATE flag
     * @var bool
     */
    private $forUpdate = false;

    /**
     * Dynamic type list for group by condition value
     * @var array
     */
    private $groupBy = [];

    /**
     * An array that holds having conditions
     * @var array
     */
    private $having = [];

    /**
     * Static instance of self
     * @var PDODb
     */
    private static $instance;

    /**
     * Is Subquery object
     * @var bool
     */
    private $isSubQuery = false;

    /**
     * An array that holds where joins
     * @var array
     */
    private $join = [];

    /**
     * Last error infromation
     * @var array
     */
    private $lastError = [];

    /**
     * Last error code
     * @var string
     */
    private $lastErrorCode = '';

    /**
     * Name of the auto increment column
     * @var string
     */
    private $lastInsertId = null;

    /**
     * The previously executed SQL query
     * @var string
     */
    private $lastQuery = '';

    /**
     * LOCK IN SHARE MODE flag
     * @var bool
     */
    private $lockInShareMode = false;

    /**
     * Should join() results be nested by table
     * @var bool
     */
    private $nestJoin = false;

    /**
     * Dynamic type list for order by condition value
     * @var array
     */
    private $orderBy = [];

    /**
     * Rows per 1 page on paginate() method
     * @var int 
     */
    private $pageLimit = 10;

    /**
     * Binded params
     * @var array
     */
    private $params = [];

    /**
     * PDO instance
     * @var PDO
     */
    private $pdo;

    /**
     * Database prefix
     * @var string
     */
    private $prefix = '';

    /**
     * Query string
     * @var string 
     */
    private $query = '';

    /**
     * The SQL query options required after SELECT, INSERT, UPDATE or DELETE
     * @var array
     */
    private $queryOptions = [];

    /**
     * Query type
     * @var string
     */
    private $queryType = '';

    /**
     * Type of returned result
     * @var string
     */
    private $returnType = PDO::FETCH_ASSOC;

    /**
     * Number of affected rows
     * @var int 
     */
    private $rowCount = 0;

    /**
     * Transaction flag
     * @var bool 
     */
    private $transaction = false;

    /**
     * Variable which holds an amount of returned rows during get/getOne/select queries with withTotalCount()
     * @var int
     */
    public $totalCount = 0;


    /**
     * Total pages of paginate() method
     * @var int
     */
    public $totalPages = 0;

    /**
     * Column names for update when using onDuplicate method
     * @var array
     */
    protected $updateColumns = null;

    /**
     * Option to use generator (yield) on get() and rawQuery methods
     * @var bool
     */
    private $useGenerator = false;

    /**
     * An array that holds where conditions
     * @var array
     */
    private $where = [];

    /**
     * @param string|array|object $type
     * @param string $host
     * @param string $username
     * @param string $password
     * @param string $dbname
     * @param int $port
     * @param string $charset
     */
    public function __construct($type, $host = null, $username = null, $password = null, $dbname = null, $port = null, $charset = null)
    {
        if (is_array($type)) { // if params were passed as array
            $this->connectionParams = $type;
        } elseif (is_object($type)) { // if type is set as pdo object
            $this->pdo = $type;
        } else {
            foreach ($this->connectionParams as $key => $param) {
                if (isset($$key) && !is_null($$key)) {
                    $this->connectionParams[$key] = $$key;
                }
            }
        }

        if (isset($this->connectionParams['prefix'])) {
            $this->setPrefix($this->connectionParams['prefix']);
        }

        if (isset($this->connectionParams['isSubQuery'])) {
            $this->isSubQuery = true;
            return;
        }

        self::$instance = $this;
    }

    /**
     * Abstraction method that will build the part of the WHERE conditions
     *
     * @param string $operator
     * @param array $conditions
     */
    private function buildCondition($operator, $conditions)
    {
        if (empty($conditions)) {
            return;
        }

        //Prepare the where portion of the query
        $this->query .= ' '.$operator;

        foreach ($conditions as $cond) {
            list ($concat, $varName, $operator, $val) = $cond;
            $this->query .= " ".$concat." ".$varName;

            switch (strtolower($operator)) {
                case 'not in':
                case 'in':
                    $comparison = ' '.$operator.' (';
                    if (is_object($val)) {
                        $comparison .= $this->buildPair("", $val);
                    } else {
                        foreach ($val as $v) {
                            $comparison .= ' ?,';
                            $this->params[] = $v;
                        }
                    }
                    $this->query .= rtrim($comparison, ',').' ) ';
                    break;
                case 'not between':
                case 'between':
                    $this->query .= " $operator ? AND ? ";
                    $this->params = array_merge($this->params, $val);
                    break;
                case 'not exists':
                case 'exists':
                    $this->query.= $operator.$this->buildPair("", $val);
                    break;
                default:
                    if (is_array($val)) {
                        $this->params = array_merge($this->params, $val);
                    } elseif ($val === null) {
                        $this->query .= ' '.$operator." NULL";
                    } elseif ($val != 'DBNULL' || $val == '0') {
                        $this->query .= $this->buildPair($operator, $val);
                    }
            }
        }
    }

    /**
     * Insert/Update query helper
     *
     * @param array $tableData
     * @param array $tableColumns
     * @param bool $isInsert INSERT operation flag
     * @throws Exception
     */
    private function buildDataPairs($tableData, $tableColumns, $isInsert)
    {
        foreach ($tableColumns as $column) {
            $value = $tableData[$column];

            if (!$isInsert) {
                if (strpos($column, '.') === false) {
                    $this->query .= "`".$column."` = ";
                } else {
                    $this->query .= str_replace('.', '.`', $column)."` = ";
                }
            }

            // Subquery value
            if ($value instanceof PDODb) {
                $this->query .= $this->buildPair("", $value).", ";
                continue;
            }

            // Simple value
            if (!is_array($value)) {
                $this->query .= '?, ';
                $this->params[] = $value;
                continue;
            }

            // Function value
            $key = key($value);
            $val = $value[$key];
            switch ($key) {
                case '[I]':
                    $this->query .= $column.$val.", ";
                    break;
                case '[F]':
                    $this->query .= $val[0].", ";
                    if (!empty($val[1])) {
                        foreach ($val[1] as $param) {
                            $this->params[] = $param;
                        }
                    }
                    break;
                case '[N]':
                    if ($val == null) {
                        $this->query .= "!".$column.", ";
                    } else {
                        $this->query .= "!".$val.", ";
                    }
                    break;
                default:
                    throw new Exception("Wrong operation");
            }
        }
        $this->query = rtrim($this->query, ', ');
    }

    /**
     * Abstraction method that will build the GROUP BY part of the WHERE statement
     *
     * @return void
     */
    protected function buildGroupBy()
    {
        if (empty($this->groupBy)) {
            return;
        }

        $this->query .= " GROUP BY ";

        foreach ($this->groupBy as $key => $value) {
            $this->query .= $value.", ";
        }

        $this->query = rtrim($this->query, ', ')." ";
    }

    /**
     * Build insert query
     *
     * @param string $tableName
     * @param array $insertData
     * @param string $operation
     * @return int|bool
     */
    private function buildInsert($tableName, $insertData, $operation)
    {
        $this->query         = $operation.implode(' ', $this->queryOptions).' INTO '.$this->getTableName($tableName);
        $this->queryType     = $operation;
        $stmt                = $this->buildQuery(null, $insertData);
        $status              = $stmt->execute();
        $this->rowCount      = $stmt->rowCount();
        $this->lastError     = $stmt->errorInfo();
        $this->lastErrorCode = $stmt->errorCode();
        $this->reset();

        if ($status && $this->pdo()->lastInsertId() > 0) {
            return (int) $this->pdo()->lastInsertId();
        }

        return $status;
    }

    /**
     * Abstraction method that will build the LIMIT part of the WHERE statement
     *
     * @param int|array $numRows Array to define SQL limit in format Array ($count, $offset)
     *                               or only $count
     * @return void
     */
    protected function _buildLimit($numRows)
    {
        if (!isset($numRows)) {
            return;
        }

        if (is_array($numRows)) {
            $this->query .= ' LIMIT '.(int) $numRows[0].', '.(int) $numRows[1];
        } else {
            $this->query .= ' LIMIT '.(int) $numRows;
        }
    }

    /**
     * Abstraction method that will build an INSERT or UPDATE part of the query
     *
     * @param array $tableData
     * @return void
     */
    private function buildInsertQuery($tableData)
    {
        if (!is_array($tableData)) {
            return;
        }

        $isInsert    = in_array($this->queryType, ['REPLACE', 'INSERT']);
        $dataColumns = array_keys($tableData);
        if ($isInsert) {
            if (isset($dataColumns[0])) $this->query .= ' (`'.implode($dataColumns, '`, `').'`) ';
            $this->query .= ' VALUES (';
        } else {
            $this->query .= " SET ";
        }

        $this->buildDataPairs($tableData, $dataColumns, $isInsert);


        if ($isInsert) {
            $this->query .= ')';
        }
    }

    /**
     * Abstraction method that will build an JOIN part of the query
     *
     * @return void
     */
    private function buildJoin()
    {
        if (empty($this->join)) {
            return;
        }

        foreach ($this->join as $data) {
            list ($joinType, $joinTable, $joinCondition) = $data;

            if (is_object($joinTable)) {
                $joinStr = $this->buildPair("", $joinTable);
            } else {
                $joinStr = $joinTable;
            }

            $this->query .= " ".$joinType." JOIN ".$joinStr.
                (false !== stripos($joinCondition, 'using') ? " " : " ON ")
                .$joinCondition;
        }
    }

    /**
     * Abstraction method that will build the LIMIT part of the WHERE statement
     *
     * @param int|array $numRows Array to define SQL limit in format Array ($count, $offset)
     *                               or only $count
     * @return void
     */
    private function buildLimit($numRows)
    {
        if (!isset($numRows)) {
            return;
        }

        if (is_array($numRows)) {
            $this->query .= ' LIMIT '.(int) $numRows[0].', '.(int) $numRows[1];
        } else {
            $this->query .= ' LIMIT '.(int) $numRows;
        }
    }

    /**
     * Helper function to add variables into the query statement
     *
     * @param array $tableData Variable with values
     */
    protected function buildOnDuplicate($tableData)
    {
        if (is_array($this->updateColumns) && !empty($this->updateColumns)) {
            $this->query .= " ON DUPLICATE KEY UPDATE ";
            if ($this->lastInsertId) {
                $this->query .= $this->lastInsertId."=LAST_INSERT_ID (".$this->lastInsertId."), ";
            }

            foreach ($this->updateColumns as $key => $val) {
                // skip all params without a value
                if (is_numeric($key)) {
                    $this->updateColumns[$val] = '';
                    unset($this->updateColumns[$key]);
                } else {
                    $tableData[$key] = $val;
                }
            }
            $this->buildDataPairs($tableData, array_keys($this->updateColumns), false);
        }
    }

    /**
     * Abstraction method that will build the LIMIT part of the WHERE statement
     *
     * @return void
     */
    private function buildOrderBy()
    {
        if (empty($this->orderBy)) {
            return;
        }

        $this->query .= " ORDER BY ";
        foreach ($this->orderBy as $prop => $value) {
            if (strtolower(str_replace(" ", "", $prop)) == 'rand()') {
                $this->query .= "RAND(), ";
            } else {
                $this->query .= $prop." ".$value.", ";
            }
        }

        $this->query = rtrim($this->query, ', ')." ";
    }

    /**
     * Helper function to add variables into bind parameters array and will return
     * its SQL part of the query according to operator in ' $operator ?' or
     * ' $operator ($subquery) ' formats
     *
     * @param string $operator
     * @param mixed $value Variable with values
     * @return string
     */
    private function buildPair($operator, $value)
    {
        if (!is_object($value)) {
            $this->params[] = $value;
            return ' '.$operator.' ? ';
        }

        $subQuery = $value->getSubQuery();
        foreach ($subQuery['params'] as $value) {
            $this->params[] = $value;
        }

        return " ".$operator." (".$subQuery['query'].") ".$subQuery['alias'];
    }

    /**
     * Abstraction method that will compile the WHERE statement,
     * any passed update data, and the desired rows.
     * It then builds the SQL query.
     *
     * @param int|array $numRows Array to define SQL limit in format Array ($count, $offset)
     *                               or only $count
     * @param array $tableData Should contain an array of data for updating the database.
     * @return PDOStatement Returns the $stmt object.
     */
    private function buildQuery($numRows, $tableData = null)
    {
        $this->buildJoin();
        $this->buildInsertQuery($tableData);
        $this->buildCondition('WHERE', $this->where);
        $this->buildGroupBy();
        $this->buildCondition('HAVING', $this->having);
        $this->buildOrderBy();
        $this->buildLimit($numRows);
        $this->buildOnDuplicate($tableData);

        if ($this->isSubQuery) {
            return;
        }

        return $this->prepare();
    }

    /**
     * Return query result
     * 
     * @param PDOStatement $stmt
     * @return array
     */
    private function buildResult($stmt)
    {
        if ($this->useGenerator) {
            return $this->buildResultGenerator($stmt);
        } else {
            return $stmt->fetchAll($this->returnType);
        }
    }

    /**
     * Return generator object
     * @param PDOStatement $stmt
     * @return Generator
     */
    private function buildResultGenerator($stmt)
    {
        while ($row = $stmt->fetch($this->returnType)) {
            yield $row;
        }
    }

    /**
     * Shutdown handler to rollback uncommited operations in order to keep
     * atomic operations sane.
     *
     * @uses pdo->rollback();
     */
    public function checkTransactionStatus()
    {
        if (!$this->transaction) {
            return;
        }
        $this->rollback();
    }

    /**
     * Transaction commit
     *
     * @uses pdo->commit();
     * @return bool
     */
    public function commit()
    {
        $result            = $this->pdo()->commit();
        $this->transaction = false;
        return $result;
    }

    /**
     * A method to connect to the database
     *
     * @throws Exception
     * @return void
     */
    public function connect()
    {
        if (empty($this->connectionParams['type'])) {
            throw new Exception('DB Type is not set.');
        }

        $connectionString = $this->connectionParams['type'].':';
        $connectionParams = ['host', 'dbname', 'port', 'charset'];

        foreach ($connectionParams as $connectionParam) {
            if (!empty($this->connectionParams[$connectionParam])) {
                $connectionString .= $connectionParam.'='.$this->connectionParams[$connectionParam].';';
            }
        }

        $connectionString = rtrim($connectionString, ';');
        $this->pdo        = new PDO($connectionString, $this->connectionParams['username'], $this->connectionParams['password']);
    }

    /**
     * Method returns a copy of a PDODb subquery object
     *
     * @return PDODb new PDODb object
     */
    public function copy()
    {
        $copy      = clone $this;
        $copy->pdo = null;
        return $copy;
    }

    /**
     * Method generates decrimental function call
     *
     * @param int $num increment by int or float. 1 by default
     * @return array
     */
    public function dec($num = 1)
    {
        if (!is_numeric($num)) {
            throw new Exception('Argument supplied to dec must be a number');
        }
        return array("[I]" => "-".$num);
    }

    /**
     * Delete query. Call the "where" method first.
     *
     * @param string  $tableName The name of the database table to work with.
     * @param int|array $numRows Array to define SQL limit in format Array ($count, $offset)
     *                               or only $count
     * @return bool Indicates success. 0 or 1.
     */
    public function delete($tableName, $numRows = null)
    {
        if ($this->isSubQuery) {
            return;
        }

        $table = $this->prefix.$tableName;

        if (count($this->join)) {
            $this->query = "DELETE ".preg_replace('/.* (.*)/', '$1', $table)." FROM ".$table;
        } else {
            $this->query = "DELETE FROM ".$table;
        }

        $stmt                = $this->buildQuery($numRows);
        $stmt->execute();
        $this->lastError     = $stmt->errorInfo();
        $this->lastErrorCode = $stmt->errorCode();
        $this->rowCount      = $stmt->rowCount();
        $this->reset();

        return ($this->rowCount > 0);
    }

    /**
     * This method is needed for prepared statements. They require
     * the data type of the field to be bound with "i" s", etc.
     * This function takes the input, determines what type it is,
     * and then updates the param_type.
     *
     * @param mixed $item Input to determine the type.
     * @return string The joined parameter types.
     */
    private function determineType($item)
    {
        switch (gettype($item)) {
            case 'NULL':
                return PDO::PARAM_NULL;
            case 'string':
                return PDO::PARAM_STR;
            case 'boolean':
                return PDO::PARAM_BOOL;
            case 'integer':
                return PDO::PARAM_INT;
            case 'blob':
                return PDO::PARAM_LOB;
            case 'double':
                return PDO::PARAM_STR;
            default:
                return PDO::PARAM_STR;
        }
    }

    /**
     * A method of returning the static instance to allow access to the
     * instantiated object from within another class.
     * Inheriting this class would require reloading connection info.
     *
     * @uses $db = PDODb::getInstance();
     * @return PDODb Returns the current instance.
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    /**
     * Method returns db error
     *
     * @return string
     */
    public function getLastError()
    {
        if (!$this->pdo) {
            return "pdo is null";
        }

        return $this->lastError;
    }

    /**
     * Method returns db error code
     *
     * @return int
     */
    public function getLastErrorCode()
    {
        return $this->lastErrorCode;
    }

    /**
     * Get last insert id
     *
     * @return int
     */
    public function getLastInsertId()
    {
        return $this->pdo()->lastInsertId();
    }

    /**
     * Method returns last executed query
     *
     * @return string
     */
    public function getLastQuery()
    {
        return $this->lastQuery;
    }

    /**
     * Get count of affected rows
     *
     * @return int
     */
    public function getRowCount()
    {
        return $this->rowCount;
    }

    /**
     * Mostly internal method to get query and its params out of subquery object
     * after get() and getAll()
     *
     * @return array
     */
    public function getSubQuery()
    {
        if (!$this->isSubQuery) {
            return null;
        }

        $val = ['query' => $this->query,
            'params' => $this->params,
            'alias' => $this->connectionParams['host']
        ];
        $this->reset();
        return $val;
    }

    /**
     * Get table name with prefix
     * 
     * @param string $tableName
     * @return string
     */
    private function getTableName($tableName)
    {
        return strpos($tableName, '.') !== false ? $tableName : $this->prefix.$tableName;
    }

    /**
     * This method allows you to specify multiple (method chaining optional) GROUP BY statements for SQL queries.
     *
     * @uses $PDODb->groupBy('name');
     * @param string $groupByField The name of the database field.
     * @return PDODb
     */
    public function groupBy($groupByField)
    {
        $groupByField    = preg_replace("/[^-a-z0-9\.\(\),_\*]+/i", '', $groupByField);
        $this->groupBy[] = $groupByField;
        return $this;
    }

    /**
     * A convenient function that returns TRUE if exists at least an element that
     * satisfy the where condition specified calling the "where" method before this one.
     *
     * @param string  $tableName The name of the database table to work with.
     *
     * @return array Contains the returned rows from the select query.
     */
    public function has($tableName)
    {
        $result = $this->getOne($tableName);
        return $result ? true : false;
    }

    /**
     * This method allows you to specify multiple (method chaining optional) AND HAVING statements for SQL queries.
     *
     * @uses $PDODb->having('SUM(tags) > 10')
     * @param string $havingProp  The name of the database field.
     * @param mixed  $havingValue The value of the database field.
     * @param string $operator Comparison operator. Default is =
     * @return PDODb
     */
    public function having($havingProp, $havingValue = 'DBNULL', $operator = '=', $cond = 'AND')
    {
        // forkaround for an old operation api
        if (is_array($havingValue) && ($key = key($havingValue)) != "0") {
            $operator    = $key;
            $havingValue = $havingValue[$key];
        }

        if (count($this->having) == 0) {
            $cond = '';
        }

        $this->having[] = array($cond, $havingProp, $operator, $havingValue);
        return $this;
    }

    /**
     * Escape harmful characters which might affect a query.
     *
     * @param mixed $value The value to escape.
     * @return string The escaped string.
     */
    public function escape($value)
    {
        return $this->pdo()->quote($value, $this->determineType($value));
    }

    /**
     * Method generates user defined function call
     *
     * @param string $expr user function body
     * @param array $bindParams
     * @return array
     */
    public function func($expr, $bindParams = null)
    {
        return ["[F]" => [$expr, $bindParams]];
    }

    /**
     * A convenient SELECT * function.
     *
     * @param string  $tableName The name of the database table to work with.
     * @param int|array $numRows Array to define SQL limit in format Array ($count, $offset)
     *                               or only $count
     * @param string $columns Desired columns
     * @return array Contains the returned rows from the select query.
     */
    public function get($tableName, $numRows = null, $columns = '*')
    {
        if (empty($columns)) {
            $columns = '*';
        }

        $column = is_array($columns) ? implode(', ', $columns) : $columns;

        $this->query = 'SELECT '.implode(' ', $this->queryOptions).' '.
            $column." FROM ".$this->getTableName($tableName);
        $stmt        = $this->buildQuery($numRows);

        if ($this->isSubQuery) {
            return $this;
        }

        $stmt->execute();
        $this->lastError     = $stmt->errorInfo();
        $this->lastErrorCode = $stmt->errorCode();
        $this->rowCount      = $stmt->rowCount();

        if (in_array('SQL_CALC_FOUND_ROWS', $this->queryOptions)) {
            $totalStmt        = $this->pdo()->query('SELECT FOUND_ROWS()');
            $this->totalCount = $totalStmt->fetchColumn();
        }

        $result = $this->buildResult($stmt);
        $this->reset();

        return $result;
    }

    /**
     * A convenient SELECT * function to get one record.
     *
     * @param string  $tableName The name of the database table to work with.
     * @param string  $columns Desired columns
     * @return array Contains the returned rows from the select query.
     */
    public function getOne($tableName, $columns = '*')
    {
        $result = $this->get($tableName, 1, $columns);

        if ($result instanceof PDODb) {
            return $result;
        }

        if ($this->useGenerator) {
            return $result->current() ? $result->current() : false;
        } else {
            return $result ? $result[0] : false;
        }
    }

    /**
     * A convenient SELECT COLUMN function to get a single column value from one row
     *
     * @param string  $tableName The name of the database table to work with.
     * @param string  $column    The desired column
     * @param int     $limit     Limit of rows to select. Use null for unlimited..1 by default
     * @return mixed Contains the value of a returned column / array of values
     */
    public function getValue($tableName, $column, $limit = 1)
    {
        $result = $this->setReturnType(PDO::FETCH_ASSOC)->get($tableName, $limit, "{$column} AS retval");

        if (!$result) {
            return null;
        }

        if ($limit == 1) {
            $current = $result[0];

            if (isset($current["retval"])) {
                return $current["retval"];
            }
            return null;
        }

        $newRes = [];
        foreach ($result as $current) {
            if (is_int($limit) && $limit-- <= 0) {
                break;
            }
            $newRes[] = $current['retval'];
        }
        return $newRes;
    }

    /**
     * Method generates incremental function call
     *
     * @param int $num increment by int or float. 1 by default
     * @throws Exception
     * @return array
     */
    public function inc($num = 1)
    {
        if (!is_numeric($num)) {
            throw new Exception('Argument supplied to inc must be a number');
        }
        return ["[I]" => "+".$num];
    }

    /**
     * Perform insert query
     * 
     * @param string $tableName
     * @param array $insertData
     * @return int
     */
    public function insert($tableName, $insertData)
    {
        return $this->buildInsert($tableName, $insertData, 'INSERT');
    }

    /**
     * Method returns generated interval function as a string
     *
     * @param string $diff interval in the formats:
     *        "1", "-1d" or "- 1 day" -- For interval - 1 day
     *        Supported intervals [s]econd, [m]inute, [h]hour, [d]day, [M]onth, [Y]ear
     *        Default null;
     * @param string $func Initial date
     * @return string
     */
    public function interval($diff, $func = "NOW()")
    {
        $types = ["s" => "second", "m" => "minute", "h" => "hour", "d" => "day", "M" => "month", "Y" => "year"];
        $incr  = '+';
        $items = '';
        $type  = 'd';

        if ($diff && preg_match('/([+-]?) ?([0-9]+) ?([a-zA-Z]?)/', $diff, $matches)) {
            if (!empty($matches[1])) {
                $incr = $matches[1];
            }

            if (!empty($matches[2])) {
                $items = $matches[2];
            }

            if (!empty($matches[3])) {
                $type = $matches[3];
            }

            if (!in_array($type, array_keys($types))) {
                throw new Exception("invalid interval type in '{$diff}'");
            }

            $func .= " ".$incr." interval ".$items." ".$types[$type]." ";
        }
        return $func;
    }

    /**
     * This method allows you to concatenate joins for the final SQL statement.
     *
     * @uses $PDODb->join('table1', 'field1 <> field2', 'LEFT')
     * @param string $joinTable The name of the table.
     * @param string $joinCondition the condition.
     * @param string $joinType 'LEFT', 'INNER' etc.
     * @throws Exception
     * @return PDODb
     */
    public function join($joinTable, $joinCondition, $joinType = '')
    {
        $allowedTypes = array('LEFT', 'RIGHT', 'OUTER', 'INNER', 'LEFT OUTER', 'RIGHT OUTER');
        $joinType     = strtoupper(trim($joinType));

        if ($joinType && !in_array($joinType, $allowedTypes)) {
            throw new Exception('Wrong JOIN type: '.$joinType);
        }

        if (!is_object($joinTable)) {
            $joinTable = $this->prefix.$joinTable;
        }

        $this->join[] = [$joinType, $joinTable, $joinCondition];

        return $this;
    }

    /**
     * Method generates change boolean function call
     *
     * @param string $col column name. null by default
     * @return array
     */
    public function not($col = null)
    {
        return ["[N]" => (string) $col];
    }

    /**
     * Method returns generated interval function as an insert/update function
     *
     * @param string $diff interval in the formats:
     *        "1", "-1d" or "- 1 day" -- For interval - 1 day
     *        Supported intervals [s]econd, [m]inute, [h]hour, [d]day, [M]onth, [Y]ear
     *        Default null;
     * @param string $func Initial date
     * @return array
     */
    public function now($diff = null, $func = "NOW()")
    {
        return ["[F]" => [$this->interval($diff, $func)]];
    }

    /**
     * This function store update column's name and column name of the
     * autoincrement column
     *
     * @param array $updateColumns Variable with values
     * @param string $lastInsertId Variable value
     * @return PDODb
     */
    public function onDuplicate($updateColumns, $lastInsertId = null)
    {
        $this->lastInsertId  = $lastInsertId;
        $this->updateColumns = $updateColumns;
        return $this;
    }

    /**
     * This method allows you to specify multiple (method chaining optional) ORDER BY statements for SQL queries.
     *
     * @uses $PDODb->orderBy('id', 'desc')->orderBy('name', 'desc');
     * @param string $orderByField The name of the database field.
     * @param string $orderByDirection Order direction.
     * @param array $customFields Fieldset for ORDER BY FIELD() ordering
     * @throws Exception
     * @return PDODb
     */
    public function orderBy($orderByField, $orderbyDirection = "DESC", $customFields = null)
    {
        $allowedDirection = ["ASC", "DESC"];
        $orderbyDirection = strtoupper(trim($orderbyDirection));
        $orderByField     = preg_replace("/[^-a-z0-9\.\(\),_`\*\'\"]+/i", '', $orderByField);

        // Add table prefix to orderByField if needed.
        //FIXME: We are adding prefix only if table is enclosed into `` to distinguish aliases
        // from table names
        $orderByField = preg_replace('/(\`)([`a-zA-Z0-9_]*\.)/', '\1'.$this->prefix.'\2', $orderByField);


        if (empty($orderbyDirection) || !in_array($orderbyDirection, $allowedDirection)) {
            throw new Exception('Wrong order direction: '.$orderbyDirection);
        }

        if (is_array($customFields)) {
            foreach ($customFields as $key => $value) {
                $customFields[$key] = preg_replace("/[^-a-z0-9\.\(\),_` ]+/i", '', $value);
            }

            $orderByField = 'FIELD ('.$orderByField.', "'.implode('","', $customFields).'")';
        }

        $this->orderBy[$orderByField] = $orderbyDirection;
        return $this;
    }

    /**
     * This method allows you to specify multiple (method chaining optional) OR HAVING statements for SQL queries.
     *
     * @uses $PDODb->orHaving('SUM(tags) > 10')
     * @param string $havingProp  The name of the database field.
     * @param mixed  $havingValue The value of the database field.
     * @param string $operator Comparison operator. Default is =
     * @return PDODb
     */
    public function orHaving($havingProp, $havingValue = null, $operator = null)
    {
        return $this->having($havingProp, $havingValue, $operator, 'OR');
    }

    /**
     * This method allows you to specify multiple (method chaining optional) OR WHERE statements for SQL queries.
     *
     * @uses $PDODb->orWhere('id', 7)->orWhere('title', 'MyTitle');
     * @param string $whereProp  The name of the database field.
     * @param mixed  $whereValue The value of the database field.
     * @param string $operator Comparison operator. Default is =
     * @return PDODb
     */
    public function orWhere($whereProp, $whereValue = 'DBNULL', $operator = '=')
    {
        return $this->where($whereProp, $whereValue, $operator, 'OR');
    }

    /**
     * Pagination wraper to get()
     *
     * @access public
     * @param string  $table The name of the database table to work with
     * @param int $page Page number
     * @param array|string $fields Array or coma separated list of fields to fetch
     * @return array
     */
    public function paginate($table, $page, $fields = null)
    {
        $offset           = $this->pageLimit * ($page - 1);
        $res              = $this->withTotalCount()->get($table, [$offset, $this->pageLimit], $fields);
        $this->totalPages = ceil($this->totalCount / $this->pageLimit);
        return $res;
    }

    /**
     * A method to get pdo object or create it in case needed
     *
     * @return PDO
     */
    public function pdo()
    {
        if (!$this->pdo) {
            $this->connect();
        }

        if (!$this->pdo) {
            throw new Exception('Cannot connect to db');
        }

        return $this->pdo;
    }

    /**
     * Prepare DB query
     *
     * @return PDOStatement
     */
    private function prepare()
    {
        $stmt            = $this->pdo()->prepare($this->query);
        $this->lastQuery = $this->query;

        if (!$stmt instanceof PDOStatement) {
            $this->lastErrorCode = $this->pdo()->errorCode();
            $this->lastError     = $this->pdo()->errorInfo();
            return null;
        }

        foreach ($this->params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : ':'.$key, $value, $this->determineType($value));
        }

        return $stmt;
    }

    /**
     * Perform db query
     * 
     * @param string $query
     * @param array $params
     * @return array
     */
    public function rawQuery($query, $params = null)
    {
        $this->query = $query;
        if (is_array($params)) {
            $this->params = $params;
        }
        $stmt = $this->prepare();
        if ($stmt) {
            $stmt->execute();
            $this->lastError     = $stmt->errorInfo();
            $this->lastErrorCode = $stmt->errorCode();
            $result              = $this->buildResult($stmt);
        } else {
            $result = null;
        }
        $this->reset();
        return $result;
    }

    /**
     * Perform db query and return only one row
     * 
     * @param string $query
     * @param array $params
     * @return array
     */
    public function rawQueryOne($query, $params = null)
    {
        $result = $this->rawQuery($query, $params);

        if ($this->useGenerator) {
            return $result->current() ? $result->current() : false;
        } else {
            return $result ? $result[0] : false;
        }
    }

    /**
     * Helper function to execute raw SQL query and return only 1 column of results.
     * If 'limit 1' will be found, then string will be returned instead of array
     * Same idea as getValue()
     *
     * @param string $query      User-provided query to execute.
     * @param array  $params Variables array to bind to the SQL statement.
     * @return mixed Contains the returned rows from the query.
     */
    public function rawQueryValue($query, $params = null)
    {
        $result = $this->rawQuery($query, $params);

        if ($this->useGenerator && !$result->current()) {
            return null;
        } else if (!$this->useGenerator && !$result) {
            return null;
        }        

        if ($this->useGenerator) {
            $firstResult = $result->current();
        } else {
            $firstResult = $result[0];
        }

        $key = key($firstResult);

        $limit = preg_match('/limit\s+1;?$/i', $query);
        if ($limit == true) {
            return isset($firstResult[$key]) ? $firstResult[$key] : null;
        }

        $return = [];
        foreach ($result as $row) {
            $return[] = $row[$key];
        }
        return $return;
    }

    /**
     * Perform insert query
     *
     * @param string $tableName
     * @param array $insertData
     * @return int
     */
    public function replace($tableName, $insertData)
    {
        return $this->buildInsert($tableName, $insertData, 'REPLACE');
    }

    /**
     * Reset PDODb internal variables
     */
    private function reset()
    {
        $this->forUpdate       = false;
        $this->groupBy         = [];
        $this->having          = [];
        $this->join            = [];
        $this->lastInsertId    = "";
        $this->lockInShareMode = false;
        $this->nestJoin        = false;
        $this->orderBy         = [];
        $this->params          = [];
        $this->query           = '';
        $this->queryOptions    = [];
        $this->queryType       = '';
        $this->rowCount        = 0;
        $this->updateColumns   = [];
        $this->where           = [];
    }

    /**
     * Transaction rollback function
     *
     * @uses pdo->rollback();
     * @return bool
     */
    public function rollback()
    {
        $result            = $this->pdo()->rollback();
        $this->transaction = false;
        return $result;
    }

    /**
     * Set row limit per 1 page
     * @param int $limit
     * @return PDODb
     */
    public function setPageLimit($limit)
    {
        $this->pageLimit = $limit;
        return $this;
    }

    /**
     * Method to set a prefix
     *
     * @param string $prefix Contains a tableprefix
     * @return PDODb
     */
    public function setPrefix($prefix = '')
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * This method allows you to specify multiple (method chaining optional) options for SQL queries.
     *
     * @uses $PDODb->setQueryOption('name');
     * @param string|array $options The optons name of the query.
     * @throws Exception
     * @return PDODb
     */
    public function setQueryOption($options)
    {
        $allowedOptions = ['ALL', 'DISTINCT', 'DISTINCTROW', 'HIGH_PRIORITY', 'STRAIGHT_JOIN', 'SQL_SMALL_RESULT',
            'SQL_BIG_RESULT', 'SQL_BUFFER_RESULT', 'SQL_CACHE', 'SQL_NO_CACHE', 'SQL_CALC_FOUND_ROWS',
            'LOW_PRIORITY', 'IGNORE', 'QUICK', 'MYSQLI_NESTJOIN', 'FOR UPDATE', 'LOCK IN SHARE MODE'];

        if (!is_array($options)) {
            $options = [$options];
        }

        foreach ($options as $option) {
            $option = strtoupper($option);
            if (!in_array($option, $allowedOptions)) {
                throw new Exception('Wrong query option: '.$option);
            }

            if ($option == 'MYSQLI_NESTJOIN') {
                $this->nestJoin = true;
            } elseif ($option == 'FOR UPDATE') {
                $this->forUpdate = true;
            } elseif ($option == 'LOCK IN SHARE MODE') {
                $this->lockInShareMode = true;
            } else {
                $this->queryOptions[] = $option;
            }
        }

        return $this;
    }

    /**
     * Set fetch return type
     *
     * @param int $returnType
     * @return PDODb
     */
    public function setReturnType($returnType)
    {
        $this->returnType = $returnType;
        return $this;
    }

    /**
     * Begin a transaction
     *
     * @uses pdo->beginTransaction()
     * @uses register_shutdown_function(array($this, "_transaction_shutdown_check"))
     */
    public function startTransaction()
    {
        $this->pdo()->beginTransaction();
        $this->transaction = true;
        register_shutdown_function([$this, "checkTransactionStatus"]);
    }

    /**
     * Method creates new PDODb object for a subquery generation
     *
     * @param string $subQueryAlias
     * @return PDODb
     */
    public function subQuery($subQueryAlias = "")
    {
        return new self(['type' => $this->connectionParams['type'], 'host' => $subQueryAlias, 'isSubQuery' => true, 'prefix' => $this->prefix]);
    }

    /**
     * Method to check if needed table is created
     *
     * @param array $tables Table name or an Array of table names to check
     * @return bool True if table exists
     */
    public function tableExists($tables)
    {
        $tables = !is_array($tables) ? [$tables] : $tables;
        $count  = count($tables);
        if ($count == 0) {
            return false;
        }

        foreach ($tables as $i => $value) {
            $tables[$i] = $this->prefix.$value;
        }
        $this->withTotalCount();
        $this->where('table_schema', $this->connectionParams['dbname']);
        $this->where('table_name', $tables, 'in');
        $this->get('information_schema.tables', $count);
        return $this->totalCount == $count;
    }

    /**
     * Update query. Be sure to first call the "where" method.
     *
     * @param string $tableName The name of the database table to work with.
     * @param array  $tableData Array of data to update the desired row.
     * @param int    $numRows   Limit on the number of rows that can be updated.
     * @return bool
     */
    public function update($tableName, $tableData, $numRows = null)
    {
        if ($this->isSubQuery) {
            return;
        }

        $this->query     = 'UPDATE '.$this->getTableName($tableName);
        $this->queryType = 'UPDATE';

        $stmt                = $this->buildQuery($numRows, $tableData);
        $status              = $stmt->execute();
        $this->lastError     = $stmt->errorInfo();
        $this->lastErrorCode = $stmt->errorCode();
        $this->reset();
        $this->rowCount      = $stmt->rowCount();


        return $status;
    }

    /**
     * Set use generator options
     * @param bool $option
     */
    public function useGenerator($option)
    {
        $this->useGenerator = $option;
    }

    /**
     * This method allows you to specify multiple (method chaining optional) AND WHERE statements for SQL queries.
     *
     * @uses $PDODb->where('id', 7)->where('title', 'MyTitle');
     * @param string $whereProp  The name of the database field.
     * @param mixed  $whereValue The value of the database field.
     * @param string $operator Comparison operator. Default is =
     * @param string $cond Condition of where statement (OR, AND)
     * @return PDODb
     */
    public function where($whereProp, $whereValue = 'DBNULL', $operator = '=', $cond = 'AND')
    {
        if (count($this->where) == 0) {
            $cond = '';
        }

        $this->where[] = [$cond, $whereProp, $operator, $whereValue];
        return $this;
    }

    /**
     * Function to enable SQL_CALC_FOUND_ROWS in the get queries
     *
     * @return PDODb
     */
    public function withTotalCount()
    {
        $this->setQueryOption('SQL_CALC_FOUND_ROWS');
        return $this;
    }
}