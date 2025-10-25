<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use InvalidArgumentException;
use PDOException;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\interfaces\ConditionBuilderInterface;
use tommyknocker\pdodb\query\interfaces\DmlQueryBuilderInterface;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;
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

    public function __construct(
        ConnectionInterface $connection,
        ParameterManagerInterface $parameterManager,
        ExecutionEngineInterface $executionEngine,
        ConditionBuilderInterface $conditionBuilder,
        RawValueResolver $rawValueResolver
    ) {
        $this->initializeCommonDependencies($connection, $parameterManager, $executionEngine, $rawValueResolver);
        $this->conditionBuilder = $conditionBuilder;
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
        $this->conditionBuilder->setTable($table);
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
        return $this->executionEngine->executeInsert($sql, $params);
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
        return $this->executionEngine->executeInsert($sql, $params, true);
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
        return $this->executionEngine->executeInsert($sql, $this->parameterManager->getParams());
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
        return $this->executionEngine->executeInsert($sql, $this->parameterManager->getParams());
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
        return $this->executionEngine->executeStatement($sql, $params)->rowCount();
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
        return $this->executionEngine->executeStatement($sql, $this->parameterManager->getParams())->rowCount();
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
     * @return self The current instance.
     */
    public function onDuplicate(array $onDuplicate): self
    {
        $this->onDuplicate = $onDuplicate;
        return $this;
    }

    /**
     * Add query option.
     *
     * @param string|array<int|string, mixed> $option
     *
     * @return self
     */
    public function addOption(string|array $option): self
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
     * @return self
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;
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
}
