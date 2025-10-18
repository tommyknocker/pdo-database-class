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
use tommyknocker\pdodb\helpers\CaseValue;
use tommyknocker\pdodb\helpers\ConfigValue;
use tommyknocker\pdodb\helpers\EscapeValue;
use tommyknocker\pdodb\helpers\ILikeValue;
use tommyknocker\pdodb\helpers\RawValue;
use tommyknocker\pdodb\helpers\NowValue;

class QueryBuilder
{
    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;

    protected ?string $table = null {
        get {
            if (!$this->table) {
                throw new RuntimeException('You must define table first. User table() or from() methods');
            }
            return $this->table;
        }
    }
    protected array $select = [];
    protected array $joins = [];
    protected array $where = [];
    protected array $having = [];
    protected array $params = [];
    protected array $data = [];
    protected array $multiRows = [];
    protected array $onDuplicate = [];
    protected array $order = [];
    protected ?string $group = null;
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected ?string $prefix = null;

    protected int $fetchMode = PDO::FETCH_ASSOC;

    protected array $options = [];

    public function __construct(ConnectionInterface $connection, string $prefix = '')
    {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->prefix = $prefix;
    }

    /**
     * Sets the table to query.
     *
     * @param string $table The table to query.
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
     * @return self The current instance.
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Adds columns to the SELECT clause.
     *
     * @param RawValue|string|array $cols The columns to add.
     * @return self The current instance.
     */
    public function select(RawValue|string|array $cols): self
    {
        if (!is_array($cols)) {
            $cols = [$cols];
        }
        foreach ($cols as $index => $col) {
            if ($col instanceof CaseValue) {
                $this->select[] = $this->resolveRawValue($col) . ' AS ' . $index;
            } elseif ($col instanceof RawValue) {
                $this->select[] = $this->resolveRawValue($col);
            } elseif (is_string($index)) { // ['total' => 'SUM(amount)] Treat it as SUM(amount) AS total
                $this->select[] = $col . ' AS ' . $index;
            } else {
                $this->select[] = $col;
            }
        }
        return $this;
    }

    /**
     * Return true if at least one row matches the current WHERE conditions.
     *
     * This builds a single query using SQL EXISTS and executes it.
     * It does not execute any nested queries separately; the subquery is compiled only.
     *
     * @return bool
     * @throws PDOException
     */
    public function exists(): bool
    {
        $this->limit(1);
        $subSql = $this->buildSelectSql();
        $params = $this->params ?? [];
        $sql = 'SELECT EXISTS(' . $subSql . ')';
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


    /**
     * Add WHERE clause.
     *
     * @param string|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     * @return self The current instance.
     */
    public function where(string|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        return $this->addCondition('where', $exprOrColumn, $value, $operator, 'AND');
    }

    /**
     * Add AND WHERE clause.
     *
     * @param string|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     * @return self The current instance.
     */
    public function andWhere(string|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        return $this->where($exprOrColumn, $value, $operator);
    }

    /**
     * Add OR WHERE clause.
     *
     * @param string|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     * @return self The current instance.
     */
    public function orWhere(string|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        return $this->addCondition('where', $exprOrColumn, $value, $operator, 'OR');
    }

    /**
     * Add HAVING clause.
     *
     * @param string|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     * @return self The current instance.
     */
    public function having(string|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        return $this->addCondition('having', $exprOrColumn, $value, $operator, 'AND');
    }

    /**
     * Add OR HAVING clause.
     *
     * @param string|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     * @return self The current instance.
     */
    public function orHaving(string|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self
    {
        return $this->addCondition('having', $exprOrColumn, $value, $operator, 'OR');
    }

    /**
     * Add condition to the WHERE or HAVING clause.
     *
     * @param string $prop The property to add the condition to.
     * @param string|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     * @param string $cond The condition to use.
     * @return self The current instance.
     */
    protected function addCondition(
        string $prop,
        string|RawValue $exprOrColumn,
        mixed $value,
        string $operator,
        string $cond
    ): self {
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
            $sub = $value->compile();
            $map = $this->mergeSubParams($sub['params'], 'sq');
            $subSql = $this->replacePlaceholdersInSql($sub['sql'], $map);
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} ({$subSql})", 'cond' => $cond];
            return $this;
        }

        $opUpper = strtoupper(trim($operator));
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
                $ph = $this->makeParam((string)$exprOrColumn . '_in_' . $i);
                $this->params[$ph] = $v;
                $placeholders[] = $ph;
            }

            $inSql = '(' . implode(', ', $placeholders) . ')';
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$opUpper} {$inSql}", 'cond' => $cond];
            return $this;
        }

        // handle BETWEEN / NOT BETWEEN when value is array with two items
        $opUpper = strtoupper(trim($operator));
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
                $left = $this->makeParam((string)$exprOrColumn . '_bt_low');
                $this->params[$left] = $low;
            }

            if ($high instanceof RawValue) {
                $right = $this->resolveRawValue($high);
            } else {
                $right = $this->makeParam((string)$exprOrColumn . '_bt_high');
                $this->params[$right] = $high;
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
            $ph = $this->makeParam((string)$exprOrColumn);
            $this->params[$ph] = $value;
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} {$ph}", 'cond' => $cond];
        }

        return $this;
    }


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
            $this->order[] = $this->dialect->quoteIdentifier($expr) . ' ' . $dir;
        }

        return $this;
    }

    /**
     * Add GROUP BY clause.
     *
     * @param string|array $cols The columns to group by.
     * @return self The current instance.
     */
    public function groupBy(string|array $cols): self
    {
        if (!is_array($cols)) {
            $cols = [$cols];
        }
        $groups = [];
        foreach ($cols as $col) {
            if ($col instanceof RawValue) {
                $groups[] = $this->resolveRawValue($col);
            } else {
                $groups[] = $this->dialect->quoteIdentifier((string)$col);
            }
        }
        $this->group = implode(', ', $groups);
        return $this;
    }

    /**
     * Add LIMIT clause.
     *
     * @param int $number The number of rows to limit.
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
     * @return self The current instance.
     */
    public function offset(int $number): self
    {
        $this->offset = $number;
        return $this;
    }

    /**
     * Add ON DUPLICATE clause.
     *
     * @param array $onDuplicate The columns to update on duplicate.
     * @return self The current instance.
     */
    public function onDuplicate(array $onDuplicate): self
    {
        $this->onDuplicate = $onDuplicate;
        return $this;
    }

    /**
     * Insert data into the table.
     *
     * @param array $data The data to insert.
     * @param array $onDuplicate The columns to update on duplicate.
     * @return mixed The result of the insert operation.
     */
    public function insert(array $data, array $onDuplicate = []): mixed
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
     * @param array $rows The rows to insert.
     * @param array $onDuplicate The columns to update on duplicate.
     * @return mixed The result of the insert operation.
     */
    public function insertMulti(array $rows, array $onDuplicate = []): mixed
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
     * @param array $data The data to replace.
     * @param array $onDuplicate The columns to update on duplicate.
     * @return mixed The result of the replace operation.
     */
    public function replace(array $data, array $onDuplicate = []): mixed
    {
        $this->data = $data;

        if ($onDuplicate) {
            $this->onDuplicate = $onDuplicate;
        }

        $columns = array_keys($this->data);
        $placeholders = [];
        $params = [];
        foreach ($columns as $col) {
            $val = $this->data[$col];
            if ($val instanceof RawValue) {
                $placeholders[] = $this->resolveRawValue($val);
            } else {
                $ph = ':' . $col;
                $placeholders[] = $ph;
                $params[$ph] = $val;
            }
        }
        $sql = $this->dialect->buildReplaceSql($this->table, $columns, $placeholders);
        return $this->executeInsert($sql, $params);
    }

    /**
     * Replace multiple rows into the table.
     *
     * @param array $rows The rows to replace.
     * @param array $onDuplicate The columns to update on duplicate.
     * @return mixed The result of the replace operation.
     */
    public function replaceMulti(array $rows, array $onDuplicate = []): mixed
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
                $val = $row[$col];
                if ($val instanceof RawValue) {
                    $placeholders[] = $this->resolveRawValue($val);
                } else {
                    $ph = ':' . $col . '_' . $i;
                    $placeholders[] = $ph;
                    $params[$ph] = $val;
                }
            }
            // collect per-row group WITHOUT adding outer parentheses here
            // because dialect will assemble VALUES list and must accept both modes
            $valuesList[] = '(' . implode(', ', $placeholders) . ')';
            $i++;
        }

        // Pass isMultiple = true so dialect will not add extra parentheses
        $sql = $this->dialect->buildReplaceSql($this->table, $columns, $valuesList, true);
        return $this->executeInsert($sql, $params);
    }

    /**
     * Execute UPDATE statement
     * @param array $data
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
     * Execute DELETE statement
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
     * Execute SELECT statement and return all rows
     * @return mixed
     * @throws PDOException
     */
    public function get(): mixed
    {
        $sql = $this->buildSelectSql();
        return $this->fetchAll($sql, $this->params);
    }

    /**
     * Execute SELECT statement and return first row
     * @return mixed
     * @throws PDOException
     */
    public function getOne(): mixed
    {
        $sql = $this->buildSelectSql();
        return $this->fetch($sql, $this->params);
    }

    /**
     * Execute SELECT statement and return column values
     * @return array
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
     * Execute SELECT statement and return single value
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

    /**
     * Execute TRUNCATE statement
     * @return bool
     * @throws PDOException
     */
    public function truncate(): bool
    {
        $sql = $this->dialect->buildTruncateSql($this->table);
        $this->executeStatement($sql);
        return $this->connection->getLastErrno() === 0;
    }

    /**
     * Sets the query options.
     *
     * @param string|array $options The query options.
     * @return self The current object.
     */
    public function option(string|array $options): self
    {
        if (is_array($options)) {
            $this->options += $options;
        } else {
            $this->options[] = $options;
        }
        return $this;
    }

    /**
     * Set fetch mode to return objects
     * @return $this
     */
    public function asObject()
    {
        $this->fetchMode = PDO::FETCH_OBJ;
        return $this;
    }

    /* internal builders */

    /**
     * Build SELECT sql
     * @return string
     */
    protected function buildSelectSql(): string
    {
        // build base select (no DB-specific option handling)
        if (empty($this->select)) {
            $select = '*';
        } else {
            $select = implode(', ', array_map(fn($value) => $this->quoteQualifiedIdentifier($value), $this->select));
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
     * Build INSERT sql
     * @return array
     */
    protected function buildInsertSql(): array
    {
        $columns = array_keys($this->data);
        $placeholders = [];
        $params = [];

        foreach ($columns as $col) {
            $value = $this->data[$col];
            if ($value instanceof RawValue) {
                $placeholders[] = $this->resolveRawValue($value);
            } else {
                $placeholder = ':' . $col;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $value;
            }
        }

        $sql = $this->dialect->buildInsertSql($this->normalizeTable(), $columns, $placeholders, $this->options);
        if (!empty($this->onDuplicate)) {
            // if no id column in columns, use first column for $defaultConflictTarget
            $defaultConflictTarget = in_array('id', $columns, true) ? 'id' : ($columns[0] ?? 'id');
            $sql .= ' ' . $this->dialect->buildUpsertClause($this->onDuplicate, $defaultConflictTarget);
        }

        return [$sql, $params];
    }

    /**
     * Build INSERT multiple rows sql
     * @return array
     */
    protected function buildInsertMultiSql(): array
    {
        if (empty($this->multiRows)) {
            throw new RuntimeException('insertMulti requires at least one row');
        }

        $columns = array_keys($this->multiRows[0]);
        $colsQuoted = array_map(fn($c) => $this->dialect->quoteIdentifier($c), $columns);
        $tuples = [];     // contains strings like "(ph1, ph2, ...)" or "(raw, ph, ...)"
        $params = [];
        $i = 0;

        foreach ($this->multiRows as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $val = $row[$col];
                if ($val instanceof RawValue) {
                    // insert raw expression directly without a parameter
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
        $tableSql = $this->dialect->quoteTable($this->table);
        $sql = 'INSERT' . $opt . ' INTO ' . $tableSql
            . ' (' . implode(',', $colsQuoted) . ') VALUES ' . implode(', ', $tuples);

        if (!empty($this->onDuplicate)) {
            $sql .= ' ' . $this->dialect->buildUpsertClause($this->onDuplicate);
        }

        return [$sql, $params];
    }

    /**
     * Build UPDATE sql
     * @return array
     */
    protected function buildUpdateSql(): array
    {
        $setParts = [];
        $params = [];
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
                            $ph = $this->makeParam("upd_{$col}");
                            $setParts[] = "{$qid} = {$ph}";
                            $params[$ph] = $valueForParam;
                        }
                        break;
                }
            } elseif ($val instanceof RawValue) {
                $setParts[] = "{$qid} = {$this->resolveRawValue($val)}";
            } else {
                $ph = $this->makeParam("upd_{$col}");
                $setParts[] = "{$qid} = {$ph}";
                $params[$ph] = $val;
            }
        }

        $table = $this->normalizeTable();
        $sql = "UPDATE {$options}{$table} SET " . implode(', ', $setParts);
        $sql .= $this->buildConditionsClause($this->where, 'WHERE');
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int)$this->limit;
        }
        return [$sql, array_merge($this->params, $params)];
    }

    /**
     * Build conditions clause
     * @param array $items
     * @param string $keyword
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

    /* execution helpers */

    /**
     * Execute INSERT statement
     * @param string $sql
     * @param array $params
     * @param bool $isMulty
     * @return int
     * @throws PDOException
     */
    protected function executeInsert(string $sql, array $params, bool $isMulty = false): int
    {
        $stmt = $this->executeStatement($sql, $params);
        if ($isMulty) {
            return $stmt->rowCount();
        }
        $id = (int)$this->connection->getLastInsertId();
        return $id > 0 ? $id : 1;
    }

    /**
     * Execute statement
     * @param string|RawValue $sql
     * @param array $params
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
     * Fetch all rows
     * @param string|RawValue $sql
     * @param array $params
     * @return array
     * @throws PDOException
     */
    public function fetchAll(string|RawValue $sql, array $params = []): array
    {
        return $this->executeStatement($sql, $params)->fetchAll($this->fetchMode);
    }

    /**
     * Fetch column
     * @param string|RawValue $sql
     * @param array $params
     * @return mixed
     * @throws PDOException
     */
    public function fetchColumn(string|RawValue $sql, array $params = []): mixed
    {
        return $this->executeStatement($sql, $params)->fetchColumn();
    }

    /**
     * Fetch row
     * @param string|RawValue $sql
     * @param array $params
     * @return mixed
     * @throws PDOException
     */
    public function fetch(string|RawValue $sql, array $params = []): mixed
    {
        return $this->executeStatement($sql, $params)->fetch($this->fetchMode);
    }

    /* utilities */

    /**
     * Compile query
     * @return array
     */
    public function compile(): array
    {
        $sql = $this->buildSelectSql();
        $params = $this->params ?? [];
        return ['sql' => $sql, 'params' => $params];
    }


    /* ---------------- LOAD CSV/XML ---------------- */

    /**
     * Loads data from a CSV file into a table.
     *
     * @param string $filePath The path to the CSV file.
     * @param array $options The options to use to load the data.
     * @return bool True on success, false on failure.
     */
    public function loadCsv(string $filePath, array $options = []): bool
    {
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
        }
        return false;
    }


    /**
     * Loads data from an XML file into a table.
     *
     * @param string $filePath The path to the XML file.
     * @param string $rowTag The tag that identifies a row.
     * @param int|null $linesToIgnore The number of lines to ignore at the beginning of the file.
     * @return bool True on success, false on failure.
     */
    public function loadXml(string $filePath, string $rowTag = '<row>', ?int $linesToIgnore = null): bool
    {
        if (!$this->connection->inTransaction()) {
            $this->connection->transaction();
        }
        try {
            $options = [
                'rowTag' => $rowTag,
                'linesToIgnore' => $linesToIgnore
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
        }
        return false;
    }

    /**
     * Merge subquery parameters
     * @param array $subParams
     * @param string $prefix
     * @return array
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
     * Replace placeholders in SQL
     * @param string $sql
     * @param array $map
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
     * Resolve selected key
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
     * Quote qualified identifier
     * @param string $name
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
        $quoted = array_map(fn($p) => $this->dialect->quoteIdentifier($p), $parts);
        return implode('.', $quoted);
    }

    /**
     * Make parameter name
     * @param string $name
     * @return string
     */
    protected function makeParam(string $name): string
    {
        // sanitize base name and ensure unique suffix
        $base = preg_replace('/[^a-z0-9_]/i', '_', $name) ?: 'p';
        $index = count($this->params);
        return ':' . $base . '_' . $index;
    }

    /**
     * Normalizes a table name by prefixing it with the database prefix if it is set.
     * @param string|null $table
     * @return string The normalized table name.
     */
    protected function normalizeTable(?string $table = null): string
    {
        $table = $table ?: $this->table;
        return $this->dialect->quoteTable($this->prefix . $table);
    }

    protected function normalizeParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $key = is_string($k) ? ltrim($k, ':') : $k;
            $out[$key] = $v;
        }
        return $out;
    }

    /**
     * Resolve RawValue instances — return dialect-specific NOW() when NowValue provided.
     * Binds any parameters from RawValue into $this->params.
     *
     * @param string|RawValue $value
     * @return string
     */
    protected function resolveRawValue(string|RawValue $value): string
    {
        if (!$value instanceof RawValue) {
            return $value;
        }
        $result = match (true) {
            $value instanceof NowValue => $this->dialect->now($value->getValue()),
            $value instanceof ILikeValue => $this->dialect->ilike($value->getValue(), $value->getParams()[0]),
            $value instanceof EscapeValue => $this->connection->quote($value->getValue()),
            $value instanceof ConfigValue => $this->dialect->config($value),
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
            $oldParam = strpos($key, ':') === 0 ? $key : ':' . $key;
            // Create new unique parameter name
            $newParam = $this->makeParam('raw_' . ltrim($oldParam, ':'));
            $paramMap[$oldParam] = $newParam;
            $this->params[ltrim($newParam, ':')] = $val;
        }

        // Replace old parameter names with new ones in SQL
        return strtr($sql, $paramMap);
    }

}