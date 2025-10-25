<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\ILikeValue;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for comparison operations.
 */
trait ComparisonHelpersTrait
{
    /**
     * Returns a RawValue instance representing a LIKE condition.
     *
     * @param string $column The column name.
     * @param string $pattern The pattern to match.
     *
     * @return RawValue The RawValue instance.
     */
    public static function like(string $column, string $pattern): RawValue
    {
        return new RawValue("$column LIKE :pattern", ['pattern' => $pattern]);
    }

    /**
     * Returns a RawValue instance representing a case-insensitive LIKE condition.
     *
     * @param string $column The column name.
     * @param string $pattern The pattern to match.
     *
     * @return RawValue The RawValue instance for the case-insensitive LIKE condition.
     */
    public static function ilike(string $column, string $pattern): RawValue
    {
        return new ILikeValue($column, $pattern);
    }

    /**
     * Inverses a RawValue condition using NOT.
     *
     * @param RawValue $value The RawValue to negate.
     *
     * @return RawValue The RawValue instance for the NOT condition.
     */
    public static function not(RawValue $value): RawValue
    {
        return new RawValue('NOT (' . $value->getValue() . ')', $value->getParams());
    }

    /**
     * Returns a RawValue instance representing a BETWEEN condition.
     *
     * @param string $column The column name.
     * @param mixed $min The minimum value.
     * @param mixed $max The maximum value.
     *
     * @return RawValue The RawValue instance for the BETWEEN condition.
     */
    public static function between(string $column, mixed $min, mixed $max): RawValue
    {
        return new RawValue("$column BETWEEN :min AND :max", ['min' => $min, 'max' => $max]);
    }

    /**
     * Returns a RawValue instance representing a NOT BETWEEN condition.
     *
     * @param string $column The column name.
     * @param mixed $min The minimum value.
     * @param mixed $max The maximum value.
     *
     * @return RawValue The RawValue instance for the NOT BETWEEN condition.
     */
    public static function notBetween(string $column, mixed $min, mixed $max): RawValue
    {
        return new RawValue("$column NOT BETWEEN :min AND :max", ['min' => $min, 'max' => $max]);
    }

    /**
     * Returns a RawValue instance representing an IN condition.
     *
     * @param string $column The column name.
     * @param array<int, string|int|float|bool|null> $values The array of values for the IN condition.
     *
     * @return RawValue The RawValue instance for the IN condition.
     */
    public static function in(string $column, array $values): RawValue
    {
        $params = [];
        $placeholders = [];

        foreach ($values as $i => $val) {
            $key = "in_{$column}_$i";
            $params[$key] = $val;
            $placeholders[] = ":$key";
        }

        return new RawValue("$column IN (" . implode(', ', $placeholders) . ')', $params);
    }

    /**
     * Returns a RawValue instance representing a NOT IN condition.
     *
     * @param string $column The column name.
     * @param array<int, string|int|float|bool|null> $values The array of values for the NOT IN condition.
     *
     * @return RawValue The RawValue instance for the NOT IN condition.
     */
    public static function notIn(string $column, array $values): RawValue
    {
        $params = [];
        $placeholders = [];

        foreach ($values as $i => $val) {
            $key = "in_{$column}_$i";
            $params[$key] = $val;
            $placeholders[] = ":$key";
        }

        return new RawValue("$column NOT IN (" . implode(', ', $placeholders) . ')', $params);
    }
}
