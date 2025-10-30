<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * TIME(value) extraction (dialect-specific formatting).
 */
class TimeOnlyValue extends RawValue
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
