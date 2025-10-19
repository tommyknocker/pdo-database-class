<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON contains check value
 */
class JsonContainsValue extends RawValue
{
    protected string $column;
    protected mixed $searchValue;
    protected array|string|null $path;

    /**
     * Constructor
     *
     * @param string $column The column name containing JSON data.
     * @param mixed $value The value to search for within the JSON data.
     * @param array|string|null $path Optional path within the JSON structure.
     */
    public function __construct(string $column, mixed $value, array|string|null $path = null)
    {
        $this->column = $column;
        $this->searchValue = $value;
        $this->path = $path;
        
        // Placeholder - will be replaced in QueryBuilder
        $this->value = '__JSON_CONTAINS__';
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
     * Get the search value.
     *
     * @return mixed The value to search for.
     */
    public function getSearchValue(): mixed
    {
        return $this->searchValue;
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

