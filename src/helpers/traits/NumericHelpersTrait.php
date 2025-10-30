<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\ModValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\TruncValue;

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

    /**
     * Returns ceiling value (round up).
     *
     * @param string|RawValue $value The value.
     *
     * @return RawValue The RawValue instance for CEIL/CEILING.
     */
    public static function ceil(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("CEIL($val)");
    }

    /**
     * Returns floor value (round down).
     *
     * @param string|RawValue $value The value.
     *
     * @return RawValue The RawValue instance for FLOOR.
     */
    public static function floor(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("FLOOR($val)");
    }

    /**
     * Returns power operation (value raised to the power of exponent).
     *
     * @param string|RawValue $value The base value.
     * @param string|int|float|RawValue $exponent Always exponent value.
     *
     * @return RawValue The RawValue instance for POWER.
     */
    public static function power(string|RawValue $value, string|int|float|RawValue $exponent): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        $exp = $exponent instanceof RawValue ? $exponent->getValue() : (string)$exponent;
        return new RawValue("POWER($val, $exp)");
    }

    /**
     * Returns square root.
     *
     * @param string|RawValue $value The value.
     *
     * @return RawValue The RawValue instance for SQRT.
     */
    public static function sqrt(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("SQRT($val)");
    }

    /**
     * Returns exponential function (e raised to the power of value).
     *
     * @param string|RawValue $value The value.
     *
     * @return RawValue The RawValue instance for EXP.
     */
    public static function exp(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("EXP($val)");
    }

    /**
     * Returns natural logarithm (base e).
     *
     * @param string|RawValue $value The value.
     *
     * @return RawValue The RawValue instance for LN.
     */
    public static function ln(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("LN($val)");
    }

    /**
     * Returns logarithm (base specified or base 10 if not specified).
     *
     * @param string|RawValue $value The value.
     * @param string|int|float|RawValue|null $base The logarithm base (default is 10).
     *
     * @return RawValue The RawValue instance for LOG.
     */
    public static function log(string|RawValue $value, string|int|float|RawValue|null $base = null): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        if ($base === null) {
            return new RawValue("LOG10($val)");
        }
        $b = $base instanceof RawValue ? $base->getValue() : (string)$base;
        return new RawValue("LOG($b, $val)");
    }

    /**
     * Returns truncated value (remove fractional part or truncate to specified precision).
     *
     * @param string|RawValue $value The value to truncate.
     * @param int $precision The number of decimal places to keep (default is 0).
     *
     * @return TruncValue The TruncValue instance for TRUNCATE/TRUNC.
     */
    public static function trunc(string|RawValue $value, int $precision = 0): TruncValue
    {
        return new TruncValue($value, $precision);
    }
}
