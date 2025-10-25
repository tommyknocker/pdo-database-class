<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

use tommyknocker\pdodb\helpers\RawValue;

interface JsonQueryBuilderInterface
{
    /**
     * Add SELECT expression extracting JSON value by path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string|null $alias
     * @param bool $asText
     *
     * @return self
     */
    public function selectJson(string $col, array|string $path, ?string $alias = null, bool $asText = true): self;

    /**
     * Add WHERE condition comparing JSON value at path.
     *
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
     * Add WHERE JSON contains (col contains value).
     *
     * @param string $col
     * @param mixed $value
     * @param array<int, string|int>|string|null $path
     * @param string $cond
     *
     * @return self
     */
    public function whereJsonContains(string $col, mixed $value, array|string|null $path = null, string $cond = 'AND'): self;

    /**
     * Update JSON field: set value at path (create missing).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param mixed $value
     *
     * @return RawValue
     */
    public function jsonSet(string $col, array|string $path, mixed $value): RawValue;

    /**
     * Remove JSON path from column (returns RawValue to use in update).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return RawValue
     */
    public function jsonRemove(string $col, array|string $path): RawValue;

    /**
     * Add ORDER BY expression based on JSON path.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $direction
     *
     * @return self
     */
    public function orderByJson(string $col, array|string $path, string $direction = 'ASC'): self;

    /**
     * Check existence of JSON path (returns boolean condition).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param string $cond
     *
     * @return self
     */
    public function whereJsonExists(string $col, array|string $path, string $cond = 'AND'): self;

    /**
     * Get JSON select expressions.
     *
     * @return array<int, string>
     */
    public function getJsonSelects(): array;

    /**
     * Get JSON order expressions.
     *
     * @return array<int, string>
     */
    public function getJsonOrders(): array;

    /**
     * Clear JSON select expressions.
     *
     * @return self
     */
    public function clearJsonSelects(): self;

    /**
     * Clear JSON order expressions.
     *
     * @return self
     */
    public function clearJsonOrders(): self;
}
