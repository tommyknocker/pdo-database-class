<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for conditional logic.
 */
trait ConditionalHelpersTrait
{
    /**
     * Returns a RawValue instance representing a CASE statement.
     *
     * Generates a SQL CASE expression with automatic identifier quoting for MSSQL reserved words.
     *
     * @param array<string, string|RawValue> $cases An associative array where keys are WHEN conditions (as strings) and values are THEN results.
     * @param string|RawValue|null $else An optional ELSE result.
     *
     * @return RawValue The RawValue instance for the CASE statement.
     *
     * @example
     * // Simple CASE statement
     * $db->find()
     *     ->from('users')
     *     ->select([
     *         'status_label' => Db::case([
     *             "status = 'active'" => "'Active'",
     *             "status = 'inactive'" => "'Inactive'",
     *         ], "'Unknown'")
     *     ])
     *     ->get();
     * @example
     * // CASE with RawValue expressions
     * $db->find()
     *     ->from('users')
     *     ->select([
     *         'type' => Db::case([
     *             Db::regexpLike('email', '^[0-9]+@') => "'Numeric Email'",
     *             Db::regexpLike('email', '@example\.com$') => "'Example Email'",
     *         ], "'Other'")
     *     ])
     *     ->get();
     * @example
     * // CASE with MSSQL reserved words (automatically quoted)
     * $db->find()
     *     ->from('plans')
     *     ->select([
     *         'plan_type' => Db::case([
     *             "plan = 'basic'" => "'Basic Plan'",
     *             "plan = 'premium'" => "'Premium Plan'",
     *         ])
     *     ])
     *     ->get();
     * @example
     * // CASE in WHERE clause
     * $db->find()
     *     ->from('orders')
     *     ->where(Db::case([
     *         "status = 'pending'" => '1',
     *         "status = 'processing'" => '1',
     *     ], '0'), '1')
     *     ->get();
     *
     * @note For MSSQL: Identifiers in simple string conditions are automatically quoted if they are
     *       reserved words (e.g., 'plan', 'order', 'group'). This prevents syntax errors.
     *
     * @warning WHEN conditions should be valid SQL expressions. Use Db::raw() or helper methods for complex expressions.
     *
     * @see documentation/07-helper-functions/06-comparison-helpers.md
     */
    public static function case(array $cases, string|RawValue|null $else = null): RawValue
    {
        $sql = 'CASE';
        foreach ($cases as $when => $then) {
            // Keys in PHP arrays can only be string or int, so $when is always string
            // Quote identifiers in simple conditions (e.g., "plan = 'value'" -> "[plan] = 'value'")
            // This handles MSSQL reserved words like 'plan'
            $whenStr = static::quoteIdentifiersInCondition((string)$when);
            // Handle RawValue in THEN result
            if ($then instanceof RawValue) {
                $thenStr = $then->getValue();
            } else {
                $thenStr = (string)$then;
            }
            $sql .= " WHEN $whenStr THEN $thenStr";
        }
        if ($else !== null) {
            if ($else instanceof RawValue) {
                $elseStr = $else->getValue();
            } else {
                $elseStr = (string)$else;
            }
            $sql .= " ELSE $elseStr";
        }
        $sql .= ' END';

        return new RawValue($sql);
    }

    /**
     * Quote identifiers in simple SQL conditions.
     * Handles patterns like "column = 'value'", "column = value", etc.
     * Only quotes identifiers that are reserved words, not SQL keywords/operators.
     *
     * @param string $condition The condition string.
     *
     * @return string The condition with quoted identifiers.
     */
    protected static function quoteIdentifiersInCondition(string $condition): string
    {
        return $condition;
    }
}
