<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\pagination\Cursor;
use tommyknocker\pdodb\query\pagination\CursorPaginationResult;
use tommyknocker\pdodb\query\pagination\PaginationResult;
use tommyknocker\pdodb\query\pagination\SimplePaginationResult;

interface SelectQueryBuilderInterface
{
    /**
     * Add columns to the SELECT clause.
     *
     * @param RawValue|callable(\tommyknocker\pdodb\query\QueryBuilder): void|string|array<int|string, string|RawValue|callable(\tommyknocker\pdodb\query\QueryBuilder): void> $cols The columns to add.
     *
     * @return self The current instance.
     */
    public function select(RawValue|callable|string|array $cols): self;

    /**
     * Execute SELECT statement and return all rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(): array;

    /**
     * Execute SELECT statement and return first row.
     *
     * @return mixed
     */
    public function getOne(): mixed;

    /**
     * Execute SELECT statement and return column values.
     *
     * @return array<int, mixed>
     */
    public function getColumn(): array;

    /**
     * Execute SELECT statement and return single value.
     *
     * @return mixed
     */
    public function getValue(): mixed;

    /**
     * Add ORDER BY clause.
     *
     * @param string|RawValue $expr The expression to order by.
     * @param string $direction The direction of the ordering (ASC or DESC).
     *
     * @return self The current instance.
     */
    public function orderBy(string|RawValue $expr, string $direction = 'ASC'): self;

    /**
     * Add ORDER BY expression directly (for JSON expressions that already contain direction).
     *
     * @param string $expr The complete ORDER BY expression.
     *
     * @return self The current instance.
     */
    public function addOrderExpression(string $expr): self;

    /**
     * Add GROUP BY clause.
     *
     * @param string|array<int, string|RawValue>|RawValue $cols The columns to group by.
     *
     * @return self The current instance.
     */
    public function groupBy(string|array|RawValue $cols): self;

    /**
     * Add LIMIT clause.
     *
     * @param int $number The number of rows to limit.
     *
     * @return self The current instance.
     */
    public function limit(int $number): self;

    /**
     * Add OFFSET clause.
     *
     * @param int $number The number of rows to offset.
     *
     * @return self The current instance.
     */
    public function offset(int $number): self;

    /**
     * Sets the query options.
     *
     * @param string|array<int|string, mixed> $options The query options.
     *
     * @return self The current object.
     */
    public function option(string|array $options): self;

    /**
     * Set fetch mode to return objects.
     *
     * @return self
     */
    public function asObject(): self;

    /**
     * Convert query to SQL string and parameters.
     *
     * @return array{sql: string, params: array<string, string|int|float|bool|null>}
     */
    public function toSQL(): array;

    /**
     * Execute EXPLAIN query to analyze query execution plan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function explain(): array;

    /**
     * Execute EXPLAIN ANALYZE query (PostgreSQL) or EXPLAIN FORMAT=JSON (MySQL).
     *
     * @return array<int, array<string, mixed>>
     */
    public function explainAnalyze(): array;

    /**
     * Execute DESCRIBE query to get table structure.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(): array;

    /**
     * Get indexes for the current table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function indexes(): array;

    /**
     * Get foreign keys for the current table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function keys(): array;

    /**
     * Get constraints for the current table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function constraints(): array;

    /**
     * Set the table name for the select query builder.
     *
     * @param string $table The table name.
     *
     * @return self The current instance.
     */
    public function setTable(string $table): self;

    /**
     * Set the prefix for the select query builder.
     *
     * @param string|null $prefix The prefix to set.
     *
     * @return self The current instance.
     */
    public function setPrefix(?string $prefix): self;

    /**
     * Get the query array with SQL and parameters.
     *
     * @return array{sql: string, params: array<string, string|int|float|bool|null>}
     */
    public function getQuery(): array;

    /**
     * Paginate query results with metadata.
     *
     * @param int $perPage
     * @param int|null $page
     * @param array<string, mixed> $options
     *
     * @return PaginationResult
     */
    public function paginate(int $perPage = 15, ?int $page = null, array $options = []): PaginationResult;

    /**
     * Simple pagination without total count.
     *
     * @param int $perPage
     * @param int|null $page
     * @param array<string, mixed> $options
     *
     * @return SimplePaginationResult
     */
    public function simplePaginate(int $perPage = 15, ?int $page = null, array $options = []): SimplePaginationResult;

    /**
     * Cursor-based pagination.
     *
     * @param int $perPage
     * @param string|Cursor|null $cursor
     * @param array<string, mixed> $options
     *
     * @return CursorPaginationResult
     */
    public function cursorPaginate(
        int $perPage = 15,
        string|Cursor|null $cursor = null,
        array $options = []
    ): CursorPaginationResult;
}
