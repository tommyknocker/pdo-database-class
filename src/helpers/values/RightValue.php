<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * RIGHT value for extracting right part of string (dialect-specific).
 */
class RightValue extends RawValue
{
    /** @var string|RawValue Source string */
    protected string|RawValue $sourceValue;

    /** @var int Number of characters */
    protected int $length;

    /**
     * Constructor.
     *
     * @param string|RawValue $value Source string
     * @param int $length Number of characters to extract
     */
    public function __construct(string|RawValue $value, int $length)
    {
        parent::__construct('');
        $this->sourceValue = $value;
        $this->length = $length;
    }

    /**
     * Get source string.
     *
     * @return string|RawValue
     */
    public function getSourceValue(): string|RawValue
    {
        return $this->sourceValue;
    }

    /**
     * Get length.
     *
     * @return int
     */
    public function getLength(): int
    {
        return $this->length;
    }
}
