<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * IFNULL / COALESCE value for NULL replacement.
 */
class IfNullValue extends RawValue
{
    /** @var string Expression to check for NULL */
    protected string $expr;

    /** @var mixed Default value if expression is NULL */
    protected mixed $defaultValue;

    public function __construct(string $expr, mixed $defaultValue)
    {
        $this->expr = $expr;
        $this->defaultValue = $defaultValue;
        parent::__construct('');
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
