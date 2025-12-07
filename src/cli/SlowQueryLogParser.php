<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

/**
 * Parser for MySQL/MariaDB slow query logs.
 */
class SlowQueryLogParser
{
    /**
     * Parse slow query log file.
     *
     * @param string $filePath Path to slow query log file
     *
     * @return array<int, array<string, mixed>> Array of parsed queries
     */
    public function parse(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $queries = [];
        $lines = explode("\n", $content);
        $currentQuery = null;
        $currentSql = '';

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines
            if ($line === '') {
                continue;
            }

            // Check for query start (Time: ...)
            if (preg_match('/^# Time:\s+(.+)$/', $line, $matches)) {
                // Save previous query if exists
                if ($currentQuery !== null && $currentSql !== '') {
                    $currentQuery['sql'] = trim($currentSql);
                    $queries[] = $currentQuery;
                }

                // Start new query
                $currentQuery = [
                    'time' => $matches[1],
                    'sql' => '',
                ];
                $currentSql = '';
                continue;
            }

            // Check for query time (Query_time: X.XXX)
            if (preg_match('/^# Query_time:\s+([\d.]+)/', $line, $matches)) {
                if ($currentQuery !== null) {
                    $currentQuery['query_time'] = (float)$matches[1];
                }
                continue;
            }

            // Check for lock time (Lock_time: X.XXX)
            if (preg_match('/^# Lock_time:\s+([\d.]+)/', $line, $matches)) {
                if ($currentQuery !== null) {
                    $currentQuery['lock_time'] = (float)$matches[1];
                }
                continue;
            }

            // Check for rows examined (Rows_examined: X)
            if (preg_match('/^# Rows_examined:\s+(\d+)/', $line, $matches)) {
                if ($currentQuery !== null) {
                    $currentQuery['rows_examined'] = (int)$matches[1];
                }
                continue;
            }

            // Check for rows sent (Rows_sent: X)
            if (preg_match('/^# Rows_sent:\s+(\d+)/', $line, $matches)) {
                if ($currentQuery !== null) {
                    $currentQuery['rows_sent'] = (int)$matches[1];
                }
                continue;
            }

            // SQL query line (not starting with #)
            if (!str_starts_with($line, '#')) {
                if ($currentQuery !== null) {
                    $currentSql .= $line . "\n";
                }
            }
        }

        // Save last query
        if ($currentQuery !== null && $currentSql !== '') {
            $currentQuery['sql'] = trim($currentSql);
            $queries[] = $currentQuery;
        }

        return $queries;
    }
}
