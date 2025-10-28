<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cache;

/**
 * Generates cache keys for queries.
 */
class QueryCacheKey
{
    /**
     * Generate a cache key from SQL and parameters.
     *
     * @param string $sql SQL query string
     * @param array<string|int, mixed> $params Query parameters
     * @param string $driver Database driver name
     * @param string $prefix Cache key prefix
     */
    public static function generate(
        string $sql,
        array $params,
        string $driver,
        string $prefix = 'pdodb_'
    ): string {
        $paramString = json_encode($params, JSON_THROW_ON_ERROR);
        $hash = md5($sql . $paramString . $driver);

        return $prefix . $hash;
    }

    /**
     * Generate a table-specific cache pattern for invalidation.
     *
     * @param string $table Table name
     * @param string $prefix Cache key prefix
     */
    public static function tablePattern(string $table, string $prefix = 'pdodb_'): string
    {
        return $prefix . 'table_' . $table . '_*';
    }
}
