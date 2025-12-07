<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\AiExplainAnalysis;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;
use tommyknocker\pdodb\query\cte\CteManager;
use tommyknocker\pdodb\query\pagination\Cursor;
use tommyknocker\pdodb\query\pagination\CursorPaginationResult;
use tommyknocker\pdodb\query\pagination\PaginationResult;
use tommyknocker\pdodb\query\pagination\SimplePaginationResult;
use tommyknocker\pdodb\query\QueryBuilder;
use tommyknocker\pdodb\query\UnionQuery;

interface SelectQueryBuilderInterface
{
    /**
     * Add columns to the SELECT clause.
     *
     * @param RawValue|callable(QueryBuilder): void|string|array<int|string, string|RawValue|callable(QueryBuilder): void> $cols The columns to add.
     *
     * @return static The current instance.
     */
    public function select(RawValue|callable|string|array $cols): static;

    /**
     * Execute SELECT statement and return all rows.
     *
     * @return array<int|string, array<string, mixed>>
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
     * @param string|null $name Column name to extract (optional, uses first column from select() if not provided)
     *
     * @return array<int, mixed>
     */
    public function getColumn(?string $name = null): array;

    /**
     * Execute SELECT statement and return single value.
     *
     * @return mixed
     */
    public function getValue(): mixed;

    /**
     * Add ORDER BY clause.
     *
     * @param string|array<int|string, string>|RawValue $expr The expression(s) to order by.
     * @param string $direction The direction of the ordering (ASC or DESC).
     *
     * @return static The current instance.
     */
    public function orderBy(string|array|RawValue $expr, string $direction = 'ASC'): static;

    /**
     * Add ORDER BY expression directly (for JSON expressions that already contain direction).
     *
     * @param string $expr The complete ORDER BY expression.
     *
     * @return static The current instance.
     */
    public function addOrderExpression(string $expr): static;

    /**
     * Enable caching for this query.
     *
     * @param int $ttl Time-to-live in seconds
     * @param string|null $key Custom cache key (null = auto-generate)
     *
     * @return static The current instance.
     */
    public function cache(int $ttl = 3600, ?string $key = null): static;

    /**
     * Disable caching for this query.
     *
     * @return static The current instance.
     */
    public function noCache(): static;

    /**
     * Add GROUP BY clause.
     *
     * @param string|array<int, string|RawValue>|RawValue $cols The columns to group by.
     *
     * @return static The current instance.
     */
    public function groupBy(string|array|RawValue $cols): static;

    /**
     * Add LIMIT clause.
     *
     * @param int $number The number of rows to limit.
     *
     * @return static The current instance.
     */
    public function limit(int $number): static;

    /**
     * Add OFFSET clause.
     *
     * @param int $number The number of rows to offset.
     *
     * @return static The current instance.
     */
    public function offset(int $number): static;

    /**
     * Sets the query options.
     *
     * @param string|array<int|string, mixed> $options The query options.
     *
     * @return static The current object.
     */
    public function option(string|array $options): static;

    /**
     * Set fetch mode to return objects.
     *
     * @return static
     */
    public function asObject(): static;

    /**
     * Convert query to SQL string and parameters.
     *
     * @param bool $formatted Whether to format SQL for readability
     *
     * @return array{sql: string, params: array<string, string|int|float|bool|null>}
     */
    public function toSQL(bool $formatted = false): array;

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
     * Analyze EXPLAIN output with optimization recommendations.
     *
     * @param string|null $tableName Optional table name for index suggestions
     *
     * @return ExplainAnalysis Analysis result with recommendations
     */
    public function explainAdvice(?string $tableName = null): ExplainAnalysis;

    /**
     * Analyze EXPLAIN output with AI-powered recommendations.
     *
     * @param string|null $tableName Optional table name for index suggestions
     * @param string|null $provider AI provider name (openai, anthropic, google, microsoft, ollama)
     * @param array<string, mixed> $options Additional options (temperature, max_tokens, model)
     *
     * @return AiExplainAnalysis Analysis result with AI recommendations
     */
    public function explainAiAdvice(?string $tableName = null, ?string $provider = null, array $options = []): AiExplainAnalysis;

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
     * @return static The current instance.
     */
    public function setTable(string $table): static;

    /**
     * Set the prefix for the select query builder.
     *
     * @param string|null $prefix The prefix to set.
     *
     * @return static The current instance.
     */
    public function setPrefix(?string $prefix): static;

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

    /**
     * Set CTE manager.
     *
     * @param CteManager|null $cteManager
     *
     * @return static
     */
    public function setCteManager(?CteManager $cteManager): static;

    /**
     * Set UNION operations.
     *
     * @param array<UnionQuery> $unions
     *
     * @return static
     */
    public function setUnions(array $unions): static;

    /**
     * Set DISTINCT flag.
     *
     * @param bool $distinct
     *
     * @return static
     */
    public function setDistinct(bool $distinct): static;

    /**
     * Set DISTINCT ON columns.
     *
     * @param array<string> $columns
     *
     * @return static
     */
    public function setDistinctOn(array $columns): static;

    /**
     * Set column name to index results by.
     *
     * @param string|null $columnName Column name to use as array keys (null = no indexing)
     *
     * @return static
     */
    public function setIndexColumn(?string $columnName): static;

    /**
     * Get debug information about the select query.
     *
     * @return array<string, mixed> Debug information about SELECT query state
     */
    public function getDebugInfo(): array;
}
