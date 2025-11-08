<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

use Closure;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\QueryBuilder;

interface DmlQueryBuilderInterface
{
    /**
     * Insert data into the table.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data The data to insert.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the insert operation.
     */
    public function insert(array $data, array $onDuplicate = []): int;

    /**
     * Insert multiple rows into the table.
     *
     * @param array<int, array<string, string|int|float|bool|null|RawValue>> $rows The rows to insert.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the insert operation.
     */
    public function insertMulti(array $rows, array $onDuplicate = []): int;

    /**
     * Insert data from a SELECT query or subquery.
     *
     * @param string|Closure(QueryBuilder): void|SelectQueryBuilderInterface|QueryBuilder $source Source query (table name, QueryBuilder, SelectQueryBuilderInterface, or Closure)
     * @param array<string>|null $columns Column names to insert into (null = use SELECT columns)
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the insert operation.
     */
    public function insertFrom(
        string|Closure|SelectQueryBuilderInterface|QueryBuilder $source,
        ?array $columns = null,
        array $onDuplicate = []
    ): int;

    /**
     * Replace data into the table.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data The data to replace.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the replace operation.
     */
    public function replace(array $data, array $onDuplicate = []): int;

    /**
     * Replace multiple rows into the table.
     *
     * @param array<int, array<string, string|int|float|bool|null|RawValue>> $rows The rows to replace.
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return int The result of the replace operation.
     */
    public function replaceMulti(array $rows, array $onDuplicate = []): int;

    /**
     * Execute UPDATE statement.
     *
     * @param array<string, string|int|float|bool|null|RawValue|array<string, string|int|float>> $data
     *
     * @return int
     */
    public function update(array $data): int;

    /**
     * Execute DELETE statement.
     *
     * @return int
     */
    public function delete(): int;

    /**
     * Execute TRUNCATE statement.
     *
     * @return bool
     */
    public function truncate(): bool;

    /**
     * Add query option.
     *
     * @param string|array<int|string, mixed> $option
     *
     * @return static
     */
    public function addOption(string|array $option): static;

    /**
     * Set query options.
     *
     * @param array<int|string, mixed> $options
     *
     * @return static
     */
    public function setOptions(array $options): static;

    /**
     * Add ON DUPLICATE clause.
     *
     * @param array<string, string|int|float|bool|null|RawValue> $onDuplicate The columns to update on duplicate.
     *
     * @return static The current instance.
     */
    public function onDuplicate(array $onDuplicate): static;

    /**
     * Set the table name for the DML query builder.
     *
     * @param string $table The table name.
     *
     * @return static The current instance.
     */
    public function setTable(string $table): static;

    /**
     * Set the prefix for the DML query builder.
     *
     * @param string|null $prefix The prefix to set.
     *
     * @return static The current instance.
     */
    public function setPrefix(?string $prefix): static;

    /**
     * Set the limit for the DML query builder.
     *
     * @param int|null $limit The limit to set.
     *
     * @return static The current instance.
     */
    public function setLimit(?int $limit): static;

    /**
     * Execute MERGE statement (INSERT/UPDATE/DELETE based on match conditions).
     *
     * @param string|Closure(QueryBuilder): void|SelectQueryBuilderInterface $source Source table/subquery for MERGE
     * @param string|array<string> $onConditions ON clause conditions
     * @param array<string, string|int|float|bool|null|RawValue> $whenMatched Update columns when matched
     * @param array<string, string|int|float|bool|null|RawValue> $whenNotMatched Insert columns when not matched
     * @param bool $whenNotMatchedBySourceDelete Delete when not matched by source
     *
     * @return int Number of affected rows
     */
    public function merge(
        string|Closure|SelectQueryBuilderInterface $source,
        string|array $onConditions,
        array $whenMatched = [],
        array $whenNotMatched = [],
        bool $whenNotMatchedBySourceDelete = false
    ): int;

    /**
     * Get debug information about the DML query.
     *
     * @return array<string, mixed> Debug information about DML query state
     */
    public function getDebugInfo(): array;

    /**
     * Add JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     * @param string $type JOIN type, e.g. INNER, LEFT, RIGHT
     *
     * @return static The current instance.
     */
    public function join(string $tableAlias, string|RawValue $condition, string $type = 'INNER'): static;

    /**
     * Add LEFT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return static The current instance.
     */
    public function leftJoin(string $tableAlias, string|RawValue $condition): static;

    /**
     * Add RIGHT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return static The current instance.
     */
    public function rightJoin(string $tableAlias, string|RawValue $condition): static;

    /**
     * Add INNER JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return static The current instance.
     */
    public function innerJoin(string $tableAlias, string|RawValue $condition): static;
}
