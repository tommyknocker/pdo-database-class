<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * POSITION / LOCATE / INSTR value for finding substring position (dialect-specific).
 */
class PositionValue extends RawValue
{
    /** @var string|RawValue Substring to search for */
    protected string|RawValue $substring;

    /** @var string|RawValue Source string */
    protected string|RawValue $sourceValue;

    /**
     * Constructor.
     *
     * @param string|RawValue $substring Substring to search for
     * @param string|RawValue $value Source string
     */
    public function __construct(string|RawValue $substring, string|RawValue $value)
    {
        parent::__construct('');
        $this->substring = $substring;
        $this->sourceValue = $value;
    }

    /**
     * Get substring to search for.
     *
     * @return string|RawValue
     */
    public function getSubstring(): string|RawValue
    {
        return $this->substring;
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
}
