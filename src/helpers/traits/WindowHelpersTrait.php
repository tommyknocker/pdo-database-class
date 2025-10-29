<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\WindowFunctionValue;

/**
 * Trait for window function helpers.
 */
trait WindowHelpersTrait
{
    /**
     * ROW_NUMBER() window function.
     * Assigns a unique sequential integer to rows within a partition.
     *
     * @return WindowFunctionValue Window function instance.
     */
    public static function rowNumber(): WindowFunctionValue
    {
        return new WindowFunctionValue('ROW_NUMBER');
    }

    /**
     * RANK() window function.
     * Assigns a rank to each row within a partition, with gaps for ties.
     *
     * @return WindowFunctionValue Window function instance.
     */
    public static function rank(): WindowFunctionValue
    {
        return new WindowFunctionValue('RANK');
    }

    /**
     * DENSE_RANK() window function.
     * Assigns a rank to each row within a partition, without gaps for ties.
     *
     * @return WindowFunctionValue Window function instance.
     */
    public static function denseRank(): WindowFunctionValue
    {
        return new WindowFunctionValue('DENSE_RANK');
    }

    /**
     * NTILE(n) window function.
     * Divides rows into n roughly equal buckets.
     *
     * @param int $buckets Number of buckets to divide rows into.
     *
     * @return WindowFunctionValue Window function instance.
     */
    public static function ntile(int $buckets): WindowFunctionValue
    {
        return new WindowFunctionValue('NTILE', [$buckets]);
    }

    /**
     * LAG() window function.
     * Access data from a previous row in the same result set.
     *
     * @param string|RawValue $column Column to access from previous row.
     * @param int $offset Number of rows back (default: 1).
     * @param mixed $default Default value if no previous row exists.
     *
     * @return WindowFunctionValue Window function instance.
     */
    public static function lag(string|RawValue $column, int $offset = 1, mixed $default = null): WindowFunctionValue
    {
        $col = $column instanceof RawValue ? $column->getValue() : $column;
        $args = [$col, $offset];
        if ($default !== null) {
            $args[] = $default;
        }
        return new WindowFunctionValue('LAG', $args);
    }

    /**
     * LEAD() window function.
     * Access data from a subsequent row in the same result set.
     *
     * @param string|RawValue $column Column to access from next row.
     * @param int $offset Number of rows forward (default: 1).
     * @param mixed $default Default value if no subsequent row exists.
     *
     * @return WindowFunctionValue Window function instance.
     */
    public static function lead(string|RawValue $column, int $offset = 1, mixed $default = null): WindowFunctionValue
    {
        $col = $column instanceof RawValue ? $column->getValue() : $column;
        $args = [$col, $offset];
        if ($default !== null) {
            $args[] = $default;
        }
        return new WindowFunctionValue('LEAD', $args);
    }

    /**
     * FIRST_VALUE() window function.
     * Returns the first value in a window frame.
     *
     * @param string|RawValue $column Column name to get first value from.
     *
     * @return WindowFunctionValue Window function instance.
     */
    public static function firstValue(string|RawValue $column): WindowFunctionValue
    {
        $col = $column instanceof RawValue ? $column->getValue() : $column;
        return new WindowFunctionValue('FIRST_VALUE', [$col]);
    }

    /**
     * LAST_VALUE() window function.
     * Returns the last value in a window frame.
     *
     * @param string|RawValue $column Column name to get last value from.
     *
     * @return WindowFunctionValue Window function instance.
     */
    public static function lastValue(string|RawValue $column): WindowFunctionValue
    {
        $col = $column instanceof RawValue ? $column->getValue() : $column;
        return new WindowFunctionValue('LAST_VALUE', [$col]);
    }

    /**
     * NTH_VALUE() window function.
     * Returns the nth value in a window frame.
     *
     * @param string|RawValue $column Column name.
     * @param int $n Position (1-based).
     *
     * @return WindowFunctionValue Window function instance.
     */
    public static function nthValue(string|RawValue $column, int $n): WindowFunctionValue
    {
        $col = $column instanceof RawValue ? $column->getValue() : $column;
        return new WindowFunctionValue('NTH_VALUE', [$col, $n]);
    }

    /**
     * Aggregate function as window function (SUM, AVG, COUNT, MIN, MAX, etc.).
     * Allows using aggregate functions with OVER clause for running totals, moving averages, etc.
     *
     * @param string $function Function name (SUM, AVG, MIN, MAX, COUNT).
     * @param string|RawValue $column Column name or expression.
     *
     * @return WindowFunctionValue Window function instance.
     */
    public static function windowAggregate(string $function, string|RawValue $column): WindowFunctionValue
    {
        $col = $column instanceof RawValue ? $column->getValue() : $column;
        return new WindowFunctionValue(strtoupper($function), [$col]);
    }
}
