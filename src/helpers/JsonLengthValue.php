<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON length/size value
 */
class JsonLengthValue extends RawValue
{
    protected string $column;
    protected array|string|null $path;

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

    public function getPath(): array|string|null
    {
        return $this->path;
    }
}
