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
    protected function extractConstraintName(string $message): ?string
    {
        $patterns = [
            '/for key \'([^\']+)\'/i',
            '/constraint "([^"]+)"/i',
            '/CONSTRAINT\s+`([^`]+)`/i',  // Match "CONSTRAINT `name`" pattern (most specific)
            '/CONSTRAINT\s+"([^"]+)"/i',  // Match "CONSTRAINT "name"" pattern
            '/constraint `([^`\s]+)`/i',   // Match "constraint `name`"
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
    protected function extractTableName(string $message): ?string
    {
        $patterns = [
            '/in table \'([^\']+)\'/i',
            '/table `?([^`\s]+)`?/i',
            // Match `schema`.`table` or `table` format in error messages
            '/`([^`]+)`\.`([^`]+)`/i',  // Match `schema`.`table`
            '/`([^`]+)`(?:\s|,)/i',     // Match `table` followed by space or comma (fallback)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                // For `schema`.`table` format, return table name (matches[2])
                // For single `table` format, return table name (matches[1])
                if (count($matches) > 2) {
                    return $matches[2];
                }
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
    protected function extractColumnName(string $message): ?string
    {
        $patterns = [
            "/column '([^']+)'/i",  // Match 'column 'name''
            '/column `?([^`\s]+)`?/i',  // Match column `name`
            '/FOREIGN KEY \(`?([^`\)]+)`?\)/i',  // Match FOREIGN KEY (`name`)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                // Remove backticks if present
                return trim($matches[1], '`');
            }
        }

        return null;
    }
}
