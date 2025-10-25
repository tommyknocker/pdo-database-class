<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\helpers\RawValue;

interface JoinBuilderInterface
{
    /**
     * Add JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     * @param string $type JOIN type, e.g. INNER, LEFT, RIGHT
     *
     * @return self The current instance.
     */
    public function join(string $tableAlias, string|RawValue $condition, string $type = 'INNER'): self;

    /**
     * Add LEFT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return self The current instance.
     */
    public function leftJoin(string $tableAlias, string|RawValue $condition): self;

    /**
     * Add RIGHT JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return self The current instance.
     */
    public function rightJoin(string $tableAlias, string|RawValue $condition): self;

    /**
     * Add INNER JOIN clause.
     *
     * @param string $tableAlias Logical table name or table + alias (e.g. "users u" or "schema.users AS u")
     * @param string|RawValue $condition Full ON condition (either a raw SQL fragment or a plain condition string)
     *
     * @return self The current instance.
     */
    public function innerJoin(string $tableAlias, string|RawValue $condition): self;

    /**
     * Set the prefix for the join builder.
     *
     * @param string|null $prefix The prefix to set.
     *
     * @return self The current instance.
     */
    public function setPrefix(?string $prefix): self;

    /**
     * Get all joins.
     *
     * @return array<int, string> The joins array.
     */
    public function getJoins(): array;
}
