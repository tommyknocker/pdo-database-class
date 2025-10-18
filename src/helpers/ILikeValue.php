<?php

namespace tommyknocker\pdodb\helpers;

/**
 * ILIKE value representation
 */
class ILikeValue extends RawValue
{
    public function __construct(string $column, string $pattern)
    {
        $this->value = $column;
        $this->params = [$pattern];
    }
}