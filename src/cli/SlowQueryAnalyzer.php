<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PdoDb;

/**
 * Analyzes slow queries from log file.
 */
class SlowQueryAnalyzer
{
    protected PdoDb $db;

    public function __construct(PdoDb $db)
    {
        $this->db = $db;
    }

    /**
     * Analyze slow queries.
     *
     * @param array<int, array<string, mixed>> $queries Parsed queries from log
     *
     * @return array<string, mixed> Analysis result
     */
    public function analyze(array $queries): array
    {
        if (empty($queries)) {
            return [
                'top_queries' => [],
                'summary' => [
                    'total_queries' => 0,
                    'unique_queries' => 0,
                    'queries_over_1s' => 0,
                    'total_slow_time' => 0,
                ],
            ];
        }

        // Group queries by normalized SQL
        $grouped = [];
        foreach ($queries as $query) {
            $sql = $query['sql'] ?? '';
            $normalized = $this->normalizeSql($sql);

            if (!isset($grouped[$normalized])) {
                $grouped[$normalized] = [
                    'normalized_sql' => $normalized,
                    'original_sql' => $sql,
                    'count' => 0,
                    'query_times' => [],
                    'max_time' => 0,
                    'total_time' => 0,
                    'rows_examined' => [],
                    'rows_sent' => [],
                ];
            }

            $grouped[$normalized]['count']++;
            $queryTime = (float)($query['query_time'] ?? 0);
            $grouped[$normalized]['query_times'][] = $queryTime;
            $grouped[$normalized]['max_time'] = max($grouped[$normalized]['max_time'], $queryTime);
            $grouped[$normalized]['total_time'] += $queryTime;

            if (isset($query['rows_examined'])) {
                $grouped[$normalized]['rows_examined'][] = (int)$query['rows_examined'];
            }
            if (isset($query['rows_sent'])) {
                $grouped[$normalized]['rows_sent'][] = (int)$query['rows_sent'];
            }
        }

        // Calculate statistics for each group
        $topQueries = [];
        foreach ($grouped as $normalized => $data) {
            $count = (int)$data['count'];
            $avgTime = $data['total_time'] / $count;
            $maxTime = $data['max_time'];
            $totalTime = $data['total_time'];

            // Try to extract table name for recommendations
            $tableName = $this->extractTableName($data['original_sql']);
            $recommendation = $this->generateRecommendation($data['original_sql'], $tableName);

            $topQueries[] = [
                'normalized_sql' => $normalized,
                'original_sql' => $data['original_sql'],
                'count' => $count,
                'avg_time' => round($avgTime, 3),
                'max_time' => round($maxTime, 3),
                'total_time' => round($totalTime, 2),
                'avg_rows_examined' => !empty($data['rows_examined']) ? (int)round(array_sum($data['rows_examined']) / count($data['rows_examined'])) : 0,
                'avg_rows_sent' => !empty($data['rows_sent']) ? (int)round(array_sum($data['rows_sent']) / count($data['rows_sent'])) : 0,
                'recommendation' => $recommendation,
            ];
        }

        // Sort by total time (descending)
        usort($topQueries, function ($a, $b) {
            return $b['total_time'] <=> $a['total_time'];
        });

        // Calculate summary
        $totalQueries = count($queries);
        $uniqueQueries = count($grouped);
        $queriesOver1s = 0;
        $totalSlowTime = 0;

        foreach ($queries as $query) {
            $queryTime = (float)($query['query_time'] ?? 0);
            $totalSlowTime += $queryTime;
            if ($queryTime > 1.0) {
                $queriesOver1s++;
            }
        }

        return [
            'top_queries' => $topQueries,
            'summary' => [
                'total_queries' => $totalQueries,
                'unique_queries' => $uniqueQueries,
                'queries_over_1s' => $queriesOver1s,
                'total_slow_time' => round($totalSlowTime, 2),
            ],
        ];
    }

    /**
     * Normalize SQL query for grouping.
     *
     * @param string $sql SQL query
     *
     * @return string Normalized SQL
     */
    protected function normalizeSql(string $sql): string
    {
        // Remove extra whitespace
        $normalized = preg_replace('/\s+/', ' ', $sql);
        if ($normalized === null) {
            $normalized = $sql;
        }
        $normalized = trim($normalized);

        // Replace literal values with placeholders
        $normalized = preg_replace('/=\s*\'[^\']*\'/', '= ?', $normalized);
        if ($normalized === null) {
            $normalized = trim($sql);
        }
        $normalized = preg_replace('/=\s*"[^"]*"/', '= ?', $normalized);
        if ($normalized === null) {
            $normalized = trim($sql);
        }
        $normalized = preg_replace('/=\s*\d+/', '= ?', $normalized);
        if ($normalized === null) {
            $normalized = trim($sql);
        }
        $normalized = preg_replace('/IN\s*\([^)]+\)/i', 'IN (?)', $normalized);
        if ($normalized === null) {
            $normalized = trim($sql);
        }
        $normalized = preg_replace('/LIMIT\s+\d+/i', 'LIMIT ?', $normalized);
        if ($normalized === null) {
            $normalized = trim($sql);
        }
        $normalized = preg_replace('/OFFSET\s+\d+/i', 'OFFSET ?', $normalized);
        if ($normalized === null) {
            $normalized = trim($sql);
        }

        return $normalized;
    }

    /**
     * Extract table name from SQL.
     *
     * @param string $sql SQL query
     *
     * @return string|null Table name
     */
    protected function extractTableName(string $sql): ?string
    {
        // Try to extract FROM table
        if (preg_match('/FROM\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }

        // Try to extract UPDATE table
        if (preg_match('/UPDATE\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }

        // Try to extract INSERT INTO table
        if (preg_match('/INSERT\s+INTO\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Generate recommendation for query.
     *
     * @param string $sql SQL query
     * @param string|null $tableName Table name
     *
     * @return string|null Recommendation
     */
    protected function generateRecommendation(string $sql, ?string $tableName): ?string
    {
        if ($tableName === null) {
            return null;
        }

        // Check for WHERE conditions
        if (preg_match('/WHERE\s+([^ORDER\s]+)/i', $sql, $matches)) {
            $whereClause = $matches[1];
            // Extract column names from WHERE
            if (preg_match_all('/(\w+)\s*=/', $whereClause, $colMatches)) {
                $columns = $colMatches[1];
                if (!empty($columns)) {
                    $cols = implode(', ', array_slice($columns, 0, 3));
                    return "Add index on ({$cols})";
                }
            }
        }

        // Check for JOIN
        if (preg_match('/JOIN\s+`?(\w+)`?\s+ON\s+([^WHERE]+)/i', $sql, $matches)) {
            return 'Check JOIN performance, consider indexes on join columns';
        }

        return null;
    }
}
