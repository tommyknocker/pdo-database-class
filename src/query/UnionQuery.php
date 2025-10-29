<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use Closure;

/**
 * Represents a UNION, INTERSECT, or EXCEPT operation.
 */
class UnionQuery
{
    protected string $type;

    /** @var QueryBuilder|Closure(QueryBuilder): void */
    protected QueryBuilder|Closure $query;

    /**
     * Constructor.
     *
     * @param string $type Operation type: UNION, UNION ALL, INTERSECT, EXCEPT
     * @param QueryBuilder|Closure(QueryBuilder): void $query Query to union
     */
    public function __construct(string $type, QueryBuilder|Closure $query)
    {
        $this->type = strtoupper($type);
        $this->query = $query;
    }

    /**
     * Get operation type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get query.
     *
     * @return QueryBuilder|Closure(QueryBuilder): void
     */
    public function getQuery(): QueryBuilder|Closure
    {
        return $this->query;
    }
}
