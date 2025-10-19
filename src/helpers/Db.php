<?php

namespace tommyknocker\pdodb\helpers;

/**
 * Database helpers
 */
class Db
{
    /* ---------------- Core helpers ---------------- */

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

    /* ---------------- NULL handling ---------------- */

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
     * @param string $column The column name.
     * @return RawValue
     */
    public static function isNull(string $column): RawValue
    {
        return new RawValue("$column IS NULL");
    }

    /**
     * Returns a RawValue instance representing an IS NOT NULL condition.
     * @param string $column The column name.
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
     * @return RawValue The RawValue instance for NULLIF.
     */
    public static function nullIf(mixed $expr1, mixed $expr2): RawValue
    {
        $e1 = $expr1 instanceof RawValue ? $expr1->getValue() : $expr1;
        $e2 = $expr2 instanceof RawValue ? $expr2->getValue() : $expr2;
        return new RawValue("NULLIF($e1, $e2)");
    }

    /* ---------------- Boolean values ---------------- */

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

    /**
     * Returns a RawValue instance representing SQL DEFAULT.
     *
     * @return RawValue The RawValue instance for DEFAULT.
     */
    public static function default(): RawValue
    {
        return new RawValue('DEFAULT');
    }

    /* ---------------- Numeric operations ---------------- */

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
     * Returns absolute value.
     *
     * @param string|RawValue $value The value.
     * @return RawValue The RawValue instance for ABS.
     */
    public static function abs(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("ABS($val)");
    }

    /**
     * Returns rounded value.
     *
     * @param string|RawValue $value The value to round.
     * @param int $precision The number of decimal places.
     * @return RawValue The RawValue instance for ROUND.
     */
    public static function round(string|RawValue $value, int $precision = 0): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("ROUND($val, $precision)");
    }

    /**
     * Returns modulo operation (dialect-specific).
     *
     * @param string|RawValue $dividend The dividend.
     * @param string|RawValue $divisor The divisor.
     * @return ModValue The ModValue instance.
     */
    public static function mod(string|RawValue $dividend, string|RawValue $divisor): ModValue
    {
        return new ModValue($dividend, $divisor);
    }

    /* ---------------- String operations ---------------- */

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
     * Converts string to uppercase.
     *
     * @param string|RawValue $value The value to convert.
     * @return RawValue The RawValue instance for UPPER.
     */
    public static function upper(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("UPPER($val)");
    }

    /**
     * Converts string to lowercase.
     *
     * @param string|RawValue $value The value to convert.
     * @return RawValue The RawValue instance for LOWER.
     */
    public static function lower(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("LOWER($val)");
    }

    /**
     * Trims whitespace from both sides.
     *
     * @param string|RawValue $value The value to trim.
     * @return RawValue The RawValue instance for TRIM.
     */
    public static function trim(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("TRIM($val)");
    }

    /**
     * Trims whitespace from the left side.
     *
     * @param string|RawValue $value The value to trim.
     * @return RawValue The RawValue instance for LTRIM.
     */
    public static function ltrim(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("LTRIM($val)");
    }

    /**
     * Trims whitespace from the right side.
     *
     * @param string|RawValue $value The value to trim.
     * @return RawValue The RawValue instance for RTRIM.
     */
    public static function rtrim(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("RTRIM($val)");
    }

    /**
     * Returns string length.
     *
     * @param string|RawValue $value The value.
     * @return RawValue The RawValue instance for LENGTH.
     */
    public static function length(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("LENGTH($val)");
    }

    /**
     * Returns substring (dialect-specific for SUBSTRING vs SUBSTR).
     *
     * @param string|RawValue $value The source string.
     * @param int $start The starting position (1-based).
     * @param int|null $length The length of substring.
     * @return SubstringValue The SubstringValue instance.
     */
    public static function substring(string|RawValue $value, int $start, ?int $length = null): SubstringValue
    {
        return new SubstringValue($value, $start, $length);
    }

    /**
     * Returns string with replacements.
     *
     * @param string|RawValue $value The source string.
     * @param string $search The string to search for.
     * @param string $replace The replacement string.
     * @return RawValue The RawValue instance for REPLACE.
     */
    public static function replace(string|RawValue $value, string $search, string $replace): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("REPLACE($val, '$search', '$replace')");
    }

    /* ---------------- Comparison operators ---------------- */

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

    /* ---------------- Conditional logic ---------------- */

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

    /* ---------------- Date/Time functions ---------------- */

    /**
     * Returns a NowValue instance representing the current timestamp with an optional difference.
     *
     * @param string|null $diff An optional time difference (e.g., '+1 day', '-2 hours').
     * @param bool $asTimestamp Whether to return as Unix timestamp.
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
     * Returns current date (dialect-specific).
     *
     * @return CurDateValue The CurDateValue instance.
     */
    public static function curDate(): CurDateValue
    {
        return new CurDateValue();
    }

    /**
     * Returns current time (dialect-specific).
     *
     * @return CurTimeValue The CurTimeValue instance.
     */
    public static function curTime(): CurTimeValue
    {
        return new CurTimeValue();
    }

    /**
     * Extracts date part from datetime.
     *
     * @param string|RawValue $value The datetime value.
     * @return RawValue The RawValue instance for DATE.
     */
    public static function date(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("DATE($val)");
    }

    /**
     * Extracts time part from datetime.
     *
     * @param string|RawValue $value The datetime value.
     * @return RawValue The RawValue instance for TIME.
     */
    public static function time(string|RawValue $value): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("TIME($val)");
    }

    /**
     * Extracts year from date (dialect-specific).
     *
     * @param string|RawValue $value The date value.
     * @return YearValue The YearValue instance.
     */
    public static function year(string|RawValue $value): YearValue
    {
        return new YearValue($value);
    }

    /**
     * Extracts month from date (dialect-specific).
     *
     * @param string|RawValue $value The date value.
     * @return MonthValue The MonthValue instance.
     */
    public static function month(string|RawValue $value): MonthValue
    {
        return new MonthValue($value);
    }

    /**
     * Extracts day from date (dialect-specific).
     *
     * @param string|RawValue $value The date value.
     * @return DayValue The DayValue instance.
     */
    public static function day(string|RawValue $value): DayValue
    {
        return new DayValue($value);
    }

    /**
     * Extracts hour from time (dialect-specific).
     *
     * @param string|RawValue $value The time value.
     * @return HourValue The HourValue instance.
     */
    public static function hour(string|RawValue $value): HourValue
    {
        return new HourValue($value);
    }

    /**
     * Extracts minute from time (dialect-specific).
     *
     * @param string|RawValue $value The time value.
     * @return MinuteValue The MinuteValue instance.
     */
    public static function minute(string|RawValue $value): MinuteValue
    {
        return new MinuteValue($value);
    }

    /**
     * Extracts second from time (dialect-specific).
     *
     * @param string|RawValue $value The time value.
     * @return SecondValue The SecondValue instance.
     */
    public static function second(string|RawValue $value): SecondValue
    {
        return new SecondValue($value);
    }

    /* ---------------- Aggregate functions ---------------- */

    /**
     * Returns COUNT expression.
     *
     * @param string|RawValue $expr The expression to count (use '*' for all rows).
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
     * @return RawValue The RawValue instance for MAX.
     */
    public static function max(string|RawValue $column): RawValue
    {
        $val = $column instanceof RawValue ? $column->getValue() : $column;
        return new RawValue("MAX($val)");
    }

    /* ---------------- Type conversion & comparison ---------------- */

    /**
     * Returns CAST expression.
     *
     * @param mixed $value The value to cast.
     * @param string $type The target type.
     * @return RawValue The RawValue instance for CAST.
     */
    public static function cast(mixed $value, string $type): RawValue
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return new RawValue("CAST($val AS $type)");
    }

    /**
     * Returns greatest (maximum) value from arguments (dialect-specific).
     *
     * @param mixed ...$values The values to compare.
     * @return GreatestValue The GreatestValue instance.
     */
    public static function greatest(...$values): GreatestValue
    {
        return new GreatestValue($values);
    }

    /**
     * Returns least (minimum) value from arguments (dialect-specific).
     *
     * @param mixed ...$values The values to compare.
     * @return LeastValue The LeastValue instance.
     */
    public static function least(...$values): LeastValue
    {
        return new LeastValue($values);
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
