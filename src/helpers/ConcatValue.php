<?php

namespace tommyknocker\pdodb\helpers;

/**
 * Concatenated value e.g. CONCAT(value1, value2, ...)
 */
class ConcatValue extends RawValue
{
    /** @var array<int, string|int|float|RawValue> Values to concatenate */
    protected array $values;

    /**
     * @param array<int, string|int|float|RawValue> $values
     */
    public function __construct(array $values)
    {
        // Initialize parent with empty value - will be handled by dialect
        parent::__construct('');
        $this->values = $values;
    }

    /**
     * Returns the array of values to be concatenated.
     *
     * @return array<int, string|int|float|RawValue> The array of values.
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * Override getValue() to prevent incorrect usage.
     * ConcatValue must be processed by dialect's concat() method, not used directly.
     *
     * @return string
     * @throws \RuntimeException
     */
    public function getValue(): string
    {
        throw new \RuntimeException(
            'ConcatValue cannot be used directly in SQL expressions. ' .
            'It must be processed by the dialect. ' .
            'Avoid nesting Db::concat() inside other helpers like Db::upper(). ' .
            'Use Db::raw() for complex expressions instead.'
        );
    }
}