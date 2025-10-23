<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON keys extraction value.
 */
class JsonKeysValue extends RawValue
{
    /** @var string JSON column name */
    protected string $column;

    /** @var array<int, string|int>|string|null JSON path */
    protected array|string|null $path;

    /**
     * @param string $column
     * @param array<int, string|int>|string|null $path
     */
    public function __construct(string $column, array|string|null $path = null)
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
     * @return array<int, string|int>|string|null
     */
    public function getPath(): array|string|null
    {
        return $this->path;
    }
}
