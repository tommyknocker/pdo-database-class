<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * HOUR extraction value (dialect-specific)
 */
class HourValue extends RawValue
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
