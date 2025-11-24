<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * Value class for NOT LIKE conditions.
 * Similar to LikeValue but for NOT LIKE operations.
 */
class NotLikeValue extends RawValue
{
    protected string $column;
    protected string $pattern;

    public function __construct(string $column, string $pattern)
    {
        $this->column = $column;
        $this->pattern = $pattern;
        // Placeholder SQL - will be replaced by ConditionBuilder using dialect->formatLike()
        parent::__construct('', ['pattern' => $pattern, '__like_column__' => $column]);
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }
}
