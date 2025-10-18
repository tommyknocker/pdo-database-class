<?php

namespace tommyknocker\pdodb\helpers;

/**
 * Concatenated value e.g. CONCAT(value1, value2, ...)
 */
class ConcatValue extends RawValue
{
    protected array $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Returns the array of values to be concatenated.
     *
     * @return array The array of values.
     */
    public function getValues(): array
    {
        return $this->values;
    }
}