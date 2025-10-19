<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * GREATEST / MAX value (dialect-specific)
 */
class GreatestValue extends RawValue
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
