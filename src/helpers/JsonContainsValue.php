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

    public function getPath(): array|string|null
    {
        return $this->path;
    }
}
