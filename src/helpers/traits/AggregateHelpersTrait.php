<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\helpers\values\FilterValue;

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
     * @return FilterValue The FilterValue instance for COUNT.
     */
    public static function count(string|RawValue $expr = '*'): FilterValue
    {
        $val = $expr instanceof RawValue ? $expr->getValue() : $expr;
        return new FilterValue("COUNT($val)");
    }

    /**
     * Returns SUM expression.
     *
     * @param string|RawValue $column The column to sum.
     *
     * @return FilterValue The FilterValue instance for SUM.
     */
    public static function sum(string|RawValue $column): FilterValue
    {
        $val = $column instanceof RawValue ? $column->getValue() : $column;
        return new FilterValue("SUM($val)");
    }

    /**
     * Returns AVG expression.
     *
     * @param string|RawValue $column The column to average.
     *
     * @return FilterValue The FilterValue instance for AVG.
     */
    public static function avg(string|RawValue $column): FilterValue
    {
        $val = $column instanceof RawValue ? $column->getValue() : $column;
        return new FilterValue("AVG($val)");
    }

    /**
     * Returns MIN expression.
     *
     * @param string|RawValue $column The column.
     *
     * @return FilterValue The FilterValue instance for MIN.
     */
    public static function min(string|RawValue $column): FilterValue
    {
        $val = $column instanceof RawValue ? $column->getValue() : $column;
        return new FilterValue("MIN($val)");
    }

    /**
     * Returns MAX expression.
     *
     * @param string|RawValue $column The column.
     *
     * @return FilterValue The FilterValue instance for MAX.
     */
    public static function max(string|RawValue $column): FilterValue
    {
        $val = $column instanceof RawValue ? $column->getValue() : $column;
        return new FilterValue("MAX($val)");
    }
}
