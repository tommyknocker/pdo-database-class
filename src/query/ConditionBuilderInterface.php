<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\helpers\RawValue;

interface ConditionBuilderInterface
{
    /**
     * Add WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return self The current instance.
     */
    public function where(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * Add AND WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return self The current instance.
     */
    public function andWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * Add OR WHERE clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return self The current instance.
     */
    public function orWhere(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * Add HAVING clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return self The current instance.
     */
    public function having(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * Add OR HAVING clause.
     *
     * @param string|array<string, mixed>|RawValue $exprOrColumn The expression or column to add.
     * @param mixed $value The value to use in the condition.
     * @param string $operator The operator to use in the condition.
     *
     * @return self The current instance.
     */
    public function orHaving(string|array|RawValue $exprOrColumn, mixed $value = null, string $operator = '='): self;

    /**
     * Add WHERE IN clause with subquery.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilder): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereIn(string $column, callable $subquery): self;

    /**
     * Add WHERE NOT IN clause with subquery.
     *
     * @param string $column The column to check
     * @param callable(QueryBuilder): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereNotIn(string $column, callable $subquery): self;

    /**
     * Add WHERE EXISTS clause.
     *
     * @param callable(QueryBuilder): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereExists(callable $subquery): self;

    /**
     * Add WHERE NOT EXISTS clause.
     *
     * @param callable(QueryBuilder): void $subquery The subquery callback
     *
     * @return self The current instance
     */
    public function whereNotExists(callable $subquery): self;

    /**
     * Add raw WHERE clause.
     *
     * @param string $sql The raw SQL condition
     * @param array<string, mixed> $params The parameters for the condition
     *
     * @return self The current instance
     */
    public function whereRaw(string $sql, array $params = []): self;

    /**
     * Add raw HAVING clause.
     *
     * @param string $sql The raw SQL condition
     * @param array<string, mixed> $params The parameters for the condition
     *
     * @return self The current instance
     */
    public function havingRaw(string $sql, array $params = []): self;

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
}
