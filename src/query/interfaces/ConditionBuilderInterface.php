<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

use tommyknocker\pdodb\helpers\values\RawValue;

interface ConditionBuilderInterface
{
    /**
     * Add WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function where(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): static;

    /**
     * Add AND WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function andWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): static;

    /**
     * Add OR WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function orWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): static;

    /**
     * Add HAVING clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function having(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): static;

    /**
     * Add OR HAVING clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return static The current instance.
     */
    public function orHaving(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): static;

    /**
     * Add WHERE IN clause with subquery or array.
     *
     * @param string $column The column to check
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereIn(string $column, callable|array $subqueryOrArray, string $boolean = 'AND'): static;

    /**
     * Add WHERE NOT IN clause with subquery or array.
     *
     * @param string $column The column to check
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNotIn(string $column, callable|array $subqueryOrArray, string $boolean = 'AND'): static;

    /**
     * Add WHERE column IS NULL clause.
     *
     * @param string $column The column to check
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNull(string $column, string $boolean = 'AND'): static;

    /**
     * Add WHERE column IS NOT NULL clause.
     *
     * @param string $column The column to check
     * @param string $boolean The boolean operator (AND or OR)
     *
     * @return static The current instance
     */
    public function whereNotNull(string $column, string $boolean = 'AND'): static;

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
    public function whereBetween(string $column, mixed $min, mixed $max, string $boolean = 'AND'): static;

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
    public function whereNotBetween(string $column, mixed $min, mixed $max, string $boolean = 'AND'): static;

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
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): static;

    /**
     * Add OR WHERE column IS NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function orWhereNull(string $column): static;

    /**
     * Add OR WHERE column IS NOT NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function orWhereNotNull(string $column): static;

    /**
     * Add OR WHERE column BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function orWhereBetween(string $column, mixed $min, mixed $max): static;

    /**
     * Add OR WHERE column NOT BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function orWhereNotBetween(string $column, mixed $min, mixed $max): static;

    /**
     * Add OR WHERE column IN clause.
     *
     * @param string $column The column to check
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function orWhereIn(string $column, callable|array $subqueryOrArray): static;

    /**
     * Add OR WHERE column NOT IN clause.
     *
     * @param string $column The column to check
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function orWhereNotIn(string $column, callable|array $subqueryOrArray): static;

    /**
     * Add AND WHERE column IS NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function andWhereNull(string $column): static;

    /**
     * Add AND WHERE column IS NOT NULL clause.
     *
     * @param string $column The column to check
     *
     * @return static The current instance
     */
    public function andWhereNotNull(string $column): static;

    /**
     * Add AND WHERE column BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function andWhereBetween(string $column, mixed $min, mixed $max): static;

    /**
     * Add AND WHERE column NOT BETWEEN clause.
     *
     * @param string $column The column to check
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     *
     * @return static The current instance
     */
    public function andWhereNotBetween(string $column, mixed $min, mixed $max): static;

    /**
     * Add AND WHERE column IN clause.
     *
     * @param string $column The column to check
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function andWhereIn(string $column, callable|array $subqueryOrArray): static;

    /**
     * Add AND WHERE column NOT IN clause.
     *
     * @param string $column The column to check
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void|array<int|string, mixed> $subqueryOrArray The subquery callback or array of values
     *
     * @return static The current instance
     */
    public function andWhereNotIn(string $column, callable|array $subqueryOrArray): static;

    /**
     * Add AND WHERE column comparison with another column.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator (=, !=, <, >, <=, >=, etc.)
     * @param string $second The second column
     *
     * @return static The current instance
     */
    public function andWhereColumn(string $first, string $operator, string $second): static;

    /**
     * Add OR WHERE column comparison with another column.
     *
     * @param string $first The first column
     * @param string $operator The comparison operator (=, !=, <, >, <=, >=, etc.)
     * @param string $second The second column
     *
     * @return static The current instance
     */
    public function orWhereColumn(string $first, string $operator, string $second): static;

    /**
     * Add WHERE EXISTS clause.
     *
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void $subquery The subquery callback
     *
     * @return static The current instance
     */
    public function whereExists(callable $subquery): static;

    /**
     * Add WHERE NOT EXISTS clause.
     *
     * @param callable(\tommyknocker\pdodb\query\QueryBuilder): void $subquery The subquery callback
     *
     * @return static The current instance
     */
    public function whereNotExists(callable $subquery): static;

    /**
     * Add raw WHERE clause.
     *
     * @param string $sql The raw SQL condition
     * @param array<string, mixed> $params The parameters for the condition
     *
     * @return static The current instance
     */
    public function whereRaw(string $sql, array $params = []): static;

    /**
     * Add raw HAVING clause.
     *
     * @param string $sql The raw SQL condition
     * @param array<string, mixed> $params The parameters for the condition
     *
     * @return static The current instance
     */
    public function havingRaw(string $sql, array $params = []): static;

    /**
     * Return true if at least one row matches the current WHERE conditions.
     *
     * @return bool
     */
    public function exists(): bool;

    /**
     * Return true if no rows match the current WHERE conditions.
     *
     * @return bool
     */
    public function notExists(): bool;

    /**
     * Checks if a table exists.
     *
     * @return bool True if the table exists, false otherwise.
     */
    public function tableExists(): bool;

    /**
     * Set the table name for the condition builder.
     *
     * @param string $table The table name.
     *
     * @return static The current instance.
     */
    public function setTable(string $table): static;

    /**
     * Set the prefix for the condition builder.
     *
     * @param string|null $prefix The prefix to set.
     *
     * @return static The current instance.
     */
    public function setPrefix(?string $prefix): static;

    /**
     * Set the limit for the condition builder.
     *
     * @param int|null $limit The limit to set.
     *
     * @return static The current instance.
     */
    public function setLimit(?int $limit): static;

    /**
     * Build conditions clause from items.
     *
     * @param array<int, mixed> $items The condition items.
     * @param string $keyword The keyword (WHERE, HAVING, etc.).
     *
     * @return string The built conditions clause.
     */
    public function buildConditionsClause(array $items, string $keyword): string;

    /**
     * Get WHERE conditions.
     *
     * @return array<int, mixed> The WHERE conditions.
     */
    public function getWhere(): array;

    /**
     * Get HAVING conditions.
     *
     * @return array<int, mixed> The HAVING conditions.
     */
    public function getHaving(): array;

    /**
     * Get debug information about conditions.
     *
     * @return array<string, mixed> Debug information about WHERE, HAVING, ORDER BY, and LIMIT
     */
    public function getDebugInfo(): array;

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
    public function extractShardKeyValue(string $shardKeyColumn): mixed;
}
