<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use InvalidArgumentException;
use PDOException;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\RawValue;

class ConditionBuilder implements ConditionBuilderInterface
{
    protected ConnectionInterface $connection;
    protected DialectInterface $dialect;
    protected ParameterManagerInterface $parameterManager;
    protected ExecutionEngineInterface $executionEngine;
    protected RawValueResolver $rawValueResolver;

    /** @var array<int, string|array<string, mixed>> */
    protected array $where = [];

    /** @var array<int, string|array<string, mixed>> */
    protected array $having = [];

    /** @var string|null table name */
    protected ?string $table = null;

    /** @var string|null Table prefix */
    protected ?string $prefix = null;

    /** @var array<int, string> ORDER BY expressions */
    protected array $order = [];

    /** @var int|null LIMIT value */
    protected ?int $limit = null;

    public function __construct(
        ConnectionInterface $connection,
        ParameterManagerInterface $parameterManager,
        ExecutionEngineInterface $executionEngine,
        RawValueResolver $rawValueResolver
    ) {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->parameterManager = $parameterManager;
        $this->executionEngine = $executionEngine;
        $this->rawValueResolver = $rawValueResolver;
    }

    /**
     * Set table name.
     *
     * @param string $table
     *
     * @return self
     */
    public function setTable(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Set table prefix.
     *
     * @param string|null $prefix
     *
     * @return self
     */
    public function setPrefix(?string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Set limit.
     *
     * @param int|null $limit
     *
     * @return self
     */
    public function setLimit(?int $limit): self
    {
        $this->limit = $limit;
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
     * Add WHERE clause.
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
        $instance = new QueryBuilder($this->connection, $this->prefix ?? '');
        $subquery($instance);
        $sub = $instance->toSQL();
        $map = $this->parameterManager->mergeSubParams($sub['params'], 'sq');
        $subSql = $this->parameterManager->replacePlaceholdersInSql($sub['sql'], $map);
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
        $instance = new QueryBuilder($this->connection, $this->prefix ?? '');
        $subquery($instance);
        $sub = $instance->toSQL();
        $map = $this->parameterManager->mergeSubParams($sub['params'], 'sq');
        $subSql = $this->parameterManager->replacePlaceholdersInSql($sub['sql'], $map);
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
            $this->parameterManager->setParam($placeholder, $value);
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
            $this->parameterManager->setParam($placeholder, $value);
        }
        $this->having[] = ['sql' => $sql, 'cond' => 'AND'];
        return $this;
    }

    /**
     * Return true if at least one row matches the current WHERE conditions.
     *
     * @return bool
     * @throws PDOException
     */
    public function exists(): bool
    {
        $this->limit(1);
        $subSql = $this->buildSelectSql();
        $params = $this->parameterManager->getParams();
        $params = $this->parameterManager->normalizeParams($params);
        $sql = 'SELECT EXISTS(' . $subSql . ')';
        return (bool)$this->executionEngine->fetchColumn($sql, $params);
    }

    /**
     * Return true if no rows match the current WHERE conditions.
     *
     * @return bool
     * @throws PDOException
     */
    public function notExists(): bool
    {
        $this->limit(1);
        $subSql = $this->buildSelectSql();
        $params = $this->parameterManager->getParams();
        $sql = 'SELECT NOT EXISTS(' . $subSql . ')';
        return (bool)$this->executionEngine->fetchColumn($sql, $params);
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
        $res = $this->executionEngine->executeStatement($sql)->fetchColumn();
        return !empty($res);
    }

    /**
     * Build conditions clause.
     *
     * @param array<int, string|array<string, mixed>> $items
     * @param string $keyword
     *
     * @return string
     */
    public function buildConditionsClause(array $items, string $keyword): string
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

    /**
     * Get WHERE conditions.
     *
     * @return array<int, string|array<string, mixed>>
     */
    public function getWhere(): array
    {
        return $this->where;
    }

    /**
     * Get HAVING conditions.
     *
     * @return array<int, string|array<string, mixed>>
     */
    public function getHaving(): array
    {
        return $this->having;
    }

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
                    $this->parameterManager->addParam(trim($val, ':'), $value[trim($val, ':')] ?? null);
                    $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} {$val}", 'cond' => $cond];
                } else {
                    $ph = $this->parameterManager->addParam((string)$col, $val);
                    $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} {$ph}", 'cond' => $cond];
                }
            }
            return $this;
        }

        // if RawValue is provided and there is no value — insert it as is
        if ($value === null) {
            if ($exprOrColumn instanceof RawValue) {
                $resolved = $this->resolveRawValue($exprOrColumn);
                $this->{$prop}[] = ['sql' => $resolved, 'cond' => $cond];
            } else {
                $this->{$prop}[] = ['sql' => $exprOrColumn, 'cond' => $cond];
            }
            return $this;
        }

        if ($exprOrColumn instanceof RawValue) {
            $left = $this->resolveRawValue($exprOrColumn);
            $this->{$prop}[] = ['sql' => "{$left} {$operator} {$value}", 'cond' => $cond];
            return $this;
        }

        $exprQuoted = $this->quoteQualifiedIdentifier((string)$exprOrColumn);

        // Process external references
        $value = $this->processExternalReferences($value);

        // subquery handling
        if ($value instanceof QueryBuilder) {
            $sub = $value->toSQL();
            $map = $this->parameterManager->mergeSubParams($sub['params'], 'sq');
            $subSql = $this->parameterManager->replacePlaceholdersInSql($sub['sql'], $map);
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} ({$subSql})", 'cond' => $cond];
            return $this;
        }

        // callback handling for subqueries
        if (is_callable($value)) {
            $subQuery = new QueryBuilder($this->connection, $this->prefix ?? '');
            $value($subQuery);
            $sub = $subQuery->toSQL();
            $map = $this->parameterManager->mergeSubParams($sub['params'], 'sq');
            $subSql = $this->parameterManager->replacePlaceholdersInSql($sub['sql'], $map);
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
                $placeholders[] = $this->parameterManager->addParam((string)$exprOrColumn . '_in_' . $i, $v);
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
                $left = $this->parameterManager->addParam($exprOrColumn . '_bt_low', $low);
            }

            if ($high instanceof RawValue) {
                $right = $this->resolveRawValue($high);
            } else {
                $right = $this->parameterManager->addParam($exprOrColumn . '_bt_high', $high);
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
            $ph = $this->parameterManager->addParam((string)$exprOrColumn, $value);
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$operator} {$ph}", 'cond' => $cond];
        }

        return $this;
    }

    /**
     * Build SELECT sql.
     *
     * @return string
     */
    protected function buildSelectSql(): string
    {
        $select = '*';
        $from = $this->normalizeTable();
        $sql = "SELECT {$select} FROM {$from}";
        $sql .= $this->buildConditionsClause($this->where, 'WHERE');
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int)$this->limit;
        }
        return trim($sql);
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
     * Resolve RawValue instances.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    protected function resolveRawValue(string|RawValue $value): string
    {
        return $this->rawValueResolver->resolveRawValue($value);
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
     * Check if a string represents an external table reference.
     *
     * @param string $reference The reference to check (e.g., 'users.id')
     *
     * @return bool True if it's an external reference
     */
    protected function isExternalReference(string $reference): bool
    {
        // Check if it matches table.column pattern
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/', $reference)) {
            return false;
        }

        $table = explode('.', $reference)[0];
        return !$this->isTableInCurrentQuery($table);
    }

    /**
     * Check if a table is referenced in the current query.
     *
     * @param string $tableName The table name to check
     *
     * @return bool True if table is in current query
     */
    protected function isTableInCurrentQuery(string $tableName): bool
    {
        return $this->table === $tableName;
    }

    /**
     * Automatically convert external references to RawValue.
     *
     * @param mixed $value The value to process
     *
     * @return mixed Processed value
     */
    protected function processExternalReferences(mixed $value): mixed
    {
        if (is_string($value) && $this->isExternalReference($value)) {
            return new RawValue($value);
        }

        return $value;
    }
}
