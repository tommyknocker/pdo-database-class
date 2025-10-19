<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON path comparison value
 */
class JsonPathValue extends RawValue
{
    protected string $column;
    protected array|string $path;
    protected string $operator;
    protected mixed $compareValue;

    public function __construct(string $column, array|string $path, string $operator, mixed $value)
    {
        $this->column = $column;
        $this->path = $path;
        $this->operator = $operator;
        $this->compareValue = $value;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getPath(): array|string
    {
        return $this->path;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getCompareValue(): mixed
    {
        return $this->compareValue;
    }
}
