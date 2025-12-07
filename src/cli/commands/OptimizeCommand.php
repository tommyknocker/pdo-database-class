<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\DatabaseConfigOptimizer;
use tommyknocker\pdodb\cli\SchemaAnalyzer;
use tommyknocker\pdodb\cli\SlowQueryAnalyzer;
use tommyknocker\pdodb\cli\SlowQueryLogParser;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;
use tommyknocker\pdodb\query\analysis\ExplainAnalyzer;

/**
 * Optimize command for database optimization analysis.
 */
class OptimizeCommand extends Command
{
    public function __construct()
    {
        parent::__construct('optimize', 'Analyze and optimize database performance');
    }

    public function execute(): int
    {
        $sub = $this->getArgument(0);
        if ($sub === null || $sub === '--help' || $sub === 'help') {
            return $this->showHelp();
        }

        return match ($sub) {
            'analyze' => $this->analyze(),
            'structure' => $this->structure(),
            'logs' => $this->logs(),
            'query' => $this->query(),
            'db' => $this->db(),
            default => $this->showError("Unknown subcommand: {$sub}"),
        };
    }

    /**
     * Analyze entire schema.
     */
    protected function analyze(): int
    {
        $schema = $this->getOption('schema');
        $format = (string)$this->getOption('format', 'table');
        $db = $this->getDb();

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyze(is_string($schema) ? $schema : null);

        if ($format === 'json') {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        }

        if ($format === 'yaml') {
            $this->printYaml($result);
            return 0;
        }

        $this->printAnalyzeReport($result);
        return 0;
    }

    /**
     * Analyze table structure.
     */
    protected function structure(): int
    {
        // Check both argument and option for table name
        $table = $this->getArgument(1) ?? $this->getOption('table');
        $format = (string)$this->getOption('format', 'table');
        $db = $this->getDb();

        if (is_string($table) && $table !== '') {
            // Single table analysis
            $analyzer = new SchemaAnalyzer($db);
            $result = $analyzer->analyzeTable($table);

            if ($format === 'json') {
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                return 0;
            }

            if ($format === 'yaml') {
                $this->printYaml($result);
                return 0;
            }

            $this->printStructureReport($result);
            return 0;
        }

        // All tables analysis (similar to analyze but focused on structure)
        return $this->analyze();
    }

    /**
     * Analyze database configuration and provide optimization recommendations.
     */
    protected function db(): int
    {
        $format = (string)$this->getOption('format', 'table');
        $db = $this->getDb();

        // Parse memory parameter (e.g., "5G", "512M", "1024")
        $memoryStr = (string)$this->getOption('memory', '');
        if ($memoryStr === '') {
            return $this->showError('--memory parameter is required (e.g., --memory=5G)');
        }
        $memoryBytes = $this->parseMemory($memoryStr);
        if ($memoryBytes <= 0) {
            return $this->showError("Invalid memory format: {$memoryStr}. Use format like 5G, 512M, or 1024");
        }

        // Parse CPU cores
        $cpuCores = (int)$this->getOption('cpu-cores', 0);
        if ($cpuCores <= 0) {
            return $this->showError('--cpu-cores parameter is required and must be > 0');
        }

        // Optional parameters
        $workload = (string)$this->getOption('workload', 'oltp');
        if (!in_array($workload, ['oltp', 'olap', 'mixed'], true)) {
            return $this->showError('--workload must be one of: oltp, olap, mixed');
        }

        $diskType = (string)$this->getOption('disk-type', 'ssd');
        if (!in_array($diskType, ['ssd', 'hdd', 'nvme'], true)) {
            return $this->showError('--disk-type must be one of: ssd, hdd, nvme');
        }

        $expectedConnections = $this->getOption('connections');
        $expectedConnections = $expectedConnections !== null ? (int)$expectedConnections : null;

        $optimizer = new DatabaseConfigOptimizer($db);
        $result = $optimizer->analyze(
            $memoryBytes,
            $cpuCores,
            $workload,
            $diskType,
            $expectedConnections
        );

        if ($format === 'json') {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        }

        if ($format === 'yaml') {
            $this->printYaml($result);
            return 0;
        }

        $this->printDbReport($result);
        return 0;
    }

    /**
     * Parse memory string to bytes.
     *
     * @param string $memoryStr Memory string (e.g., "5G", "512M", "1024")
     *
     * @return int Bytes
     */
    protected function parseMemory(string $memoryStr): int
    {
        $memoryStr = trim($memoryStr);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]?)$/i', $memoryStr, $matches)) {
            $num = (float)$matches[1];
            $unit = $matches[2] !== '' ? strtolower($matches[2]) : '';
            $multiplier = match ($unit) {
                'k' => 1024,
                'm' => 1024 * 1024,
                'g' => 1024 * 1024 * 1024,
                't' => 1024 * 1024 * 1024 * 1024,
                default => 1,
            };
            return (int)($num * $multiplier);
        }

        return 0;
    }

    /**
     * Print database configuration optimization report.
     *
     * @param array<string, mixed> $result Analysis result
     */
    protected function printDbReport(array $result): void
    {
        $resources = $result['resources'] ?? [];
        $comparison = $result['comparison'] ?? [];
        $summary = $result['summary'] ?? [];
        $sqlCommands = $result['sql_commands'] ?? [];

        echo "\n";
        echo "Database Configuration Optimization Report\n";
        echo str_repeat('=', 60) . "\n";
        echo "\n";

        // Resources section
        echo "Server Resources:\n";
        echo '  Memory: ' . ($resources['memory_human'] ?? '') . "\n";
        echo '  CPU Cores: ' . ($resources['cpu_cores'] ?? '') . "\n";
        echo '  Workload: ' . strtoupper($resources['workload'] ?? '') . "\n";
        echo '  Disk Type: ' . strtoupper($resources['disk_type'] ?? '') . "\n";
        $expectedConnections = $resources['expected_connections'] ?? null;
        if ($expectedConnections !== null) {
            echo '  Expected Connections: ' . $expectedConnections . "\n";
        }
        echo "\n";

        // Comparison table
        if (!empty($comparison)) {
            echo "Current vs Recommended Settings:\n";
            echo str_repeat('─', 80) . "\n";
            printf(
                "%-30s %-15s %-15s %-10s\n",
                'Setting',
                'Current',
                'Recommended',
                'Priority'
            );
            echo str_repeat('─', 80) . "\n";

            foreach ($comparison as $item) {
                $name = $item['name'] ?? '';
                $current = $item['current_human'] ?? '';
                $recommended = $item['recommended_human'] ?? '';
                $priority = $item['priority'] ?? 'low';
                $needsChange = $item['needs_change'] ?? false;

                $priorityIcon = match ($priority) {
                    'high' => '🔴',
                    'medium' => '🟡',
                    default => '🟢',
                };

                $marker = $needsChange ? '*' : ' ';

                printf(
                    "%s%-29s %-15s %-15s %s %-9s\n",
                    $marker,
                    $name,
                    $current,
                    $recommended,
                    $priorityIcon,
                    strtoupper($priority)
                );
            }

            echo str_repeat('─', 80) . "\n";
            echo "* = needs change\n";
            echo "\n";

            // Summary
            if (!empty($summary)) {
                echo "Summary:\n";
                $high = $summary['high_priority'] ?? 0;
                $medium = $summary['medium_priority'] ?? 0;
                $low = $summary['low_priority'] ?? 0;
                $needsChange = $summary['needs_change'] ?? 0;

                if ($high > 0) {
                    echo "  🔴 High priority: {$high} setting" . ($high > 1 ? 's' : '') . "\n";
                }
                if ($medium > 0) {
                    echo "  🟡 Medium priority: {$medium} setting" . ($medium > 1 ? 's' : '') . "\n";
                }
                if ($low > 0) {
                    echo "  🟢 Low priority: {$low} setting" . ($low > 1 ? 's' : '') . "\n";
                }
                echo "\n";
                echo "Total settings requiring changes: {$needsChange}\n";
                echo "\n";
            }
        }

        // SQL commands
        if (!empty($sqlCommands)) {
            echo "SQL to Apply:\n";
            echo str_repeat('─', 80) . "\n";
            foreach ($sqlCommands as $cmd) {
                echo $cmd . "\n";
            }
            echo str_repeat('─', 80) . "\n";
            echo "\n";
            echo "Note: Review and test these commands before applying in production.\n";
            echo "Some settings may require server restart.\n";
            echo "\n";
        }
    }

    /**
     * Analyze slow query logs.
     */
    protected function logs(): int
    {
        $file = $this->getOption('file');
        if (!is_string($file) || $file === '') {
            return $this->showError('--file option is required for logs subcommand');
        }

        if (!file_exists($file) || !is_readable($file)) {
            return $this->showError("Cannot read slow query log file: {$file}");
        }

        $format = (string)$this->getOption('format', 'table');

        $parser = new SlowQueryLogParser();
        $queries = $parser->parse($file);

        $analyzer = new SlowQueryAnalyzer($this->getDb());
        $result = $analyzer->analyze($queries);

        if ($format === 'json') {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        }

        if ($format === 'yaml') {
            $this->printYaml($result);
            return 0;
        }

        $this->printLogsReport($result);
        return 0;
    }

    /**
     * Analyze single query.
     */
    protected function query(): int
    {
        $sql = $this->getArgument(1);
        if (!is_string($sql) || $sql === '') {
            return $this->showError('Query string is required for query subcommand');
        }

        $format = (string)$this->getOption('format', 'table');
        $db = $this->getDb();

        try {
            // For raw SQL, we need to use a QueryBuilder to get ExecutionEngine
            // Create a dummy query builder to access ExecutionEngine
            $queryBuilder = $db->find()->from('users')->limit(1);
            $explainResults = $db->explain($sql, []);

            // Use explainAdvice from a query builder (it will use the same ExecutionEngine)
            // But we need to analyze raw SQL results, so we'll use ExplainAnalyzer directly
            // Get ExecutionEngine via reflection from SelectQueryBuilder
            $reflection = new \ReflectionClass($queryBuilder);
            $selectBuilderProperty = $reflection->getProperty('selectQueryBuilder');
            $selectBuilderProperty->setAccessible(true);
            $selectBuilder = $selectBuilderProperty->getValue($queryBuilder);

            $selectReflection = new \ReflectionClass($selectBuilder);
            $executionEngineProperty = $selectReflection->getProperty('executionEngine');
            $executionEngineProperty->setAccessible(true);
            $executionEngine = $executionEngineProperty->getValue($selectBuilder);

            $dialect = $db->schema()->getDialect();
            $analyzer = new ExplainAnalyzer($dialect, $executionEngine);

            // Try to extract table name from SQL
            $tableName = $this->extractTableName($sql);
            $analysis = $analyzer->analyze($explainResults, $tableName);

            if ($format === 'json') {
                echo json_encode($this->formatAnalysisResult($analysis), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                return 0;
            }

            if ($format === 'yaml') {
                $this->printYaml($this->formatAnalysisResult($analysis));
                return 0;
            }

            $this->printQueryReport($sql, $analysis);
            return 0;
        } catch (QueryException $e) {
            return $this->showError('Failed to analyze query: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return $this->showError('Failed to analyze query: ' . $e->getMessage());
        }
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
     * Format ExplainAnalysis for output.
     *
     * @return array<string, mixed>
     */
    protected function formatAnalysisResult(ExplainAnalysis $analysis): array
    {
        return [
            'raw_explain' => $analysis->rawExplain,
            'issues' => array_map(function ($issue) {
                return [
                    'severity' => $issue->severity,
                    'type' => $issue->type,
                    'description' => $issue->description,
                    'table' => $issue->table,
                ];
            }, $analysis->issues),
            'recommendations' => array_map(function ($rec) {
                return [
                    'severity' => $rec->severity,
                    'type' => $rec->type,
                    'message' => $rec->message,
                    'suggestion' => $rec->suggestion,
                    'affected_tables' => $rec->affectedTables,
                ];
            }, $analysis->recommendations),
        ];
    }

    /**
     * Print analyze report.
     *
     * @param array<string, mixed> $result
     */
    protected function printAnalyzeReport(array $result): void
    {
        echo "\nSchema Analysis Report\n";
        echo str_repeat('=', 50) . "\n\n";

        $critical = $result['critical_issues'] ?? [];
        $warnings = $result['warnings'] ?? [];
        $info = $result['info'] ?? [];
        $statistics = $result['statistics'] ?? [];
        $suggestionsSummary = $result['suggestions_summary'] ?? [];

        if (!empty($critical)) {
            echo 'Critical Issues (' . count($critical) . "):\n";
            foreach ($critical as $issue) {
                echo "  ❌ {$issue['message']}\n";
            }
            echo "\n";
        }

        if (!empty($warnings)) {
            echo 'Warnings (' . count($warnings) . "):\n";
            foreach ($warnings as $warning) {
                echo "  ⚠️  {$warning['message']}\n";
            }
            echo "\n";
        }

        if (!empty($info)) {
            echo 'Info (' . count($info) . "):\n";
            foreach ($info as $item) {
                echo "  ℹ️  {$item['message']}\n";
            }
            echo "\n";
        }

        if (!empty($statistics)) {
            echo "Statistics:\n";
            foreach ($statistics as $key => $value) {
                echo '  - ' . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
            }
            echo "\n";
        }

        // Show suggestions summary if there are any
        if (!empty($suggestionsSummary) && $suggestionsSummary['tables_needing_indexes'] > 0) {
            echo "Index Suggestions Summary:\n";
            echo "  - Tables needing indexes: {$suggestionsSummary['tables_needing_indexes']}\n";
            if ($suggestionsSummary['high_priority_suggestions'] > 0) {
                echo "  - High priority suggestions: {$suggestionsSummary['high_priority_suggestions']}\n";
            }
            if ($suggestionsSummary['medium_priority_suggestions'] > 0) {
                echo "  - Medium priority suggestions: {$suggestionsSummary['medium_priority_suggestions']}\n";
            }
            if ($suggestionsSummary['low_priority_suggestions'] > 0) {
                echo "  - Low priority suggestions: {$suggestionsSummary['low_priority_suggestions']}\n";
            }

            // Show breakdown by type
            $typeBreakdown = [];
            if ($suggestionsSummary['foreign_key_indexes_needed'] > 0) {
                $typeBreakdown[] = "Foreign keys: {$suggestionsSummary['foreign_key_indexes_needed']}";
            }
            if ($suggestionsSummary['soft_delete_indexes_needed'] > 0) {
                $typeBreakdown[] = "Soft delete columns: {$suggestionsSummary['soft_delete_indexes_needed']}";
            }
            if ($suggestionsSummary['status_column_indexes_needed'] > 0) {
                $typeBreakdown[] = "Status columns: {$suggestionsSummary['status_column_indexes_needed']}";
            }
            if ($suggestionsSummary['timestamp_indexes_needed'] > 0) {
                $typeBreakdown[] = "Timestamp columns: {$suggestionsSummary['timestamp_indexes_needed']}";
            }

            if (!empty($typeBreakdown)) {
                echo '  - Breakdown: ' . implode(', ', $typeBreakdown) . "\n";
            }

            echo "\n";
            echo "💡 Tip: Run 'pdodb optimize structure --table=<table_name>' for detailed index suggestions.\n";
            echo "\n";
        }

        if (empty($critical) && empty($warnings) && empty($info) && empty($suggestionsSummary)) {
            static::success('No issues found. Schema appears to be well-optimized.');
        }
    }

    /**
     * Print structure report.
     *
     * @param array<string, mixed> $result
     */
    protected function printStructureReport(array $result): void
    {
        $table = $result['table'] ?? 'unknown';
        echo "\nTable Structure Analysis: '{$table}'\n";
        echo str_repeat('=', 50) . "\n\n";

        // Primary Key
        $hasPk = $result['has_primary_key'] ?? false;
        $pkColumns = $result['primary_key_columns'] ?? [];
        if ($hasPk) {
            $pkCols = implode(', ', $pkColumns);
            echo "Primary Key: ✓ Present ({$pkCols})\n\n";
        } else {
            echo "Primary Key: ❌ MISSING\n\n";
        }

        // Indexes
        $indexes = $result['indexes'] ?? [];
        $redundant = $result['redundant_indexes'] ?? [];
        $missingFk = $result['missing_fk_indexes'] ?? [];

        if (!empty($indexes)) {
            echo 'Indexes (' . count($indexes) . "):\n";
            foreach ($indexes as $idx) {
                $name = $idx['name'] ?? 'unknown';
                $cols = implode(', ', $idx['columns'] ?? []);
                $status = '✓';
                $note = '';

                // Check if redundant
                foreach ($redundant as $red) {
                    if ($red['index'] === $name) {
                        $status = '⚠️';
                        $note = ' - REDUNDANT (covered by ' . $red['covered_by'] . ')';
                        break;
                    }
                }

                // Check if FK without index
                foreach ($missingFk as $fk) {
                    if ($fk['column'] === $cols) {
                        $status = '⚠️';
                        $note = ' - MISSING (FK column without index)';
                        break;
                    }
                }

                echo "  {$status} {$name} ({$cols}){$note}\n";
            }
            echo "\n";
        }

        // Foreign Keys
        $foreignKeys = $result['foreign_keys'] ?? [];
        if (!empty($foreignKeys)) {
            echo 'Foreign Keys (' . count($foreignKeys) . "):\n";
            foreach ($foreignKeys as $fk) {
                $name = $fk['name'] ?? 'unknown';
                $column = $fk['column'] ?? 'unknown';
                $refTable = $fk['referenced_table'] ?? 'unknown';
                $refColumn = $fk['referenced_column'] ?? 'unknown';
                $hasIndex = $fk['has_index'] ?? false;

                $status = $hasIndex ? '✓' : '⚠️';
                $note = $hasIndex ? ' - has index' : ' - missing index';
                echo "  {$status} {$name} ({$column} -> {$refTable}.{$refColumn}){$note}\n";
            }
            echo "\n";
        }

        // Suggestions
        $suggestions = $result['suggestions'] ?? [];
        if (!empty($suggestions)) {
            $byPriority = ['high' => [], 'medium' => [], 'low' => []];
            foreach ($suggestions as $suggestion) {
                $priority = $suggestion['priority'] ?? 'low';
                $byPriority[$priority][] = $suggestion;
            }

            $icons = ['high' => '🔴', 'medium' => '🟡', 'low' => '🟢'];
            $labels = ['high' => 'HIGH', 'medium' => 'MEDIUM', 'low' => 'LOW'];

            echo "Suggestions:\n";
            foreach (['high', 'medium', 'low'] as $priority) {
                if (empty($byPriority[$priority])) {
                    continue;
                }
                foreach ($byPriority[$priority] as $suggestion) {
                    $icon = $icons[$priority];
                    $label = $labels[$priority];
                    $message = $suggestion['reason'] ?? $suggestion['message'] ?? 'No message';
                    echo "  {$icon} {$label}: {$message}\n";
                    // Show SQL if available
                    if (!empty($suggestion['sql'])) {
                        echo "     SQL: {$suggestion['sql']}\n";
                    }
                }
            }
        }
    }

    /**
     * Print logs report.
     *
     * @param array<string, mixed> $result
     */
    protected function printLogsReport(array $result): void
    {
        echo "\nSlow Query Log Analysis\n";
        echo str_repeat('=', 50) . "\n\n";

        $topQueries = $result['top_queries'] ?? [];
        if (!empty($topQueries)) {
            echo "Top 10 Slowest Queries (by total time):\n\n";
            $count = 1;
            foreach (array_slice($topQueries, 0, 10) as $query) {
                echo "{$count}. " . ($query['normalized_sql'] ?? 'Unknown query') . "\n";
                echo '   - Count: ' . ($query['count'] ?? 0) . "\n";
                echo '   - Avg time: ' . ($query['avg_time'] ?? 0) . "s\n";
                echo '   - Max time: ' . ($query['max_time'] ?? 0) . "s\n";
                echo '   - Total time: ' . ($query['total_time'] ?? 0) . "s\n";
                if (!empty($query['recommendation'])) {
                    echo "   - Recommendation: {$query['recommendation']}\n";
                }
                echo "\n";
                $count++;
            }
        }

        $summary = $result['summary'] ?? [];
        if (!empty($summary)) {
            echo "Summary:\n";
            foreach ($summary as $key => $value) {
                echo '  - ' . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
            }
        }
    }

    /**
     * Print query report.
     */
    protected function printQueryReport(string $sql, ExplainAnalysis $analysis): void
    {
        echo "\nQuery Analysis\n";
        echo str_repeat('=', 50) . "\n\n";
        echo "SQL: {$sql}\n\n";

        // EXPLAIN Plan
        if (!empty($analysis->rawExplain)) {
            echo "EXPLAIN Plan:\n";
            echo json_encode($analysis->rawExplain, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
        }

        // Issues
        if (!empty($analysis->issues)) {
            echo "Issues:\n";
            foreach ($analysis->issues as $issue) {
                $icon = match ($issue->severity) {
                    'critical' => '🔴',
                    'warning' => '⚠️',
                    default => 'ℹ️',
                };
                echo "  {$icon} {$issue->description}\n";
            }
            echo "\n";
        }

        // Recommendations
        if (!empty($analysis->recommendations)) {
            echo "Recommendations:\n";
            foreach ($analysis->recommendations as $rec) {
                $icon = match ($rec->severity) {
                    'critical' => '🔴',
                    'warning' => '🟡',
                    default => '🟢',
                };
                $label = strtoupper($rec->severity);
                echo "  {$icon} {$label}: {$rec->message}\n";
                if ($rec->suggestion !== null) {
                    echo "     Suggestion: {$rec->suggestion}\n";
                }
            }
        }

        if (empty($analysis->issues) && empty($analysis->recommendations)) {
            static::success('No issues found. Query appears to be well-optimized.');
        }
    }

    /**
     * Print YAML-like output.
     *
     * @param array<string, mixed> $data
     */
    protected function printYaml(array $data, int $indent = 0): void
    {
        foreach ($data as $key => $val) {
            $pad = str_repeat('  ', $indent);
            if (is_array($val)) {
                echo "{$pad}{$key}:\n";
                $this->printYaml($val, $indent + 1);
            } else {
                echo "{$pad}{$key}: {$val}\n";
            }
        }
    }

    protected function showHelp(): int
    {
        echo "Database Optimization\n\n";
        echo "Usage: pdodb optimize <subcommand> [arguments] [options]\n\n";
        echo "Subcommands:\n";
        echo "  analyze [--schema=SCHEMA] [--format=FORMAT]\n";
        echo "    Holistic schema analysis (all tables, PKs, indexes, FKs)\n\n";
        echo "  structure [--table=TABLE] [--format=FORMAT]\n";
        echo "    Structure analysis (PK, redundant indexes, FK indexes)\n\n";
        echo "  logs --file=FILE [--format=FORMAT]\n";
        echo "    Analyze slow query logs\n\n";
        echo "  query \"SELECT ...\" [--format=FORMAT]\n";
        echo "    EXPLAIN + recommendations for single query\n\n";
        echo "  db --memory=SIZE --cpu-cores=N [OPTIONS] [--format=FORMAT]\n";
        echo "    Database configuration optimization recommendations\n\n";
        echo "Options:\n";
        echo "  --format=table|json|yaml    Output format (default: table)\n";
        echo "  --schema=SCHEMA              Schema name (for analyze)\n";
        echo "  --table=TABLE                Table name (for structure)\n";
        echo "  --file=FILE                  Slow query log file path (for logs)\n";
        echo "  --memory=SIZE                Available memory (e.g., 5G, 512M) (for db)\n";
        echo "  --cpu-cores=N                Number of CPU cores (for db)\n";
        echo "  --workload=TYPE              Workload type: oltp|olap|mixed (default: oltp) (for db)\n";
        echo "  --disk-type=TYPE             Disk type: ssd|hdd|nvme (default: ssd) (for db)\n";
        echo "  --connections=N              Expected number of connections (for db)\n\n";
        echo "Examples:\n";
        echo "  pdodb optimize analyze\n";
        echo "  pdodb optimize analyze --schema=public --format=json\n";
        echo "  pdodb optimize structure --table=users\n";
        echo "  pdodb optimize logs --file=/var/log/mysql/slow.log\n";
        echo "  pdodb optimize query \"SELECT * FROM users WHERE id = 1\"\n";
        echo "  pdodb optimize db --memory=5G --cpu-cores=32\n";
        echo "  pdodb optimize db --memory=8G --cpu-cores=16 --workload=olap --disk-type=nvme\n";
        return 0;
    }
}
