<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;

/**
 * Builds context for AI analysis requests.
 */
class AiAnalysisContext
{
    protected PdoDb $db;

    public function __construct(PdoDb $db)
    {
        $this->db = $db;
    }

    /**
     * Build context for query analysis.
     *
     * @param string $sql SQL query
     * @param string|null $tableName Table name
     * @param ExplainAnalysis|null $explainAnalysis Existing explain analysis
     *
     * @return array<string, mixed> Context data
     */
    public function buildQueryContext(string $sql, ?string $tableName = null, ?ExplainAnalysis $explainAnalysis = null): array
    {
        $context = [
            'sql' => $sql,
            'dialect' => $this->db->connection->getDriverName(),
        ];

        if ($tableName !== null) {
            $context['table_schema'] = $this->getTableSchema($tableName);
            $context['table_stats'] = $this->getTableStats($tableName);
        }

        if ($explainAnalysis !== null) {
            $context['explain_plan'] = $this->formatExplainAnalysis($explainAnalysis);
        }

        return $context;
    }

    /**
     * Build context for schema analysis.
     *
     * @param string|null $tableName Specific table or null for all tables
     *
     * @return array<string, mixed> Context data
     */
    public function buildSchemaContext(?string $tableName = null): array
    {
        $context = [
            'dialect' => $this->db->connection->getDriverName(),
        ];

        if ($tableName !== null) {
            $context['schema'] = [
                'tables' => [
                    $tableName => $this->getTableSchema($tableName),
                ],
            ];
            $context['table_stats'] = [
                $tableName => $this->getTableStats($tableName),
            ];
        } else {
            $tables = TableManager::listTables($this->db);
            $context['schema'] = [
                'tables' => [],
            ];
            foreach ($tables as $table) {
                $context['schema']['tables'][$table] = $this->getTableSchema($table);
            }
        }

        return $context;
    }

    /**
     * Get table schema information.
     *
     * @param string $tableName Table name
     *
     * @return array<string, mixed> Schema information
     */
    protected function getTableSchema(string $tableName): array
    {
        try {
            $columns = $this->db->describe($tableName);
            $indexes = $this->db->schema()->getIndexes($tableName);
            $foreignKeys = $this->db->schema()->getForeignKeys($tableName);

            return [
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get table statistics.
     *
     * @param string $tableName Table name
     *
     * @return array<string, mixed> Statistics
     */
    protected function getTableStats(string $tableName): array
    {
        try {
            $dialect = $this->db->connection->getDialect();
            $driverName = $this->db->connection->getDriverName();

            $stats = [];

            // Get row count
            $count = $this->db->find()->from($tableName)->select([Db::count('*')])->getValue();
            $stats['row_count'] = $count;

            // Get table size if supported
            if ($driverName === 'mysql' || $driverName === 'mariadb') {
                $sizeQuery = 'SELECT
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                    FROM information_schema.TABLES
                    WHERE table_schema = DATABASE()
                    AND table_name = ?';
                $result = $this->db->rawQueryOne($sizeQuery, [$tableName]);
                if ($result !== null && is_array($result) && isset($result['size_mb'])) {
                    $stats['size_mb'] = (float)$result['size_mb'];
                }
            } elseif ($driverName === 'pgsql') {
                $sizeQuery = 'SELECT pg_size_pretty(pg_total_relation_size(?)) as size';
                $result = $this->db->rawQueryOne($sizeQuery, [$tableName]);
                if ($result !== null && is_array($result) && isset($result['size'])) {
                    $stats['size'] = (string)$result['size'];
                }
            }

            return $stats;
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Format ExplainAnalysis for AI context.
     *
     * @param ExplainAnalysis $analysis Explain analysis
     *
     * @return array<string, mixed> Formatted analysis
     */
    protected function formatExplainAnalysis(ExplainAnalysis $analysis): array
    {
        return [
            'raw_explain' => $analysis->rawExplain,
            'plan' => [
                'table_scans' => $analysis->plan->tableScans ?? [],
                'used_index' => $analysis->plan->usedIndex ?? null,
                'used_columns' => $analysis->plan->usedColumns ?? [],
                'access_type' => $analysis->plan->accessType ?? null,
            ],
            'issues' => array_map(function ($issue) {
                return [
                    'type' => $issue->type,
                    'severity' => $issue->severity,
                    'description' => $issue->description,
                    'table' => $issue->table ?? null,
                ];
            }, $analysis->issues),
            'recommendations' => array_map(function ($rec) {
                return [
                    'type' => $rec->type,
                    'severity' => $rec->severity,
                    'message' => $rec->message,
                    'suggestion' => $rec->suggestion ?? null,
                ];
            }, $analysis->recommendations),
        ];
    }
}
