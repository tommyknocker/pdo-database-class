<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\analysis;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;

/**
 * Generates optimization recommendations based on EXPLAIN analysis.
 */
class RecommendationGenerator
{
    public function __construct(
        protected ExecutionEngineInterface $executionEngine,
        protected DialectInterface $dialect
    ) {
    }

    /**
     * Generate recommendations based on parsed plan.
     *
     * @param ParsedExplainPlan $plan Parsed execution plan
     * @param string|null $tableName Optional table name for index suggestions
     *
     * @return array<Recommendation> List of recommendations
     */
    public function generate(ParsedExplainPlan $plan, ?string $tableName = null): array
    {
        $recommendations = [];

        // Full table scan recommendations
        if (!empty($plan->tableScans)) {
            foreach ($plan->tableScans as $table) {
                $targetTable = $tableName ?? $table;
                $recommendations[] = new Recommendation(
                    severity: 'warning',
                    type: 'full_table_scan',
                    message: sprintf(
                        'Full table scan detected on table "%s". Consider adding an index.',
                        $table
                    ),
                    suggestion: $this->suggestIndexForTable($targetTable, $plan),
                    affectedTables: [$table]
                );
            }
        }

        // Missing index recommendation (possible_keys but no key used)
        if (!empty($plan->warnings)) {
            foreach ($plan->warnings as $warning) {
                if (
                    str_contains($warning, 'possible keys')
                    || str_contains($warning, 'without index usage')
                ) {
                    $targetTable = $tableName ?? $this->extractTableFromWarning($warning);
                    $recommendations[] = new Recommendation(
                        severity: 'info',
                        type: 'missing_index',
                        message: $warning,
                        suggestion: $targetTable ? $this->suggestIndexForTable($targetTable, $plan) : null
                    );
                }
            }
        }

        // Filesort recommendation
        if (in_array('Query requires filesort (temporary sorting)', $plan->warnings, true)) {
            $recommendations[] = new Recommendation(
                severity: 'info',
                type: 'filesort',
                message: 'Query requires filesort. Consider adding ORDER BY columns to an index.',
                suggestion: $tableName ? $this->suggestOrderByIndex($tableName, $plan) : null
            );
        }

        // GROUP BY without index recommendation
        foreach ($plan->warnings as $warning) {
            if (str_contains($warning, 'GROUP BY requires temporary table and filesort')) {
                $recommendations[] = new Recommendation(
                    severity: 'warning',
                    type: 'group_by_without_index',
                    message: 'GROUP BY requires temporary table and filesort. Consider adding index on GROUP BY columns.',
                    suggestion: $tableName ? $this->suggestGroupByIndex($tableName, $plan) : null
                );
                break;
            }
        }

        // Dependent subquery recommendation
        foreach ($plan->warnings as $warning) {
            if (str_contains($warning, 'Dependent subquery')) {
                $recommendations[] = new Recommendation(
                    severity: 'warning',
                    type: 'dependent_subquery',
                    message: 'Dependent subquery detected. Consider rewriting as JOIN for better performance.',
                    suggestion: 'Rewrite subquery as JOIN: SELECT ... FROM table1 JOIN (SELECT ...) AS subquery ON ...'
                );
                break;
            }
        }

        // Low filter ratio recommendation (MySQL)
        if ($plan->filtered < 100.0 && $plan->filtered > 0.0 && $plan->filtered < 10.0) {
            $table = $tableName ?? (!empty($plan->tableScans) ? $plan->tableScans[0] : null);
            if ($table !== null) {
                $recommendations[] = new Recommendation(
                    severity: 'warning',
                    type: 'low_filter_ratio',
                    message: sprintf(
                        'Only %.1f%% of rows are filtered after index lookup on table "%s". Consider improving index selectivity or adding more selective WHERE conditions.',
                        $plan->filtered,
                        $table
                    ),
                    suggestion: $this->suggestIndexForTable($table, $plan),
                    affectedTables: [$table]
                );
            }
        }

        // Unused possible indexes recommendation
        if (!empty($plan->possibleKeys) && empty($plan->usedIndex)) {
            $table = $tableName ?? (!empty($plan->tableScans) ? $plan->tableScans[0] : null);
            $recommendations[] = new Recommendation(
                severity: 'info',
                type: 'unused_possible_indexes',
                message: sprintf(
                    'Query has possible indexes but none is used: %s. Consider analyzing why indexes are not being used.',
                    implode(', ', $plan->possibleKeys)
                ),
                suggestion: sprintf(
                    'Check index statistics: ANALYZE TABLE %s;',
                    $table !== null ? $this->dialect->quoteTable($table) : 'table_name'
                ),
                affectedTables: $table !== null ? [$table] : null
            );
        }

        // High query cost recommendation (PostgreSQL)
        if ($plan->totalCost !== null && $plan->totalCost > 100000) {
            $recommendations[] = new Recommendation(
                severity: 'warning',
                type: 'high_query_cost',
                message: sprintf(
                    'Query cost is very high (%.0f). Consider optimization.',
                    $plan->totalCost
                ),
                suggestion: 'Consider adding indexes, optimizing JOINs, or rewriting query'
            );
        }

        // Inefficient JOIN recommendation (PostgreSQL)
        if (!empty($plan->joinTypes)) {
            foreach ($plan->joinTypes as $joinType) {
                if ($joinType === 'Nested Loop' && $plan->estimatedRows > 10000) {
                    $recommendations[] = new Recommendation(
                        severity: 'warning',
                        type: 'inefficient_join',
                        message: 'Nested Loop join detected on large dataset. Consider hash join or index optimization.',
                        suggestion: 'Enable hash joins: SET enable_hashjoin = on; or add indexes on JOIN columns'
                    );
                    break;
                }
            }
        }

        // Temporary table recommendation
        if (in_array('Query creates temporary table', $plan->warnings, true)) {
            $recommendations[] = new Recommendation(
                severity: 'info',
                type: 'temporary_table',
                message: 'Query creates temporary table. Consider optimizing GROUP BY or ORDER BY clauses.',
                suggestion: null
            );
        }

        return $recommendations;
    }

    /**
     * Suggest index for GROUP BY optimization.
     */
    protected function suggestGroupByIndex(string $table, ParsedExplainPlan $plan): ?string
    {
        if (empty($plan->usedColumns)) {
            return null;
        }

        $existingIndexes = $this->getExistingIndexes($table);
        $indexName = $this->generateIndexName($table, $plan->usedColumns, 'group_by');

        if (in_array($indexName, $existingIndexes, true)) {
            return null;
        }

        $columns = implode(', ', array_map(function ($col) {
            return $this->dialect->quoteIdentifier($col);
        }, $plan->usedColumns));

        return sprintf(
            'CREATE INDEX %s ON %s (%s);',
            $this->dialect->quoteIdentifier($indexName),
            $this->dialect->quoteTable($table),
            $columns
        );
    }

    /**
     * Suggest index creation SQL for a table.
     */
    protected function suggestIndexForTable(string $table, ParsedExplainPlan $plan): ?string
    {
        if (empty($plan->usedColumns) && empty($plan->possibleKeys)) {
            return null;
        }

        // Get existing indexes to avoid suggesting duplicates
        $existingIndexes = $this->getExistingIndexes($table);
        $indexName = $this->generateIndexName($table, $plan->usedColumns);

        // Check if index already exists
        if (in_array($indexName, $existingIndexes, true)) {
            return null;
        }

        // Use possible keys if available, otherwise use suggested columns
        if (!empty($plan->possibleKeys)) {
            // Suggest using one of the possible keys
            $suggestedKey = $plan->possibleKeys[0];
            return sprintf(
                '-- Consider using existing index: %s',
                $suggestedKey
            );
        }

        // Generate CREATE INDEX suggestion
        if (empty($plan->usedColumns)) {
            return null;
        }

        $columns = implode(', ', array_map(function ($col) {
            return $this->dialect->quoteIdentifier($col);
        }, $plan->usedColumns));

        return sprintf(
            'CREATE INDEX %s ON %s (%s);',
            $this->dialect->quoteIdentifier($indexName),
            $this->dialect->quoteTable($table),
            $columns
        );
    }

    /**
     * Suggest index for ORDER BY optimization.
     */
    protected function suggestOrderByIndex(string $table, ParsedExplainPlan $plan): ?string
    {
        if (empty($plan->usedColumns)) {
            return null;
        }

        $existingIndexes = $this->getExistingIndexes($table);
        $indexName = $this->generateIndexName($table, $plan->usedColumns, 'order_by');

        if (in_array($indexName, $existingIndexes, true)) {
            return null;
        }

        $columns = implode(', ', array_map(function ($col) {
            return $this->dialect->quoteIdentifier($col);
        }, $plan->usedColumns));

        return sprintf(
            'CREATE INDEX %s ON %s (%s);',
            $this->dialect->quoteIdentifier($indexName),
            $this->dialect->quoteTable($table),
            $columns
        );
    }

    /**
     * Get existing indexes for a table.
     *
     * @return array<string> List of existing index names
     */
    protected function getExistingIndexes(string $table): array
    {
        try {
            $sql = $this->dialect->buildShowIndexesSql($table);
            $indexes = $this->executionEngine->fetchAll($sql);

            $indexNames = [];
            foreach ($indexes as $index) {
                // Handle different column names in different dialects
                $name = null;
                if (isset($index['Key_name']) && is_string($index['Key_name'])) {
                    $name = $index['Key_name'];
                } elseif (isset($index['indexname']) && is_string($index['indexname'])) {
                    $name = $index['indexname'];
                } elseif (isset($index['name']) && is_string($index['name'])) {
                    $name = $index['name'];
                }

                if ($name !== null && $name !== '') {
                    $indexNames[] = $name;
                }
            }

            return array_unique($indexNames);
        } catch (\Exception $e) {
            // If we can't fetch indexes, return empty array
            return [];
        }
    }

    /**
     * Generate index name from table and columns.
     *
     * @param string $table Table name
     * @param array<string> $columns Column names
     * @param string $prefix Index name prefix
     */
    protected function generateIndexName(string $table, array $columns, string $prefix = 'idx'): string
    {
        $tablePart = str_replace([' ', '-', '.'], '_', $table);
        $columnPart = implode('_', array_map(function (string $col): string {
            return str_replace([' ', '-', '.'], '_', $col);
        }, array_slice($columns, 0, 3))); // Limit to 3 columns for name

        return sprintf('%s_%s_%s', $prefix, $tablePart, $columnPart);
    }

    /**
     * Extract table name from warning message.
     */
    protected function extractTableFromWarning(string $warning): ?string
    {
        if (preg_match('/"([^"]+)"/', $warning, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
