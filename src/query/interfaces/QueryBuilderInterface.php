<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

use Generator;
use PDOStatement;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\values\RawValue;

interface QueryBuilderInterface
{
    // Construction / meta
    /**
     * @param ConnectionInterface $connection
     * @param string $prefix
     */
    public function __construct(ConnectionInterface $connection, string $prefix = '');

    /**
     * @return ConnectionInterface
     */
    public function getConnection(): ConnectionInterface;

    /**
     * @return DialectInterface
     */
    public function getDialect(): DialectInterface;

    /**
     * @return string|null
     */
    public function getPrefix(): ?string;

    // Table / source
    /**
     * @param string $table
     *
     * @return static
     */
    public function table(string $table): self;

    /**
     * @param string $table
     *
     * @return static
     */
    public function from(string $table): self;

    /**
     * @param string $prefix
     *
     * @return static
     */
    public function prefix(string $prefix): self;

    // Select / projection
    /**
     * @param RawValue|string|array<int|string, string|RawValue|callable(\tommyknocker\pdodb\query\QueryBuilder): void> $cols
     *
     * @return static
     */
    public function select(RawValue|string|array $cols): self;

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function get(): array;

    /**
     * @return mixed
     */
    public function getOne(): mixed;

    /**
     * @return array<int, mixed>
     */
    public function getColumn(): array;

    /**
     * @return mixed
     */
    public function getValue(): mixed;

    /**
     * Get the first row ordered by the specified field.
     *
     * @param string|array<int|string, string>|RawValue $orderByField Field(s) to order by (default: 'id')
     *
     * @return array<string, mixed>|null First row or null if no rows found
     */
    public function first(string|array|RawValue $orderByField = 'id'): ?array;

    /**
     * Get the last row ordered by the specified field.
     *
     * @param string|array<int|string, string>|RawValue $orderByField Field(s) to order by (default: 'id')
     *
     * @return array<string, mixed>|null Last row or null if no rows found
     */
    public function last(string|array|RawValue $orderByField = 'id'): ?array;

    /**
     * Index query results by the specified column.
     *
     * @param string $columnName Column name to use as array keys (default: 'id')
     *
     * @return static
     */
    public function index(string $columnName = 'id'): static;

    // DML: insert / update / delete / replace
    /**
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate
     *
     * @return int
     */
    public function insert(array $data, array $onDuplicate = []): int;

    /**
     * @param array<int, array<string, string|int|float|bool|null|RawValue>> $rows
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate
     *
     * @return int
     */
    public function insertMulti(array $rows, array $onDuplicate = []): int;

    /**
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate
     *
     * @return int
     */
    public function replace(array $data, array $onDuplicate = []): int;

    /**
     * @param array<int, array<string, string|int|float|bool|null|RawValue>> $rows
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate
     *
     * @return int
     */
    public function replaceMulti(array $rows, array $onDuplicate = []): int;

    /**
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data
     *
     * @return int
     */
    public function update(array $data): int;

    /**
     * @return int
     */
    public function delete(): int;

    /**
     * @return bool
     */
    public function truncate(): bool;

    // Conditions: where / having / logical variants

    /**
     * @param string|array<string, mixed>|RawValue $exprOrColumn
     * @param mixed|null $value
     * @param string $operator
     *
     * @return static
     */
    public function where(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * @param string|array<string, mixed>|RawValue $exprOrColumn
     * @param mixed|null $value
     * @param string $operator
     *
     * @return static
     */
    public function andWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * @param string|array<string, mixed>|RawValue $exprOrColumn
     * @param mixed|null $value
     * @param string $operator
     *
     * @return static
     */
    public function orWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * @param string|array<string, mixed>|RawValue $exprOrColumn
     * @param mixed|null $value
     * @param string $operator
     *
     * @return static
     */
    public function having(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * @param string|array<string, mixed>|RawValue $exprOrColumn
     * @param mixed|null $value
     * @param string $operator
     *
     * @return static
     */
    public function orHaving(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    // New Query Builder methods
    /**
     * @param string $column
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray
     * @param string $boolean
     *
     * @return static
     */
    public function whereIn(string $column, callable|array $subqueryOrArray, string $boolean = 'AND'): self;

    /**
     * @param string $column
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray
     * @param string $boolean
     *
     * @return static
     */
    public function whereNotIn(string $column, callable|array $subqueryOrArray, string $boolean = 'AND'): self;

    /**
     * @param string $column
     * @param string $boolean
     *
     * @return static
     */
    public function whereNull(string $column, string $boolean = 'AND'): self;

    /**
     * @param string $column
     * @param string $boolean
     *
     * @return static
     */
    public function whereNotNull(string $column, string $boolean = 'AND'): self;

    /**
     * @param string $column
     * @param mixed $min
     * @param mixed $max
     * @param string $boolean
     *
     * @return static
     */
    public function whereBetween(string $column, mixed $min, mixed $max, string $boolean = 'AND'): self;

    /**
     * @param string $column
     * @param mixed $min
     * @param mixed $max
     * @param string $boolean
     *
     * @return static
     */
    public function whereNotBetween(string $column, mixed $min, mixed $max, string $boolean = 'AND'): self;

    /**
     * @param string $first
     * @param string $operator
     * @param string $second
     * @param string $boolean
     *
     * @return static
     */
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): self;

    /**
     * @param string $column
     *
     * @return static
     */
    public function orWhereNull(string $column): self;

    /**
     * @param string $column
     *
     * @return static
     */
    public function orWhereNotNull(string $column): self;

    /**
     * @param string $column
     * @param mixed $min
     * @param mixed $max
     *
     * @return static
     */
    public function orWhereBetween(string $column, mixed $min, mixed $max): self;

    /**
     * @param string $column
     * @param mixed $min
     * @param mixed $max
     *
     * @return static
     */
    public function orWhereNotBetween(string $column, mixed $min, mixed $max): self;

    /**
     * @param string $column
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray
     *
     * @return static
     */
    public function orWhereIn(string $column, callable|array $subqueryOrArray): self;

    /**
     * @param string $column
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray
     *
     * @return static
     */
    public function orWhereNotIn(string $column, callable|array $subqueryOrArray): self;

    /**
     * @param string $column
     *
     * @return static
     */
    public function andWhereNull(string $column): self;

    /**
     * @param string $column
     *
     * @return static
     */
    public function andWhereNotNull(string $column): self;

    /**
     * @param string $column
     * @param mixed $min
     * @param mixed $max
     *
     * @return static
     */
    public function andWhereBetween(string $column, mixed $min, mixed $max): self;

    /**
     * @param string $column
     * @param mixed $min
     * @param mixed $max
     *
     * @return static
     */
    public function andWhereNotBetween(string $column, mixed $min, mixed $max): self;

    /**
     * @param string $column
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray
     *
     * @return static
     */
    public function andWhereIn(string $column, callable|array $subqueryOrArray): self;

    /**
     * @param string $column
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray
     *
     * @return static
     */
    public function andWhereNotIn(string $column, callable|array $subqueryOrArray): self;

    /**
     * @param string $first
     * @param string $operator
     * @param string $second
     *
     * @return static
     */
    public function andWhereColumn(string $first, string $operator, string $second): self;

    /**
     * @param string $first
     * @param string $operator
     * @param string $second
     *
     * @return static
     */
    public function orWhereColumn(string $first, string $operator, string $second): self;

    /**
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void $subquery
     *
     * @return static
     */
    public function whereExists(callable $subquery): self;

    /**
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void $subquery
     *
     * @return static
     */
    public function whereNotExists(callable $subquery): self;

    /**
     * @param string $sql
     * @param array<string, mixed> $params
     *
     * @return static
     */
    public function whereRaw(string $sql, array $params = []): self;

    /**
     * @param string $sql
     * @param array<string, mixed> $params
     *
     * @return static
     */
    public function havingRaw(string $sql, array $params = []): self;

    // Existence helpers
    /**
     * @return bool
     */
    public function exists(): bool;

    /**
     * @return bool
     */
    public function notExists(): bool;

    /**
     * @return bool
     */
    public function tableExists(): bool;

    // Batch processing methods
    /**
     * @param int $batchSize
     *
     * @return Generator<int, array<int, array<string, mixed>>, mixed, void>
     */
    public function batch(int $batchSize = 100): Generator;

    /**
     * @param int $batchSize
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function each(int $batchSize = 100): Generator;

    /**
     * Stream query results without loading into memory.
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function stream(): Generator;

    // Joins
    /**
     * @param string $tableAlias
     * @param string|RawValue $condition
     * @param string $type
     *
     * @return static
     */
    public function join(string $tableAlias, string|RawValue $condition, string $type = 'INNER'): self;

    /**
     * @param string $tableAlias
     * @param string|RawValue $condition
     *
     * @return static
     */
    public function leftJoin(string $tableAlias, string|RawValue $condition): self;

    /**
     * @param string $tableAlias
     * @param string|RawValue $condition
     *
     * @return static
     */
    public function rightJoin(string $tableAlias, string|RawValue $condition): self;

    /**
     * @param string $tableAlias
     * @param string|RawValue $condition
     *
     * @return static
     */
    public function innerJoin(string $tableAlias, string|RawValue $condition): self;

    /**
     * Add LATERAL JOIN clause.
     *
     * @param string|callable(\tommyknocker\pdodb\query\QueryBuilder): void $tableOrSubquery Table name or callable for subquery
     * @param string|RawValue|null $condition Optional ON condition
     * @param string $type JOIN type (default: LEFT)
     * @param string|null $alias Optional alias for LATERAL subquery
     *
     * @return static
     */
    public function lateralJoin(
        string|callable $tableOrSubquery,
        string|RawValue|null $condition = null,
        string $type = 'LEFT',
        ?string $alias = null
    ): self;

    // Ordering / grouping / pagination / options
    /**
     * @param string|array<int|string, string>|RawValue $expr
     * @param string $direction
     *
     * @return static
     */
    public function orderBy(string|array|RawValue $expr, string $direction = 'ASC'): self;

    /**
     * @param string|array<int, string>|RawValue $cols
     *
     * @return static
     */
    public function groupBy(string|array|RawValue $cols): self;

    /**
     * @param int $number
     *
     * @return static
     */
    public function limit(int $number): self;

    /**
     * @param int $number
     *
     * @return static
     */
    public function offset(int $number): self;

    /**
     * @param string|array<int, string> $options
     *
     * @return static
     */
    public function option(string|array $options): self;

    /**
     * @return static
     */
    public function asObject(): self;

    // ON DUPLICATE / upsert helpers
    /**
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate
     *
     * @return static
     */
    public function onDuplicate(array $onDuplicate): self;

    // JSON helpers

    /**
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string|null $alias
     * @param bool $asText
     *
     * @return static
     */
    public function selectJson(string $col, array|string $path, ?string $alias = null, bool $asText = true): self;

    /**
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $operator
     * @param mixed $value
     * @param string $cond
     *
     * @return static
     */
    public function whereJsonPath(string $col, array|string $path, string $operator, mixed $value, string $cond = 'AND'): self;

    /**
     * @param string $col
     * @param mixed $value
     * @param array<int, string|int>|string|null $path
     * @param string $cond
     *
     * @return static
     */
    public function whereJsonContains(string $col, mixed $value, array|string|null $path = null, string $cond = 'AND'): self;

    /**
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param mixed $value
     *
     * @return RawValue
     */
    public function jsonSet(string $col, array|string $path, mixed $value): RawValue;

    /**
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return RawValue
     */
    public function jsonRemove(string $col, array|string $path): RawValue;

    /**
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $direction
     *
     * @return static
     */
    public function orderByJson(string $col, array|string $path, string $direction = 'ASC'): self;

    /**
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $cond
     *
     * @return static
     */
    public function whereJsonExists(string $col, array|string $path, string $cond = 'AND'): self;

    // Introspect
    /**
     * Convert query to SQL string and parameters.
     *
     * @param bool $formatted Whether to format SQL for readability
     *
     * @return array{sql: string, params: array<string, string|int|float|bool|null>}
     */
    public function toSQL(bool $formatted = false): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function explain(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function explainAnalyze(): array;

    /**
     * Analyze EXPLAIN output with optimization recommendations.
     *
     * @param string|null $tableName Optional table name for index suggestions
     *
     * @return \tommyknocker\pdodb\query\analysis\ExplainAnalysis Analysis result with recommendations
     */
    public function explainAdvice(?string $tableName = null): \tommyknocker\pdodb\query\analysis\ExplainAnalysis;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function describe(): array;

    // Execution primitives (pass-through helpers)

    /**
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return PDOStatement
     */
    public function executeStatement(string|RawValue $sql, array $params = []): PDOStatement;

    /**
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string|RawValue $sql, array $params = []): array;

    /**
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return mixed
     */
    public function fetchColumn(string|RawValue $sql, array $params = []): mixed;

    /**
     * @param string|RawValue $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return mixed
     */
    public function fetch(string|RawValue $sql, array $params = []): mixed;

    // CSV / XML loaders
    /**
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public function loadCsv(string $filePath, array $options = []): bool;

    /**
     * @param string $filePath
     * @param string $rowTag
     * @param int|null $linesToIgnore
     *
     * @return bool
     */
    public function loadXml(string $filePath, string $rowTag = '<row>', ?int $linesToIgnore = null): bool;

    /**
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public function loadJson(string $filePath, array $options = []): bool;

    // Query Scopes
    /**
     * Apply a query scope.
     *
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder, mixed...): \tommyknocker\pdodb\query\QueryBuilder|string $scope Scope callable or scope name
     * @param mixed ...$args Additional arguments to pass to the scope
     *
     * @return static
     */
    public function scope(callable|string $scope, mixed ...$args): self;

    /**
     * Temporarily disable a global scope.
     *
     * @param string $scopeName Name of the global scope to disable
     *
     * @return static
     */
    public function withoutGlobalScope(string $scopeName): self;

    /**
     * Temporarily disable multiple global scopes.
     *
     * @param array<string> $scopeNames Names of the global scopes to disable
     *
     * @return static
     */
    public function withoutGlobalScopes(array $scopeNames): self;

    /**
     * Check if a global scope is disabled.
     *
     * @param string $scopeName Name of the global scope
     *
     * @return bool
     */
    public function isGlobalScopeDisabled(string $scopeName): bool;

    /**
     * Get list of disabled global scopes.
     *
     * @return array<string>
     */
    public function getDisabledGlobalScopes(): array;
}
