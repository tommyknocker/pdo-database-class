<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\cte;

use Closure;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Represents a Common Table Expression (CTE) definition.
 */
class CteDefinition
{
    protected string $name;

    /** @var QueryBuilder|Closure(QueryBuilder): void|string */
    protected QueryBuilder|Closure|string $query;
    protected bool $recursive;
    protected bool $materialized;

    /** @var array<string> */
    protected array $columns;

    /**
     * Constructor.
     *
     * @param string $name CTE name
     * @param QueryBuilder|Closure(QueryBuilder): void|string $query Query builder, closure, or raw SQL
     * @param bool $recursive Whether this is a recursive CTE
     * @param bool $materialized Whether this CTE should be materialized
     * @param array<string> $columns Optional column names
     */
    public function __construct(
        string $name,
        QueryBuilder|Closure|string $query,
        bool $recursive = false,
        bool $materialized = false,
        array $columns = []
    ) {
        $this->name = $name;
        $this->query = $query;
        $this->recursive = $recursive;
        $this->materialized = $materialized;
        $this->columns = $columns;
    }

    /**
     * Get CTE name.
     *
     * @return string CTE name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get CTE query.
     *
     * @return QueryBuilder|Closure(QueryBuilder): void|string Query.
     */
    public function getQuery(): QueryBuilder|Closure|string
    {
        return $this->query;
    }

    /**
     * Check if CTE is recursive.
     *
     * @return bool True if recursive.
     */
    public function isRecursive(): bool
    {
        return $this->recursive;
    }

    /**
     * Get column names.
     *
     * @return array<string> Column names.
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Check if CTE has explicit column list.
     *
     * @return bool True if has columns.
     */
    public function hasColumns(): bool
    {
        return !empty($this->columns);
    }

    /**
     * Check if CTE is materialized.
     *
     * @return bool True if materialized.
     */
    public function isMaterialized(): bool
    {
        return $this->materialized;
    }
}
