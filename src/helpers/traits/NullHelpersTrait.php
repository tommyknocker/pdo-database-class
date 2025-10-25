<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\IfNullValue;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for NULL handling operations.
 */
trait NullHelpersTrait
{
    /**
     * Returns a RawValue instance representing SQL NULL.
     *
     * @return RawValue The RawValue instance for NULL.
     */
    public static function null(): RawValue
    {
        return new RawValue('NULL');
    }

    /**
     * Returns a RawValue instance representing an IS NULL condition.
     *
     * @param string $column The column name.
     *
     * @return RawValue
     */
    public static function isNull(string $column): RawValue
    {
        return new RawValue("$column IS NULL");
    }

    /**
     * Returns a RawValue instance representing an IS NOT NULL condition.
     *
     * @param string $column The column name.
     *
     * @return RawValue
     */
    public static function isNotNull(string $column): RawValue
    {
        return new RawValue("$column IS NOT NULL");
    }

    /**
     * Returns first non-NULL value from arguments.
     *
     * @param mixed ...$values The values to check.
     *
     * @return RawValue The RawValue instance for COALESCE.
     */
    public static function coalesce(...$values): RawValue
    {
        $args = [];
        foreach ($values as $val) {
            if ($val instanceof RawValue) {
                $args[] = $val->getValue();
            } elseif (is_string($val)) {
                $args[] = $val;
            } else {
                $args[] = (string)$val;
            }
        }
        return new RawValue('COALESCE(' . implode(', ', $args) . ')');
    }

    /**
     * Returns IFNULL / COALESCE for NULL replacement (dialect-specific).
     *
     * @param string $expr The expression to check.
     * @param mixed $default The default value if NULL.
     *
     * @return IfNullValue The IfNullValue instance.
     */
    public static function ifNull(string $expr, mixed $default): IfNullValue
    {
        return new IfNullValue($expr, $default);
    }

    /**
     * Returns NULL if two expressions are equal.
     *
     * @param mixed $expr1 The first expression.
     * @param mixed $expr2 The second expression.
     *
     * @return RawValue The RawValue instance for NULLIF.
     */
    public static function nullIf(mixed $expr1, mixed $expr2): RawValue
    {
        $e1 = $expr1 instanceof RawValue ? $expr1->getValue() : $expr1;
        $e2 = $expr2 instanceof RawValue ? $expr2->getValue() : $expr2;
        return new RawValue("NULLIF($e1, $e2)");
    }
}
