<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * REVERSE value for reversing strings (dialect-specific).
 */
class ReverseValue extends RawValue
{
    /** @var string|RawValue Source string */
    protected string|RawValue $sourceValue;

    /**
     * Constructor.
     *
     * @param string|RawValue $value Source string
     */
    public function __construct(string|RawValue $value)
    {
        parent::__construct('');
        $this->sourceValue = $value;
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
