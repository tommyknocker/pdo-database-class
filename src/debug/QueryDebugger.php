<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\debug;

/**
 * Query debugging helper class.
 *
 * Provides utilities for debugging queries and extracting diagnostic information.
 */
class QueryDebugger
{
    /**
     * Sanitize parameter values for safe display in error messages.
     *
     * Removes sensitive data and truncates long values.
     *
     * @param array<string, mixed> $params Original parameters
     * @param array<string> $sensitiveKeys Keys to mask (e.g., 'password', 'token')
     * @param int $maxLength Maximum length for string values
     *
     * @return array<string, mixed> Sanitized parameters
     */
    public static function sanitizeParams(array $params, array $sensitiveKeys = [], int $maxLength = 100): array
    {
        $sensitiveKeysLower = array_map('strtolower', $sensitiveKeys);
        $sanitized = [];

        foreach ($params as $key => $value) {
            $keyLower = strtolower((string) $key);
            $keyLower = str_replace([':', '?'], '', $keyLower);

            // Mask sensitive keys
            if (in_array($keyLower, $sensitiveKeysLower, true)) {
                $sanitized[$key] = '***';
                continue;
            }

            // Handle different value types
            if (is_string($value)) {
                if (strlen($value) > $maxLength) {
                    $sanitized[$key] = substr($value, 0, $maxLength) . '...';
                } else {
                    $sanitized[$key] = $value;
                }
            } elseif (is_array($value)) {
                $sanitized[$key] = '[array(' . count($value) . ')]';
            } elseif (is_object($value)) {
                $sanitized[$key] = '[' . get_class($value) . ']';
            } elseif (is_resource($value)) {
                $sanitized[$key] = '[resource]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Format query context for display in error messages.
     *
     * @param array<string, mixed> $debugInfo Debug information from QueryBuilder
     *
     * @return string Formatted context string
     */
    public static function formatContext(array $debugInfo): string
    {
        $parts = [];

        if (isset($debugInfo['table'])) {
            $parts[] = "Table: {$debugInfo['table']}";
        }

        if (isset($debugInfo['operation']) && $debugInfo['operation'] !== 'SELECT') {
            $parts[] = "Operation: {$debugInfo['operation']}";
        }

        if (isset($debugInfo['where']) && !empty($debugInfo['where'])) {
            $parts[] = 'Has WHERE conditions';
        }

        if (isset($debugInfo['joins']) && !empty($debugInfo['joins'])) {
            $parts[] = 'Has JOINs: ' . count($debugInfo['joins']);
        }

        if (isset($debugInfo['params']) && !empty($debugInfo['params'])) {
            $sanitized = self::sanitizeParams($debugInfo['params'], ['password', 'token', 'secret', 'key']);
            $parts[] = 'Parameters: ' . json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode(' | ', $parts);
    }

    /**
     * Extract query operation type from SQL.
     *
     * @param string|null $sql SQL query
     *
     * @return string Operation type (SELECT, INSERT, UPDATE, DELETE, etc.)
     */
    public static function extractOperation(?string $sql): string
    {
        if ($sql === null) {
            return 'UNKNOWN';
        }

        $sql = trim($sql);
        $firstToken = strtok($sql, ' ');
        if ($firstToken === false) {
            return 'UNKNOWN';
        }
        $firstWord = strtoupper($firstToken);

        return match ($firstWord) {
            'SELECT' => 'SELECT',
            'INSERT' => 'INSERT',
            'UPDATE' => 'UPDATE',
            'DELETE' => 'DELETE',
            'REPLACE' => 'REPLACE',
            'MERGE' => 'MERGE',
            default => 'UNKNOWN',
        };
    }
}
