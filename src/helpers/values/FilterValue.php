<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * Represents an aggregate function with FILTER clause.
 */
class FilterValue extends RawValue
{
    protected string $aggregateFunc;

    /** @var array<int|string, mixed> */
    protected array $filterConditions = [];

    /**
     * Constructor.
     *
     * @param string $aggregateFunc Aggregate function call (e.g., "COUNT(*)", "SUM(amount)")
     */
    public function __construct(string $aggregateFunc)
    {
        $this->aggregateFunc = $aggregateFunc;
        parent::__construct($aggregateFunc);
    }

    /**
     * Add a filter condition.
     *
     * @param string $column Column name.
     * @param mixed $value Value to compare.
     * @param string $operator Comparison operator.
     *
     * @return self
     */
    public function filter(string $column, mixed $value, string $operator = '='): self
    {
        $this->filterConditions[] = [
            'column' => $column,
            'value' => $value,
            'operator' => $operator,
        ];
        return $this;
    }

    /**
     * Get aggregate function.
     *
     * @return string
     */
    public function getAggregateFunc(): string
    {
        return $this->aggregateFunc;
    }

    /**
     * Get filter conditions.
     *
     * @return array<int|string, mixed>
     */
    public function getFilterConditions(): array
    {
        return $this->filterConditions;
    }

    /**
     * Check if filter conditions exist.
     *
     * @return bool
     */
    public function hasFilter(): bool
    {
        return !empty($this->filterConditions);
    }
}
