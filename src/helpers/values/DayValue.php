<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * DAY extraction value (dialect-specific).
 */
class DayValue extends RawValue
{
    /** @var string|RawValue Source date/datetime expression */
    protected string|RawValue $source;

    public function __construct(string|RawValue $source)
    {
        $this->source = $source;
        parent::__construct('');
    }

    public function getSource(): string|RawValue
    {
        return $this->source;
    }
}
