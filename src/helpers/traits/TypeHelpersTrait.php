<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\GreatestValue;
use tommyknocker\pdodb\helpers\values\LeastValue;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for type conversion and comparison.
 */
trait TypeHelpersTrait
{
    /**
     * Returns CAST expression.
     *
     * @param mixed $value The value to cast.
     * @param string $type The target type.
     *
     * @return RawValue The RawValue instance for CAST.
     */
    public static function cast(mixed $value, string $type): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("CAST($val AS $type)");
    }

    /**
     * Returns greatest (maximum) value from arguments (dialect-specific).
     *
     * @param string|int|float|RawValue ...$values The values to compare.
     *
     * @return GreatestValue The GreatestValue instance.
     */
    public static function greatest(string|int|float|RawValue ...$values): GreatestValue
    {
        return new GreatestValue(array_values($values));
    }

    /**
     * Returns least (minimum) value from arguments (dialect-specific).
     *
     * @param string|int|float|RawValue ...$values The values to compare.
     *
     * @return LeastValue The LeastValue instance.
     */
    public static function least(string|int|float|RawValue ...$values): LeastValue
    {
        return new LeastValue(array_values($values));
    }
}
