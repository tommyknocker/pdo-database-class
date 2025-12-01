<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * TO_DATE value for Oracle.
 * Converts a string to DATE with specified format.
 */
class ToDateValue extends RawValue
{
    /** @var string|RawValue The date string value or SQL expression */
    protected string|RawValue $dateString;

    /** @var string The format string (e.g., 'YYYY-MM-DD') */
    protected string $format;

    /**
     * Constructor.
     *
     * @param string|RawValue $dateString The date string to convert or SQL expression
     * @param string $format The format string (e.g., 'YYYY-MM-DD')
     */
    public function __construct(string|RawValue $dateString, string $format = 'YYYY-MM-DD')
    {
        $this->dateString = $dateString;
        $this->format = $format;
        // Placeholder - will be replaced by dialect
        parent::__construct('');
    }

    /**
     * Get the date string.
     *
     * @return string|RawValue The date string or SQL expression
     */
    public function getDateString(): string|RawValue
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
