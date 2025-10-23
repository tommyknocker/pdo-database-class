<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON get/extract value.
 */
class JsonGetValue extends RawValue
{
    /** @var string JSON column name */
    protected string $column;

    /** @var array<int, string|int>|string JSON path */
    protected array|string $path;

    /** @var bool Whether to extract as text */
    protected bool $asText;

    /**
     * @param string $column
     * @param array<int, string|int>|string $path
     * @param bool $asText
     */
    public function __construct(string $column, array|string $path, bool $asText = true)
    {
        $this->column = $column;
        $this->path = $path;
        $this->asText = $asText;
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

    public function getAsText(): bool
    {
        return $this->asText;
    }
}
