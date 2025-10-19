<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * IFNULL / COALESCE value for NULL replacement
 */
class IfNullValue extends RawValue
{
    protected string $expr;
    protected mixed $defaultValue;

    public function __construct(string $expr, mixed $defaultValue)
    {
        $this->expr = $expr;
        $this->defaultValue = $defaultValue;
    }

    public function getExpr(): string
    {
        return $this->expr;
    }

    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }
}
