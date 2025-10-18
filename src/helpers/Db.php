<?php

namespace tommyknocker\pdodb\helpers;

/**
 * Database helpers
 */
class Db
{
    /**
     * Returns a raw value.
     *
     * @param string $sql The SQL to execute.
     * @param array $params The parameters to bind to the SQL.
     * @return RawValue The raw value.
     */
    public static function raw(string $sql, array $params = []): RawValue
    {
        return new RawValue($sql, $params);
    }

    /**
     * Escapes a string for use in a SQL query.
     *
     * @param string $str The string to escape.
     * @return EscapeValue The EscapeValue instance
     */
    public static function escape(string $str): EscapeValue
    {
        return new EscapeValue($str);
    }

    /**
     * Returns a ConfigValue instance representing a SET statement.
     * e.g. SET FOREIGN_KEY_CHECKS = 1 OR SET NAMES 'utf8mb4' in MySQL and PostgreSQL or PRAGMA statements in SQLite
     *
     * @param string $key The column name.
     * @param mixed $value The value to set.
     * @param bool $useEqualSign Whether to use an equal sign (=) in the statement. Default is true.
     * @param bool $quoteValue Whether to quote value or not.
     * @return ConfigValue The ConfigValue instance for the SET/PRAGMA operation.
     */
    public static function config(
        string $key,
        mixed $value,
        bool $useEqualSign = true,
        bool $quoteValue = false
    ): ConfigValue {
        return new ConfigValue($key, $value, $useEqualSign, $quoteValue);
    }

    /**
     * Returns a NowValue instance representing the current timestamp with an optional difference.
     *
     * @param string|null $diff An optional time difference (e.g., '+1 day', '-2 hours').
     * @return NowValue The NowValue instance.
     */
    public static function now(?string $diff = null): NowValue
    {
        return new NowValue($diff);
    }

    /**
     * Returns an array with an increment operation.
     *
     * @param int|float $num The number to increment by.
     * @return array The array with the increment operation.
     */
    public static function inc(int|float $num = 1): array
    {
        return ['__op' => 'inc', 'val' => $num];
    }

    /**
     * Returns an array with a decrement operation.
     *
     * @param int|float $num The number to decrement by.
     * @return array The array with the decrement operation.
     */
    public static function dec(int|float $num = 1): array
    {
        return ['__op' => 'dec', 'val' => $num];
    }

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
     * Returns a RawValue instance representing a LIKE condition.
     *
     * @param string $column The column name.
     * @param string $pattern The pattern to match.
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
     * @return RawValue The RawValue instance for the NOT condition.
     */
    public static function not(RawValue $value): RawValue
    {
        return new RawValue("NOT (" . $value->getValue() . ")", $value->getParams());
    }

    /**
     * Returns a RawValue instance representing a BETWEEN condition.
     *
     * @param string $column The column name.
     * @param mixed $min The minimum value.
     * @param mixed $max The maximum value.
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
     * @param array $values The array of values for the IN condition.
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

        return new RawValue("$column IN (" . implode(', ', $placeholders) . ")", $params);
    }

    /**
     * Returns a RawValue instance representing a NOT IN condition.
     *
     * @param string $column The column name.
     * @param array $values The array of values for the NOT IN condition.
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

        return new RawValue("$column NOT IN (" . implode(', ', $placeholders) . ")", $params);
    }

    /**
     * Returns a RawValue instance representing an IS NULL condition.
     * @param string $column The column name.
     * @return RawValue
     */
    public static function isNull(string $column): RawValue
    {
        return new RawValue("$column IS NULL");
    }

    /**
     * Returns a RawValue instance representing an IS NULL condition.
     * @param string $column The column name.
     * @return RawValue
     */
    public static function isNotNull(string $column): RawValue
    {
        return new RawValue("$column IS NOT NULL");
    }

    /**
     * Returns a RawValue instance representing a CASE statement.
     *
     * @param array $cases An associative array where keys are WHEN conditions and values are THEN results.
     * @param string|null $else An optional ELSE result.
     * @return RawValue The RawValue instance for the CASE statement.
     */
    public static function case(array $cases, string|null $else = null): RawValue
    {
        $sql = 'CASE';
        foreach ($cases as $when => $then) {
            $sql .= " WHEN $when THEN $then";
        }
        if ($else !== null) {
            $sql .= " ELSE $else";
        }
        $sql .= ' END';

        return new RawValue($sql);
    }

    /**
     * Returns a ConcatValue instance representing a concatenation of values.
     *
     * @param mixed ...$args The values to concatenate.
     * @return ConcatValue The ConcatValue instance.
     */
    public static function concat(...$args): ConcatValue
    {
        return new ConcatValue($args);
    }

    /**
     * Returns a RawValue instance representing SQL DEFAULT.
     *
     * @return RawValue The RawValue instance for DEFAULT.
     */
    public static function default(): RawValue
    {
        return new RawValue('DEFAULT');
    }

    /**
     * Returns a RawValue instance representing SQL TRUE.
     *
     * @return RawValue The RawValue instance for TRUE.
     */
    public static function true(): RawValue
    {
        return new RawValue('TRUE');
    }

    /**
     * Returns a RawValue instance representing SQL FALSE.
     *
     * @return RawValue The RawValue instance for FALSE.
     */
    public static function false(): RawValue
    {
        return new RawValue('FALSE');
    }
}