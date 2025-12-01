<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * TO_CHAR value for Oracle.
 * Converts a value to character string (useful for CLOB columns).
 */
class ToCharValue extends RawValue
{
    /** @var string|RawValue The value to convert */
    protected string|RawValue $sourceValue;

    /**
     * Constructor.
     *
     * @param string|RawValue $sourceValue The value to convert to character string
     */
    public function __construct(string|RawValue $sourceValue)
    {
        $this->sourceValue = $sourceValue;
        // Placeholder - will be replaced by dialect
        parent::__construct('');
    }

    /**
     * Get the source value.
     *
     * @return string|RawValue The source value
     */
    public function getSourceValue(): string|RawValue
    {
        return $this->sourceValue;
    }
}
