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

    /**
     * Constructor
     *
     * @param string $column The column name containing JSON data.
     * @param array|string $path The path within the JSON structure to extract the value from.
     * @param bool $asText Whether to return the value as text (true) or as JSON (false).
     */
    public function __construct(string $column, array|string $path, bool $asText = true)
    {
        $this->column = $column;
        $this->path = $path;
        $this->asText = $asText;
        
        // Placeholder - will be replaced in QueryBuilder
        $this->value = '__JSON_GET__';
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
     * Get the JSON path.
     *
     * @return array|string The JSON path.
     */
    public function getPath(): array|string
    {
        return $this->path;
    }

    /**
     * Get whether to return the value as text.
     *
     * @return bool True if the value should be returned as text, false for JSON.
     */
    public function getAsText(): bool
    {
        return $this->asText;
    }
}

