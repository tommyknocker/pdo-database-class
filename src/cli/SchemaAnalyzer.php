<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PdoDb;

/**
 * Analyzes database schema for optimization opportunities.
 */
class SchemaAnalyzer
{
    protected PdoDb $db;
    protected RedundantIndexDetector $redundantIndexDetector;
    protected IndexSuggestionAnalyzer $indexSuggestionAnalyzer;

    public function __construct(PdoDb $db)
    {
        $this->db = $db;
        $this->redundantIndexDetector = new RedundantIndexDetector($db);
        $this->indexSuggestionAnalyzer = new IndexSuggestionAnalyzer($db);
    }

    /**
     * Analyze entire schema.
     *
     * @param string|null $schema Schema name (optional)
     *
     * @return array<string, mixed> Analysis result
     */
    public function analyze(?string $schema = null): array
    {
        $tables = TableManager::listTables($this->db, $schema);
        $critical = [];
        $warnings = [];
        $info = [];
        $totalIndexes = 0;
        $redundantCount = 0;
        $tablesWithIssues = 0;
        $suggestionsSummary = [
            'tables_needing_indexes' => 0,
            'high_priority_suggestions' => 0,
            'medium_priority_suggestions' => 0,
            'low_priority_suggestions' => 0,
            'foreign_key_indexes_needed' => 0,
            'soft_delete_indexes_needed' => 0,
            'status_column_indexes_needed' => 0,
            'timestamp_indexes_needed' => 0,
        ];

        foreach ($tables as $table) {
            $tableResult = $this->analyzeTable($table);
            $hasIssues = false;

            // Check for critical issues (missing PK)
            if (!($tableResult['has_primary_key'] ?? false)) {
                $critical[] = [
                    'table' => $table,
                    'message' => "Table '{$table}' has no PRIMARY KEY",
                    'type' => 'missing_primary_key',
                ];
                $hasIssues = true;
            }

            // Check for redundant indexes
            $redundant = $tableResult['redundant_indexes'] ?? [];
            foreach ($redundant as $red) {
                $warnings[] = [
                    'table' => $table,
                    'message' => "Table '{$table}': Redundant index '{$red['index']}' (covered by '{$red['covered_by']}')",
                    'type' => 'redundant_index',
                ];
                $redundantCount++;
                $hasIssues = true;
            }

            // Check for missing FK indexes
            $missingFk = $tableResult['missing_fk_indexes'] ?? [];
            foreach ($missingFk as $fk) {
                $warnings[] = [
                    'table' => $table,
                    'message' => "Table '{$table}': Missing index on foreign key '{$fk['column']}'",
                    'type' => 'missing_fk_index',
                ];
                $hasIssues = true;
            }

            // Collect statistics
            $indexes = $tableResult['indexes'] ?? [];
            $totalIndexes += count($indexes);

            // Collect suggestions summary
            $suggestions = $tableResult['suggestions'] ?? [];
            if (!empty($suggestions)) {
                $suggestionsSummary['tables_needing_indexes']++;
                foreach ($suggestions as $suggestion) {
                    $priority = $suggestion['priority'] ?? 'low';
                    $type = $suggestion['type'] ?? '';

                    if ($priority === 'high') {
                        $suggestionsSummary['high_priority_suggestions']++;
                    } elseif ($priority === 'medium') {
                        $suggestionsSummary['medium_priority_suggestions']++;
                    } else {
                        $suggestionsSummary['low_priority_suggestions']++;
                    }

                    // Count by type
                    if ($type === 'foreign_key') {
                        $suggestionsSummary['foreign_key_indexes_needed']++;
                    } elseif ($type === 'soft_delete') {
                        $suggestionsSummary['soft_delete_indexes_needed']++;
                    } elseif ($type === 'status_column') {
                        $suggestionsSummary['status_column_indexes_needed']++;
                    } elseif ($type === 'timestamp_sorting') {
                        $suggestionsSummary['timestamp_indexes_needed']++;
                    }
                }
            }

            if ($hasIssues) {
                $tablesWithIssues++;
            }

            // Info messages (low priority)
            $rowCount = $this->getTableRowCount($table);
            if ($rowCount > 1000000) {
                $info[] = [
                    'table' => $table,
                    'message' => "Table '{$table}': " . number_format($rowCount) . ' rows, consider partitioning',
                    'type' => 'large_table',
                ];
            }
        }

        return [
            'critical_issues' => $critical,
            'warnings' => $warnings,
            'info' => $info,
            'statistics' => [
                'total_tables' => count($tables),
                'tables_with_issues' => $tablesWithIssues,
                'total_indexes' => $totalIndexes,
                'redundant_indexes' => $redundantCount,
            ],
            'suggestions_summary' => $suggestionsSummary,
        ];
    }

    /**
     * Analyze single table structure.
     *
     * @param string $table Table name
     *
     * @return array<string, mixed> Analysis result
     */
    public function analyzeTable(string $table): array
    {
        if (!TableManager::tableExists($this->db, $table)) {
            return [
                'table' => $table,
                'error' => "Table '{$table}' does not exist",
            ];
        }

        $info = TableManager::info($this->db, $table);
        $indexes = $this->extractIndexes($info['indexes'] ?? []);
        $primaryKey = $this->extractPrimaryKey($table, $info['indexes'] ?? []);
        $foreignKeys = $this->getForeignKeys($table);
        $redundant = $this->redundantIndexDetector->detect($table, $indexes);
        $missingFkIndexes = $this->findMissingFkIndexes($table, $foreignKeys, $indexes);

        // Get index suggestions
        $suggestions = $this->indexSuggestionAnalyzer->analyze($table, []);

        return [
            'table' => $table,
            'has_primary_key' => !empty($primaryKey),
            'primary_key_columns' => $primaryKey,
            'indexes' => $this->formatIndexes($indexes),
            'redundant_indexes' => $redundant,
            'foreign_keys' => $this->formatForeignKeys($foreignKeys, $indexes),
            'missing_fk_indexes' => $missingFkIndexes,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Extract indexes from table info.
     *
     * @param array<int, array<string, mixed>> $rawIndexes
     *
     * @return array<string, array<int, string>> Index name => array of columns
     */
    protected function extractIndexes(array $rawIndexes): array
    {
        $result = [];
        foreach ($rawIndexes as $idx) {
            $name = $idx['Key_name'] ?? $idx['indexname'] ?? $idx['name'] ?? $idx['INDEX_NAME'] ?? null;
            $column = $idx['Column_name'] ?? $idx['column_name'] ?? $idx['column'] ?? $idx['COLUMN_NAME'] ?? null;

            if (!is_string($name) || !is_string($column) || $column === '') {
                continue;
            }

            // Skip PRIMARY key (we handle it separately)
            if (strtoupper($name) === 'PRIMARY') {
                continue;
            }

            if (!isset($result[$name])) {
                $result[$name] = [];
            }
            $result[$name][] = $column;
        }
        return $result;
    }

    /**
     * Extract primary key columns from indexes.
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $rawIndexes Raw indexes from table info
     *
     * @return array<int, string> Primary key columns
     */
    protected function extractPrimaryKey(string $table, array $rawIndexes): array
    {
        // Use dialect-specific method to get primary key columns
        $dialect = $this->db->schema()->getDialect();
        return $dialect->getPrimaryKeyColumns($this->db, $table);
    }

    /**
     * Get foreign keys for table.
     *
     * @param string $table Table name
     *
     * @return array<int, array<string, mixed>> Foreign keys
     */
    protected function getForeignKeys(string $table): array
    {
        try {
            return $this->db->schema()->getForeignKeys($table);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Find foreign keys without indexes.
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $foreignKeys Foreign keys
     * @param array<string, array<int, string>> $indexes Existing indexes
     *
     * @return array<int, array<string, mixed>> Missing FK indexes
     */
    protected function findMissingFkIndexes(string $table, array $foreignKeys, array $indexes): array
    {
        $missing = [];
        $indexedColumns = $this->getIndexedColumns($indexes);
        // Normalize to lowercase for case-insensitive comparison
        $indexedColumns = array_map('strtolower', $indexedColumns);

        foreach ($foreignKeys as $fk) {
            // Try all possible key variations (Oracle may return keys in different cases)
            $column = $fk['COLUMN_NAME'] ?? $fk['column_name'] ?? $fk['from'] ?? null;
            // Also try case-insensitive key lookup
            if ($column === null) {
                foreach ($fk as $key => $value) {
                    if (strtolower($key) === 'column_name' || strtolower($key) === 'from') {
                        $column = $value;
                        break;
                    }
                }
            }
            if (!is_string($column) || $column === '') {
                continue;
            }

            // Check if column is indexed (as first column in any index)
            // Compare in lowercase for case-insensitive matching (Oracle returns uppercase)
            $hasIndex = false;
            $columnLower = strtolower($column);
            foreach ($indexes as $idxColumns) {
                if (!empty($idxColumns) && strtolower($idxColumns[0]) === $columnLower) {
                    $hasIndex = true;
                    break;
                }
            }

            if (!$hasIndex) {
                $missing[] = [
                    'column' => $columnLower,
                    'foreign_key' => $fk['CONSTRAINT_NAME'] ?? $fk['constraint_name'] ?? $fk['name'] ?? 'unknown',
                ];
            }
        }

        return $missing;
    }

    /**
     * Get columns that are indexed.
     *
     * @param array<string, array<int, string>> $indexes
     *
     * @return array<string> Indexed column names
     */
    protected function getIndexedColumns(array $indexes): array
    {
        $indexed = [];
        foreach ($indexes as $cols) {
            foreach ($cols as $col) {
                $indexed[$col] = true;
            }
        }
        return array_keys($indexed);
    }

    /**
     * Format indexes for output.
     *
     * @param array<string, array<int, string>> $indexes
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatIndexes(array $indexes): array
    {
        $result = [];
        foreach ($indexes as $name => $columns) {
            $result[] = [
                'name' => $name,
                'columns' => $columns,
            ];
        }
        return $result;
    }

    /**
     * Format foreign keys for output.
     *
     * @param array<int, array<string, mixed>> $foreignKeys
     * @param array<string, array<int, string>> $indexes
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatForeignKeys(array $foreignKeys, array $indexes): array
    {
        $result = [];
        $indexedColumns = $this->getIndexedColumns($indexes);

        foreach ($foreignKeys as $fk) {
            $column = $fk['COLUMN_NAME'] ?? $fk['column_name'] ?? $fk['from'] ?? null;
            $refTable = $fk['REFERENCED_TABLE_NAME'] ?? $fk['referenced_table_name'] ?? $fk['table'] ?? null;
            $refColumn = $fk['REFERENCED_COLUMN_NAME'] ?? $fk['referenced_column_name'] ?? $fk['to'] ?? null;

            if (!is_string($column) || !is_string($refTable) || !is_string($refColumn)) {
                continue;
            }

            $hasIndex = false;
            foreach ($indexes as $idxColumns) {
                if (!empty($idxColumns) && $idxColumns[0] === $column) {
                    $hasIndex = true;
                    break;
                }
            }

            $result[] = [
                'name' => $fk['CONSTRAINT_NAME'] ?? $fk['constraint_name'] ?? $fk['name'] ?? 'unknown',
                'column' => $column,
                'referenced_table' => $refTable,
                'referenced_column' => $refColumn,
                'has_index' => $hasIndex,
            ];
        }

        return $result;
    }

    /**
     * Get table row count.
     *
     * @param string $table Table name
     *
     * @return int Row count
     */
    protected function getTableRowCount(string $table): int
    {
        try {
            $dialect = $this->db->schema()->getDialect();
            $quotedTable = $dialect->quoteTable($table);
            $count = $this->db->rawQueryValue("SELECT COUNT(*) FROM {$quotedTable}");
            return (int)($count ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
}
