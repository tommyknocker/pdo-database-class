<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * LEAST / MIN value (dialect-specific)
 */
class LeastValue extends RawValue
{
    protected array $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function getValues(): array
    {
        return $this->values;
    }
}
