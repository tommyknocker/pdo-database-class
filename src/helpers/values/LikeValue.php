<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * Value class for LIKE conditions.
 * Allows dialect-specific formatting (e.g., TO_CHAR() for Oracle CLOB columns).
 */
class LikeValue extends RawValue
{
    protected string $column;
    protected string $pattern;

    public function __construct(string $column, string $pattern)
    {
        $this->column = $column;
        $this->pattern = $pattern;
        // Placeholder SQL - will be replaced by RawValueResolver using dialect->formatLike()
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

