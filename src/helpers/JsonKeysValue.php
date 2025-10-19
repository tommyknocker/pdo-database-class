<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON keys extraction value
 */
class JsonKeysValue extends RawValue
{
    protected string $column;
    protected array|string|null $path;

    /**
     * Constructor
     *
     * @param string $column The column name containing JSON data.
     * @param array|string|null $path Optional path within the JSON structure.
     */
    public function __construct(string $column, array|string|null $path = null)
    {
        $this->column = $column;
        $this->path = $path;
        
        // Placeholder - will be replaced in QueryBuilder
        $this->value = '__JSON_KEYS__';
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
     * @return array|string|null The JSON path.
     */
    public function getPath(): array|string|null
    {
        return $this->path;
    }
}

