<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * TO_TIMESTAMP value for Oracle.
 * Converts a string to TIMESTAMP with specified format.
 */
class ToTimestampValue extends RawValue
{
    /** @var string The timestamp string value */
    protected string $timestampString;

    /** @var string The format string (e.g., 'YYYY-MM-DD HH24:MI:SS') */
    protected string $format;

    /**
     * Constructor.
     *
     * @param string $timestampString The timestamp string to convert
     * @param string $format The format string (e.g., 'YYYY-MM-DD HH24:MI:SS')
     */
    public function __construct(string $timestampString, string $format = 'YYYY-MM-DD HH24:MI:SS')
    {
        $this->timestampString = $timestampString;
        $this->format = $format;
        // Placeholder - will be replaced by dialect
        parent::__construct('');
    }

    /**
     * Get the timestamp string.
     *
     * @return string The timestamp string
     */
    public function getTimestampString(): string
    {
        return $this->timestampString;
    }

    /**
     * Get the format string.
     *
     * @return string The format string
     */
    public function getFormat(): string
    {
        return $this->format;
    }
}
