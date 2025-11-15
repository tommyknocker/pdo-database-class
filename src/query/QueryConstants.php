<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

/**
 * Query constants for SQL operators, condition types, and connection types.
 *
 * Provides type-safe constants to replace magic strings throughout the codebase.
 * This improves code quality, IDE autocomplete, and prevents typos.
 */
final class QueryConstants
{
    // Boolean operators
    public const string BOOLEAN_AND = 'AND';
    public const string BOOLEAN_OR = 'OR';

    // Comparison operators
    public const string OP_EQUAL = '=';
    public const string OP_NOT_EQUAL = '!=';
    public const string OP_NOT_EQUAL_ALT = '<>';
    public const string OP_LESS_THAN = '<';
    public const string OP_GREATER_THAN = '>';
    public const string OP_LESS_THAN_OR_EQUAL = '<=';
    public const string OP_GREATER_THAN_OR_EQUAL = '>=';

    // SQL operators
    public const string OP_IN = 'IN';
    public const string OP_NOT_IN = 'NOT IN';
    public const string OP_BETWEEN = 'BETWEEN';
    public const string OP_NOT_BETWEEN = 'NOT BETWEEN';
    public const string OP_EXISTS = 'EXISTS';
    public const string OP_NOT_EXISTS = 'NOT EXISTS';
    public const string OP_IS = 'IS';
    public const string OP_IS_NOT = 'IS NOT';
    public const string OP_LIKE = 'LIKE';
    public const string OP_NOT_LIKE = 'NOT LIKE';

    // Condition types
    public const string COND_WHERE = 'where';
    public const string COND_HAVING = 'having';

    // Connection names
    public const string CONNECTION_DEFAULT = 'default';

    // Lock types
    public const string LOCK_WRITE = 'WRITE';
    public const string LOCK_READ = 'READ';

    /**
     * Get all valid comparison operators.
     *
     * @return array<string>
     */
    public static function getComparisonOperators(): array
    {
        return [
            self::OP_EQUAL,
            self::OP_NOT_EQUAL,
            self::OP_NOT_EQUAL_ALT,
            self::OP_LESS_THAN,
            self::OP_GREATER_THAN,
            self::OP_LESS_THAN_OR_EQUAL,
            self::OP_GREATER_THAN_OR_EQUAL,
        ];
    }

    /**
     * Get all valid boolean operators.
     *
     * @return array<string>
     */
    public static function getBooleanOperators(): array
    {
        return [
            self::BOOLEAN_AND,
            self::BOOLEAN_OR,
        ];
    }

    /**
     * Get all valid SQL operators (IN, BETWEEN, EXISTS, etc.).
     *
     * @return array<string>
     */
    public static function getSqlOperators(): array
    {
        return [
            self::OP_IN,
            self::OP_NOT_IN,
            self::OP_BETWEEN,
            self::OP_NOT_BETWEEN,
            self::OP_EXISTS,
            self::OP_NOT_EXISTS,
            self::OP_IS,
            self::OP_IS_NOT,
            self::OP_LIKE,
            self::OP_NOT_LIKE,
        ];
    }

    /**
     * Validate if operator is a valid comparison operator.
     *
     * @param string $operator
     *
     * @return bool
     */
    public static function isValidComparisonOperator(string $operator): bool
    {
        return in_array($operator, self::getComparisonOperators(), true);
    }

    /**
     * Validate if operator is a valid boolean operator.
     *
     * @param string $operator
     *
     * @return bool
     */
    public static function isValidBooleanOperator(string $operator): bool
    {
        return in_array($operator, self::getBooleanOperators(), true);
    }

    /**
     * Validate if operator is a valid SQL operator.
     *
     * @param string $operator
     *
     * @return bool
     */
    public static function isValidSqlOperator(string $operator): bool
    {
        return in_array($operator, self::getSqlOperators(), true);
    }
}
