<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\JsonContainsValue;
use tommyknocker\pdodb\helpers\values\JsonExistsValue;
use tommyknocker\pdodb\helpers\values\JsonGetValue;
use tommyknocker\pdodb\helpers\values\JsonKeysValue;
use tommyknocker\pdodb\helpers\values\JsonLengthValue;
use tommyknocker\pdodb\helpers\values\JsonPathValue;
use tommyknocker\pdodb\helpers\values\JsonTypeValue;

/**
 * Trait for JSON operations.
 */
trait JsonHelpersTrait
{
    /**
     * Returns a JsonPathValue for comparing JSON value at path.
     *
     * @param string $column The JSON column name.
     * @param array<int, string|int>|string $path The JSON path.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare.
     *
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
     * @param array<int, string|int>|string|null $path Optional JSON path.
     *
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
     * @param array<int, string|int>|string $path The JSON path to check.
     *
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
     * @param array<int, string|int>|string $path The JSON path.
     * @param bool $asText Whether to return as text (default true).
     *
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
     * @param array<int, string|int>|string $path The JSON path.
     * @param bool $asText Whether to return as text (default true).
     *
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
     * @param array<int, string|int>|string|null $path Optional JSON path.
     *
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
     * @param array<int, string|int>|string|null $path Optional JSON path.
     *
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
     * @param array<int, string|int>|string|null $path Optional JSON path.
     *
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
     *
     * @return string The JSON-encoded array string.
     */
    public static function jsonArray(...$values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Returns a JSON-encoded object string.
     *
     * @param array<string, mixed> $pairs Associative array of key-value pairs.
     *
     * @return string The JSON-encoded object string.
     */
    public static function jsonObject(array $pairs): string
    {
        return json_encode($pairs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
