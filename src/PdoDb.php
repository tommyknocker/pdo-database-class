<?php
declare(strict_types=1);

namespace tommyknocker\pdodb;

use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\MySQLDialect;
use tommyknocker\pdodb\dialects\PostgreSQLDialect;
use tommyknocker\pdodb\dialects\SqliteDialect;

class PdoDb
{
    protected ?PDO $pdo = null {
        get {
            if (!$this->pdo instanceof PDO) {
                throw new RuntimeException('PDO object not initialized');
            }
            return $this->pdo;
        }
        set (?PDO $pdo) {
            $this->pdo = $pdo;
        }
    }
    protected DialectInterface $dialect;

    protected string $prefix = '';
    protected array $queryOptions = [];
    protected bool $isSubQuery = false;
    protected array $where = [];
    protected array $join = [];
    protected array $orderBy = [];
    protected array $groupBy = [];
    protected array $having = [];
    protected array $params = [];
    protected string $lastQuery = '';
    protected string $lastError = '';
    protected int $lastErrno = 0;
    protected bool $transactionInProgress = false;
    protected bool $traceEnabled = false;
    protected array $traceLog = [];
    protected int $totalCount = 0;
    protected string $lockMethod = 'WRITE';
    protected array $connections = [];
    protected ?string $lastTableUsed = null;
    protected array $onDuplicateColumns = [];
    protected array $queryOptionsFlags = [];

    /**
     * Initializes a new PdoDb object.
     *
     * @param string $driver The database driver to use. Defaults to 'mysql'.
     * @param array $config An array of configuration options for the database connection.
     *     The following options are supported:
     *     - host: The hostname or IP address of the database server.
     *     - port: The port number to use when connecting to the database server.
     *     - username: The username to use when connecting to the database server.
     *     - password: The password to use when connecting to the database server.
     *     - db: The name of the database to use.
     *     - charset: The character set to use when connecting to the database server.
     *     - prefix: The prefix to use for all database tables.
     *     - path: database path (sqlite3 only)
     *     - pdo : PDO object. if present, all other settings except driver and prefix are ignored
     */
    public function __construct(string $driver = 'mysql', array $config = [])
    {
        $this->prefix = $config['prefix'] ?? '';

        $this->addConnection('default', [
            'driver' => $driver,
            ...$config
        ]);

        // use default connection
        $this->connection('default');
    }


    /* ---------------- CRUD ---------------- */
    /**
     * Build multi-row insert/replace SQL parts.
     *
     * @param array $multiData
     * @return array [columns string, rows string, params array]
     */
    protected function buildMultiParts(array $multiData): array
    {
        $columns = implode(', ', array_keys($multiData[0]));
        $rows = [];
        $params = [];

        foreach ($multiData as $i => $row) {
            $placeholders = [];
            foreach ($row as $k => $v) {
                $param = ":{$k}_$i";
                $placeholders[] = $param;
                $params[$param] = $v;
            }
            $rows[] = '(' . implode(', ', $placeholders) . ')';
        }

        return [$columns, implode(', ', $rows), $params];
    }


    /**
     * Insert data into the database.
     *
     * @param string $table
     * @param array|self $data
     * @param string|null $returning RETURNING param for PostgreSQL
     * @return int|false
     *
     * @throws InvalidArgumentException if $data is not an array or a valid PdoDb object
     */
    public function insert(string $table, array|self $data, ?string $returning = null): int|false
    {
        $this->lastTableUsed = $table;
        $this->queryOptions['table'] = $table;

        $params = [];
        $targetTable = $this->prefix . $table;

        // --- 1. Subquery (builder)
        if ($data instanceof self && !empty($data->isSubQuery)) {
            $subSql = $data->getLastQuery();
            $params = $data->getParams() ?? [];

            $sql = $this->dialect->buildInsertSubquery($targetTable, null, $subSql, $this->queryOptionsFlags);

            if (!empty($this->onDuplicateColumns)) {
                $sql .= ' ' . $this->dialect->buildUpsertClause($this->onDuplicateColumns, 'id');
            }
        } // --- 2. Same subquery
        elseif (is_array($data) && count($data) >= 1 && current($data) instanceof self && $this->allValuesAreSameSubQuery($data)) {
            /** @var self $sub */
            $sub = current($data);
            $subSql = $sub->getLastQuery();
            $params = $sub->getParams() ?? [];

            $columns = array_keys($data);

            $sql = $this->dialect->buildInsertSubquery($targetTable, $columns, $subSql, $this->queryOptionsFlags);

            if (!empty($this->onDuplicateColumns)) {
                $sql .= ' ' . $this->dialect->buildUpsertClause($this->onDuplicateColumns, 'id');
            }
        } // --- 3. Common array
        else {
            $columns = array_keys($data);
            $placeholders = array_map(static fn($k) => ":$k", $columns);

            $sql = $this->dialect->buildInsertValues($targetTable, $columns, $placeholders, $this->queryOptionsFlags);

            if (!empty($this->onDuplicateColumns)) {
                $sql .= ' ' . $this->dialect->buildUpsertClause($this->onDuplicateColumns, 'id');
            }

            foreach ($data as $k => $v) {
                $params[":$k"] = $v;
            }
        }

        // RETURNING (Postgres)
        if ($this->dialect->supportsReturning()) {
            $sql .= $this->dialect->buildReturningClause($returning);
        }

        $this->lastQuery = $sql;
        $this->logTrace($sql, $params);

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $pk => $pv) {
            $stmt->bindValue($pk, $pv);
        }

        $ok = $stmt->execute();
        $this->resetQueryState();

        // If RETURNING and dialect supports it
        if ($ok && $returning && $this->dialect->supportsReturning()) {
            $ret = $stmt->fetchColumn();
            if ($ret !== false && $ret !== null) {
                return (int)$ret;
            }
        }

        return $ok ? (int)$this->lastInsertId($table) : false;
    }


    /**
     * Check if all values in the array are the same subquery
     * @param array $data
     * @return bool
     */
    protected function allValuesAreSameSubQuery(array $data): bool
    {
        $first = current($data);
        if (!($first instanceof self) || empty($first->isSubQuery)) {
            return false;
        }
        return array_all($data, static fn($v) => $v === $first);
    }

    /**
     * Insert multiple rows into a table
     * @param string $table
     * @param array $multiData
     * @return bool
     */
    public function insertMulti(string $table, array $multiData): bool
    {
        if (empty($multiData)) {
            return false;
        }

        $this->lastTableUsed = $table;
        $this->queryOptions['table'] = $table;

        [$columns, $rows, $params] = $this->buildMultiParts($multiData);

        $sql = "INSERT INTO {$this->prefix}$table ($columns) VALUES $rows";

        if (!empty($this->onDuplicateColumns)) {
            $updates = implode(', ', array_map(static fn($k) => "$k = VALUES($k)", $this->onDuplicateColumns));
            $sql .= " ON DUPLICATE KEY UPDATE $updates";
        }

        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute($params);

        $this->onDuplicateColumns = [];
        $this->resetQueryState();

        return $ok;
    }

    /**
     * Set the columns to be updated on duplicate key
     * @param array $updateColumns
     * @return self
     */
    public function onDuplicate(array $updateColumns): self
    {
        $this->onDuplicateColumns = $updateColumns;
        return $this;
    }

    /**
     * Insert or update a row in a table
     * @param string $table
     * @param array $data
     * @return int
     */
    public function replace(string $table, array $data): int
    {
        $this->lastTableUsed = $table;

        // 1) Raw identifiers and placeholders
        $columns = array_keys($data);
        $placeholders = array_map(static fn($k) => ":{$k}", $columns);
        $fullTable = $this->prefix . $table;

        // 2) Base INSERT/REPLACE is built by the dialect (it will quote identifiers itself)
        //    Important: pass RAW names, not already quoted ones.
        $sql = $this->dialect->buildInsertValues(
            $fullTable,      // raw table name; dialect will quote
            $columns,        // raw column names; dialect will quote
            $placeholders,   // :named placeholders (as strings)
            $this->queryOptionsFlags
        );

        // 3) UPSERT/ON CONFLICT — only if the dialect requires it
        //    (for MySQL it should return an empty string).
        //    Pass raw column names, dialect will quote internally.
        $upsert = $this->dialect->buildUpsertClause($columns);
        if ($upsert !== '') {
            $sql .= ' ' . $upsert;
        }

        // 4) Prepare + bind (parameter names without leading colon)
        $this->lastQuery = $sql;
        $this->logTrace($sql, $data);

        $stmt = $this->pdo->prepare($sql);
        foreach ($data as $k => $v) {
            $stmt->bindValue($k, $v);
        }

        $stmt->execute();
        $affected = $stmt->rowCount();

        $this->resetQueryState();
        return $affected;
    }


    /**
     * Insert multiple rows into a table
     * @param string $table
     * @param array $multiData
     * @return bool
     */
    public function replaceMulti(string $table, array $multiData): bool
    {
        if (empty($multiData)) {
            return false;
        }

        $this->lastTableUsed = $table;
        $this->queryOptions['table'] = $table;

        [$columns, $rows, $params] = $this->buildMultiParts($multiData);

        $sql = "REPLACE INTO {$this->prefix}$table ($columns) VALUES $rows";

        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute($params);

        $this->resetQueryState();
        return $ok;
    }

    /**
     * Update a row in a table
     * @param string $table
     * @param array $data
     * @param int|null $limit
     * @return int
     */
    public function update(string $table, array $data, ?int $limit = null): int
    {
        $this->lastTableUsed = $table;

        // --- SET
        $bindData = [];
        $setParts = [];

        foreach ($data as $col => $val) {
            $qid = $this->dialect->quoteIdentifier($col);

            if (is_array($val) && isset($val['__op'])) {
                switch ($val['__op']) {
                    case 'inc':
                        $num = (float)$val['val'];
                        $setParts[] = "{$qid} = {$qid} + {$num}";
                        break;
                    case 'dec':
                        $num = (float)$val['val'];
                        $setParts[] = "{$qid} = {$qid} - {$num}";
                        break;
                    case 'not':
                        $setParts[] = "{$qid} = NOT {$qid}";
                        break;
                    default:
                        $ph = ":upd_{$col}";
                        $setParts[] = "{$qid} = {$ph}";
                        $bindData[$ph] = $val;
                        break;
                }
            } elseif (is_string($val) && preg_match('/\bNOW\(\)\b/', $val)) {
                // Raw NOW() / NOW() + INTERVAL
                $setParts[] = "{$qid} = {$val}";
            } else {
                // Regular bind
                $ph = ":upd_{$col}";
                $setParts[] = "{$qid} = {$ph}";
                $bindData[$ph] = $val;
            }
        }

        // --- UPDATE header
        $modifiers = $this->queryOptionsFlags ? implode(' ', $this->queryOptionsFlags) . ' ' : '';
        $fullTable = $this->prefix . $table;

        $sql = "UPDATE {$modifiers}" . $this->dialect->quoteIdentifier($fullTable) .
            " SET " . implode(', ', $setParts);

        if ($limit && $this->dialect->isStandardUpdateLimit($table, $limit, $this)) {
            $limitClause = $this->dialect->limitOffsetClause($limit, null);
            if ($limitClause !== '') {
                $sql .= ' ' . $limitClause;
            }
        }

        // --- WHERE (includes possible ctid IN)
        $sql .= $this->buildWhere();

        // --- RETURNING if supported and set
        if (!empty($this->queryOptions['returning']) && $this->dialect->supportsReturning()) {
            $sql .= $this->dialect->buildReturningClause($this->queryOptions['returning']);
        }

        // --- Prepare and bind
        $this->lastQuery = $sql;
        $this->logTrace($sql, $bindData);

        $stmt = $this->pdo->prepare($sql);

        // Bind SET named params (PDO expects name without leading colon)
        foreach ($bindData as $p => $v) {
            $stmt->bindValue(ltrim($p, ':'), $v);
        }

        // Bind WHERE params: positional (?) or named (:name)
        if (!empty($this->params)) {
            $keys = array_keys($this->params);
            $isPositional = $keys === range(0, count($keys) - 1);

            if ($isPositional) {
                $i = 1;
                foreach ($this->params as $v) {
                    $stmt->bindValue($i++, $v);
                }
            } else {
                foreach ($this->params as $p => $v) {
                    $stmt->bindValue(ltrim($p, ':'), $v);
                }
            }
        }

        $stmt->execute();
        $affected = $stmt->rowCount();

        $this->resetQueryState();
        return $affected;
    }


    /**
     * Delete data from the database.
     *
     * @param string $table The table from which to delete data.
     * @return int The number of rows deleted.
     */
    public function delete(string $table): int
    {
        $this->lastTableUsed = $table;
        $modifiers = $this->queryOptionsFlags ? implode(' ', $this->queryOptionsFlags) . ' ' : '';
        $sql = "DELETE {$modifiers}FROM {$this->prefix}$table";
        $sql .= $this->buildWhere();

        $this->lastQuery = $sql;
        $this->logTrace($sql);

        $stmt = $this->pdo->prepare($sql);
        foreach ($this->params as $p => $v) {
            $stmt->bindValue($p, $v);
        }

        $stmt->execute();
        $result = $stmt->rowCount();
        $this->resetQueryState();
        return $result;
    }

    /* ---------------- RAW ---------------- */

    /**
     * Execute a raw query.
     *
     * @param string $query The raw query to be executed.
     * @param array $params The parameters to be bound to the query.
     * @return array The result of the query.
     */
    public function rawQuery(string $query, array $params = []): array
    {
        $this->lastQuery = $query;
        $this->logTrace($query, $params);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetchAll();
        $this->resetQueryState();
        return $result;
    }

    public function rawQueryOne(string $query, array $params = []): array
    {
        $this->lastQuery = $query;
        $this->logTrace($query, $params);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch() ?: [];
        $this->resetQueryState();
        return $result;
    }

    /**
     * Execute a raw query and return the value of the first column of the first row.
     *
     * @param string $query The raw query to be executed.
     * @param array $params The parameters to be bound to the query.
     * @return mixed The value of the first column of the first row.
     */
    public function rawQueryValue(string $query, array $params = []): mixed
    {
        $this->lastQuery = $query;
        $this->logTrace($query, $params);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetchColumn();
        $this->resetQueryState();
        return $result;
    }

    /**
     * Builds a SELECT query string
     *
     * @param string $table The table to be queried
     * @param array $columns The columns to be retrieved
     * @param int|null $limit The maximum number of rows to be retrieved
     * @param int|null $offset The offset of the first row to be retrieved
     * @return string The SELECT query string
     */
    protected function buildSelect(string $table, array $columns, ?int $limit = null, ?int $offset = null): string
    {
        $tailQueryOptions = [];
        $queryOptions = $this->queryOptionsFlags;

        foreach ($queryOptions as $index => $queryOption) {
            if (in_array($queryOption, ['LOCK IN SHARE MODE', 'FOR UPDATE'])) {
                $tailQueryOptions[] = $queryOption;
                unset($queryOptions[$index]);
            }
        }

        if (in_array('FOR UPDATE', $tailQueryOptions) && in_array('LOCK IN SHARE MODE', $tailQueryOptions)) {
            throw new InvalidArgumentException('Cannot use FOR UPDATE and LOCK IN SHARE MODE together');
        }

        $modifiers = $queryOptions ? implode(' ', $queryOptions) . ' ' : '';
        $endingModifiers = $tailQueryOptions ? ' ' . implode(' ', $tailQueryOptions) : '';

        $sql = "SELECT {$modifiers}" . implode(', ', $columns) . " FROM {$this->prefix}$table";

        if ($this->join) {
            $sql .= ' ' . implode(' ', $this->join);
        }
        $sql .= $this->buildWhere();

        if ($this->groupBy) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        if ($this->having) {
            $sql .= $this->buildHaving();
        }
        if ($this->orderBy) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        if ($limit !== null) {
            $sql .= ' ' . $this->dialect->limitOffsetClause($limit, $offset);
        }
        $sql .= $endingModifiers;

        return $sql;
    }


    /**
     * Execute a SELECT query.
     *
     * Builds a SELECT query based on the provided parameters and then executes it.
     * The results are returned as an array.
     *
     * @param string $table The name of the table to select from.
     * @param array $columns The columns to select from the table.
     * @param ?int $limit The maximum number of rows to return. If null, all rows are returned.
     * @param ?int $offset The offset from which to start returning rows. If null, the first row is returned.
     * @return array The results of the query.
     */
    protected function runSelect(string $table, array $columns, ?int $limit = null, ?int $offset = null): array
    {
        // Build the SELECT query based on the provided parameters
        $sql = $this->buildSelect($table, $columns, $limit, $offset);

        // Store the query for later use
        $this->lastQuery = $sql;

        // Log the query for debugging purposes
        $this->logTrace($sql);

        // Prepare the query and execute it
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);

        // Fetch the results of the query
        // The fetch mode is determined by the query options
        $mode = $this->queryOptions['fetchMode'] ?? PDO::FETCH_ASSOC;
        return $stmt->fetchAll($mode);
    }


    /**
     * Fetch data from the database.
     *
     * Runs a SELECT query on the specified table with the provided columns and limit.
     * If the query options flag 'json' is set, the results are returned as a JSON string.
     * Otherwise, the results are returned as an array.
     *
     * @param string $table The table from which to fetch data.
     * @param ?int $limit The maximum number of rows to return. If null, all rows are returned.
     * @param array $columns The columns to select from the table. Defaults to ['*'].
     * @return array|string The results of the query.
     * @throws JsonException
     */
    public function get(string $table, ?int $limit = null, array $columns = ['*']): array|string
    {
        $this->lastTableUsed = $table;
        $rows = $this->runSelect($table, $columns, $limit);

        $json = $this->queryOptions['json'] ?? false;

        if (!$this->isSubQuery) {
            $this->resetQueryState();
        }

        return $json ? json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $rows;
    }

    /**
     * Fetch a single column from the result set.
     *
     * @param string $table The table from which to fetch data.
     * @param string $column The column to select from the table.
     * @param ?int $limit The maximum number of rows to return. If null, all rows are returned.
     * @return array The results of the query.
     * @throws JsonException
     */
    public function getColumn(string $table, string $column, ?int $limit = null): array
    {
        $rows = $this->get($table, $limit, [$column]);
        $result = [];
        foreach ($rows as $row) {
            if (is_object($row) && $row->{$column} === null) {
                $result[] = $row->{$column};
            } elseif (array_key_exists($column, $row)) {
                $result[] = $row[$column];
            } else {
                throw new InvalidArgumentException('getColumn accepts array or object builders only');
            }
        }
        return $result;
    }

    /**
     * Fetch a single row from the database.
     *
     * Runs a SELECT query on the specified table with the provided columns and limit 1.
     * If the query options flag 'json' is set, the results are returned as a JSON string.
     * Otherwise, the results are returned as an array.
     *
     * @param string $table The table from which to fetch data.
     * @param array $columns The columns to select from the table. Defaults to ['*'].
     * @return array The results of the query.
     * @throws JsonException
     */
    public function getOne(string $table, array $columns = ['*']): array
    {
        $result = $this->get($table, 1, $columns);
        return $result[0] ?? [];
    }

    /**
     * Retrieve a single value from the database.
     *
     * Runs a SELECT query on the specified table with the provided column and limit.
     * If the limit is 1 or null, the first value of the column is returned.
     * Otherwise, an array of all values of the column is returned.
     *
     * @param string $table The table from which to retrieve the value.
     * @param string $column The column from which to retrieve the value.
     * @param ?int $limit The maximum number of values to return. If null or 1, the first value is returned.
     * @return mixed The value of the column, or an array of values if the limit is greater than 1.
     * @throws JsonException
     */
    public function getValue(string $table, string $column): mixed
    {
        $alias = $column;
        if (stripos($column, ' as ') !== false) {
            $parts = preg_split('/\s+as\s+/i', $column);
            $alias = trim(end($parts));
        } elseif (preg_match('/\W/', $column)) {
            $alias = 'value';
            $column .= " AS {$alias}";
        }

        $result = $this->get($table, 1, [$column]);

        return $result[0][$alias] ?? null;
    }


    /**
     * Checks if a table exists in the database.
     *
     * @param string $table The name of the table to check.
     * @return bool True if the table exists, false otherwise.
     */
    public function has(string $table): bool
    {
        $sql = "SELECT EXISTS(SELECT 1 FROM {$this->prefix}$table";
        $sql .= $this->buildWhere();
        $sql .= ') as exist';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Adds a condition to the query.
     *
     * @param string $type The type of condition ('where' or 'having').
     * @param ?string $expr The expression for the condition.
     * @param mixed $value The value for the condition.
     * @param string $operator The operator for the condition.
     * @param string $cond The condition to add.
     */
    protected function addCondition(
        string $type,       // 'where' or 'having'
        ?string $expr,
        mixed $value,
        string $operator,
        string $cond
    ): void {
        $operator = strtoupper($operator);
        $prop = ($type === 'having') ? 'having' : 'where';

        if ($value instanceof self) {
            $subSql = (string)$value;
            $subParams = $value->getParams();
            foreach ($subParams as $k => $v) {
                if (array_key_exists($k, $this->params)) {
                    $newKey = $k . '_sq' . count($this->params);
                    $subSql = str_replace($k, $newKey, $subSql);
                    $this->params[$newKey] = $v;
                } else {
                    $this->params[$k] = $v;
                }
            }

            $this->{$prop}[] = [
                'cond' => $cond,
                'sql' => "$expr $operator ($subSql)"
            ];
            return;
        }

        // Validate expression type to prevent "Array" in SQL
        $isExistsOp = ($operator === 'EXISTS' || $operator === 'NOT EXISTS');
        if (!$isExistsOp) {
            if (!is_string($expr) || $expr === '') {
                throw new InvalidArgumentException("Expression for $type must be a non-empty string");
            }
        }

        switch ($operator) {
            case 'EXISTS':
            case 'NOT EXISTS':
                // value is raw subquery string
                $subSql = (string)$value;
                $this->{$prop}[] = ['cond' => $cond, 'sql' => "$operator ($subSql)"];
                break;

            case 'IN':
            case 'NOT IN':
                if (is_array($value)) {
                    if (empty($value)) {
                        // Deterministic behavior for empty IN list
                        $sql = ($operator === 'IN') ? '1=0' : '1=1';
                        $this->{$prop}[] = ['cond' => $cond, 'sql' => $sql];
                        break;
                    }
                    $ph = [];
                    foreach ($value as $i => $v) {
                        $p = $this->makeParam($expr, "_$i");
                        $this->params[$p] = $v;
                        $ph[] = $p;
                    }
                    $this->{$prop}[] = ['cond' => $cond, 'sql' => "$expr $operator (" . implode(', ', $ph) . ")"];
                } else {
                    // treat string as subquery: expr IN (SELECT ...)
                    $this->{$prop}[] = ['cond' => $cond, 'sql' => "$expr $operator ($value)"];
                }
                break;

            case 'BETWEEN':
            case 'NOT BETWEEN':
                if (!is_array($value) || count($value) !== 2) {
                    throw new InvalidArgumentException("$operator expects an array of two bounds");
                }
                [$from, $to] = $value;
                $p1 = $this->makeParam($expr, '_from');
                $p2 = $this->makeParam($expr, '_to');
                $this->params[$p1] = $from;
                $this->params[$p2] = $to;
                $this->{$prop}[] = ['cond' => $cond, 'sql' => "$expr $operator $p1 AND $p2"];
                break;

            default:
                if ($expr !== null) {
                    if (is_string($value) && str_starts_with(trim($value), 'SELECT')) {
                        $this->{$prop}[] = [
                            'cond' => $cond,
                            'sql' => "$expr $operator ($value)"
                        ];
                    } else {
                        $p = $this->makeParam($expr);

                        $this->params[$p] = $value;

                        $this->{$prop}[] = [
                            'cond' => $cond,
                            'sql' => "$expr $operator $p"
                        ];
                    }
                }
                break;

        }
    }

    /**
     * Makes a parameter for the query.
     *
     * @param string $expr The expression for the parameter.
     * @param string $suffix The suffix for the parameter.
     * @return string The parameter.
     */
    protected function makeParam(string $expr, string $suffix = ''): string
    {
        // sanitize expression to safe identifier
        $base = preg_replace('/\W/', '_', $expr);
        return ':' . strtolower($base) . $suffix . count($this->params);
    }

    /**
     * Builds the conditions for the query.
     *
     * @param array $items The items to build the conditions from.
     * @param string $keyword The keyword to use for the conditions.
     * @return string The conditions for the query.
     */
    protected function buildConditions(array $items, string $keyword): string
    {
        if (empty($items)) {
            return '';
        }

        $clauses = [];
        foreach ($items as $i => $w) {
            if (is_string($w)) {
                $clauses[] = ($i === 0 ? '' : 'AND ') . $w;
                continue;
            }
            $sql = $w['sql'] ?? '';
            $cond = $w['cond'] ?? ($i === 0 ? '' : 'AND');
            if ($sql === '') {
                continue;
            }
            $clauses[] = ($i === 0 || $cond === '') ? $sql : strtoupper($cond) . ' ' . $sql;
        }

        return ' ' . $keyword . ' ' . implode(' ', $clauses);
    }

    /**
     * Builds the WHERE clause for the query.
     *
     * @return string The WHERE clause for the query.
     */
    protected function buildWhere(): string
    {
        return $this->buildConditions($this->where, 'WHERE');
    }

    /**
     * Builds the HAVING clause for the query.
     *
     * @return string The HAVING clause for the query.
     */
    protected function buildHaving(): string
    {
        return $this->buildConditions($this->having, 'HAVING');
    }

    /**
     * Adds AND condition to the WHERE clause.
     *
     * @param ?string $expr The expression for the condition.
     * @param mixed $value The value for the condition.
     * @param string $operator The operator for the condition.
     * @return self The current object.
     */
    public function where(?string $expr, mixed $value, string $operator = '='): self
    {
        $this->addCondition('where', $expr, $value, $operator, 'AND');
        return $this;
    }

    /**
     * Adds OR condition to the WHERE clause.
     *
     * @param ?string $expr The expression for the condition.
     * @param mixed $value The value for the condition.
     * @param string $operator The operator for the condition.
     * @return self The current object.
     */
    public function orWhere(?string $expr, mixed $value, string $operator = '='): self
    {
        $this->addCondition('where', $expr, $value, $operator, 'OR');
        return $this;
    }

    /**
     * Adds AND condition to the HAVING clause.
     *
     * @param string $expr The expression for the condition.
     * @param mixed $value The value for the condition.
     * @param string $operator The operator for the condition.
     * @return self The current object.
     */
    public function having(string $expr, mixed $value = null, string $operator = '='): self
    {
        $this->addCondition('having', $expr, $value, $operator, 'AND');
        return $this;
    }

    /**
     * Adds OR condition to the HAVING clause.
     *
     * @param string $expr The expression for the condition.
     * @param mixed $value The value for the condition.
     * @param string $operator The operator for the condition.
     * @return self The current object.
     */
    public function orHaving(string $expr, mixed $value = null, string $operator = '='): self
    {
        $this->addCondition('having', $expr, $value, $operator, 'OR');
        return $this;
    }

    /**
     * Adds a join to the query.
     *
     * @param string $table The table to join.
     * @param string $condition The condition for the join.
     * @param string $type The type of join.
     * @return self The current object.
     */
    public function join(string $table, string $condition, string $type = 'INNER'): self
    {
        $this->join[] = strtoupper($type) . " JOIN {$this->prefix}$table ON $condition";
        return $this;
    }

    /**
     * Adds a condition to the join.
     *
     * @param string $table The table to join.
     * @param string $column The column to join on.
     * @param mixed $value The value for the condition.
     * @param string $operator The operator for the condition.
     * @return self The current object.
     */
    public function joinWhere(string $table, string $column, mixed $value, string $operator = '='): self
    {
        $this->buildJoinCondition('AND', $table, $column, $value, $operator);
        return $this;
    }

    /**
     * Adds OR condition to the join.
     *
     * @param string $table The table to join.
     * @param string $column The column to join on.
     * @param mixed $value The value for the condition.
     * @param string $operator The operator for the condition.
     * @return self The current object.
     */
    public function joinOrWhere(string $table, string $column, mixed $value, string $operator = '='): self
    {
        $this->buildJoinCondition('OR', $table, $column, $value, $operator);
        return $this;
    }

    /**
     * Builds a join condition.
     *
     * @param string $cond The condition to build.
     * @param string $table The table to join.
     * @param string $column The column to join on.
     * @param mixed $value The value for the condition.
     * @param string $operator The operator for the condition.
     * @return void
     */
    protected function buildJoinCondition(
        string $cond,
        string $table,
        string $column,
        mixed $value,
        string $operator
    ): void {
        // alias
        if (str_contains($table, ' ')) {
            $parts = preg_split('/\s+/', trim($table));
            $table = end($parts);
        }

        if (is_array($value) && strtoupper($operator) === 'IN') {
            $placeholders = [];
            foreach ($value as $i => $v) {
                $ph = ':' . $table . '_' . str_replace('.', '_', $column) . "_{$i}" . count($this->params);
                $placeholders[] = $ph;
                $this->params[$ph] = $v;
            }
            $sql = "$table.$column IN (" . implode(', ', $placeholders) . ")";
        } else {
            $param = ':' . $table . '_' . str_replace('.', '_', $column) . count($this->params);
            $this->params[$param] = $value;
            $sql = "$table.$column $operator $param";
        }

        $this->where[] = ['cond' => $cond, 'sql' => $sql];
    }

    /**
     * Groups the results by the specified column.
     *
     * @param string $column The column to group by.
     * @return self The current object.
     */
    public function groupBy(string $column): self
    {
        $this->groupBy[] = $column;
        return $this;
    }

    /**
     * Orders the results by the specified column.
     *
     * @param string $column The column to order by.
     * @param string $direction The direction to order by.
     * @return self The current object.
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = "$column " . strtoupper($direction);
        return $this;
    }

    /**
     * Creates a subquery.
     *
     * @param string $alias The alias for the subquery.
     * @return self The current object.
     */
    public function subQuery(string $alias = ''): self
    {
        $sub = $this->copy();
        $sub->isSubQuery = true;
        $sub->resetQueryState();
        if ($alias) {
            $sub->queryOptions['alias'] = $alias;
        }
        return $sub;
    }

    /**
     * Resets the query state.
     *
     * @return void
     */
    protected function resetQueryState(): void
    {
        $this->queryOptions = [];
        $this->where = [];
        $this->join = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->having = [];
        $this->params = [];
        $this->onDuplicateColumns = [];
        $this->queryOptionsFlags = [];
    }


    /* ---------------- PAGINATION ---------------- */

    /**
     * Enables total count calculation.
     *
     * @return self The current object.
     */
    public function withTotalCount(): self
    {
        $this->queryOptions['calcFoundRows'] = true;
        return $this;
    }

    /**
     * Sets the page limit.
     *
     * @param int $limit The limit for the page.
     * @return self The current object.
     */
    public function setPageLimit(int $limit): self
    {
        $this->queryOptions['limit'] = $limit;
        return $this;
    }

    /**
     * Paginates the results.
     *
     * @param string $table The table to paginate.
     * @param int $page The page number.
     * @param array $columns The columns to select.
     * @return array|string The paginated results.
     * @throws JsonException
     */
    public function paginate(string $table, int $page, array $columns = ['*']): array|string
    {
        $this->lastTableUsed = $table;

        $limit = $this->queryOptions['limit'] ?? 20;
        $this->queryOptions['limit'] = $limit;
        $offset = max(0, ($page - 1)) * $limit;

        // run main query
        $rows = $this->runSelect($table, $columns, $limit, $offset);

        // count total
        if (!empty($this->queryOptions['calcFoundRows'])) {
            $countSql = "SELECT COUNT(*) FROM {$this->prefix}$table" . $this->buildWhere();
            $countStmt = $this->pdo->prepare($countSql);
            $countStmt->execute($this->params);
            $this->totalCount = (int)$countStmt->fetchColumn();
        }

        $json = $this->queryOptions['json'] ?? false;
        $this->resetQueryState();

        return $json ? json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $rows;
    }

    /**
     * Returns the total count of rows.
     *
     * @return int The total count of rows.
     */
    public function totalCount(): int
    {
        return $this->totalCount;
    }

    /* ---------------- TRANSACTIONS ---------------- */

    /**
     * Starts a transaction.
     *
     * @return void
     */
    public function startTransaction(): void
    {
        if (!$this->transactionInProgress) {
            $this->pdo->beginTransaction();
            $this->transactionInProgress = true;
        }
    }

    /**
     * Commits the transaction.
     *
     * @return void
     */
    public function commit(): void
    {
        if ($this->transactionInProgress) {
            $this->pdo->commit();
            $this->transactionInProgress = false;
        }
    }

    /**
     * Rolls back the transaction.
     *
     * @return void
     */
    public function rollback(): void
    {
        if ($this->transactionInProgress) {
            $this->pdo->rollBack();
            $this->transactionInProgress = false;
        }
    }


    /* ---------------- LOCKING ---------------- */

    /**
     * Locks the specified tables.
     *
     * @param string|array $tables The tables to lock.
     * @return bool True if the lock was successful, false otherwise.
     */
    public function lock(string|array $tables): bool
    {
        $tables = (array)$tables;

        $sql = $this->dialect->buildLockSql($tables, $this->prefix, $this->lockMethod);

        $this->logTrace($sql);
        $this->lastQuery = $sql;

        return $this->pdo->exec($sql) !== false;
    }

    /**
     * Unlocks the specified tables.
     *
     * @return bool True if unlock was successful, false otherwise.
     */
    public function unlock(): bool
    {
        $sql = $this->dialect->buildUnlockSql();

        $this->logTrace($sql);
        $this->lastQuery = $sql;

        // In Postgres unlock — no‑op, return true
        if ($sql === '') {
            return true;
        }

        return $this->pdo->exec($sql) !== false;
    }

    /**
     * Sets the lock method.
     *
     * @param string $method The lock method to use.
     * @return self The current object.
     */
    public function setLockMethod(string $method): self
    {
        $this->lockMethod = strtoupper($method);
        return $this;
    }

    /* ---------------- HELPERS ---------------- */

    public function lastInsertId(string $table, ?string $column = null)
    {
        return $this->dialect->lastInsertId($this->pdo, $table, $column);
    }

    /**
     * Get params
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Escapes a string for use in a SQL query.
     *
     * @param string $str The string to escape.
     * @return string The escaped string.
     */
    public function escape(string $str): string
    {
        return $this->pdo->quote($str);
    }

    /**
     * Disconnects from the database.
     *
     * @return void
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Pings the database.
     *
     * @return bool True if the ping was successful, false otherwise.
     */
    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Checks if a table exists.
     *
     * @param string $table The table to check.
     * @return bool True if the table exists, false otherwise.
     */
    public function tableExists(string $table): bool
    {
        $sql = $this->dialect->tableExistsSql($this->prefix . $table);
        $res = $this->rawQuery($sql);
        return !empty($res);
    }


    /* ---------------- TRACE ---------------- */

    /**
     * Sets the trace enabled state.
     *
     * @param bool $enabled The trace enabled state.
     * @return void
     */
    public function setTrace(bool $enabled = true): void
    {
        $this->traceEnabled = $enabled;
    }

    /**
     * Logs a trace of the query.
     *
     * @param string $sql The SQL query to log.
     * @param array $params The parameters for the query.
     * @return void
     */
    protected function logTrace(string $sql, array $params = []): void
    {
        if ($this->traceEnabled) {
            $this->traceLog[] = [
                'query' => $sql,
                'params' => $params,
                'time' => microtime(true),
            ];
        }
    }

    /**
     * Returns the trace log.
     *
     * @return array The trace log.
     */
    public function trace(): array
    {
        return $this->traceLog;
    }

    /**
     * Returns the last query.
     *
     * @return string The last query.
     */
    public function getLastQuery(): string
    {
        return $this->lastQuery;
    }

    /**
     * Returns the last error.
     *
     * @return string The last error.
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Returns the last error number.
     *
     * @return int The last error number.
     */
    public function getLastErrno(): int
    {
        return $this->lastErrno;
    }

    /**
     * Returns the trace log.
     *
     * @return array The trace log.
     */
    public function getLogTrace(): array
    {
        return $this->traceLog;
    }

    /* ---------------- UTILS ---------------- */

    /**
     * Sets the query options.
     *
     * @param string|array $flags The query options.
     * @return self The current object.
     */
    public function setQueryOption(string|array $flags): self
    {
        $this->queryOptionsFlags = is_array($flags) ? $flags : [$flags];
        return $this;
    }

    /**
     * Returns the query options.
     *
     * @return array The query options.
     */
    public function getQueryOptions(): array
    {
        return $this->queryOptionsFlags;
    }

    /**
     * Sets the prefix.
     *
     * @param string $prefix The prefix.
     * @return self The current object.
     */
    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Replaces placeholders in a string with bind parameters.
     *
     * @param string $expr The string to replace placeholders in.
     * @param array $bindParams The bind parameters.
     * @return string The string with placeholders replaced.
     */
    public function func(string $expr, array $bindParams = []): string
    {
        foreach ($bindParams as $k => $v) {
            $param = ":func_" . $k . count($this->params);
            $this->params[$param] = $v;
            $expr = str_replace('?', $param, $expr, $count);
            if ($count === 0) {
                break;
            }
        }
        return $expr;
    }

    /**
     * Returns a string with the current date and time.
     *
     * @param string $diff The time interval to add to the current date and time.
     * @param string $func The function to use to get the current date and time.
     * @return string The current date and time.
     */
    public function now(string $diff = '', string $func = 'NOW()'): string
    {
        return $this->dialect->now($diff, $func);
    }

    /**
     * Returns an array with an increment operation.
     *
     * @param int|float $num The number to increment by.
     * @return array The array with the increment operation.
     */
    public function inc(int|float $num = 1): array
    {
        return ['__op' => 'inc', 'val' => $num];
    }

    /**
     * Returns an array with a decrement operation.
     *
     * @param int|float $num The number to decrement by.
     * @return array The array with the decrement operation.
     */
    public function dec(int|float $num = 1): array
    {
        return ['__op' => 'dec', 'val' => $num];
    }

    /**
     * Returns an array with a not operation.
     *
     * @param mixed $val The value to negate.
     * @return array The array with the not operation.
     */
    public function not(mixed $val): array
    {
        return ['__op' => 'not', 'val' => $val];
    }

    /**
     * Returns an array with a map operation.
     *
     * @param string $idField The field to use as the map key.
     * @return self The current object.
     */
    public function map(string $idField): self
    {
        $this->queryOptions['mapKey'] = $idField;
        return $this;
    }

    /**
     * Returns a copy of the current object.
     *
     * @return self The copy of the current object.
     */
    public function copy(): self
    {
        return clone $this;
    }

    /* ---------------- CONNECTIONS ---------------- */
    public function buildDsn(array $params): string
    {
        return $this->dialect->buildDsn($params);
    }

    /**
     * Adds a connection to the connection pool.
     *
     * @param string $name The name of the connection.
     * @param array $params The parameters to use to connect to the database.
     * @return void
     */
    public function addConnection(string $name, array $params): void
    {
        $this->dialect = match ($params['driver']) {
            'mysql' => new MySQLDialect(),
            'pgsql' => new PostgreSQLDialect(),
            'sqlite' => new SqliteDialect(),
            default => throw new InvalidArgumentException("Unsupported driver: {$params['driver']}"),
        };

        $dsn = $this->buildDsn($params);
        $pdo = $params['pdo'] ?? null;

        if (!$pdo) {
            $pdo = new PDO($dsn,
                $params['username'] ?? null,
                $params['password'] ?? null,
                $this->dialect->defaultPdoOptions() // @todo Support client's options
            );
        }
        $this->connections[$name] = $pdo;

        if ($name === 'default') {
            $this->pdo = $pdo;
        }
    }

    /**
     * Returns a connection from the connection pool.
     *
     * @param string $name The name of the connection.
     * @return self The connection.
     */
    public function connection(string $name): self
    {
        if (!isset($this->connections[$name])) {
            throw new RuntimeException("Connection $name not found");
        }
        $clone = clone $this;
        $clone->pdo = $this->connections[$name];
        return $clone;
    }

    /* ---------------- BUILDERS ---------------- */

    /**
     * Sets the fetch mode to array.
     *
     * @return self The current object.
     */
    public function arrayBuilder(): self
    {
        $this->queryOptions['fetchMode'] = PDO::FETCH_ASSOC;
        return $this;
    }

    /**
     * Sets the fetch mode to object.
     *
     * @return self The current object.
     */
    public function objectBuilder(): self
    {
        $this->queryOptions['fetchMode'] = PDO::FETCH_OBJ;
        return $this;
    }

    /**
     * Sets the fetch mode to JSON.
     *
     * @return self The current object.
     */
    public function jsonBuilder(): self
    {
        // mark json output flag instead of passing a string mode to fetchAll
        $this->queryOptions['json'] = true;
        $this->queryOptions['fetchMode'] = PDO::FETCH_ASSOC; // base mode for json
        return $this;
    }

    /* ---------------- LOAD DATA/XML ---------------- */

    /**
     * Loads data from a CSV file into a table.
     *
     * @param string $table The table to load data into.
     * @param string $filePath The path to the CSV file.
     * @param array $options The options to use to load the data.
     * @return bool True on success, false on failure.
     */
    public function loadData(string $table, string $filePath, array $options = []): bool
    {
        if (!$this->dialect->canLoadData()) {
            throw new RuntimeException('Driver does not support bulk load: ' . $this->dialect->getDriverName());
        }

        $sql = $this->dialect->buildLoadDataSql($this->pdo, $this->prefix . $table, $filePath, $options);

        $this->lastQuery = $sql;
        $this->logTrace($sql);

        return $this->pdo->exec($sql) !== false;
    }


    /**
     * Loads data from an XML file into a table.
     *
     * @param string $table The table to load data into.
     * @param string $filePath The path to the XML file.
     * @param string $rowTag The tag that identifies a row.
     * @param int|null $linesToIgnore The number of lines to ignore at the beginning of the file.
     * @return bool True on success, false on failure.
     */
    public function loadXml(string $table, string $filePath, string $rowTag = '<row>', ?int $linesToIgnore = null): bool
    {
        if (!$this->dialect->canLoadXml()) {
            throw new InvalidArgumentException('LOAD XML is not supported by ' . $this->dialect->getDriverName());
        }
        $sql = "LOAD XML LOCAL INFILE " . $this->pdo->quote($filePath) .
            " INTO TABLE {$this->prefix}$table " .
            "ROWS IDENTIFIED BY " . $this->pdo->quote($rowTag)
            . ($linesToIgnore ? sprintf(' IGNORE %d LINES', $linesToIgnore) : '');
        $this->lastQuery = $sql;
        $this->logTrace($sql);
        return $this->pdo->exec($sql) !== false;
    }


    /**
     * Describes a table.
     *
     * @param string $table The table to describe.
     * @return array The table description.
     */
    public function describe(string $table): array
    {
        $sql = $this->dialect->describeTableSql($this->prefix . $table);
        return $this->rawQuery($sql);
    }

    /**
     * Explains a query.
     *
     * @param string $query The query to explain.
     * @param array $params The parameters to use to explain the query.
     * @return array The query explanation.
     */
    public function explain(string $query, array $params = []): array
    {
        $sql = $this->dialect->explainSql($query, false);
        return $this->rawQuery($sql, $params);
    }

    /**
     * Explains and analyzes a query.
     *
     * @param string $query The query to explain and analyze.
     * @param array $params The parameters to use to explain and analyze the query.
     * @return array The query explanation and analysis.
     */
    public function explainAnalyze(string $query, array $params = []): array
    {
        $sql = $this->dialect->explainSql($query, true);
        return $this->rawQuery($sql, $params);
    }


    /**
     * Returns a string representation of the last query.
     *
     * @return string The last query.
     */
    public function __toString(): string
    {
        return $this->lastQuery;
    }
}
