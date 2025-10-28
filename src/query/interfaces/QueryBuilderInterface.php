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
     * @return self
     */
    public function table(string $table): self;

    /**
     * @param string $table
     *
     * @return self
     */
    public function from(string $table): self;

    /**
     * @param string $prefix
     *
     * @return self
     */
    public function prefix(string $prefix): self;

    // Select / projection
    /**
     * @param RawValue|string|array<int|string, string|RawValue|callable(\tommyknocker\pdodb\query\QueryBuilder): void> $cols
     *
     * @return self
     */
    public function select(RawValue|string|array $cols): self;

    /**
     * @return array<int, array<string, mixed>>
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
     * @return self
     */
    public function where(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * @param string|array<string, mixed>|RawValue $exprOrColumn
     * @param mixed|null $value
     * @param string $operator
     *
     * @return self
     */
    public function andWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * @param string|array<string, mixed>|RawValue $exprOrColumn
     * @param mixed|null $value
     * @param string $operator
     *
     * @return self
     */
    public function orWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * @param string|array<string, mixed>|RawValue $exprOrColumn
     * @param mixed|null $value
     * @param string $operator
     *
     * @return self
     */
    public function having(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * @param string|array<string, mixed>|RawValue $exprOrColumn
     * @param mixed|null $value
     * @param string $operator
     *
     * @return self
     */
    public function orHaving(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    // New Query Builder methods
    /**
     * @param string $column
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void $subquery
     *
     * @return self
     */
    public function whereIn(string $column, callable $subquery): self;

    /**
     * @param string $column
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void $subquery
     *
     * @return self
     */
    public function whereNotIn(string $column, callable $subquery): self;

    /**
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void $subquery
     *
     * @return self
     */
    public function whereExists(callable $subquery): self;

    /**
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void $subquery
     *
     * @return self
     */
    public function whereNotExists(callable $subquery): self;

    /**
     * @param string $sql
     * @param array<string, mixed> $params
     *
     * @return self
     */
    public function whereRaw(string $sql, array $params = []): self;

    /**
     * @param string $sql
     * @param array<string, mixed> $params
     *
     * @return self
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
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function cursor(): Generator;

    // Joins
    /**
     * @param string $tableAlias
     * @param string|RawValue $condition
     * @param string $type
     *
     * @return self
     */
    public function join(string $tableAlias, string|RawValue $condition, string $type = 'INNER'): self;

    /**
     * @param string $tableAlias
     * @param string|RawValue $condition
     *
     * @return self
     */
    public function leftJoin(string $tableAlias, string|RawValue $condition): self;

    /**
     * @param string $tableAlias
     * @param string|RawValue $condition
     *
     * @return self
     */
    public function rightJoin(string $tableAlias, string|RawValue $condition): self;

    /**
     * @param string $tableAlias
     * @param string|RawValue $condition
     *
     * @return self
     */
    public function innerJoin(string $tableAlias, string|RawValue $condition): self;

    // Ordering / grouping / pagination / options
    /**
     * @param string|array<int|string, string>|RawValue $expr
     * @param string $direction
     *
     * @return self
     */
    public function orderBy(string|array|RawValue $expr, string $direction = 'ASC'): self;

    /**
     * @param string|array<int, string>|RawValue $cols
     *
     * @return self
     */
    public function groupBy(string|array|RawValue $cols): self;

    /**
     * @param int $number
     *
     * @return self
     */
    public function limit(int $number): self;

    /**
     * @param int $number
     *
     * @return self
     */
    public function offset(int $number): self;

    /**
     * @param string|array<int, string> $options
     *
     * @return self
     */
    public function option(string|array $options): self;

    /**
     * @return self
     */
    public function asObject(): self;

    // ON DUPLICATE / upsert helpers
    /**
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate
     *
     * @return self
     */
    public function onDuplicate(array $onDuplicate): self;

    // JSON helpers

    /**
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string|null $alias
     * @param bool $asText
     *
     * @return self
     */
    public function selectJson(string $col, array|string $path, ?string $alias = null, bool $asText = true): self;

    /**
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $operator
     * @param mixed $value
     * @param string $cond
     *
     * @return self
     */
    public function whereJsonPath(string $col, array|string $path, string $operator, mixed $value, string $cond = 'AND'): self;

    /**
     * @param string $col
     * @param mixed $value
     * @param array<int, string|int>|string|null $path
     * @param string $cond
     *
     * @return self
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
     * @return self
     */
    public function orderByJson(string $col, array|string $path, string $direction = 'ASC'): self;

    /**
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $cond
     *
     * @return self
     */
    public function whereJsonExists(string $col, array|string $path, string $cond = 'AND'): self;

    // Introspect
    /**
     * @return array{sql: string, params: array<string, string|int|float|bool|null>}
     */
    public function toSQL(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function explain(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function explainAnalyze(): array;

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
}
