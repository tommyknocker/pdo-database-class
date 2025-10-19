<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON path existence check value
 */
class JsonExistsValue extends RawValue
{
    protected string $column;
    protected array|string $path;

    /**
     * Constructor
     *
     * @param string $column The column name containing JSON data.
     * @param array|string $path The path within the JSON structure to check for existence.
     */
    public function __construct(string $column, array|string $path)
    {
        $this->column = $column;
        $this->path = $path;
        
        // Placeholder - will be replaced in QueryBuilder
        $this->value = '__JSON_EXISTS__';
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
}

