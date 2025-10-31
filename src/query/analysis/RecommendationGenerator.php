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
