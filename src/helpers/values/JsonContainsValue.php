<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * JSON contains check value.
 */
class JsonContainsValue extends RawValue
{
    /** @var string JSON column name */
    protected string $column;

    /** @var mixed Value to search for */
    protected mixed $searchValue;

    /** @var array<int, string|int>|string|null JSON path */
    protected array|string|null $path;

    /**
     * @param string $column
     * @param mixed $value
     * @param array<int, string|int>|string|null $path
     */
    public function __construct(string $column, mixed $value, array|string|null $path = null)
    {
        $this->column = $column;
        $this->searchValue = $value;
        $this->path = $path;
        parent::__construct('');
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getSearchValue(): mixed
    {
        return $this->searchValue;
    }

    /**
     * @return array<int, string|int>|string|null
     */
    public function getPath(): array|string|null
    {
        return $this->path;
    }
}
