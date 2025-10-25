<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\parsers;

/**
 * Parser for extracting constraint violation details from error messages.
 *
 * Parses database error messages to extract constraint names, table names,
 * and column names for better error reporting.
 */
class ConstraintParser
{
    /**
     * Parse constraint violation details from an error message.
     *
     * @param string $message The error message to parse
     *
     * @return array{constraintName: ?string, tableName: ?string, columnName: ?string}
     */
    public function parse(string $message): array
    {
        return [
            'constraintName' => $this->extractConstraintName($message),
            'tableName' => $this->extractTableName($message),
            'columnName' => $this->extractColumnName($message),
        ];
    }

    /**
     * Extract constraint name from error message.
     *
     * @param string $message The error message
     *
     * @return string|null The constraint name or null if not found
     */
    private function extractConstraintName(string $message): ?string
    {
        $patterns = [
            '/for key \'([^\']+)\'/i',
            '/constraint "([^"]+)"/i',
            '/constraint `?([^`\s]+)`?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Extract table name from error message.
     *
     * @param string $message The error message
     *
     * @return string|null The table name or null if not found
     */
    private function extractTableName(string $message): ?string
    {
        $patterns = [
            '/in table \'([^\']+)\'/i',
            '/table `?([^`\s]+)`?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Extract column name from error message.
     *
     * @param string $message The error message
     *
     * @return string|null The column name or null if not found
     */
    private function extractColumnName(string $message): ?string
    {
        if (preg_match('/column `?([^`\s]+)`?/i', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
