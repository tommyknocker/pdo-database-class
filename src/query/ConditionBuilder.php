<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use InvalidArgumentException;
use PDOException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\interfaces\ConditionBuilderInterface;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;
use tommyknocker\pdodb\query\traits\CommonDependenciesTrait;
use tommyknocker\pdodb\query\traits\ExternalReferenceProcessingTrait;
use tommyknocker\pdodb\query\traits\IdentifierQuotingTrait;
use tommyknocker\pdodb\query\traits\RawValueResolutionTrait;
use tommyknocker\pdodb\query\traits\TableManagementTrait;

class ConditionBuilder implements ConditionBuilderInterface
{
    use CommonDependenciesTrait;
    use RawValueResolutionTrait;
    use TableManagementTrait;
    use IdentifierQuotingTrait;
    use ExternalReferenceProcessingTrait;

    /** @var array<int, string|array<string, mixed>> */
    protected array $where = [];

    /** @var array<int, string|array<string, mixed>> */
    protected array $having = [];

    /** @var string|null table name */
    protected ?string $table = null;

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
        $this->initializeCommonDependencies($connection, $parameterManager, $executionEngine, $rawValueResolver);
    }

    /**
     * Set limit.
     *
     * @param int|null $limit
     *
     * @return static
     */
    public function setLimit(?int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Add LIMIT clause.
     *
     * @param int $number The number of rows to limit.
     *
     * @return static The current instance.
     */
    public function limit(int $number): static
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
     * @return static The current instance.
     */
    public function where(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = QueryConstants::OP_EQUAL): static
    {
        return $this->addCondition(QueryConstants::COND_WHERE, $exprOrColumn, $value, $operator, QueryConstants::BOOLEAN_AND);
    }

    /**
     * Add AND WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function andWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = QueryConstants::OP_EQUAL): static
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
     * @return static The current instance.
     */
    public function orWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = QueryConstants::OP_EQUAL): static
    {
        return $this->addCondition(QueryConstants::COND_WHERE, $exprOrColumn, $value, $operator, QueryConstants::BOOLEAN_OR);
    }

    /**
     * Add HAVING clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function having(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = QueryConstants::OP_EQUAL): static
    {
        return $this->addCondition(QueryConstants::COND_HAVING, $exprOrColumn, $value, $operator, QueryConstants::BOOLEAN_AND);
    }

    /**
     * Add OR HAVING clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function orHaving(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = QueryConstants::OP_EQUAL): static
    {
        return $this->addCondition(QueryConstants::COND_HAVING, $exprOrColumn, $value, $operator, QueryConstants::BOOLEAN_OR);
    }

    /**
     * Add WHERE IN clause with subquery or array.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function whereIn(string $column, callable|array $subqueryOrArray, string $boolean = QueryConstants::BOOLEAN_AND): static
    {
        return $this->addCondition(QueryConstants::COND_WHERE, $column, $subqueryOrArray, QueryConstants::OP_IN, $boolean);
    }

    /**
     * Add WHERE NOT IN clause with subquery or array.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNotIn(string $column, callable|array $subqueryOrArray, string $boolean = QueryConstants::BOOLEAN_AND): static
    {
        return $this->addCondition(QueryConstants::COND_WHERE, $column, $subqueryOrArray, QueryConstants::OP_NOT_IN, $boolean);
    }

    /**
     * Add WHERE column IS NULL clause.
     *
     * @param string $column The column to check
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNull(string $column, string $boolean = QueryConstants::BOOLEAN_AND): static
    {
        return $this->addCondition(QueryConstants::COND_WHERE, $column, null, QueryConstants::OP_EQUAL, $boolean);
    }

    /**
     * Add WHERE column IS NOT NULL clause.
     *
     * @param string $column The column to check
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNotNull(string $column, string $boolean = QueryConstants::BOOLEAN_AND): static
    {
        return $this->addCondition(QueryConstants::COND_WHERE, $column, null, QueryConstants::OP_NOT_EQUAL, $boolean);
    }

    /**
     * Add WHERE column BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereBetween(string $column, mixed $min, mixed $max, string $boolean = QueryConstants::BOOLEAN_AND): static
    {
        return $this->addCondition(QueryConstants::COND_WHERE, $column, [$min, $max], QueryConstants::OP_BETWEEN, $boolean);
    }

    /**
     * Add WHERE column NOT BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNotBetween(string $column, mixed $min, mixed $max, string $boolean = QueryConstants::BOOLEAN_AND): static
    {
        return $this->addCondition(QueryConstants::COND_WHERE, $column, [$min, $max], QueryConstants::OP_NOT_BETWEEN, $boolean);
    }

    /**
     * Add WHERE column comparison with another column.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator (=, !=, <, >, <=, >=, etc.)
     * @param string $second The second column
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereColumn(string $first, string $operator, string $second, string $boolean = QueryConstants::BOOLEAN_AND): static
    {
        $firstQuoted = $this->quoteQualifiedIdentifier($first);
        $secondQuoted = $this->quoteQualifiedIdentifier($second);
        $this->where[] = [
            'sql' => "{$firstQuoted} {$operator} {$secondQuoted}",
            'cond' => $boolean,
        ];
        return $this;
    }

    /**
     * Add OR WHERE column IS NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function orWhereNull(string $column): static
    {
        return $this->whereNull($column, QueryConstants::BOOLEAN_OR);
    }

    /**
     * Add OR WHERE column IS NOT NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function orWhereNotNull(string $column): static
    {
        return $this->whereNotNull($column, QueryConstants::BOOLEAN_OR);
    }

    /**
     * Add OR WHERE column BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function orWhereBetween(string $column, mixed $min, mixed $max): static
    {
        return $this->whereBetween($column, $min, $max, QueryConstants::BOOLEAN_OR);
    }

    /**
     * Add OR WHERE column NOT BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function orWhereNotBetween(string $column, mixed $min, mixed $max): static
    {
        return $this->whereNotBetween($column, $min, $max, QueryConstants::BOOLEAN_OR);
    }

    /**
     * Add OR WHERE column IN clause.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function orWhereIn(string $column, callable|array $subqueryOrArray): static
    {
        return $this->addCondition(QueryConstants::COND_WHERE, $column, $subqueryOrArray, QueryConstants::OP_IN, QueryConstants::BOOLEAN_OR);
    }

    /**
     * Add OR WHERE column NOT IN clause.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function orWhereNotIn(string $column, callable|array $subqueryOrArray): static
    {
        return $this->addCondition(QueryConstants::COND_WHERE, $column, $subqueryOrArray, QueryConstants::OP_NOT_IN, QueryConstants::BOOLEAN_OR);
    }

    /**
     * Add AND WHERE column IS NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function andWhereNull(string $column): static
    {
        return $this->whereNull($column, QueryConstants::BOOLEAN_AND);
    }

    /**
     * Add AND WHERE column IS NOT NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function andWhereNotNull(string $column): static
    {
        return $this->whereNotNull($column, QueryConstants::BOOLEAN_AND);
    }

    /**
     * Add AND WHERE column BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function andWhereBetween(string $column, mixed $min, mixed $max): static
    {
        return $this->whereBetween($column, $min, $max, QueryConstants::BOOLEAN_AND);
    }

    /**
     * Add AND WHERE column NOT BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function andWhereNotBetween(string $column, mixed $min, mixed $max): static
    {
        return $this->whereNotBetween($column, $min, $max, QueryConstants::BOOLEAN_AND);
    }

    /**
     * Add AND WHERE column IN clause.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function andWhereIn(string $column, callable|array $subqueryOrArray): static
    {
        return $this->whereIn($column, $subqueryOrArray, QueryConstants::BOOLEAN_AND);
    }

    /**
     * Add AND WHERE column NOT IN clause.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function andWhereNotIn(string $column, callable|array $subqueryOrArray): static
    {
        return $this->whereNotIn($column, $subqueryOrArray, QueryConstants::BOOLEAN_AND);
    }

    /**
     * Add AND WHERE column comparison with another column.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator (=, !=, <, >, <=, >=, etc.)
     * @param string $second The second column
     *
     * @return static The current instance
     */
    public function andWhereColumn(string $first, string $operator, string $second): static
    {
        return $this->whereColumn($first, $operator, $second, QueryConstants::BOOLEAN_AND);
    }

    /**
     * Add OR WHERE column comparison with another column.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator (=, !=, <, >, <=, >=, etc.)
     * @param string $second The second column
     *
     * @return static The current instance
     */
    public function orWhereColumn(string $first, string $operator, string $second): static
    {
        return $this->whereColumn($first, $operator, $second, QueryConstants::BOOLEAN_OR);
    }

    /**
     * Add WHERE EXISTS clause.
     *
     * @param callable(QueryBuilder): void $subquery The subquery callback
     *
     * @return static The current instance
     */
    public function whereExists(callable $subquery): static
    {
        $instance = new QueryBuilder($this->connection, $this->prefix ?? '');
        $subquery($instance);
        $sub = $instance->toSQL();
        $map = $this->parameterManager->mergeSubParams($sub['params'], 'sq');
        $subSql = $this->parameterManager->replacePlaceholdersInSql($sub['sql'], $map);
        $this->where[] = ['sql' => QueryConstants::OP_EXISTS . " ({$subSql})", 'cond' => QueryConstants::BOOLEAN_AND];
        return $this;
    }

    /**
     * Add WHERE NOT EXISTS clause.
     *
     * @param callable(QueryBuilder): void $subquery The subquery callback
     *
     * @return static The current instance
     */
    public function whereNotExists(callable $subquery): static
    {
        $instance = new QueryBuilder($this->connection, $this->prefix ?? '');
        $subquery($instance);
        $sub = $instance->toSQL();
        $map = $this->parameterManager->mergeSubParams($sub['params'], 'sq');
        $subSql = $this->parameterManager->replacePlaceholdersInSql($sub['sql'], $map);
        $this->where[] = ['sql' => QueryConstants::OP_NOT_EXISTS . " ({$subSql})", 'cond' => QueryConstants::BOOLEAN_AND];
        return $this;
    }

    /**
     * Add raw WHERE clause.
     *
     * @param string $sql The raw SQL condition
     * @param array<string, mixed> $params The parameters for the condition
     *
     * @return static The current instance
     */
    public function whereRaw(string $sql, array $params = []): static
    {
        foreach ($params as $key => $value) {
            $placeholder = str_starts_with($key, ':') ? $key : ':' . $key;
            $this->parameterManager->setParam($placeholder, $value);
        }
        $this->where[] = ['sql' => $sql, 'cond' => QueryConstants::BOOLEAN_AND];
        return $this;
    }

    /**
     * Add raw HAVING clause.
     *
     * @param string $sql The raw SQL condition
     * @param array<string, mixed> $params The parameters for the condition
     *
     * @return static The current instance
     */
    public function havingRaw(string $sql, array $params = []): static
    {
        foreach ($params as $key => $value) {
            $placeholder = str_starts_with($key, ':') ? $key : ':' . $key;
            $this->parameterManager->setParam($placeholder, $value);
        }
        $this->having[] = ['sql' => $sql, 'cond' => QueryConstants::BOOLEAN_AND];
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
        $originalLimit = $this->limit;

        // Some dialects don't support LIMIT in EXISTS subqueries
        if ($this->dialect->supportsLimitInExists()) {
            $this->limit(1);
        }

        $subSql = $this->buildSelectSql();
        $params = $this->parameterManager->getParams();
        $params = $this->parameterManager->normalizeParams($params);

        // Restore original limit
        $this->limit = $originalLimit;

        // Build EXISTS expression using dialect-specific method
        $sql = $this->dialect->buildExistsExpression($subSql);
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
        $originalLimit = $this->limit;

        // Some dialects don't support LIMIT in EXISTS subqueries
        if ($this->dialect->supportsLimitInExists()) {
            $this->limit(1);
        }

        $subSql = $this->buildSelectSql();
        $params = $this->parameterManager->getParams();

        // Restore original limit
        $this->limit = $originalLimit;

        // Build NOT EXISTS expression using dialect-specific method
        if (method_exists($this->dialect, 'buildNotExistsExpression')) {
            $sql = $this->dialect->buildNotExistsExpression($subSql);
        } else {
            // Fallback: use buildExistsExpression and replace EXISTS with NOT EXISTS
            $existsExpr = $this->dialect->buildExistsExpression($subSql);
            $sql = str_replace('EXISTS(', 'NOT EXISTS(', $existsExpr);
        }
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
                $clauses[] = ($i === 0 ? '' : QueryConstants::BOOLEAN_AND . ' ') . $sql;
                continue;
            }
            if (is_string($w)) {
                $clauses[] = ($i === 0 ? '' : QueryConstants::BOOLEAN_AND . ' ') . $w;
                continue;
            }
            $sql = $w['sql'] ?? '';
            $cond = $w['cond'] ?? ($i === 0 ? '' : QueryConstants::BOOLEAN_AND);
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
     * @return static The current instance.
     */
    protected function addCondition(
        string $prop,
        string|array|RawValue $exprOrColumn,
        mixed $value,
        string $operator,
        string $cond
    ): static {
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

        // handle NULL comparisons
        if ($value === null) {
            // When a raw expression is provided without a right-hand value,
            // treat it as a complete condition and insert as-is.
            if ($exprOrColumn instanceof RawValue) {
                $resolved = $this->resolveRawValue($exprOrColumn);
                $this->{$prop}[] = ['sql' => $resolved, 'cond' => $cond];
                return $this;
            }

            // If plain string condition provided (e.g., "age = 50"), use as-is
            $exprStr = (string)$exprOrColumn;
            if (preg_match('/[\s<>=()]/', $exprStr) === 1) {
                $this->{$prop}[] = ['sql' => $exprStr, 'cond' => $cond];
                return $this;
            }

            $opUpper = $this->normalizeOperator($operator);
            $exprQuoted = $this->quoteQualifiedIdentifier($exprStr);
            $nullSql = ($opUpper === QueryConstants::OP_IS_NOT || $opUpper === QueryConstants::OP_NOT_EQUAL || $opUpper === QueryConstants::OP_NOT_EQUAL_ALT) ? QueryConstants::OP_IS_NOT . ' NULL' : QueryConstants::OP_IS . ' NULL';
            $this->{$prop}[] = ['sql' => "{$exprQuoted} {$nullSql}", 'cond' => $cond];
            return $this;
        }

        if ($exprOrColumn instanceof RawValue) {
            $left = $this->resolveRawValue($exprOrColumn);
            if ($value instanceof RawValue) {
                $right = $this->resolveRawValue($value);
                $this->{$prop}[] = ['sql' => "{$left} {$operator} {$right}", 'cond' => $cond];
                return $this;
            }

            // Inline literal on the right side to avoid driver-specific parameter casting issues
            $right = $this->literalFromValue($value);
            $this->{$prop}[] = ['sql' => "{$left} {$operator} {$right}", 'cond' => $cond];
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
        if (($opUpper === QueryConstants::OP_IN || $opUpper === QueryConstants::OP_NOT_IN) && is_array($value)) {
            if (empty($value)) {
                // The semantics of an empty IN depend on the logic: it's better to form a condition
                // that is always false/true. Here it's safe to create a condition that never matches for IN,
                // and always matches for NOT IN.
                if ($opUpper === QueryConstants::OP_IN) {
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
        if (($opUpper === QueryConstants::OP_BETWEEN || $opUpper === QueryConstants::OP_NOT_BETWEEN) && is_array($value)) {
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
            // Use dialect-specific LIMIT formatting
            $sql = $this->dialect->formatLimitOffset($sql, $this->limit, null);
        }
        return trim($sql);
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
     * Convert a PHP value into a safe SQL literal.
     * Note: Use only for right-hand side of expressions when left side is a RawValue.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function literalFromValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        // Fallback: quote single quotes inside and wrap with quotes
        $s = (string)$value;
        return "'" . str_replace("'", "''", $s) . "'";
    }

    /**
     * Get debug information about conditions.
     *
     * @return array<string, mixed> Debug information about WHERE, HAVING, ORDER BY, and LIMIT
     */
    public function getDebugInfo(): array
    {
        $info = [];

        if (!empty($this->where)) {
            $info['where_count'] = count($this->where);
            $info['where'] = $this->where;
        }

        if (!empty($this->having)) {
            $info['having_count'] = count($this->having);
            $info['having'] = $this->having;
        }

        if (!empty($this->order)) {
            $info['order_count'] = count($this->order);
            $info['order'] = $this->order;
        }

        if ($this->limit !== null) {
            $info['limit'] = $this->limit;
        }

        return $info;
    }

    /**
     * Extract shard key value from WHERE conditions.
     *
     * Searches for WHERE condition with the specified column and operator '='.
     * Returns the value if found, null otherwise.
     *
     * @param string $shardKeyColumn Column name to search for
     *
     * @return mixed|null Shard key value or null if not found
     */
    public function extractShardKeyValue(string $shardKeyColumn): mixed
    {
        if (empty($this->where)) {
            return null;
        }

        $quotedColumn = $this->quoteQualifiedIdentifier($shardKeyColumn);
        $quotedColumnLower = strtolower($quotedColumn);

        foreach ($this->where as $condition) {
            if (!is_array($condition) || !isset($condition['sql'])) {
                continue;
            }

            $sql = $condition['sql'];
            $sqlLower = strtolower($sql);

            // Check for equality condition: column = value or column = :param
            if (strpos($sqlLower, $quotedColumnLower) === 0) {
                // Extract operator and value
                $pattern = '/^' . preg_quote($quotedColumn, '/') . '\s*=\s*(.+)$/i';
                if (preg_match($pattern, $sql, $matches)) {
                    $valuePart = trim($matches[1]);

                    // Check if it's a parameter placeholder
                    if (preg_match('/^:([a-z0-9_]+)$/i', $valuePart, $paramMatches)) {
                        $paramName = $valuePart;
                        $params = $this->parameterManager->getParams();
                        if (isset($params[$paramName])) {
                            return $params[$paramName];
                        }
                    } elseif (preg_match('/^(\d+)$/', $valuePart)) {
                        // Numeric literal
                        return (int)$valuePart;
                    } elseif (preg_match("/^'([^']*)'$/", $valuePart, $strMatches)) {
                        // String literal
                        return $strMatches[1];
                    }
                }
            }
        }

        return null;
    }
}
