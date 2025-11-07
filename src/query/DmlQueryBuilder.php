<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use Closure;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\interfaces\ConditionBuilderInterface;
use tommyknocker\pdodb\query\interfaces\DmlQueryBuilderInterface;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
use tommyknocker\pdodb\query\interfaces\JoinBuilderInterface;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;
use tommyknocker\pdodb\query\interfaces\SelectQueryBuilderInterface;
use tommyknocker\pdodb\query\traits\CommonDependenciesTrait;
use tommyknocker\pdodb\query\traits\RawValueResolutionTrait;
use tommyknocker\pdodb\query\traits\TableManagementTrait;

class DmlQueryBuilder implements DmlQueryBuilderInterface
{
    use CommonDependenciesTrait;
    use RawValueResolutionTrait;
    use TableManagementTrait;

    /** @var string|null table name */
    protected ?string $table = null {
        get {
            if (!$this->table) {
                throw new RuntimeException('You must define table first. Use table() or from() methods');
            }
            return $this->table;
        }
    }

    /** @var array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> */
    protected array $data = [];

    /** @var array<int, array<string, string|int|float|bool|null|RawValue>> */
    protected array $multiRows = [];

    /** @var array<string, string|int|float|bool|null|RawValue> */
    protected array $onDuplicate = [];

    /** @var array<int|string, mixed> Query options (e.g., FOR UPDATE, IGNORE) */
    protected array $options = [];

    /** @var int|null LIMIT value */
    protected ?int $limit = null;

    protected ConditionBuilderInterface $conditionBuilder;

    /** @var MergeClause|null Merge clause configuration */
    protected ?MergeClause $mergeClause = null;

    /** @var string|\Closure(QueryBuilder): void|SelectQueryBuilderInterface|null Source for MERGE */
    protected string|\Closure|SelectQueryBuilderInterface|null $mergeSource = null;

    /** @var array<string> Join conditions for MERGE ON clause */
    protected array $mergeOnConditions = [];

    protected JoinBuilderInterface $joinBuilder;

    public function __construct(
        ConnectionInterface $connection,
        ParameterManagerInterface $parameterManager,
        ExecutionEngineInterface $executionEngine,
        ConditionBuilderInterface $conditionBuilder,
        RawValueResolver $rawValueResolver,
        ?JoinBuilderInterface $joinBuilder = null
    ) {
        $this->initializeCommonDependencies($connection, $parameterManager, $executionEngine, $rawValueResolver);
        $this->conditionBuilder = $conditionBuilder;
        $this->joinBuilder = $joinBuilder ?? new JoinBuilder($connection, $rawValueResolver, $parameterManager);
    }

    /**
     * Set table name.
     *
     * @param string $table
     *
     * @return static
     */
    public function setTable(string $table): static
    {
        $this->table = $table;
        $this->conditionBuilder->setTable($table);
        return $this;
    }

    /**
     * Set table prefix.
     *
     * @param string|null $prefix
     *
     * @return static
     */
    public function setPrefix(?string $prefix): static
    {
        $this->prefix = $prefix;
        $this->conditionBuilder->setPrefix($prefix);
        return $this;
    }

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

        try {
            return $this->executionEngine->executeInsert($sql, $params);
        } catch (PDOException $e) {
            $this->enhanceExceptionWithContext($e, $sql);

            throw $e;
        }
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

        try {
            return $this->executionEngine->executeInsert($sql, $params, true);
        } catch (PDOException $e) {
            $this->enhanceExceptionWithContext($e, $sql);

            throw $e;
        }
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
        foreach ($columns as $col) {
            $colStr = (string)$col;
            $result = $this->processValueForSql($this->data[$col], $colStr);
            $placeholders[] = $result['sql'];
        }
        $tableName = $this->table; // Use getter to ensure not null
        assert(is_string($tableName)); // PHPStan assertion
        $sql = $this->dialect->buildReplaceSql($tableName, array_values(array_map('strval', $columns)), $placeholders);

        try {
            return $this->executionEngine->executeInsert($sql, $this->parameterManager->getParams());
        } catch (PDOException $e) {
            $this->enhanceExceptionWithContext($e, $sql);

            throw $e;
        }
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
        $i = 0;

        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $result = $this->processValueForSql($row[$col], (string)$col, $col . '_' . $i . '_');
                $placeholders[] = $result['sql'];
            }
            // collect per-row group WITHOUT adding outer parentheses here
            // because dialect will assemble VALUES list and must accept both modes
            $valuesList[] = '(' . implode(', ', $placeholders) . ')';
            $i++;
        }

        // Pass isMultiple = true so dialect will not add extra parentheses
        $tableName = $this->table; // Use getter to ensure not null
        assert(is_string($tableName)); // PHPStan assertion
        $sql = $this->dialect->buildReplaceSql(
            $tableName,
            array_values(array_map('strval', $columns)),
            $valuesList,
            true
        );

        try {
            return $this->executionEngine->executeInsert($sql, $this->parameterManager->getParams());
        } catch (PDOException $e) {
            $this->enhanceExceptionWithContext($e, $sql);

            throw $e;
        }
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

        try {
            return $this->executionEngine->executeStatement($sql, $params)->rowCount();
        } catch (PDOException $e) {
            $this->enhanceExceptionWithContext($e, $sql);

            throw $e;
        }
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
        $sql .= $this->conditionBuilder->buildConditionsClause($this->conditionBuilder->getWhere(), 'WHERE');

        try {
            return $this->executionEngine->executeStatement($sql, $this->parameterManager->getParams())->rowCount();
        } catch (PDOException $e) {
            $this->enhanceExceptionWithContext($e, $sql);

            throw $e;
        }
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
        $this->executionEngine->executeStatement($sql);
        return $this->connection->getLastErrno() === 0;
    }

    /**
     * Add ON DUPLICATE clause.
     *
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return static The current instance.
     */
    public function onDuplicate(array $onDuplicate): static
    {
        $this->onDuplicate = $onDuplicate;
        return $this;
    }

    /**
     * Add query option.
     *
     * @param string|array<int|string, mixed> $option
     *
     * @return static
     */
    public function addOption(string|array $option): static
    {
        if (is_array($option)) {
            foreach ($option as $key => $value) {
                if (is_string($key)) {
                    $this->options[$key] = $value;
                } else {
                    $this->options[] = $value;
                }
            }
        } else {
            $this->options[] = $option;
        }
        return $this;
    }

    /**
     * Set query options.
     *
     * @param array<int|string, mixed> $options
     *
     * @return static
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;
        return $this;
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
     * Build INSERT sql.
     *
     * @return array{string, array<string, string|int|float|bool|null>}
     */
    protected function buildInsertSql(): array
    {
        $columns = array_keys($this->data);
        $placeholders = [];

        foreach ($columns as $col) {
            $result = $this->processValueForSql($this->data[$col], $col);
            $placeholders[] = $result['sql'];
        }

        $sql = $this->dialect->buildInsertSql($this->normalizeTable(), $columns, $placeholders, $this->options);
        if (!empty($this->onDuplicate)) {
            // if no id column in columns, use first column for $defaultConflictTarget
            $defaultConflictTarget = in_array('id', $columns, true) ? 'id' : (string)($columns[0] ?? 'id');
            $tableName = $this->table ?? '';
            $sql .= ' ' . $this->dialect->buildUpsertClause($this->onDuplicate, $defaultConflictTarget, $tableName);
        }

        return [$sql, $this->parameterManager->getParams()];
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
                    $this->parameterManager->setParam($placeholder, $val);
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

        return [$sql, $this->parameterManager->getParams()];
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
                            $rawSql = $this->resolveRawValue($valueForParam);
                            // Parameters are added to ParameterManager by RawValueResolver
                            $setParts[] = "{$qid} = " . $rawSql;
                        } else {
                            $ph = $this->parameterManager->addParam("upd_{$col}", $valueForParam);
                            $setParts[] = "{$qid} = {$ph}";
                        }
                        break;
                }
            } elseif ($val instanceof RawValue) {
                $sql = $this->resolveRawValue($val);
                // Parameters are added to ParameterManager by RawValueResolver
                $setParts[] = "{$qid} = {$sql}";
            } else {
                $ph = $this->parameterManager->addParam("upd_{$col}", $val);
                $setParts[] = "{$qid} = {$ph}";
            }
        }

        $table = $this->normalizeTable();
        $sql = "UPDATE {$options}{$table} SET " . implode(', ', $setParts);
        $sql .= $this->conditionBuilder->buildConditionsClause($this->conditionBuilder->getWhere(), 'WHERE');
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int)$this->limit;
        }
        return [$sql, $this->parameterManager->getParams()];
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
            $sql = $this->resolveRawValue($value);
            // Parameters are added to ParameterManager by RawValueResolver
            // Return empty params array since parameters are already in ParameterManager
            return ['sql' => $sql, 'params' => []];
        }

        // Create placeholder with original column name and add to ParameterManager
        $paramName = $prefix === '' ? ':' . $columnName : ':' . $prefix . $columnName;
        $this->parameterManager->setParam($paramName, $value);
        return ['sql' => $paramName, 'params' => []];
    }

    /**
     * Execute MERGE statement (INSERT/UPDATE/DELETE based on match conditions).
     *
     * @param string|\Closure(QueryBuilder): void|SelectQueryBuilderInterface $source Source table/subquery for MERGE
     * @param string|array<string> $onConditions ON clause conditions
     * @param array<string, string|int|float|bool|null|RawValue> $whenMatched Update columns when matched
     * @param array<string, string|int|float|bool|null|RawValue> $whenNotMatched Insert columns when not matched
     * @param bool $whenNotMatchedBySourceDelete Delete when not matched by source
     *
     * @return int Number of affected rows
     * @throws RuntimeException If MERGE is not supported by dialect
     */
    public function merge(
        string|\Closure|SelectQueryBuilderInterface $source,
        string|array $onConditions,
        array $whenMatched = [],
        array $whenNotMatched = [],
        bool $whenNotMatchedBySourceDelete = false
    ): int {
        // Check if MERGE is supported
        if (!$this->dialect->supportsMerge()) {
            throw new RuntimeException(
                sprintf('MERGE statement is not supported by %s dialect', $this->dialect->getDriverName())
            );
        }

        $this->mergeSource = $source;
        $this->mergeOnConditions = is_array($onConditions) ? $onConditions : [$onConditions];

        $this->mergeClause = new MergeClause();
        $this->mergeClause->whenMatched = $whenMatched;
        $this->mergeClause->whenNotMatched = $whenNotMatched;
        $this->mergeClause->whenNotMatchedBySourceDelete = $whenNotMatchedBySourceDelete;

        [$sql, $params] = $this->buildMergeSql();
        return $this->executionEngine->executeStatement($sql, $params)->rowCount();
    }

    /**
     * Build MERGE SQL statement.
     *
     * @return array{string, array<string, string|int|float|bool|null>}
     */
    protected function buildMergeSql(): array
    {
        if ($this->mergeClause === null || $this->mergeSource === null) {
            throw new RuntimeException('MERGE requires source and merge clause configuration');
        }

        $tableName = $this->table;
        assert(is_string($tableName));

        // Build source SQL
        $sourceSql = $this->buildMergeSourceSql($this->mergeSource);

        // Build ON conditions
        $onClause = $this->buildMergeOnClause($this->mergeOnConditions);

        // Build WHEN clauses
        $whenClauses = $this->buildMergeWhenClauses($this->mergeClause);

        $sql = $this->dialect->buildMergeSql(
            $tableName,
            $sourceSql,
            $onClause,
            $whenClauses
        );

        return [$sql, $this->parameterManager->getParams()];
    }

    /**
     * Build source SQL for MERGE.
     *
     * @param string|\Closure(QueryBuilder): void|SelectQueryBuilderInterface $source
     *
     * @return string
     */
    protected function buildMergeSourceSql(string|\Closure|SelectQueryBuilderInterface $source): string
    {
        if (is_string($source)) {
            // Simple table name - dialect will add alias if needed
            return $this->dialect->quoteTable($source);
        }

        if ($source instanceof SelectQueryBuilderInterface) {
            // SelectQueryBuilder instance - get SQL
            $result = $source->toSQL();
            $sql = $result['sql'] ?? $result[0] ?? '';
            $params = $result['params'] ?? $result[1] ?? [];
            // Merge parameters
            foreach ($params as $key => $value) {
                $this->parameterManager->setParam($key, $value);
            }
            // For MySQL/SQLite emulation, source alias is added in dialect
            // For PostgreSQL MERGE, alias is needed here
            return '(' . $sql . ') AS source';
        }

        if ($source instanceof \Closure) {
            // Closure - create QueryBuilder instance and use its selectQueryBuilder
            $queryBuilder = new QueryBuilder(
                $this->connection,
                $this->prefix ?? '',
                null,
                null
            );
            $source($queryBuilder);
            // Use QueryBuilder's toSQL method
            $result = $queryBuilder->toSQL();
            $sql = $result['sql'] ?? $result[0] ?? '';
            $params = $result['params'] ?? $result[1] ?? [];
            // Merge parameters
            foreach ($params as $key => $value) {
                $this->parameterManager->setParam($key, $value);
            }
            return '(' . $sql . ') AS source';
        }

        // This should never happen, but is kept for type safety
        /* @phpstan-ignore-next-line */
        throw new InvalidArgumentException('Invalid MERGE source type');
    }

    /**
     * Create a new SelectQueryBuilder instance for subqueries.
     *
     * @return SelectQueryBuilder
     */
    protected function createSelectSubquery(): SelectQueryBuilder
    {
        return new SelectQueryBuilder(
            $this->connection,
            $this->parameterManager,
            $this->executionEngine,
            $this->conditionBuilder,
            $this->joinBuilder,
            $this->rawValueResolver,
            null,
            null
        );
    }

    /**
     * Build ON clause conditions.
     *
     * @param array<string> $conditions
     *
     * @return string
     */
    protected function buildMergeOnClause(array $conditions): string
    {
        return implode(' AND ', $conditions);
    }

    /**
     * Build WHEN clauses.
     *
     * @param MergeClause $clause
     *
     * @return array{whenMatched: string|null, whenNotMatched: string|null, whenNotMatchedBySourceDelete: bool}
     */
    protected function buildMergeWhenClauses(MergeClause $clause): array
    {
        $whenMatchedSql = null;
        if (!empty($clause->whenMatched)) {
            $parts = $this->buildMergeUpdateExpressions($clause->whenMatched);
            $whenMatchedSql = implode(', ', $parts);
            if ($clause->whenMatchedCondition) {
                $whenMatchedSql .= ' AND ' . $clause->whenMatchedCondition;
            }
        }

        $whenNotMatchedSql = null;
        if (!empty($clause->whenNotMatched)) {
            $columns = [];
            $values = [];
            foreach ($clause->whenNotMatched as $col => $val) {
                $colName = (string)$col;
                $columns[] = $this->dialect->quoteIdentifier($colName);
                if ($val instanceof RawValue) {
                    $result = $this->processValueForSql($val, $colName);
                    $values[] = $result['sql'];
                } else {
                    // For MERGE, use source.column reference for non-raw values
                    // This will be replaced in dialect-specific implementation
                    $values[] = 'MERGE_SOURCE_COLUMN_' . $colName;
                }
            }
            $whenNotMatchedSql = '(' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
            if ($clause->whenNotMatchedCondition) {
                $whenNotMatchedSql .= ' AND ' . $clause->whenNotMatchedCondition;
            }
        }

        return [
            'whenMatched' => $whenMatchedSql,
            'whenNotMatched' => $whenNotMatchedSql,
            'whenNotMatchedBySourceDelete' => $clause->whenNotMatchedBySourceDelete,
        ];
    }

    /**
     * Build update expressions for WHEN MATCHED.
     *
     * @param array<string, string|int|float|bool|null|RawValue> $columns
     *
     * @return array<int, string>
     */
    protected function buildMergeUpdateExpressions(array $columns): array
    {
        $parts = [];
        foreach ($columns as $col => $val) {
            $colSql = $this->dialect->quoteIdentifier((string)$col);
            $result = $this->processValueForSql($val, (string)$col);
            // For MERGE, use source column reference by default
            // Use unquoted column name for source/excluded references
            $colName = (string)$col;
            if ($val instanceof RawValue) {
                // For RawValue, use the resolved SQL as-is (dialects will handle source->excluded replacement)
                $parts[] = "{$colSql} = {$result['sql']}";
            } else {
                // Use unquoted column name - dialects will handle quoting correctly
                $parts[] = "{$colSql} = source.{$colName}";
            }
        }
        return $parts;
    }

    /**
     * Get debug information about the DML query.
     *
     * @return array<string, mixed> Debug information about DML query state
     */
    public function getDebugInfo(): array
    {
        $info = [];

        if (!empty($this->data)) {
            $info['data'] = $this->data;
            $info['data_count'] = count($this->data);
        }

        if (!empty($this->multiRows)) {
            $info['multi_rows_count'] = count($this->multiRows);
        }

        if (!empty($this->onDuplicate)) {
            $info['on_duplicate'] = $this->onDuplicate;
        }

        if (!empty($this->options)) {
            $info['options'] = $this->options;
        }

        if ($this->limit !== null) {
            $info['limit'] = $this->limit;
        }

        if ($this->mergeClause !== null) {
            $info['merge'] = true;
        }

        if (!empty($this->mergeOnConditions)) {
            $info['merge_on_conditions'] = $this->mergeOnConditions;
        }

        return $info;
    }

    /**
     * Enhance PDOException with query context if available.
     *
     * Stores query context in Connection's temporary storage so it can be
     * included in exception when Connection::handlePdoException is called.
     */
    protected function enhanceExceptionWithContext(PDOException $e, string $sql): void
    {
        $queryContext = $this->executionEngine->getQueryContext();
        if ($queryContext !== null) {
            $this->connection->setTempQueryContext($queryContext);
        }
    }
}
