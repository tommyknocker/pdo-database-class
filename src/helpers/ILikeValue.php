<?php

namespace tommyknocker\pdodb\helpers;

/**
 * ILIKE value representation
 */
class ILikeValue extends RawValue
{
    /**
     * Constructor
     *
     * @param string $column The column name to apply the ILIKE pattern matching.
     * @param string $pattern The pattern to match against.
     */
    public function __construct(string $column, string $pattern)
    {
        $this->value = $column;
        $this->params = [$pattern];
    }
}