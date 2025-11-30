<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PdoDb;

/**
 * Analyzes table structure and suggests indexes for optimization.
 */
class IndexSuggestionAnalyzer
{
    protected PdoDb $db;

    public function __construct(PdoDb $db)
    {
        $this->db = $db;
    }

    /**
     * Analyze table and generate index suggestions.
     *
     * @param string $table Table name
     * @param array<string, mixed> $options Analysis options
     *
     * @return array<int, array<string, mixed>> Array of suggestions with priority, reason, and SQL
     */
    public function analyze(string $table, array $options = []): array
    {
        $suggestions = [];

        // Get table structure
        $info = TableManager::info($this->db, $table);
        $columns = $info['columns'] ?? [];
        $existingIndexes = $this->extractIndexes($info['indexes'] ?? []);
        $foreignKeys = $this->getForeignKeys($table);

        // Analyze columns
        $columnNames = $this->extractColumnNames($columns);
        $indexedColumns = $this->getIndexedColumns($existingIndexes);

        // 1. Foreign keys without indexes (HIGH priority)
        $fkSuggestions = $this->suggestForeignKeyIndexes($table, $foreignKeys, $indexedColumns);
        $suggestions = array_merge($suggestions, $fkSuggestions);

        // 2. Common patterns (MEDIUM priority)
        $patternSuggestions = $this->suggestCommonPatterns($table, $columns, $indexedColumns);
        $suggestions = array_merge($suggestions, $patternSuggestions);

        // 3. Timestamp columns for sorting (LOW priority)
        $timestampSuggestions = $this->suggestTimestampIndexes($table, $columns, $indexedColumns);
        $suggestions = array_merge($suggestions, $timestampSuggestions);

        // Filter by priority if specified
        $priorityFilter = $options['priority'] ?? 'all';
        if ($priorityFilter !== 'all') {
            $suggestions = array_filter($suggestions, function ($suggestion) use ($priorityFilter) {
                return $suggestion['priority'] === $priorityFilter;
            });
        }

        // Sort by priority (high -> medium -> low)
        usort($suggestions, function ($a, $b) {
            $priorityOrder = ['high' => 1, 'medium' => 2, 'low' => 3];
            return ($priorityOrder[$a['priority']] ?? 99) <=> ($priorityOrder[$b['priority']] ?? 99);
        });

        return $suggestions;
    }

    /**
     * Extract column names from table description.
     *
     * @param array<int, array<string, mixed>> $columns
     *
     * @return array<string> Column names
     */
    protected function extractColumnNames(array $columns): array
    {
        $names = [];
        foreach ($columns as $col) {
            $name = $col['Field'] ?? $col['COLUMN_NAME'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Extract indexes from table info.
     *
     * @param array<int, array<string, mixed>> $indexes
     *
     * @return array<string, array<int, string>> Index name => array of columns
     */
    protected function extractIndexes(array $indexes): array
    {
        $result = [];
        foreach ($indexes as $idx) {
            $name = $idx['Key_name'] ?? $idx['indexname'] ?? $idx['name'] ?? null;
            $column = $idx['Column_name'] ?? $idx['column_name'] ?? $idx['column'] ?? null;

            if (is_string($name) && is_string($column) && $column !== '') {
                if (!isset($result[$name])) {
                    $result[$name] = [];
                }
                $result[$name][] = $column;
            }
        }
        return $result;
    }

    /**
     * Get columns that are already indexed.
     *
     * @param array<string, array<int, string>> $indexes
     *
     * @return array<string> Column names that are indexed
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
     * Suggest indexes for foreign keys that don't have indexes.
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $foreignKeys Foreign keys
     * @param array<string> $indexedColumns Already indexed columns
     *
     * @return array<int, array<string, mixed>> Suggestions
     */
    protected function suggestForeignKeyIndexes(string $table, array $foreignKeys, array $indexedColumns): array
    {
        $suggestions = [];

        foreach ($foreignKeys as $fk) {
            // Extract column name from different dialect formats
            $column = $fk['COLUMN_NAME'] ?? $fk['column_name'] ?? $fk['from'] ?? null;

            if (!is_string($column) || $column === '') {
                continue;
            }

            // Check if column is already indexed
            if (in_array($column, $indexedColumns, true)) {
                continue;
            }

            $fkName = $fk['CONSTRAINT_NAME'] ?? $fk['constraint_name'] ?? $fk['name'] ?? 'unknown';
            $refTable = $fk['REFERENCED_TABLE_NAME'] ?? $fk['referenced_table_name'] ?? $fk['table'] ?? 'unknown';

            $indexName = $this->generateIndexName($table, [$column]);
            $sql = $this->buildCreateIndexSql($table, $indexName, [$column]);

            $suggestions[] = [
                'priority' => 'high',
                'type' => 'foreign_key',
                'columns' => [$column],
                'reason' => sprintf(
                    'Foreign key column "%s" (references %s.%s) without index. Foreign keys should be indexed for JOIN performance.',
                    $column,
                    $refTable,
                    $fk['REFERENCED_COLUMN_NAME'] ?? $fk['referenced_column_name'] ?? $fk['to'] ?? '?'
                ),
                'sql' => $sql,
                'index_name' => $indexName,
            ];
        }

        return $suggestions;
    }

    /**
     * Suggest indexes based on common patterns.
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param array<string> $indexedColumns Already indexed columns
     *
     * @return array<int, array<string, mixed>> Suggestions
     */
    protected function suggestCommonPatterns(string $table, array $columns, array $indexedColumns): array
    {
        $suggestions = [];
        $columnNames = $this->extractColumnNames($columns);

        // Common status/enum columns
        $statusColumns = ['status', 'state', 'type', 'kind', 'category'];
        foreach ($statusColumns as $statusCol) {
            if (in_array($statusCol, $columnNames, true) && !in_array($statusCol, $indexedColumns, true)) {
                // Check if it's an enum or has limited values
                $colInfo = $this->findColumn($columns, $statusCol);
                if ($colInfo !== null) {
                    $indexName = $this->generateIndexName($table, [$statusCol]);
                    $sql = $this->buildCreateIndexSql($table, $indexName, [$statusCol]);

                    $suggestions[] = [
                        'priority' => 'medium',
                        'type' => 'status_column',
                        'columns' => [$statusCol],
                        'reason' => sprintf(
                            'Status/enum column "%s" frequently used in WHERE clauses. Consider indexing for filtering performance.',
                            $statusCol
                        ),
                        'sql' => $sql,
                        'index_name' => $indexName,
                    ];
                }
            }
        }

        // Common soft delete pattern
        $softDeleteColumns = ['deleted_at', 'deleted', 'is_deleted', 'archived_at', 'archived'];
        foreach ($softDeleteColumns as $delCol) {
            if (in_array($delCol, $columnNames, true) && !in_array($delCol, $indexedColumns, true)) {
                $indexName = $this->generateIndexName($table, [$delCol]);
                $sql = $this->buildCreateIndexSql($table, $indexName, [$delCol]);

                $suggestions[] = [
                    'priority' => 'high',
                    'type' => 'soft_delete',
                    'columns' => [$delCol],
                    'reason' => sprintf(
                        'Soft delete column "%s" should be indexed for efficient filtering of active records (WHERE %s IS NULL).',
                        $delCol,
                        $delCol
                    ),
                    'sql' => $sql,
                    'index_name' => $indexName,
                ];
            }
        }

        // Composite index for status + created_at pattern
        if (in_array('status', $columnNames, true) && in_array('created_at', $columnNames, true)) {
            $compositeCols = ['status', 'created_at'];
            $hasComposite = $this->hasCompositeIndex($table, $compositeCols);
            if (!$hasComposite) {
                $indexName = $this->generateIndexName($table, $compositeCols);
                $sql = $this->buildCreateIndexSql($table, $indexName, $compositeCols);

                $suggestions[] = [
                    'priority' => 'medium',
                    'type' => 'composite_status_date',
                    'columns' => $compositeCols,
                    'reason' => 'Common pattern: filtering by status and ordering by created_at. Composite index can optimize both operations.',
                    'sql' => $sql,
                    'index_name' => $indexName,
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Suggest indexes for timestamp columns used in sorting.
     *
     * @param string $table Table name
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param array<string> $indexedColumns Already indexed columns
     *
     * @return array<int, array<string, mixed>> Suggestions
     */
    protected function suggestTimestampIndexes(string $table, array $columns, array $indexedColumns): array
    {
        $suggestions = [];
        $timestampColumns = ['created_at', 'updated_at', 'last_login_at', 'published_at', 'expires_at'];

        foreach ($timestampColumns as $tsCol) {
            $colInfo = $this->findColumn($columns, $tsCol);
            if ($colInfo === null) {
                continue;
            }

            $type = strtolower($colInfo['Type'] ?? $colInfo['type'] ?? $colInfo['data_type'] ?? '');
            if (!str_contains($type, 'timestamp') && !str_contains($type, 'datetime') && !str_contains($type, 'date')) {
                continue;
            }

            if (!in_array($tsCol, $indexedColumns, true)) {
                $indexName = $this->generateIndexName($table, [$tsCol]);
                $sql = $this->buildCreateIndexSql($table, $indexName, [$tsCol]);

                $suggestions[] = [
                    'priority' => 'low',
                    'type' => 'timestamp_sorting',
                    'columns' => [$tsCol],
                    'reason' => sprintf(
                        'Timestamp column "%s" may be used for sorting (ORDER BY). Index can improve sorting performance for large tables.',
                        $tsCol
                    ),
                    'sql' => $sql,
                    'index_name' => $indexName,
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Find column info by name.
     *
     * @param array<int, array<string, mixed>> $columns
     * @param string $columnName
     *
     * @return array<string, mixed>|null
     */
    protected function findColumn(array $columns, string $columnName): ?array
    {
        foreach ($columns as $col) {
            $name = $col['Field'] ?? $col['COLUMN_NAME'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if (is_string($name) && $name === $columnName) {
                return $col;
            }
        }
        return null;
    }

    /**
     * Check if composite index exists for given columns.
     *
     * @param string $table Table name
     * @param array<int, string> $columns Column names
     *
     * @return bool
     */
    protected function hasCompositeIndex(string $table, array $columns): bool
    {
        try {
            $info = TableManager::info($this->db, $table);
            $indexes = $this->extractIndexes($info['indexes'] ?? []);

            foreach ($indexes as $indexCols) {
                if (count($indexCols) === count($columns)) {
                    $match = true;
                    foreach ($columns as $col) {
                        if (!in_array($col, $indexCols, true)) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            // If we can't check, assume it doesn't exist
        }

        return false;
    }

    /**
     * Generate index name from table and columns.
     *
     * @param string $table Table name
     * @param array<int, string> $columns Column names
     *
     * @return string Index name
     */
    protected function generateIndexName(string $table, array $columns): string
    {
        $tablePart = str_replace([' ', '-', '.'], '_', $table);
        $columnPart = implode('_', array_map(function (string $col): string {
            return str_replace([' ', '-', '.'], '_', $col);
        }, array_slice($columns, 0, 3))); // Limit to 3 columns for name

        return sprintf('idx_%s_%s', $tablePart, $columnPart);
    }

    /**
     * Build CREATE INDEX SQL statement.
     *
     * @param string $table Table name
     * @param string $indexName Index name
     * @param array<int, string> $columns Column names
     *
     * @return string SQL statement
     */
    protected function buildCreateIndexSql(string $table, string $indexName, array $columns): string
    {
        $dialect = $this->db->schema()->getDialect();
        $quotedTable = $dialect->quoteTable($table);
        $quotedIndexName = $dialect->quoteIdentifier($indexName);
        $quotedColumns = implode(', ', array_map(function ($col) use ($dialect) {
            return $dialect->quoteIdentifier($col);
        }, $columns));

        return sprintf('CREATE INDEX %s ON %s (%s);', $quotedIndexName, $quotedTable, $quotedColumns);
    }
}
