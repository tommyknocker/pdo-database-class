<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\ModValue;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for numeric operations.
 */
trait NumericHelpersTrait
{
    /**
     * Returns an array with an increment operation.
     *
     * @param int|float $num The number to increment by.
     *
     * @return array<string, string|int|float> The array with the increment operation.
     */
    public static function inc(int|float $num = 1): array
    {
        return ['__op' => 'inc', 'val' => $num];
    }

    /**
     * Returns an array with a decrement operation.
     *
     * @param int|float $num The number to decrement by.
     *
     * @return array<string, string|int|float> The array with the decrement operation.
     */
    public static function dec(int|float $num = 1): array
    {
        return ['__op' => 'dec', 'val' => $num];
    }

    /**
     * Returns absolute value.
     *
     * @param string|RawValue $value The value.
     *
     * @return RawValue The RawValue instance for ABS.
     */
    public static function abs(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("ABS($val)");
    }

    /**
     * Returns rounded value.
     *
     * @param string|RawValue $value The value to round.
     * @param int $precision The number of decimal places.
     *
     * @return RawValue The RawValue instance for ROUND.
     */
    public static function round(string|RawValue $value, int $precision = 0): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("ROUND($val, $precision)");
    }

    /**
     * Returns modulo operation (dialect-specific).
     *
     * @param string|RawValue $dividend The dividend.
     * @param string|RawValue $divisor The divisor.
     *
     * @return ModValue The ModValue instance.
     */
    public static function mod(string|RawValue $dividend, string|RawValue $divisor): ModValue
    {
        return new ModValue($dividend, $divisor);
    }
}
