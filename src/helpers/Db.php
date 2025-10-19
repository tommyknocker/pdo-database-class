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
    public static function now(?string $diff = null, bool $asTimestamp = false): NowValue
    {
        return new NowValue($diff, $asTimestamp);
    }

    /**
     * Returns a NowValue instance representing the current timestamp as a Unix timestamp.
     *
     * @param string|null $diff An optional time difference (e.g., '+1 day', '-2 hours').
     * @return NowValue The NowValue instance representing the timestamp.
     */
    public static function ts(?string $diff = null): NowValue
    {
        return new NowValue($diff, true);
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

    /* ---------------- JSON helpers ---------------- */

    /**
     * Returns a JsonPathValue for comparing JSON value at path.
     *
     * @param string $column The JSON column name.
     * @param array|string $path The JSON path.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare.
     * @return JsonPathValue The JsonPathValue instance.
     */
    public static function jsonPath(string $column, array|string $path, string $operator, mixed $value): JsonPathValue
    {
        return new JsonPathValue($column, $path, $operator, $value);
    }

    /**
     * Returns a JsonContainsValue for JSON contains condition.
     *
     * @param string $column The JSON column name.
     * @param mixed $value The value to check for.
     * @param array|string|null $path Optional JSON path.
     * @return JsonContainsValue The JsonContainsValue instance.
     */
    public static function jsonContains(string $column, mixed $value, array|string|null $path = null): JsonContainsValue
    {
        return new JsonContainsValue($column, $value, $path);
    }

    /**
     * Returns a JsonExistsValue for checking JSON path existence.
     *
     * @param string $column The JSON column name.
     * @param array|string $path The JSON path to check.
     * @return JsonExistsValue The JsonExistsValue instance.
     */
    public static function jsonExists(string $column, array|string $path): JsonExistsValue
    {
        return new JsonExistsValue($column, $path);
    }

    /**
     * Returns a JsonGetValue for extracting JSON value.
     * Useful in SELECT, ORDER BY, GROUP BY clauses.
     *
     * @param string $column The JSON column name.
     * @param array|string $path The JSON path.
     * @param bool $asText Whether to return as text (default true).
     * @return JsonGetValue The JsonGetValue instance.
     */
    public static function jsonGet(string $column, array|string $path, bool $asText = true): JsonGetValue
    {
        return new JsonGetValue($column, $path, $asText);
    }

    /**
     * Alias for jsonGet().
     *
     * @param string $column The JSON column name.
     * @param array|string $path The JSON path.
     * @param bool $asText Whether to return as text (default true).
     * @return JsonGetValue The JsonGetValue instance.
     */
    public static function jsonExtract(string $column, array|string $path, bool $asText = true): JsonGetValue
    {
        return self::jsonGet($column, $path, $asText);
    }

    /**
     * Returns a JsonLengthValue for JSON length/size.
     *
     * @param string $column The JSON column name.
     * @param array|string|null $path Optional JSON path.
     * @return JsonLengthValue The JsonLengthValue instance.
     */
    public static function jsonLength(string $column, array|string|null $path = null): JsonLengthValue
    {
        return new JsonLengthValue($column, $path);
    }

    /**
     * Returns a JsonKeysValue for extracting JSON object keys.
     *
     * @param string $column The JSON column name.
     * @param array|string|null $path Optional JSON path.
     * @return JsonKeysValue The JsonKeysValue instance.
     */
    public static function jsonKeys(string $column, array|string|null $path = null): JsonKeysValue
    {
        return new JsonKeysValue($column, $path);
    }

    /**
     * Returns a JsonTypeValue for getting JSON value type.
     *
     * @param string $column The JSON column name.
     * @param array|string|null $path Optional JSON path.
     * @return JsonTypeValue The JsonTypeValue instance.
     */
    public static function jsonType(string $column, array|string|null $path = null): JsonTypeValue
    {
        return new JsonTypeValue($column, $path);
    }

    /**
     * Returns a JSON-encoded array string.
     *
     * @param mixed ...$values The values to include in array.
     * @return string The JSON-encoded array string.
     */
    public static function jsonArray(...$values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Returns a JSON-encoded object string.
     *
     * @param array $pairs Associative array of key-value pairs.
     * @return string The JSON-encoded object string.
     */
    public static function jsonObject(array $pairs): string
    {
        return json_encode($pairs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}