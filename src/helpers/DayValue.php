<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * DAY extraction value (dialect-specific)
 */
class DayValue extends RawValue
{
    protected string|RawValue $source;

    public function __construct(string|RawValue $source)
    {
        $this->source = $source;
    }

    public function getSource(): string|RawValue
    {
        return $this->source;
    }
}
