<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON path comparison value
 */
class JsonPathValue extends RawValue
{
    /** @var string JSON column name */
    protected string $column;
    
    /** @var array<int, string|int>|string JSON path */
    protected array|string $path;
    
    /** @var string Comparison operator (e.g., '=', '>', '<') */
    protected string $operator;
    
    /** @var mixed Value to compare against */
    protected mixed $compareValue;

    /**
     * @param string $column
     * @param array<int, string|int>|string $path
     * @param string $operator
     * @param mixed $value
     */
    public function __construct(string $column, array|string $path, string $operator, mixed $value)
    {
        $this->column = $column;
        $this->path = $path;
        $this->operator = $operator;
        $this->compareValue = $value;
        parent::__construct('');
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    /**
     * @return array<int, string|int>|string
     */
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
