<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\values;

/**
 * Value with alias (AS clause).
 */
class AsValue
{
    /** @var string|int|float|RawValue The value to alias */
    protected string|int|float|RawValue $value;

    /** @var string The alias name */
    protected string $alias;

    /**
     * @param string|int|float|RawValue $value
     * @param string $alias
     */
    public function __construct(string|int|float|RawValue $value, string $alias)
    {
        $this->value = $value;
        $this->alias = $alias;
    }

    /**
     * Get the value.
     *
     * @return string|int|float|RawValue
     */
    public function getValue(): string|int|float|RawValue
    {
        return $this->value;
    }

    /**
     * Get the alias.
     *
     * @return string
     */
    public function getAlias(): string
    {
        return $this->alias;
    }
}
