<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * TO_DATE value for Oracle.
 * Converts a string to DATE with specified format.
 */
class ToDateValue extends RawValue
{
    /** @var string The date string value */
    protected string $dateString;

    /** @var string The format string (e.g., 'YYYY-MM-DD') */
    protected string $format;

    /**
     * Constructor.
     *
     * @param string $dateString The date string to convert
     * @param string $format The format string (e.g., 'YYYY-MM-DD')
     */
    public function __construct(string $dateString, string $format = 'YYYY-MM-DD')
    {
        $this->dateString = $dateString;
        $this->format = $format;
        // Placeholder - will be replaced by dialect
        parent::__construct('');
    }

    /**
     * Get the date string.
     *
     * @return string The date string
     */
    public function getDateString(): string
    {
        return $this->dateString;
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
