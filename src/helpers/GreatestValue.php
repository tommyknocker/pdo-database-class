<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\helpers;

/**
 * GREATEST / MAX value (dialect-specific)
 */
class GreatestValue extends RawValue
{
    /** @var array<int, string|int|float|RawValue> Values to compare */
    protected array $values;

    /**
     * @param array<int, string|int|float|RawValue> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
        parent::__construct('');
    }

    /**
     * @return array<int, string|int|float|RawValue>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
