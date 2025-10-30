<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * DATE(value) extraction (dialect-specific formatting).
 */
class DateOnlyValue extends RawValue
{
    /** @var string|RawValue */
    protected string|RawValue $sourceValue;

    public function __construct(string|RawValue $value)
    {
        parent::__construct('');
        $this->sourceValue = $value;
    }

    public function getSourceValue(): string|RawValue
    {
        return $this->sourceValue;
    }
}


