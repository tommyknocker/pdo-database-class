<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for aggregate operations.
 */
trait AggregateHelpersTrait
{
    /**
     * Returns COUNT expression.
     *
     * @param string|RawValue $expr The expression to count (use '*' for all rows).
     *
     * @return RawValue The RawValue instance for COUNT.
     */
    public static function count(string|RawValue $expr = '*'): RawValue
    {
        $val = $expr instanceof RawValue ? $expr->getValue() : $expr;
        return new RawValue("COUNT($val)");
    }

    /**
     * Returns SUM expression.
     *
     * @param string|RawValue $column The column to sum.
     *
     * @return RawValue The RawValue instance for SUM.
     */
    public static function sum(string|RawValue $column): RawValue
    {
        $val = $column instanceof RawValue ? $column->getValue() : $column;
        return new RawValue("SUM($val)");
    }

    /**
     * Returns AVG expression.
     *
     * @param string|RawValue $column The column to average.
     *
     * @return RawValue The RawValue instance for AVG.
     */
    public static function avg(string|RawValue $column): RawValue
    {
        $val = $column instanceof RawValue ? $column->getValue() : $column;
        return new RawValue("AVG($val)");
    }

    /**
     * Returns MIN expression.
     *
     * @param string|RawValue $column The column.
     *
     * @return RawValue The RawValue instance for MIN.
     */
    public static function min(string|RawValue $column): RawValue
    {
        $val = $column instanceof RawValue ? $column->getValue() : $column;
        return new RawValue("MIN($val)");
    }

    /**
     * Returns MAX expression.
     *
     * @param string|RawValue $column The column.
     *
     * @return RawValue The RawValue instance for MAX.
     */
    public static function max(string|RawValue $column): RawValue
    {
        $val = $column instanceof RawValue ? $column->getValue() : $column;
        return new RawValue("MAX($val)");
    }
}
