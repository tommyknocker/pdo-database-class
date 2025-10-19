<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * YEAR extraction value (dialect-specific)
 */
class YearValue extends RawValue
{
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
