<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\exceptions\ExceptionFactory;
use tommyknocker\pdodb\helpers\ConcatValue;
use tommyknocker\pdodb\helpers\ConfigValue;
use tommyknocker\pdodb\helpers\CurDateValue;
use tommyknocker\pdodb\helpers\CurTimeValue;
use tommyknocker\pdodb\helpers\DayValue;
use tommyknocker\pdodb\helpers\EscapeValue;
use tommyknocker\pdodb\helpers\GreatestValue;
use tommyknocker\pdodb\helpers\HourValue;
use tommyknocker\pdodb\helpers\IfNullValue;
use tommyknocker\pdodb\helpers\ILikeValue;
use tommyknocker\pdodb\helpers\JsonContainsValue;
use tommyknocker\pdodb\helpers\JsonExistsValue;
use tommyknocker\pdodb\helpers\JsonGetValue;
use tommyknocker\pdodb\helpers\JsonKeysValue;
use tommyknocker\pdodb\helpers\JsonLengthValue;
use tommyknocker\pdodb\helpers\JsonPathValue;
use tommyknocker\pdodb\helpers\JsonTypeValue;
use tommyknocker\pdodb\helpers\LeastValue;
use tommyknocker\pdodb\helpers\MinuteValue;
use tommyknocker\pdodb\helpers\ModValue;
use tommyknocker\pdodb\helpers\MonthValue;
use tommyknocker\pdodb\helpers\NowValue;
use tommyknocker\pdodb\helpers\RawValue;
use tommyknocker\pdodb\helpers\SecondValue;
use tommyknocker\pdodb\helpers\SubstringValue;
use tommyknocker\pdodb\helpers\YearValue;

class QueryBuilder implements QueryBuilderInterface
{
    /* ---------------- Construction / meta ---------------- */

    /** @var ConnectionInterface Database connection instance */
    protected ConnectionInterface $connection;

    /** @var DialectInterface Dialect instance for database-specific SQL */
    protected DialectInterface $dialect;

    /** @var string|null table name */
    protected ?string $table = null {
        get {
            if (!$this->table) {
                throw new RuntimeException('You must define table first. Use table() or from() methods');
            }
            return $this->table;
        }
    }

    /** @var array<int, string> */
    protected array $select = [];

    /** @var array<int, string> */
    protected array $joins = [];

    /** @var array<int, string|array<string, mixed>> */
    protected array $where = [];

    /** @var array<int, string|array<string, mixed>> */
    protected array $having = [];

    /** @var array<string, string|int|float|bool|null> */
    protected array $params = [];

    /** @var array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> */
    protected array $data = [];

    /** @var array<int, array<string, string|int|float|bool|null|RawValue>> */
    protected array $multiRows = [];

    /** @var array<string, string|int|float|bool|null|RawValue> */
    protected array $onDuplicate = [];

    /** @var array<int, string> ORDER BY expressions */
    protected array $order = [];

    /** @var string|null GROUP BY expression */
    protected ?string $group = null;

    /** @var int|null LIMIT value */
    protected ?int $limit = null;

    /** @var int|null OFFSET value */
    protected ?int $offset = null;

    /** @var string|null Table prefix */
    protected ?string $prefix = null;

    /** @var int PDO fetch mode */
    protected int $fetchMode = PDO::FETCH_ASSOC;

    /** @var array<int|string, mixed> Query options (e.g., FOR UPDATE, IGNORE) */
    protected array $options = [];

    /** @var int Parameter counter for generating unique param names */
    protected int $paramCounter = 0;
    /**
     * QueryBuilder constructor.
     *
     * @param ConnectionInterface $connection
     * @param string $prefix
     */
    public function __construct(ConnectionInterface $connection, string $prefix = '')
    {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->prefix = $prefix;
    }

    /**
     * Return active connection instance.
     *
     * @return ConnectionInterface
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Return dialect instance used by this builder.
     *
     * @return DialectInterface
     */
    public function getDialect(): DialectInterface
    {
        return $this->dialect;
    }

    /**
     * Return table prefix configured for this builder.
     *
     * @return string|null
     */
    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    /* ---------------- Table / source ---------------- */

    /**
     * Sets the table to query.
     *
     * @param string $table The table to query.
     *
     * @return self The current instance.
     */
    public function table(string $table): self
    {
        return $this->from($table);
    }

    /**
     * Sets the table to query.
     *
     * @param string $table The table to query.
     *
     * @return self The current instance.
     */
    public function from(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Sets the prefix for table names.
     *
     * @param string $prefix The prefix for table names.
     *
     * @return self The current instance.
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /* ---------------- Select / projection ---------------- */

    /**
     * Adds columns to the SELECT clause.
     *
     * @param RawValue|string|array<int|string, string|callable(QueryBuilder): void> $cols The columns to add.
     *
     * @return self The current instance.
     */
    public function select(RawValue|callable|string|array $cols): self
    {
        if (!is_array($cols)) {
            $cols = [$cols];
        }
        foreach ($cols as $index => $col) {
            if ($col instanceof RawValue && is_string($index)) {
                $this->select[] = $this->resolveRawValue($col) . ' AS ' . $index;
            } elseif ($col instanceof RawValue) {
                $this->select[] = $this->resolveRawValue($col);
            } elseif (is_callable($col)) {
                // Handle callback for subqueries
                $subQuery = new self($this->connection, $this->prefix ?? '');
                $col($subQuery);
                $sub = $subQuery->toSQL();
                $map = $this->mergeSubParams($sub['params'], 'sq');
                $subSql = $this->replacePlaceholdersInSql($sub['sql'], $map);
                $this->select[] = is_string($index) ? "({$subSql}) AS {$index}" : "({$subSql})";
            } elseif (is_string($index)) { // ['total' => 'SUM(amount)] Treat it as SUM(amount) AS total
                $this->select[] = $col . ' AS ' . $index;
            } else {
                $this->select[] = $col;
            }
        }
        return $this;
    }

    /**
     * Execute SELECT statement and return all rows.
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function get(): array
    {
        $sql = $this->buildSelectSql();
        return $this->fetchAll($sql, $this->params);
    }

    /**
     * Execute SELECT statement and return first row.
     *
     * @return mixed
     * @throws PDOException
     */
    public function getOne(): mixed
    {
        $sql = $this->buildSelectSql();
        return $this->fetch($sql, $this->params);
    }

    /**
     * Execute SELECT statement and return column values.
     *
     * @return array<int, mixed>
     * @throws PDOException
     */
    public function getColumn(): array
    {
        if (count($this->select) !== 1) {
            return [];
        }
        $key = $this->resolveSelectedKey();
        $rows = $this->get();
        return array_column($rows, $key);
    }

    /**
     * Execute SELECT statement and return single value.
     *
     * @return mixed
     * @throws PDOException
     */
    public function getValue(): mixed
    {
        if (count($this->select) !== 1) {
            return false;
        }
        $row = $this->getOne();
        $key = $this->resolveSelectedKey();
        if (count($row) === 1 && !isset($row[$key])) {
            return array_shift($row);
        }
        return $row[$key] ?? null;
    }

    /* ---------------- DML: insert / update / delete / replace ---------------- */

    /**
     * Insert data into the table.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data The data to insert.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the insert operation.
     */
    public function insert(array $data, array $onDuplicate = []): int
    {
        $this->data = $data;
        if ($onDuplicate) {
            $this->onDuplicate = $onDuplicate;
        }
        [$sql, $params] = $this->buildInsertSql();
        return $this->executeInsert($sql, $params);
    }

    /**
     * Insert multiple rows into the table.
     *
     * @param array<int, array<string, string|int|float|bool|null|RawValue>> $rows The rows to insert.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the insert operation.
     */
    public function insertMulti(array $rows, array $onDuplicate = []): int
    {
        if (empty($rows)) {
            throw new RuntimeException('insertMulti requires at least one row');
        }
        $this->multiRows = $rows;
        if ($onDuplicate) {
            $this->onDuplicate = $onDuplicate;
        }
        [$sql, $params] = $this->buildInsertMultiSql();
        return $this->executeInsert($sql, $params, true);
    }

    /**
     * Replace data into the table.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data The data to replace.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the replace operation.
     */
    public function replace(array $data, array $onDuplicate = []): int
    {
        $this->data = $data;

        if ($onDuplicate) {
            $this->onDuplicate = $onDuplicate;
        }

        $columns = array_keys($this->data);
        $placeholders = [];
        $params = [];
        foreach ($columns as $col) {
            $colStr = (string)$col;
            $result = $this->processValueForSql($this->data[$col], $colStr);
            $placeholders[] = $result['sql'];
            $params = array_merge($params, $result['params']);
        }
        $tableName = $this->table; // Use getter to ensure not null
        assert(is_string($tableName)); // PHPStan assertion
        $sql = $this->dialect->buildReplaceSql($tableName, array_values(array_map('strval', $columns)), $placeholders);
        return $this->executeInsert($sql, $params);
    }

    /**
     * Replace multiple rows into the table.
     *
     * @param array<int, array<string, string|int|float|bool|null|RawValue>> $rows The rows to replace.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the replace operation.
     */
    public function replaceMulti(array $rows, array $onDuplicate = []): int
    {
        if (empty($rows)) {
            throw new RuntimeException('replaceMulti requires at least one row');
        }

        $this->multiRows = $rows;

        if ($onDuplicate) {
            $this->onDuplicate = $onDuplicate;
        }

        $columns = array_keys($rows[0]);
        $valuesList = [];
        $params = [];
        $i = 0;

        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $result = $this->processValueForSql($row[$col], (string)$col, (string)$col . '_' . $i . '_');
                $placeholders[] = $result['sql'];
                $params = array_merge($params, $result['params']);
            }
            // collect per-row group WITHOUT adding outer parentheses here
            // because dialect will assemble VALUES list and must accept both modes
            $valuesList[] = '(' . implode(', ', $placeholders) . ')';
            $i++;
        }

        // Pass isMultiple = true so dialect will not add extra parentheses
        $tableName = $this->table; // Use getter to ensure not null
        assert(is_string($tableName)); // PHPStan assertion
        $sql = $this->dialect->buildReplaceSql($tableName, array_values(array_map('strval', $columns)), $valuesList, true);
        return $this->executeInsert($sql, $params);
    }

    /**
     * Execute UPDATE statement.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data
     *
     * @return int
     * @throws PDOException
     */
    public function update(array $data): int
    {
        $this->data = $data;
        [$sql, $params] = $this->buildUpdateSql();
        return $this->executeStatement($sql, $params)->rowCount();
    }

    /**
     * Execute DELETE statement.
     *
     * @return int
     * @throws PDOException
     */
    public function delete(): int
    {
        $table = $this->normalizeTable();
        $options = $this->options ? implode(',', $this->options) . ' ' : '';
        $sql = "DELETE {$options}FROM {$table}";
        $sql .= $this->buildConditionsClause($this->where, 'WHERE');
        return $this->executeStatement($sql, $this->params)->rowCount();
    }

    /**
     * Execute TRUNCATE statement.
     *
     * @return bool
     * @throws PDOException
     */
    public function truncate(): bool
    {
        $tableName = $this->table; // Use getter to ensure not null
        assert(is_string($tableName)); // PHPStan assertion
        $sql = $this->dialect->buildTruncateSql($tableName);
        $this->executeStatement($sql);
        return $this->connection->getLastErrno() === 0;
    }

    /* ---------------- Batch processing methods ---------------- */

    /**
     * Execute query and return iterator for batch processing.
     *
     * Processes data in batches of specified size, yielding arrays of records.
     * Useful for processing large datasets without loading everything into memory.
     *
     * @param int $batchSize Number of records per batch (default: 100)
     *
     * @return \Generator<int, array<int, array<string, mixed>>, mixed, void>
     * @throws InvalidArgumentException If batch size is invalid
     * @throws PDOException
     */
    public function batch(int $batchSize = 100): \Generator
    {
        if ($batchSize <= 0) {
            throw new InvalidArgumentException('Batch size must be greater than 0');
        }

        $offset = 0;
        $sql = $this->buildSelectSql();
        $params = $this->params ?? [];

        while (true) {
            // Add LIMIT and OFFSET to the query
            $batchSql = $sql . " LIMIT {$batchSize} OFFSET {$offset}";

            $rows = $this->fetchAll($batchSql, $params);

            if (empty($rows)) {
                break; // No more data
            }

            yield $rows;

            // If we got less than batchSize, we're done
            if (count($rows) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }
    }

    /**
     * Execute query and return iterator for individual record processing.
     *
     * Processes data one record at a time, but loads them from database in batches
     * for efficiency. Useful when you need to process each record individually
     * but want to avoid memory issues with large datasets.
     *
     * @param int $batchSize Internal batch size for database queries (default: 100)
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     * @throws InvalidArgumentException If batch size is invalid
     * @throws PDOException
     */
    public function each(int $batchSize = 100): \Generator
    {
        if ($batchSize <= 0) {
            throw new InvalidArgumentException('Batch size must be greater than 0');
        }

        $offset = 0;
        $sql = $this->buildSelectSql();
        $params = $this->params ?? [];
        $buffer = [];

        while (true) {
            // Refill buffer if empty
            if (empty($buffer)) {
                $batchSql = $sql . " LIMIT {$batchSize} OFFSET {$offset}";
                $buffer = $this->fetchAll($batchSql, $params);

                if (empty($buffer)) {
                    break; // No more data
                }

                $offset += $batchSize;
            }

            // Yield one record from buffer
            yield array_shift($buffer);
        }
    }

    /**
     * Execute query and return iterator for individual record processing with cursor.
     *
     * Most memory efficient method for very large datasets. Uses database cursor
     * to stream results without loading them into memory. Best for simple
     * sequential processing of large datasets.
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     * @throws PDOException
     */
    public function cursor(): \Generator
    {
        $sql = $this->buildSelectSql();
        $params = $this->normalizeParams($this->params ?? []);

        // Use executeStatement which returns PDOStatement directly
        $stmt = $this->executeStatement($sql, $params);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        while ($row = $stmt->fetch()) {
            yield $row;
        }

        $stmt->closeCursor();
    }

    /* ---------------- Conditions: where / having / logical variants ---------------- */

    /**
     * Add WHERE clause.
     *
     * Syntax:
     *
     * where('column', 'value') // column = value
     * where('column', 'value', '!=') // column != value
     * where(new RawValue('LENGTH(column)'), 5, '>') // LENGTH(column) > 5
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return self The current instance.
     */
    public function where(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        return $this->addCondition('where', $exprOrColumn, $value, $operator, 'AND');
    }

    /**
     * Add AND WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return self The current instance.
     */
    public function andWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        return $this->where($exprOrColumn, $value, $operator);
    }

    /**
     * Add OR WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return self The current instance.
     */
    public function orWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        return $this->addCondition('where', $exprOrColumn, $value, $operator, 'OR');
    }

    /**
     * Add HAVING clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return self The current instance.
     */
    public function having(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        return $this->addCondition('having', $exprOrColumn, $value, $operator, 'AND');
    }

    /**
     * Add OR HAVING clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return self The current instance.
     */
    public function orHaving(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        return $this->addCondition('having', $exprOrColumn, $value, $operator, 'OR');
    }

    /**
     * Add WHERE IN clause with subquery.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilder): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereIn(string $column, callable $subquery): self
    {
        return $this->where($column, $subquery, 'IN');
    }

    /**
     * Add WHERE NOT IN clause with subquery.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilder): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereNotIn(string $column, callable $subquery): self
    {
        return $this->where($column, $subquery, 'NOT IN');
    }

    /**
     * Add WHERE EXISTS clause.
     *
     * @param callable(QueryBuilder): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereExists(callable $subquery): self
    {
        $instance = new self($this->connection, $this->prefix ?? '');
        $subquery($instance);
        $sub = $instance->toSQL();
        $map = $this->mergeSubParams($sub['params'], 'sq');
        $subSql = $this->replacePlaceholdersInSql($sub['sql'], $map);
        $this->where[] = ['sql' => "EXISTS ({$subSql})", 'cond' => 'AND'];
        return $this;
    }

    /**
     * Add WHERE NOT EXISTS clause.
     *
     * @param callable(QueryBuilder): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereNotExists(callable $subquery): self
    {
        $instance = new self($this->connection, $this->prefix ?? '');
        $subquery($instance);
        $sub = $instance->toSQL();
        $map = $this->mergeSubParams($sub['params'], 'sq');
        $subSql = $this->replacePlaceholdersInSql($sub['sql'], $map);
        $this->where[] = ['sql' => "NOT EXISTS ({$subSql})", 'cond' => 'AND'];
        return $this;
    }

    /**
     * Add raw WHERE clause.
     *
     * @param string $sql The raw SQL condition
     * @param array<string, mixed> $params The parameters for the condition
     *
     * @return self The current instance
     */
    public function whereRaw(string $sql, array $params = []): self
    {
        foreach ($params as $key => $value) {
            $placeholder = str_starts_with($key, ':') ? $key : ':' . $key;
            $this->params[$placeholder] = $value;
        }
        $this->where[] = ['sql' => $sql, 'cond' => 'AND'];
        return $this;
    }

    /**
     * Add raw HAVING clause.
     *
     * @param string $sql The raw SQL condition
     * @param array<string, mixed> $params The parameters for the condition
     *
     * @return self The current instance
     */
    public function havingRaw(string $sql, array $params = []): self
    {
        foreach ($params as $key => $value) {
            $placeholder = str_starts_with($key, ':') ? $key : ':' . $key;
            $this->params[$placeholder] = $value;
        }
        $this->having[] = ['sql' => $sql, 'cond' => 'AND'];
        return $this;
    }

    /* ---------------- Existence helpers ---------------- */

    /**
     * Return true if at least one row matches the current WHERE conditions.
     *
     * This builds a single query using SQL EXISTS and executes it.
     * It does not execute any nested queries separately; the subquery is compiled only.
     *
     *
     * @return bool
     * @throws PDOException
     */
    public function exists(): bool
    {
        $this->limit(1);
        $subSql = $this->buildSelectSql();
        $params = $this->params ?? [];
        $params = $this->normalizeParams($params);
        $sql = 'SELECT EXISTS(' . $subSql . ')';
        return (bool)$this->fetchColumn($sql, $params);
    }

    /**
     * Return true if no rows match the current WHERE conditions.
     *
     * This builds a single query using SQL NOT EXISTS and executes it.
     * It does not execute any nested queries separately; the subquery is compiled only.
     *
     *
     * @return bool
     * @throws PDOException
     */
    public function notExists(): bool
    {
        $this->limit(1);
        $subSql = $this->buildSelectSql();
        $params = $this->params ?? [];
        $sql = 'SELECT NOT EXISTS(' . $subSql . ')';
        return (bool)$this->fetchColumn($sql, $params);
    }

    /**
     * Checks if a table exists.
     *
     * @return bool True if the table exists, false otherwise.
     */
    public function tableExists(): bool
    {
        $table = $this->prefix . $this->table;
        $sql = $this->dialect->buildTableExistsSql($table);
        $res = $this->executeStatement($sql)->fetchColumn();
        return !empty($res);
    }

    /* ---------------- Joins ---------------- */

    /**
     * Add JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     * @param string $type JOIN type, e.g. INNER, LEFT, RIGHT
     */
    public function join(string $tableAlias, string|RawValue $condition, string $type = 'INNER'): self
    {
        $type = strtoupper(trim($type));
        $tableSql = $this->normalizeTable($tableAlias);
        $onSql = $condition instanceof RawValue ? $this->resolveRawValue($condition) : (string)$condition;
        $this->joins[] = "{$type} JOIN {$tableSql} ON {$onSql}";
        return $this;
    }

    /**
     * Add LEFT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     */
    public function leftJoin(string $tableAlias, string|RawValue $condition): self
    {
        $this->join($tableAlias, $condition, 'LEFT');
        return $this;
    }

    /**
     * Add RIGHT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     */
    public function rightJoin(string $tableAlias, string|RawValue $condition): self
    {
        $this->join($tableAlias, $condition, 'RIGHT');
        return $this;
    }

    /**
     * Add INNER JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     */
    public function innerJoin(string $tableAlias, string|RawValue $condition): self
    {
        $this->join($tableAlias, $condition, 'INNER');
        return $this;
    }

    /* ---------------- Ordering / grouping / pagination / options ---------------- */

    /**
     * Add ORDER BY clause.
     * $expr may be a column name (string), complete expression or RawValue instance.
     * $direction can be 'ASC' or 'DESC' (case-insensitive). Defaults to 'ASC'.
     *
     * Syntax:
     *
     * orderBy('column_name', 'ASC')
     * orderBy(new RawValue('LENGTH(column_name)'), 'DESC')
     * orderBy('column_name DESC') // full expression
     *
     * @param string|RawValue $expr The expression to order by.
     * @param string $direction The direction of the ordering (ASC or DESC).
     *
     * @return self The current instance.
     */
    public function orderBy(string|RawValue $expr, string $direction = 'ASC'): self
    {
        $dir = strtoupper(trim($direction));
        if ($dir !== 'ASC' && $dir !== 'DESC') {
            $dir = 'ASC';
        }
        if ($expr instanceof RawValue) {
            $this->order[] = $this->resolveRawValue($expr) . ' ' . $dir;
        } elseif (preg_match('/^[a-z0-9]+\s+(ASC|DESC)/iu', $expr)) {
            $this->order[] = $expr;
        } else {
            $this->order[] = $this->quoteQualifiedIdentifier($expr) . ' ' . $dir;
        }

        return $this;
    }

    /**
     * Add GROUP BY clause.
     *
     * @param string|array<int, string|RawValue>|RawValue $cols The columns to group by.
     *
     * @return self The current instance.
     */
    public function groupBy(string|array|RawValue $cols): self
    {
        if (!is_array($cols)) {
            $cols = [$cols];
        }
        $groups = [];
        foreach ($cols as $col) {
            if ($col instanceof RawValue) {
                $groups[] = $this->resolveRawValue($col);
            } else {
                $groups[] = $this->quoteQualifiedIdentifier((string)$col);
            }
        }
        $this->group = implode(', ', $groups);
        return $this;
    }

    /**
     * Add LIMIT clause.
     *
     * @param int $number The number of rows to limit.
     *
     * @return self The current instance.
     */
    public function limit(int $number): self
    {
        $this->limit = $number;
        return $this;
    }

    /**
     * Add OFFSET clause.
     *
     * @param int $number The number of rows to offset.
     *
     * @return self The current instance.
     */
    public function offset(int $number): self
    {
        $this->offset = $number;
        return $this;
    }

    /**
     * Sets the query options.
     *
     * @param string|array<int|string, mixed> $options The query options.
     *
     * @return self The current object.
     */
    public function option(string|array $options): self
    {
        if (is_array($options)) {
            foreach ($options as $key => $value) {
                if (is_string($key)) {
                    $this->options[$key] = $value;
                } else {
                    $this->options[] = $value;
                }
            }
        } else {
            $this->options[] = $options;
        }
        return $this;
    }

    /**
     * Set fetch mode to return objects.
     *
     * @return $this
     */
    public function asObject(): self
    {
        $this->fetchMode = PDO::FETCH_OBJ;
        return $this;
    }

    /* ---------------- ON DUPLICATE / upsert helpers ---------------- */

    /**
     * Add ON DUPLICATE clause.
     *
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return self The current instance.
     */
    public function onDuplicate(array $onDuplicate): self
    {
        $this->onDuplicate = $onDuplicate;
        return $this;
    }

    /* ---------------- Introspect ---------------- */

    /**
     * Convert query to SQL string and parameters.
     *
     * @return array{sql: string, params: array<string, string|int|float|bool|null>}
     */
    public function toSQL(): array
    {
        $sql = $this->buildSelectSql();
        $params = $this->params ?? [];
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Execute EXPLAIN query to analyze query execution plan.
     *
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function explain(): array
    {
        $sql = $this->buildSelectSql();
        $explainSql = $this->dialect->buildExplainSql($sql);
        return $this->fetchAll($explainSql, $this->params);
    }

    /**
     * Execute EXPLAIN ANALYZE query (PostgreSQL) or EXPLAIN FORMAT=JSON (MySQL).
     *
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function explainAnalyze(): array
    {
        $sql = $this->buildSelectSql();
        $explainSql = $this->dialect->buildExplainAnalyzeSql($sql);
        return $this->fetchAll($explainSql, $this->params);
    }

    /**
     * Execute DESCRIBE query to get table structure.
     *
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function describe(): array
    {
        $tableName = $this->table; // Use getter to ensure not null
        assert(is_string($tableName)); // PHPStan assertion
        $describeSql = $this->dialect->buildDescribeSql($tableName);
        return $this->fetchAll($describeSql);
    }

    /* ---------------- Execution primitives (pass-through helpers) ---------------- */

    /**
     * Execute statement.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return PDOStatement
     * @throws PDOException
     */
    public function executeStatement(string|RawValue $sql, array $params = []): PDOStatement
    {
        $sql = $this->resolveRawValue($sql);
        $params = array_merge($this->params, $params);
        $params = $this->normalizeParams($params);
        return $this->connection->prepare($sql)->execute($params);
    }

    /**
     * Fetch all rows.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return array<int, array<string, mixed>>
     * @throws PDOException
     */
    public function fetchAll(string|RawValue $sql, array $params = []): array
    {
        return $this->executeStatement($sql, $params)->fetchAll($this->fetchMode);
    }

    /**
     * Fetch column.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return mixed
     * @throws PDOException
     */
    public function fetchColumn(string|RawValue $sql, array $params = []): mixed
    {
        return $this->executeStatement($sql, $params)->fetchColumn();
    }

    /**
     * Fetch row.
     *
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return mixed
     * @throws PDOException
     */
    public function fetch(string|RawValue $sql, array $params = []): mixed
    {
        return $this->executeStatement($sql, $params)->fetch($this->fetchMode);
    }

    /* ---------------- CSV / XML loaders ---------------- */

    /**
     * Loads data from a CSV file into a table.
     *
     * @param string $filePath The path to the CSV file.
     * @param array<string, mixed> $options The options to use to load the data.
     *
     * @return bool True on success, false on failure.
     */
    public function loadCsv(string $filePath, array $options = []): bool
    {
        $sql = null;
        if (!$this->connection->inTransaction()) {
            $this->connection->transaction();
        }

        try {
            $sql = $this->connection->getDialect()->buildLoadCsvSql($this->prefix . $this->table, $filePath, $options);
            $this->connection->prepare($sql)->execute();
            if ($this->connection->inTransaction()) {
                $this->connection->commit();
            }
            return $this->connection->getExecuteState() !== false;
        } catch (PDOException $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollback();
            }

            // Convert to specialized exception and re-throw
            $dbException = ExceptionFactory::createFromPdoException(
                $e,
                $this->connection->getDriverName(),
                $sql,
                ['operation' => 'loadCsv', 'file' => $filePath]
            );

            throw $dbException;
        }
    }

    /**
     * Loads data from an XML file into a table.
     *
     * @param string $filePath The path to the XML file.
     * @param string $rowTag The tag that identifies a row.
     * @param int|null $linesToIgnore The number of lines to ignore at the beginning of the file.
     *
     * @return bool True on success, false on failure.
     */
    public function loadXml(string $filePath, string $rowTag = '<row>', ?int $linesToIgnore = null): bool
    {
        $sql = null;
        if (!$this->connection->inTransaction()) {
            $this->connection->transaction();
        }

        try {
            $options = [
                'rowTag' => $rowTag,
                'linesToIgnore' => $linesToIgnore,
            ];
            $sql = $this->connection->getDialect()->buildLoadXML($this->prefix . $this->table, $filePath, $options);
            $this->connection->prepare($sql)->execute();
            if ($this->connection->inTransaction()) {
                $this->connection->commit();
            }
            return $this->connection->getExecuteState() !== false;
        } catch (PDOException $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollback();
            }

            // Convert to specialized exception and re-throw
            $dbException = ExceptionFactory::createFromPdoException(
                $e,
                $this->connection->getDriverName(),
                $sql,
                ['operation' => 'loadXml', 'file' => $filePath, 'rowTag' => $rowTag]
            );

            throw $dbException;
        }
    }

    /* ---------------- JSON methods ---------------- */

    /**
     * Add SELECT expression extracting JSON value by path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string|null $alias
     * @param bool $asText
     *
     * @return self
     */
    public function selectJson(string $col, array|string $path, ?string $alias = null, bool $asText = true): self
    {
        $expr = $this->dialect->formatJsonGet($col, $path, $asText);
        if ($alias) {
            $this->select[] = $expr . ' AS ' . $this->dialect->quoteIdentifier($alias);
        } else {
            $this->select[] = $expr;
        }
        return $this;
    }

    /**
     * Add WHERE condition comparing JSON value at path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $operator
     * @param mixed $value
     * @param string $cond
     *
     * @return self
     */
    public function whereJsonPath(string $col, array|string $path, string $operator, mixed $value, string $cond = 'AND'): self
    {
        $expr = $this->dialect->formatJsonGet($col, $path, true);

        if ($value instanceof RawValue) {
            $right = $this->resolveRawValue($value);
            $this->where($expr . " {$operator} " . $right);
            return $this;
        }

        $ph = $this->addParam('json_' . $col, $value);
        $this->where[] = ['sql' => "{$expr} {$operator} {$ph}", 'cond' => 'AND'];
        return $this;
    }

    /**
     * Add WHERE JSON contains (col contains value).
     *
     * @param string $col
     * @param mixed $value
     * @param array<int, string|int>|string|null $path
     * @param string $cond
     *
     * @return self
     */
    // QueryBuilder
    public function whereJsonContains(string $col, mixed $value, array|string|null $path = null, string $cond = 'AND'): self
    {
        $res = $this->dialect->formatJsonContains($col, $value, $path);
        if (is_array($res)) {
            [$sql, $params] = $res;
            foreach ($params as $k => $v) {
                $old = str_starts_with($k, ':') ? $k : ':' . $k;
                $new = $this->makeParam('jsonc_' . ltrim($old, ':'));
                $this->params[$new] = $v;
                $sql = strtr($sql, [$old => $new]);
            }
            $this->where[] = ['sql' => $sql, 'cond' => $cond];
            return $this;
        }
        $this->where[] = ['sql' => $res, 'cond' => $cond];
        return $this;
    }

    /**
     * Update JSON field: set value at path (create missing).
     *
     * Usage:
     *   ->update(['meta' => $this->jsonSet('meta', ['a','b'], $value)])
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param mixed $value
     *
     * @return RawValue
     */
    public function jsonSet(string $col, array|string $path, mixed $value): RawValue
    {
        // ask dialect for expression and parameters
        [$expr, $params] = $this->dialect->formatJsonSet($col, $path, $value);

        // integrate params into RawValue with unique placeholders
        $paramMap = [];
        foreach ($params as $k => $v) {
            $old = str_starts_with($k, ':') ? $k : ':' . $k;
            $new = $this->makeParam('jsonset_' . ltrim($old, ':'));
            $paramMap[$old] = $new;
            $this->params[$new] = $v;
        }

        $sql = strtr($expr, $paramMap);
        $rawParams = [];
        foreach ($paramMap as $old => $new) {
            $key = ltrim($old, ':');
            if (isset($params[$key])) {
                $rawParams[$new] = $params[$key];
            }
        }
        return new RawValue($sql, $rawParams);
    }

    /**
     * Remove JSON path from column (returns RawValue to use in update).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return RawValue
     */
    public function jsonRemove(string $col, array|string $path): RawValue
    {
        $expr = $this->dialect->formatJsonRemove($col, $path);
        return new RawValue($expr);
    }

    /**
     * Add ORDER BY expression based on JSON path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $direction
     *
     * @return self
     */
    public function orderByJson(string $col, array|string $path, string $direction = 'ASC'): self
    {
        $expr = $this->dialect->formatJsonOrderExpr($col, $path);
        return $this->orderBy(new RawValue($expr), $direction);
    }

    /**
     * Check existence of JSON path (returns boolean condition).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $cond
     *
     * @return self
     */
    public function whereJsonExists(string $col, array|string $path, string $cond = 'AND'): self
    {
        $expr = $this->dialect->formatJsonExists($col, $path);
        $this->where[] = ['sql' => $expr, 'cond' => $cond];
        return $this;
    }

    /* ---------------- Protected helpers (grouped by purpose) ---------------- */

    /* Builders */

    /**
     * Build SELECT sql.
     *
     * @return string
     */
    protected function buildSelectSql(): string
    {
        // build base select (no DB-specific option handling)
        if (empty($this->select)) {
            $select = '*';
        } else {
            $select = implode(', ', array_map(function ($value) {
                // Check if it's already a compiled subquery (starts with '(')
                if (str_starts_with($value, '(')) {
                    return $value;
                }
                return $this->quoteQualifiedIdentifier($value);
            }, $this->select));
        }

        $from = $this->normalizeTable();
        $sql = "SELECT {$select} FROM {$from}";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $sql .= $this->buildConditionsClause($this->where, 'WHERE');

        if (!empty($this->group)) {
            $sql .= ' GROUP BY ' . $this->group;
        }

        $sql .= $this->buildConditionsClause($this->having, 'HAVING');

        if (!empty($this->order)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->order);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int)$this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . (int)$this->offset;
        }

        $sql = $this->dialect->formatSelectOptions($sql, $this->options);

        return trim($sql);
    }

    /**
     * Build INSERT sql.
     *
     * @return array{string, array<string, string|int|float|bool|null>}
     */
    protected function buildInsertSql(): array
    {
        $columns = array_keys($this->data);
        $placeholders = [];
        $params = [];

        foreach ($columns as $col) {
            $result = $this->processValueForSql($this->data[$col], $col);
            $placeholders[] = $result['sql'];
            $params = array_merge($params, $result['params']);
        }

        $sql = $this->dialect->buildInsertSql($this->normalizeTable(), $columns, $placeholders, $this->options);
        if (!empty($this->onDuplicate)) {
            // if no id column in columns, use first column for $defaultConflictTarget
            $defaultConflictTarget = in_array('id', $columns, true) ? 'id' : (string)($columns[0] ?? 'id');
            $tableName = $this->table ?? '';
            $sql .= ' ' . $this->dialect->buildUpsertClause($this->onDuplicate, $defaultConflictTarget, $tableName);
        }

        return [$sql, $params];
    }

    /**
     * Build INSERT multiple rows sql.
     *
     * @return array{string, array<string, string|int|float|bool|null>}
     */
    protected function buildInsertMultiSql(): array
    {
        if (empty($this->multiRows)) {
            throw new RuntimeException('insertMulti requires at least one row');
        }

        $columns = array_keys($this->multiRows[0]);
        $colsQuoted = array_map(fn ($c) => $this->dialect->quoteIdentifier($c), $columns);
        $tuples = [];     // contains strings like "(ph1, ph2, ...)" or "(raw, ph, ...)"
        $params = [];
        $i = 0;

        foreach ($this->multiRows as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $val = $row[$col];
                if ($val instanceof RawValue) {
                    $placeholders[] = $this->resolveRawValue($val);
                } else {
                    // unique placeholder for each row/column
                    $placeholder = ':' . $col . '_' . $i;
                    $placeholders[] = $placeholder;
                    $params[$placeholder] = $val;
                }
            }
            // each tuple must be wrapped in its own parentheses
            $tuples[] = '(' . implode(', ', $placeholders) . ')';
            $i++;
        }

        $opt = $this->options ? ' ' . implode(',', $this->options) : ''; // " LOW_PRIORITY IGNORE" or ''
        $tableName = $this->table; // Use getter to ensure not null
        assert(is_string($tableName)); // PHPStan assertion
        $tableSql = $this->dialect->quoteTable($tableName);
        $sql = 'INSERT' . $opt . ' INTO ' . $tableSql
            . ' (' . implode(',', $colsQuoted) . ') VALUES ' . implode(', ', $tuples);

        if (!empty($this->onDuplicate)) {
            // if no id column in columns, use first column for $defaultConflictTarget
            $defaultConflictTarget = in_array('id', $columns, true) ? 'id' : (string)($columns[0] ?? 'id');
            $tableName = $this->table ?? '';
            $sql .= ' ' . $this->dialect->buildUpsertClause($this->onDuplicate, $defaultConflictTarget, $tableName);
        }

        return [$sql, $params];
    }

    /**
     * Build UPDATE sql.
     *
     * @return array{string, array<int|string, string|int|float|bool|null>}
     */
    protected function buildUpdateSql(): array
    {
        $setParts = [];
        $options = $this->options ? implode(',', $this->options) . ' ' : '';

        foreach ($this->data as $col => $val) {
            $qid = $this->dialect->quoteIdentifier($col);

            if (is_array($val) && isset($val['__op'])) {
                $op = $val['__op'];
                switch ($op) {
                    case 'inc':
                        $setParts[] = "{$qid} = {$qid} + " . (int)$val['val'];
                        break;
                    case 'dec':
                        $setParts[] = "{$qid} = {$qid} - " . (int)$val['val'];
                        break;
                    default:
                        // for other ops expect payload under 'val'
                        if (!array_key_exists('val', $val)) {
                            throw new InvalidArgumentException("Missing 'val' for operation '{$op}' on column {$col}");
                        }
                        $valueForParam = $val['val'];
                        if ($valueForParam instanceof RawValue) {
                            $setParts[] = "{$qid} = " . $this->resolveRawValue($valueForParam);
                        } else {
                            $ph = $this->addParam("upd_{$col}", $valueForParam);
                            $setParts[] = "{$qid} = {$ph}";
                        }
                        break;
                }
            } elseif ($val instanceof RawValue) {
                $setParts[] = "{$qid} = {$this->resolveRawValue($val)}";
            } else {
                $ph = $this->addParam("upd_{$col}", $val);
                $setParts[] = "{$qid} = {$ph}";
            }
        }

        $table = $this->normalizeTable();
        $sql = "UPDATE {$options}{$table} SET " . implode(', ', $setParts);
        $sql .= $this->buildConditionsClause($this->where, 'WHERE');
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int)$this->limit;
        }
        return [$sql, $this->normalizeParams($this->params)];
    }

    /**
     * Build conditions clause.
     *
     * @param array<int, string|array<string, mixed>> $items
     * @param string $keyword
     *
     * @return string
     */
    protected function buildConditionsClause(array $items, string $keyword): string
    {
        if (empty($items)) {
            return '';
        }
        $clauses = [];
        foreach ($items as $i => $w) {
            if ($w instanceof RawValue) {
                $sql = $this->resolveRawValue($w);
                $clauses[] = ($i === 0 ? '' : 'AND ') . $sql;
                continue;
            }
            if (is_string($w)) {
                $clauses[] = ($i === 0 ? '' : 'AND ') . $w;
                continue;
            }
            $sql = $w['sql'] ?? '';
            $cond = $w['cond'] ?? ($i === 0 ? '' : 'AND');
            if ($sql === '') {
                continue;
            }
            if ($sql instanceof RawValue) {
                $sql = $this->resolveRawValue($sql);
            }
            $clauses[] = ($i === 0 || $cond === '') ? $sql : strtoupper($cond) . ' ' . $sql;
        }
        return ' ' . $keyword . ' ' . implode(' ', $clauses);
    }

    /* Condition helper core */

    /**
     * Add condition to the WHERE or HAVING clause.
     *
     * @param string $prop The property to add the condition to.
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     * @param string $cond The condition to use.
     *
     * @return self The current instance.
     */
    protected function addCondition(
        string $prop,
        string|array|RawValue $exprOrColumn,
        mixed $value,
        string $operator,
        string $cond
    ): self {
        if (is_array($exprOrColumn)) {
            foreach ($exprOrColumn as $col => $val) {
                $exprQuoted = $this->quoteQualifiedIdentifier((string)$col);
                if ($val instanceof RawValue) {
                    $resolved = $this->resolveRawValue($val);
                    // Check if RawValue already contains the column name (full condition)
                    // e.g., "age LIKE :pattern" or "age IN (:p1, :p2)"
                    $quotedCol = $this->dialect->quoteIdentifier((string)$col);
                    if (stripos($resolved, (string)$col) === 0 || stripos($resolved, $quotedCol) === 0) {
                        // Full condition - use as is
                        $this->{$prop}[] = ['sql' => $resolved, 'cond' => $cond];
                    } else {
                        // Just a value - add column and operator
                        $this->{$prop}[] = [
                            'sql' => "{$exprQuoted} {$operator} {$resolved}",
                            'cond' => $cond,
                        ];
                    }
                } elseif (is_array($value)) {
                    $this->params[trim($val, ':')] = $value[trim($val, ':')] ?? null;
                    $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} {$val}", 'cond' => $cond];
                } else {
                    $ph = $this->addParam((string)$col, $val);
                    $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} {$ph}", 'cond' => $cond];
                }
            }
            return $this;
        }

        // if RawValue is provided and there is no value — insert it as is
        if ($value === null) {
            if ($exprOrColumn instanceof RawValue) {
                $this->{$prop}[] = $this->resolveRawValue($exprOrColumn);
            } else {
                $this->{$prop}[] = $exprOrColumn;
            }
            return $this;
        }

        if ($exprOrColumn instanceof RawValue) {
            $left = $this->resolveRawValue($exprOrColumn);
            $this->{$prop}[] = ['sql' => "{$left} {$operator} {$value}", 'cond' => $cond];
            return $this;
        }

        $exprQuoted = $this->quoteQualifiedIdentifier((string)$exprOrColumn);

        // subquery handling
        if ($value instanceof self) {
            $sub = $value->toSQL();
            $map = $this->mergeSubParams($sub['params'], 'sq');
            $subSql = $this->replacePlaceholdersInSql($sub['sql'], $map);
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} ({$subSql})", 'cond' => $cond];
            return $this;
        }

        // callback handling for subqueries
        if (is_callable($value)) {
            $subQuery = new self($this->connection, $this->prefix ?? '');
            $value($subQuery);
            $sub = $subQuery->toSQL();
            $map = $this->mergeSubParams($sub['params'], 'sq');
            $subSql = $this->replacePlaceholdersInSql($sub['sql'], $map);
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} ({$subSql})", 'cond' => $cond];
            return $this;
        }

        $opUpper = $this->normalizeOperator($operator);
        // support IN / NOT IN with an array of values
        if (($opUpper === 'IN' || $opUpper === 'NOT IN') && is_array($value)) {
            if (empty($value)) {
                // The semantics of an empty IN depend on the logic: it's better to form a condition
                // that is always false/true. Here it's safe to create a condition that never matches for IN,
                // and always matches for NOT IN.
                if ($opUpper === 'IN') {
                    $this->{$prop}[] = ['sql' => '0=1', 'cond' => $cond];
                } else {
                    $this->{$prop}[] = ['sql' => '1=1', 'cond' => $cond];
                }
                return $this;
            }

            $placeholders = [];
            foreach ($value as $i => $v) {
                if ($v instanceof RawValue) {
                    $placeholders[] = $this->resolveRawValue($v);
                    continue;
                }
                $placeholders[] = $this->addParam((string)$exprOrColumn . '_in_' . $i, $v);
            }

            $inSql = '(' . implode(', ', $placeholders) . ')';
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$opUpper} {$inSql}", 'cond' => $cond];
            return $this;
        }

        // handle BETWEEN / NOT BETWEEN when value is array with two items
        if (($opUpper === 'BETWEEN' || $opUpper === 'NOT BETWEEN') && is_array($value)) {
            $value = array_values($value);

            // require exactly two bounds; if not - treat defensively
            if (count($value) !== 2) {
                throw new InvalidArgumentException('BETWEEN requires an array with exactly two elements.');
            }

            // left and right bounds
            [$low, $high] = $value;

            // support RawValue bounds
            if ($low instanceof RawValue) {
                $left = $this->resolveRawValue($low);
            } else {
                $left = $this->addParam($exprOrColumn . '_bt_low', $low);
            }

            if ($high instanceof RawValue) {
                $right = $this->resolveRawValue($high);
            } else {
                $right = $this->addParam($exprOrColumn . '_bt_high', $high);
            }

            $this->{$prop}[] = [
                'sql' => "{$exprQuoted} {$opUpper} {$left} AND {$right}",
                'cond' => $cond,
            ];
            return $this;
        }

        if ($value instanceof RawValue) {
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} {$this->resolveRawValue($value)}", 'cond' => $cond];
        } else {
            $ph = $this->addParam((string)$exprOrColumn, $value);
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} {$ph}", 'cond' => $cond];
        }

        return $this;
    }

    /* Execution helpers */

    /**
     * Execute INSERT statement.
     *
     * @param string $sql
     * @param array<string, string|int|float|bool|null> $params
     * @param bool $isMulty
     *
     * @return int
     * @throws PDOException
     */
    protected function executeInsert(string $sql, array $params, bool $isMulty = false): int
    {
        $stmt = $this->executeStatement($sql, $params);
        if ($isMulty) {
            return $stmt->rowCount();
        }

        try {
            $id = (int)$this->connection->getLastInsertId();
            return $id > 0 ? $id : 1;
        } catch (PDOException $e) {
            // PostgreSQL: lastval is not yet defined (no SERIAL column)
            // Convert to specialized exception for better error handling
            $dbException = ExceptionFactory::createFromPdoException(
                $e,
                $this->connection->getDriverName(),
                $sql,
                ['operation' => 'getLastInsertId', 'fallback' => true]
            );

            // For this specific case, we return 1 as fallback instead of throwing
            // This maintains backward compatibility
            return 1;
        }
    }

    /* Utilities - table / params / quoting / raw resolution */

    /**
     * Merge subquery parameters.
     *
     * @param array<string, string|int|float|bool|null> $subParams
     * @param string $prefix
     *
     * @return array<string, string>
     */
    protected function mergeSubParams(array $subParams, string $prefix = 'sub'): array
    {
        $map = [];
        foreach ($subParams as $old => $val) {
            $key = ltrim($old, ':');
            $new = ':' . $prefix . '_' . $key . '_' . count($this->params);
            $this->params[$new] = $val;
            $map[$old] = $new;
        }
        return $map;
    }

    /**
     * Replace placeholders in SQL.
     *
     * @param string $sql
     * @param array<string, string> $map
     *
     * @return string
     */
    protected function replacePlaceholdersInSql(string $sql, array $map): string
    {
        if (empty($map)) {
            return $sql;
        }
        uksort($map, static function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        return strtr($sql, $map);
    }

    /**
     * Resolve selected key.
     *
     * @return ?string
     */
    protected function resolveSelectedKey(): ?string
    {
        if (count($this->select) !== 1) {
            return null;
        }

        $expr = $this->select[0];

        // 1) Try to capture explicit alias at the end: " ... AS alias" or " ... alias"
        //    Allow optional quoting with backticks, double quotes or square brackets.
        if (preg_match('/\s+(?:AS\s+)?[`"\[]?([A-Za-z0-9_]+)[`"\]]?\s*$/i', $expr, $matches)) {
            return $matches[1];
        }

        // 2) If expression is a simple identifier (table.col or col), return last segment
        if (preg_match('/^[A-Za-z0-9_\.]+$/', $expr)) {
            $parts = explode('.', $expr);
            return end($parts);
        }

        // 3) Complex expression without alias — cannot determine key
        return $expr;
    }

    /**
     * Quote qualified identifier.
     *
     * @param string $name
     *
     * @return string
     */
    protected function quoteQualifiedIdentifier(string $name): string
    {
        // If looks like an expression (contains spaces, parentheses, commas or quotes)
        // treat as raw expression but DO NOT accept suspicious unquoted parts silently.
        if (preg_match('/[`\["\'\s\(\),]/', $name)) {
            // allow already-quoted or complex expressions to pass through,
            // but still protect obvious injection attempts by checking for dangerous tokens
            if (preg_match('/;|--|\bDROP\b|\bDELETE\b|\bINSERT\b|\bUPDATE\b|\bSELECT\b|\bUNION\b/i', $name)) {
                throw new InvalidArgumentException('Unsafe SQL expression provided as identifier/expression.');
            }
            return $name;
        }

        $parts = explode('.', $name);
        foreach ($parts as $p) {
            // require valid simple identifier parts
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $p)) {
                throw new InvalidArgumentException("Invalid identifier part: {$p}");
            }
        }
        $quoted = array_map(fn ($p) => $this->dialect->quoteIdentifier($p), $parts);
        return implode('.', $quoted);
    }

    /**
     * Make parameter name.
     *
     * @param string $name
     *
     * @return string
     */
    protected function makeParam(string $name): string
    {
        $base = preg_replace('/[^a-z0-9_]/i', '_', $name) ?: 'p';
        $this->paramCounter++;
        return ':' . $base . '_' . $this->paramCounter;
    }

    /**
     * Create parameter and register it in params array.
     *
     * @param string $name
     * @param mixed $value
     *
     * @return string The placeholder name
     */
    protected function addParam(string $name, mixed $value): string
    {
        $ph = $this->makeParam($name);
        $this->params[$ph] = $value;
        return $ph;
    }

    /**
     * Normalize operator (trim and uppercase).
     *
     * @param string $operator
     *
     * @return string
     */
    protected function normalizeOperator(string $operator): string
    {
        return strtoupper(trim($operator));
    }

    /**
     * Process value for SQL - returns placeholder or raw SQL.
     *
     * @param mixed $value
     * @param string $columnName
     * @param string $prefix
     *
     * @return array{sql: string, params: array<string, mixed>}
     */
    protected function processValueForSql(mixed $value, string $columnName, string $prefix = ''): array
    {
        if ($value instanceof RawValue) {
            return ['sql' => $this->resolveRawValue($value), 'params' => []];
        }

        // Create placeholder (simple or with prefix)
        $ph = $prefix === '' ? ':' . $columnName : ':' . $prefix . $columnName;
        return ['sql' => $ph, 'params' => [$ph => $value]];
    }

    /**
     * Normalizes a table name by prefixing it with the database prefix if it is set.
     *
     * @param string|null $table
     *
     * @return string The normalized table name.
     */
    protected function normalizeTable(?string $table = null): string
    {
        $table = $table ?: $this->table;
        return $this->dialect->quoteTable($this->prefix . $table);
    }

    /**
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return array<int|string, string|int|float|bool|null>
     */
    protected function normalizeParams(array $params): array
    {
        // Check if all keys are sequential integers starting from 0 (positional parameters)
        $keys = array_keys($params);
        if ($keys === range(0, count($keys) - 1)) {
            // Positional parameters - return as is
            return $params;
        }

        // Named parameters - normalize by adding : prefix if needed
        /** @var array<string, string|int|float|bool|null> $out */
        $out = [];
        foreach ($params as $k => $v) {
            if (is_string($k)) {
                $key = !str_starts_with($k, ':') ? ":$k" : $k;
            } else {
                $key = ':param_' . $k;
            }
            $out[$key] = $v;
        }
        return $out;
    }

    /**
     * Resolve RawValue instances — return dialect-specific NOW() when NowValue provided.
     * Binds any parameters from RawValue into $this->params.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    protected function resolveRawValue(string|RawValue $value): string
    {
        if (!$value instanceof RawValue) {
            return $value;
        }
        $result = match (true) {
            $value instanceof NowValue => $this->dialect->now($value->getValue(), $value->getAsTimestamp()),
            $value instanceof ILikeValue => $this->dialect->ilike($value->getValue(), (string)$value->getParams()[0]),
            $value instanceof EscapeValue => $this->connection->quote($value->getValue()) ?: "'" . addslashes((string)$value->getValue()) . "'",
            $value instanceof ConfigValue => $this->dialect->config($value),
            $value instanceof ConcatValue => $this->dialect->concat($value),
            $value instanceof JsonGetValue => $this->dialect->formatJsonGet($value->getColumn(), $value->getPath(), $value->getAsText()),
            $value instanceof JsonLengthValue => $this->dialect->formatJsonLength($value->getColumn(), $value->getPath()),
            $value instanceof JsonKeysValue => $this->dialect->formatJsonKeys($value->getColumn(), $value->getPath()),
            $value instanceof JsonTypeValue => $this->dialect->formatJsonType($value->getColumn(), $value->getPath()),
            $value instanceof JsonPathValue => $this->resolveJsonPathValue($value),
            $value instanceof JsonContainsValue => $this->resolveJsonContainsValue($value),
            $value instanceof JsonExistsValue => $this->dialect->formatJsonExists($value->getColumn(), $value->getPath()),
            $value instanceof IfNullValue => $this->dialect->formatIfNull($value->getExpr(), $value->getDefaultValue()),
            $value instanceof GreatestValue => $this->dialect->formatGreatest($value->getValues()),
            $value instanceof LeastValue => $this->dialect->formatLeast($value->getValues()),
            $value instanceof SubstringValue => $this->dialect->formatSubstring($value->getSource(), $value->getStart(), $value->getLength()),
            $value instanceof ModValue => $this->dialect->formatMod($value->getDividend(), $value->getDivisor()),
            $value instanceof CurDateValue => $this->dialect->formatCurDate(),
            $value instanceof CurTimeValue => $this->dialect->formatCurTime(),
            $value instanceof YearValue => $this->dialect->formatYear($value->getSource()),
            $value instanceof MonthValue => $this->dialect->formatMonth($value->getSource()),
            $value instanceof DayValue => $this->dialect->formatDay($value->getSource()),
            $value instanceof HourValue => $this->dialect->formatHour($value->getSource()),
            $value instanceof MinuteValue => $this->dialect->formatMinute($value->getSource()),
            $value instanceof SecondValue => $this->dialect->formatSecond($value->getSource()),
            default => $value,
        };

        if ($result instanceof RawValue) { // Allow nested RawValue resolution
            $value = $result;
        } else {
            return $result;
        }

        $sql = $value->getValue();
        $params = $value->getParams();

        if (empty($params)) {
            return $sql;
        }

        // Create map of old => new parameter names and merge params
        $paramMap = [];
        foreach ($params as $key => $val) {
            // Ensure parameter name starts with :
            $keyStr = (string)$key;
            $oldParam = str_starts_with($keyStr, ':') ? $keyStr : ':' . $keyStr;
            // Create new unique parameter name
            $newParam = $this->makeParam('raw_' . ltrim($oldParam, ':'));
            $paramMap[$oldParam] = $newParam;
            $this->params[$newParam] = $val;
        }

        // Replace old parameter names with new ones in SQL
        return strtr($sql, $paramMap);
    }

    /**
     * Resolve JsonPathValue - build comparison expression.
     *
     * @param JsonPathValue $value
     *
     * @return string
     */
    protected function resolveJsonPathValue(JsonPathValue $value): string
    {
        $expr = $this->dialect->formatJsonGet($value->getColumn(), $value->getPath(), true);
        $compareValue = $value->getCompareValue();

        if ($compareValue instanceof RawValue) {
            $right = $this->resolveRawValue($compareValue);
            return "{$expr} {$value->getOperator()} {$right}";
        }

        $ph = $this->addParam('jsonpath_' . $value->getColumn(), $compareValue);
        return "{$expr} {$value->getOperator()} {$ph}";
    }

    /**
     * Resolve JsonContainsValue - build contains check expression.
     *
     * @param JsonContainsValue $value
     *
     * @return string
     */
    protected function resolveJsonContainsValue(JsonContainsValue $value): string
    {
        $res = $this->dialect->formatJsonContains($value->getColumn(), $value->getSearchValue(), $value->getPath());

        if (is_array($res)) {
            [$sql, $params] = $res;
            foreach ($params as $k => $v) {
                $old = strpos($k, ':') === 0 ? $k : ':' . $k;
                $new = $this->makeParam('jsonc_' . ltrim($old, ':'));
                $this->params[$new] = $v;
                $sql = strtr($sql, [$old => $new]);
            }
            return $sql;
        }

        return $res;
    }
}
