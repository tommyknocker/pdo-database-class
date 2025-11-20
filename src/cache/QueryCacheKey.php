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
        $hash = hash('sha256', $sql . $paramString . $driver);

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

    /**
     * Extract table names from SQL query.
     *
     * @param string $sql SQL query string
     *
     * @return array<string> Array of table names
     */
    public static function extractTables(string $sql): array
    {
        $tables = [];

        // Extract table from FROM clause
        // Match table name after FROM (handles backticks, brackets, quotes, schemas)
        $fromMatches = [];
        if (preg_match('/FROM\s+(?:[`"\[ ]*)?(?:[\w.]+\.)?(\w+)(?:[`"\] ]*)/i', $sql, $fromMatches) > 0) {
            // preg_match creates array with numeric keys: [0] = full match, [1] = first capture group
            // When pattern matches, capture group always exists (may be empty string)
            if (count($fromMatches) > 1) {
                // @phpstan-ignore-next-line isset.offset - preg_match always creates index 1 when pattern matches
                $table = $fromMatches[1] ?? '';
                // @phpstan-ignore-next-line notIdentical.alwaysTrue - check for clarity even though non-empty-string !== '' is always true
                if (is_string($table) && $table !== '') {
                    $tables[] = $table;
                }
            }
        }

        // Extract tables from JOIN clauses
        $joinMatches = [];
        if (preg_match_all('/JOIN\s+(?:[`"\[ ]*)?(?:[\w.]+\.)?(\w+)(?:[`"\] ]*)/i', $sql, $joinMatches) > 0) {
            // preg_match_all creates array: [0] = full matches, [1] = first capture group array
            // When pattern matches, capture group array always exists
            // @phpstan-ignore-next-line - preg_match_all always creates index 1, but PHPStan sees type variations
            if (isset($joinMatches[1]) && is_array($joinMatches[1])) {
                foreach ($joinMatches[1] as $table) {
                    // @phpstan-ignore-next-line - array elements from preg_match_all are strings, but PHPStan sees type variations
                    if (is_string($table) && $table !== '') {
                        $tables[] = $table;
                    }
                }
            }
        }

        // Remove duplicates and normalize
        $normalized = [];
        foreach (array_unique($tables) as $table) {
            // Remove schema prefix (schema.table -> table)
            if (preg_match('/\.([^.]+)$/', $table, $m)) {
                $table = $m[1];
            }
            // Remove quotes
            $table = trim($table, '`"[]');
            if ($table !== '') {
                $normalized[] = strtolower($table);
            }
        }

        return array_unique($normalized);
    }
}
