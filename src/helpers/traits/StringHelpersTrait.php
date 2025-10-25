<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\SubstringValue;

/**
 * Trait for string operations.
 */
trait StringHelpersTrait
{
    /**
     * Returns a ConcatValue instance representing a concatenation of values.
     *
     * @param string|int|float|RawValue ...$args The values to concatenate.
     *
     * @return ConcatValue The ConcatValue instance.
     */
    public static function concat(string|int|float|RawValue ...$args): ConcatValue
    {
        return new ConcatValue(array_values($args));
    }

    /**
     * Converts string to uppercase.
     *
     * @param string|RawValue $value The value to convert.
     *
     * @return RawValue The RawValue instance for UPPER.
     */
    public static function upper(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("UPPER($val)");
    }

    /**
     * Converts string to lowercase.
     *
     * @param string|RawValue $value The value to convert.
     *
     * @return RawValue The RawValue instance for LOWER.
     */
    public static function lower(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("LOWER($val)");
    }

    /**
     * Trims whitespace from both sides.
     *
     * @param string|RawValue $value The value to trim.
     *
     * @return RawValue The RawValue instance for TRIM.
     */
    public static function trim(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("TRIM($val)");
    }

    /**
     * Trims whitespace from the left side.
     *
     * @param string|RawValue $value The value to trim.
     *
     * @return RawValue The RawValue instance for LTRIM.
     */
    public static function ltrim(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("LTRIM($val)");
    }

    /**
     * Trims whitespace from the right side.
     *
     * @param string|RawValue $value The value to trim.
     *
     * @return RawValue The RawValue instance for RTRIM.
     */
    public static function rtrim(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("RTRIM($val)");
    }

    /**
     * Returns string length.
     *
     * @param string|RawValue $value The value.
     *
     * @return RawValue The RawValue instance for LENGTH.
     */
    public static function length(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("LENGTH($val)");
    }

    /**
     * Returns substring (dialect-specific for SUBSTRING vs SUBSTR).
     *
     * @param string|RawValue $value The source string.
     * @param int $start The starting position (1-based).
     * @param int|null $length The length of substring.
     *
     * @return SubstringValue The SubstringValue instance.
     */
    public static function substring(string|RawValue $value, int $start, ?int $length = null): SubstringValue
    {
        return new SubstringValue($value, $start, $length);
    }

    /**
     * Returns string with replacements.
     *
     * @param string|RawValue $value The source string.
     * @param string $search The string to search for.
     * @param string $replace The replacement string.
     *
     * @return RawValue The RawValue instance for REPLACE.
     */
    public static function replace(string|RawValue $value, string $search, string $replace): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("REPLACE($val, '$search', '$replace')");
    }
}
