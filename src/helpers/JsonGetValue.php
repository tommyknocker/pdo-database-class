<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * JSON get/extract value
 */
class JsonGetValue extends RawValue
{
    protected string $column;
    protected array|string $path;
    protected bool $asText;

    public function __construct(string $column, array|string $path, bool $asText = true)
    {
        $this->column = $column;
        $this->path = $path;
        $this->asText = $asText;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getPath(): array|string
    {
        return $this->path;
    }

    public function getAsText(): bool
    {
        return $this->asText;
    }
}
