<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * JSON replace value for UPDATE operations (only replaces if path exists).
 */
class JsonReplaceValue extends RawValue
{
    /** @var string JSON column name */
    protected string $column;

    /** @var array<int, string|int>|string JSON path */
    protected array|string $path;

    /** @var mixed Value to replace */
    protected mixed $replaceValue;

    /**
     * @param string $column
     * @param array<int, string|int>|string $path
     * @param mixed $value
     */
    public function __construct(string $column, array|string $path, mixed $value)
    {
        $this->column = $column;
        $this->path = $path;
        $this->replaceValue = $value;
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

    public function getReplaceValue(): mixed
    {
        return $this->replaceValue;
    }
}
