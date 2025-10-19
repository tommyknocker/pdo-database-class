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

    /**
     * Constructor
     *
     * @param string $column The column name containing JSON data.
     * @param array|string $path The path within the JSON structure.
     * @param string $operator The comparison operator (e.g., '=', '!=', '<', '>').
     * @param mixed $value The value to compare against the JSON data at the specified path.
     */
    public function __construct(string $column, array|string $path, string $operator, mixed $value)
    {
        $this->column = $column;
        $this->path = $path;
        $this->operator = $operator;
        $this->compareValue = $value;
        
        // Placeholder - will be replaced in QueryBuilder
        $this->value = '__JSON_PATH__';
        $this->params = [];
    }

    /**
     * Get the column name.
     *
     * @return string The column name.
     */
    public function getColumn(): string
    {
        return $this->column;
    }

    /**
     * Get the JSON path.
     *
     * @return array|string The JSON path.
     */
    public function getPath(): array|string
    {
        return $this->path;
    }

    /**
     * Get the comparison operator.
     *
     * @return string The comparison operator.
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * Get the comparison value.
     *
     * @return mixed The value to compare against.
     */
    public function getCompareValue(): mixed
    {
        return $this->compareValue;
    }
}

