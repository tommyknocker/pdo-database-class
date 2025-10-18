<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use PDOStatement;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\RawValue;

interface QueryBuilderInterface
{
    // Construction / meta
    public function __construct(ConnectionInterface $connection, string $prefix = '');
    public function getConnection(): ConnectionInterface;
    public function getDialect(): DialectInterface;
    public function getPrefix(): ?string;

    // Table / source
    public function table(string $table): self;
    public function from(string $table): self;
    public function prefix(string $prefix): self;

    // Select / projection
    public function select(RawValue|string|array $cols): self;
    public function get(): mixed;
    public function getOne(): mixed;
    public function getColumn(): array;
    public function getValue(): mixed;

    // DML: insert / update / delete / replace
    public function insert(array $data, array $onDuplicate = []): mixed;
    public function insertMulti(array $rows, array $onDuplicate = []): mixed;
    public function replace(array $data, array $onDuplicate = []): mixed;
    public function replaceMulti(array $rows, array $onDuplicate = []): mixed;
    public function update(array $data): int;
    public function delete(): int;
    public function truncate(): bool;

    // Conditions: where / having / logical variants
    public function where(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;
    public function andWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;
    public function orWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;
    public function having(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;
    public function orHaving(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    // Existence helpers
    public function exists(): bool;
    public function notExists(): bool;
    public function tableExists(): bool;

    // Joins
    public function join(string $tableAlias, string|RawValue $condition, string $type = 'INNER'): self;
    public function leftJoin(string $tableAlias, string|RawValue $condition): self;
    public function rightJoin(string $tableAlias, string|RawValue $condition): self;
    public function innerJoin(string $tableAlias, string|RawValue $condition): self;

    // Ordering / grouping / pagination / options
    public function orderBy(string|RawValue $expr, string $direction = 'ASC'): self;
    public function groupBy(string|array|RawValue $cols): self;
    public function limit(int $number): self;
    public function offset(int $number): self;
    public function option(string|array $options): self;
    public function asObject(): self;

    // ON DUPLICATE / upsert helpers
    public function onDuplicate(array $onDuplicate): self;

    // JSON helpers
    public function selectJson(string $col, array|string $path, ?string $alias = null, bool $asText = true): self;
    public function whereJsonPath(string $col, array|string $path, string $operator, mixed $value, string $cond = 'AND'): self;
    public function whereJsonContains(string $col, mixed $value, array|string|null $path = null, string $cond = 'AND'): self;
    public function jsonSet(string $col, array|string $path, mixed $value): RawValue;
    public function jsonRemove(string $col, array|string $path): RawValue;
    public function orderByJson(string $col, array|string $path, string $direction = 'ASC'): self;
    public function whereJsonExists(string $col, array|string $path, string $cond = 'AND'): self;

    // Compile / introspect
    public function compile(): array;

    // Execution primitives (pass-through helpers)
    public function executeStatement(string|RawValue $sql, array $params = []): PDOStatement;
    public function fetchAll(string|RawValue $sql, array $params = []): array;
    public function fetchColumn(string|RawValue $sql, array $params = []): mixed;
    public function fetch(string|RawValue $sql, array $params = []): mixed;

    // CSV / XML loaders
    public function loadCsv(string $filePath, array $options = []): bool;
    public function loadXml(string $filePath, string $rowTag = '<row>', ?int $linesToIgnore = null): bool;
}
