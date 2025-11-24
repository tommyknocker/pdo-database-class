<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\ILikeValue;
use tommyknocker\pdodb\helpers\values\LikeValue;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for comparison operations.
 */
trait ComparisonHelpersTrait
{
    /**
     * Returns a LikeValue instance representing a LIKE condition.
     * Allows dialect-specific formatting (e.g., TO_CHAR() for Oracle CLOB columns).
     *
     * @param string $column The column name.
     * @param string $pattern The pattern to match.
     *
     * @return LikeValue The LikeValue instance.
     */
    public static function like(string $column, string $pattern): LikeValue
    {
        return new LikeValue($column, $pattern);
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
        // For LikeValue, create a special NOT LIKE value that will be handled in ConditionBuilder
        if ($value instanceof \tommyknocker\pdodb\helpers\values\LikeValue) {
            // Return a RawValue that ConditionBuilder can recognize and handle
            // The actual SQL will be generated in ConditionBuilder using formatLike()
            return new \tommyknocker\pdodb\helpers\values\NotLikeValue($value->getColumn(), $value->getPattern());
        }
        // For other RawValue, wrap in NOT (...)
        $valueStr = $value->getValue();
        // If value is already wrapped in parentheses or is a complete condition, use as-is
        if (str_starts_with(trim($valueStr), '(') && str_ends_with(trim($valueStr), ')')) {
            return new RawValue('NOT ' . $valueStr, $value->getParams());
        }
        return new RawValue('NOT (' . $valueStr . ')', $value->getParams());
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

        // Note: formatColumnForComparison() will be applied in ConditionBuilder
        // for CLOB compatibility (e.g., Oracle)
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
