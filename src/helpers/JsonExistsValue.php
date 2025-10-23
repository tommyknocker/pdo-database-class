<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON path existence check value.
 */
class JsonExistsValue extends RawValue
{
    /** @var string JSON column name */
    protected string $column;

    /** @var array<int, string|int>|string JSON path */
    protected array|string $path;

    /**
     * @param string $column
     * @param array<int, string|int>|string $path
     */
    public function __construct(string $column, array|string $path)
    {
        $this->column = $column;
        $this->path = $path;
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
}
