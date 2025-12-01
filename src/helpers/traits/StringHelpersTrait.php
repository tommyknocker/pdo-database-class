<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\LeftValue;
use tommyknocker\pdodb\helpers\values\PadValue;
use tommyknocker\pdodb\helpers\values\PositionValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\RegexpExtractValue;
use tommyknocker\pdodb\helpers\values\RegexpMatchValue;
use tommyknocker\pdodb\helpers\values\RegexpReplaceValue;
use tommyknocker\pdodb\helpers\values\RepeatValue;
use tommyknocker\pdodb\helpers\values\ReverseValue;
use tommyknocker\pdodb\helpers\values\RightValue;
use tommyknocker\pdodb\helpers\values\SubstringValue;
use tommyknocker\pdodb\helpers\values\ToCharValue;

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
        $searchEscaped = addslashes($search);
        $replaceEscaped = addslashes($replace);
        return new RawValue("REPLACE($val, '$searchEscaped', '$replaceEscaped')");
    }

    /**
     * Returns left part of string (first N characters).
     *
     * @param string|RawValue $value The source string.
     * @param int $length Number of characters to extract.
     *
     * @return LeftValue The LeftValue instance for LEFT.
     */
    public static function left(string|RawValue $value, int $length): LeftValue
    {
        return new LeftValue($value, $length);
    }

    /**
     * Returns right part of string (last N characters).
     *
     * @param string|RawValue $value The source string.
     * @param int $length Number of characters to extract.
     *
     * @return RightValue The RightValue instance for RIGHT.
     */
    public static function right(string|RawValue $value, int $length): RightValue
    {
        return new RightValue($value, $length);
    }

    /**
     * Returns position of substring in string (1-indexed, or 0 if not found).
     *
     * @param string|RawValue $substring The substring to search for.
     * @param string|RawValue $value The source string.
     *
     * @return PositionValue The PositionValue instance for POSITION/INSTR/LOCATE.
     */
    public static function position(string|RawValue $substring, string|RawValue $value): PositionValue
    {
        return new PositionValue($substring, $value);
    }

    /**
     * Returns repeated string.
     *
     * @param string|RawValue $value The string to repeat.
     * @param int $count Number of times to repeat.
     *
     * @return RepeatValue The RepeatValue instance for REPEAT.
     */
    public static function repeat(string|RawValue $value, int $count): RepeatValue
    {
        return new RepeatValue($value, $count);
    }

    /**
     * Returns reversed string.
     *
     * @param string|RawValue $value The string to reverse.
     *
     * @return ReverseValue The ReverseValue instance for REVERSE.
     */
    public static function reverse(string|RawValue $value): ReverseValue
    {
        return new ReverseValue($value);
    }

    /**
     * Returns string padded on the left to specified length.
     *
     * @param string|RawValue $value The source string.
     * @param int $length Target length.
     * @param string $padString The padding string (default is space).
     *
     * @return PadValue The PadValue instance for LPAD.
     */
    public static function padLeft(string|RawValue $value, int $length, string $padString = ' '): PadValue
    {
        return new PadValue($value, $length, $padString, true);
    }

    /**
     * Returns string padded on the right to specified length.
     *
     * @param string|RawValue $value The source string.
     * @param int $length Target length.
     * @param string $padString The padding string (default is space).
     *
     * @return PadValue The PadValue instance for RPAD.
     */
    public static function padRight(string|RawValue $value, int $length, string $padString = ' '): PadValue
    {
        return new PadValue($value, $length, $padString, false);
    }

    /**
     * Alias for padLeft.
     *
     * @param string|RawValue $value
     * @param int $length
     * @param string $padString
     *
     * @return PadValue
     */
    public static function lpad(string|RawValue $value, int $length, string $padString = ' '): PadValue
    {
        return self::padLeft($value, $length, $padString);
    }

    /**
     * Alias for padRight.
     *
     * @param string|RawValue $value
     * @param int $length
     * @param string $padString
     *
     * @return PadValue
     */
    public static function rpad(string|RawValue $value, int $length, string $padString = ' '): PadValue
    {
        return self::padRight($value, $length, $padString);
    }

    /**
     * Returns regexp match result (dialect-specific).
     * Returns boolean expression (true if matches, false otherwise).
     *
     * @param string|RawValue $value The source string.
     * @param string $pattern The regex pattern.
     *
     * @return RegexpMatchValue The RegexpMatchValue instance for REGEXP match.
     */
    public static function regexpMatch(string|RawValue $value, string $pattern): RegexpMatchValue
    {
        return new RegexpMatchValue($value, $pattern);
    }

    /**
     * Returns regexp replace expression (dialect-specific).
     * Replaces all occurrences of pattern with replacement string.
     *
     * @param string|RawValue $value The source string.
     * @param string $pattern The regex pattern.
     * @param string $replacement The replacement string.
     *
     * @return RegexpReplaceValue The RegexpReplaceValue instance for REGEXP replace.
     */
    public static function regexpReplace(string|RawValue $value, string $pattern, string $replacement): RegexpReplaceValue
    {
        return new RegexpReplaceValue($value, $pattern, $replacement);
    }

    /**
     * Returns regexp extract expression (dialect-specific).
     * Extracts matched substring or capture group from string.
     *
     * @param string|RawValue $value The source string.
     * @param string $pattern The regex pattern.
     * @param int|null $groupIndex Capture group index (0 = full match, 1+ = specific group, null = full match).
     *
     * @return RegexpExtractValue The RegexpExtractValue instance for REGEXP extract.
     */
    public static function regexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): RegexpExtractValue
    {
        return new RegexpExtractValue($value, $pattern, $groupIndex);
    }

    /**
     * Converts a value to character string (Oracle-specific).
     * Throws UnsupportedOperationException for non-Oracle dialects.
     *
     * @param string|RawValue $value The value to convert to character string
     *
     * @return ToCharValue The ToCharValue instance
     */
    public static function toChar(string|RawValue $value): ToCharValue
    {
        return new ToCharValue($value);
    }
}
