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

    public function __construct(string $column, array|string $path)
    {
        $this->column = $column;
        $this->path = $path;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getPath(): array|string
    {
        return $this->path;
    }
}
