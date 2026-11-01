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
            default => $this->fail("Unknown subcommand: {$sub}"),
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
            $this->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
                $this->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
            return $this->fail('--memory parameter is required (e.g., --memory=5G)');
        }
        $memoryBytes = $this->parseMemory($memoryStr);
        if ($memoryBytes <= 0) {
            return $this->fail("Invalid memory format: {$memoryStr}. Use format like 5G, 512M, or 1024");
        }

        // Parse CPU cores
        $cpuCores = (int)$this->getOption('cpu-cores', 0);
        if ($cpuCores <= 0) {
            return $this->fail('--cpu-cores parameter is required and must be > 0');
        }

        // Optional parameters
        $workload = (string)$this->getOption('workload', 'oltp');
        if (!in_array($workload, ['oltp', 'olap', 'mixed'], true)) {
            return $this->fail('--workload must be one of: oltp, olap, mixed');
        }

        $diskType = (string)$this->getOption('disk-type', 'ssd');
        if (!in_array($diskType, ['ssd', 'hdd', 'nvme'], true)) {
            return $this->fail('--disk-type must be one of: ssd, hdd, nvme');
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
            $this->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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

        $this->writeln('');
        $this->writeln('Database Configuration Optimization Report');
        $this->writeln(str_repeat('=', 60));
        $this->writeln('');

        // Resources section
        $this->writeln('Server Resources:');
        $this->writeln('  Memory: ' . ($resources['memory_human'] ?? ''));
        $this->writeln('  CPU Cores: ' . ($resources['cpu_cores'] ?? ''));
        $this->writeln('  Workload: ' . strtoupper($resources['workload'] ?? ''));
        $this->writeln('  Disk Type: ' . strtoupper($resources['disk_type'] ?? ''));
        $expectedConnections = $resources['expected_connections'] ?? null;
        if ($expectedConnections !== null) {
            $this->writeln('  Expected Connections: ' . $expectedConnections);
        }
        $this->writeln('');

        // Comparison table
        if (!empty($comparison)) {
            $this->writeln('Current vs Recommended Settings:');
            $this->writeln(str_repeat('─', 80));
            printf(
                "%-30s %-15s %-15s %-10s\n",
                'Setting',
                'Current',
                'Recommended',
                'Priority'
            );
            $this->writeln(str_repeat('─', 80));

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

            $this->writeln(str_repeat('─', 80));
            $this->writeln('* = needs change');
            $this->writeln('');

            // Summary
            if (!empty($summary)) {
                $this->writeln('Summary:');
                $high = $summary['high_priority'] ?? 0;
                $medium = $summary['medium_priority'] ?? 0;
                $low = $summary['low_priority'] ?? 0;
                $needsChange = $summary['needs_change'] ?? 0;

                if ($high > 0) {
                    $this->writeln("  🔴 High priority: {$high} setting" . ($high > 1 ? 's' : ''));
                }
                if ($medium > 0) {
                    $this->writeln("  🟡 Medium priority: {$medium} setting" . ($medium > 1 ? 's' : ''));
                }
                if ($low > 0) {
                    $this->writeln("  🟢 Low priority: {$low} setting" . ($low > 1 ? 's' : ''));
                }
                $this->writeln('');
                $this->writeln("Total settings requiring changes: {$needsChange}");
                $this->writeln('');
            }
        }

        // SQL commands
        if (!empty($sqlCommands)) {
            $this->writeln('SQL to Apply:');
            $this->writeln(str_repeat('─', 80));
            foreach ($sqlCommands as $cmd) {
                $this->writeln($cmd);
            }
            $this->writeln(str_repeat('─', 80));
            $this->writeln('');
            $this->writeln('Note: Review and test these commands before applying in production.');
            $this->writeln('Some settings may require server restart.');
            $this->writeln('');
        }
    }

    /**
     * Analyze slow query logs.
     */
    protected function logs(): int
    {
        $file = $this->getOption('file');
        if (!is_string($file) || $file === '') {
            return $this->fail('--file option is required for logs subcommand');
        }

        if (!file_exists($file) || !is_readable($file)) {
            return $this->fail("Cannot read slow query log file: {$file}");
        }

        $format = (string)$this->getOption('format', 'table');

        $parser = new SlowQueryLogParser();
        $queries = $parser->parse($file);

        $analyzer = new SlowQueryAnalyzer($this->getDb());
        $result = $analyzer->analyze($queries);

        if ($format === 'json') {
            $this->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
            return $this->fail('Query string is required for query subcommand');
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

            $selectBuilder = $selectBuilderProperty->getValue($queryBuilder);

            $selectReflection = new \ReflectionClass($selectBuilder);
            $executionEngineProperty = $selectReflection->getProperty('executionEngine');

            $executionEngine = $executionEngineProperty->getValue($selectBuilder);

            $dialect = $db->schema()->getDialect();
            $analyzer = new ExplainAnalyzer($dialect, $executionEngine);

            // Try to extract table name from SQL
            $tableName = $this->extractTableName($sql);
            $analysis = $analyzer->analyze($explainResults, $tableName);

            if ($format === 'json') {
                $this->writeln(json_encode($this->formatAnalysisResult($analysis), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return 0;
            }

            if ($format === 'yaml') {
                $this->printYaml($this->formatAnalysisResult($analysis));
                return 0;
            }

            $this->printQueryReport($sql, $analysis);
            return 0;
        } catch (QueryException $e) {
            return $this->fail('Failed to analyze query: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('Failed to analyze query: ' . $e->getMessage());
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
        $this->writeln("\nSchema Analysis Report");
        $this->write(str_repeat('=', 50) . "\n\n");

        $critical = $result['critical_issues'] ?? [];
        $warnings = $result['warnings'] ?? [];
        $info = $result['info'] ?? [];
        $statistics = $result['statistics'] ?? [];
        $suggestionsSummary = $result['suggestions_summary'] ?? [];

        if (!empty($critical)) {
            $this->write('Critical Issues (' . count($critical) . "):\n");
            foreach ($critical as $issue) {
                $this->writeln("  ❌ {$issue['message']}");
            }
            $this->writeln('');
        }

        if (!empty($warnings)) {
            $this->write('Warnings (' . count($warnings) . "):\n");
            foreach ($warnings as $warning) {
                $this->writeln("  ⚠️  {$warning['message']}");
            }
            $this->writeln('');
        }

        if (!empty($info)) {
            $this->write('Info (' . count($info) . "):\n");
            foreach ($info as $item) {
                $this->writeln("  ℹ️  {$item['message']}");
            }
            $this->writeln('');
        }

        if (!empty($statistics)) {
            $this->writeln('Statistics:');
            foreach ($statistics as $key => $value) {
                $this->write('  - ' . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n");
            }
            $this->writeln('');
        }

        // Show suggestions summary if there are any
        if (!empty($suggestionsSummary) && $suggestionsSummary['tables_needing_indexes'] > 0) {
            $this->writeln('Index Suggestions Summary:');
            $this->writeln("  - Tables needing indexes: {$suggestionsSummary['tables_needing_indexes']}");
            if ($suggestionsSummary['high_priority_suggestions'] > 0) {
                $this->writeln("  - High priority suggestions: {$suggestionsSummary['high_priority_suggestions']}");
            }
            if ($suggestionsSummary['medium_priority_suggestions'] > 0) {
                $this->writeln("  - Medium priority suggestions: {$suggestionsSummary['medium_priority_suggestions']}");
            }
            if ($suggestionsSummary['low_priority_suggestions'] > 0) {
                $this->writeln("  - Low priority suggestions: {$suggestionsSummary['low_priority_suggestions']}");
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
                $this->writeln('  - Breakdown: ' . implode(', ', $typeBreakdown));
            }

            $this->writeln('');
            $this->writeln("💡 Tip: Run 'pdodb optimize structure --table=<table_name>' for detailed index suggestions.");
            $this->writeln('');
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
        $this->writeln("\nTable Structure Analysis: '{$table}'");
        $this->write(str_repeat('=', 50) . "\n\n");

        // Primary Key
        $hasPk = $result['has_primary_key'] ?? false;
        $pkColumns = $result['primary_key_columns'] ?? [];
        if ($hasPk) {
            $pkCols = implode(', ', $pkColumns);
            $this->writeln("Primary Key: ✓ Present ({$pkCols})\n");
        } else {
            $this->writeln("Primary Key: ❌ MISSING\n");
        }

        // Indexes
        $indexes = $result['indexes'] ?? [];
        $redundant = $result['redundant_indexes'] ?? [];
        $missingFk = $result['missing_fk_indexes'] ?? [];

        if (!empty($indexes)) {
            $this->write('Indexes (' . count($indexes) . "):\n");
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

                $this->writeln("  {$status} {$name} ({$cols}){$note}");
            }
            $this->writeln('');
        }

        // Foreign Keys
        $foreignKeys = $result['foreign_keys'] ?? [];
        if (!empty($foreignKeys)) {
            $this->write('Foreign Keys (' . count($foreignKeys) . "):\n");
            foreach ($foreignKeys as $fk) {
                $name = $fk['name'] ?? 'unknown';
                $column = $fk['column'] ?? 'unknown';
                $refTable = $fk['referenced_table'] ?? 'unknown';
                $refColumn = $fk['referenced_column'] ?? 'unknown';
                $hasIndex = $fk['has_index'] ?? false;

                $status = $hasIndex ? '✓' : '⚠️';
                $note = $hasIndex ? ' - has index' : ' - missing index';
                $this->writeln("  {$status} {$name} ({$column} -> {$refTable}.{$refColumn}){$note}");
            }
            $this->writeln('');
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

            $this->writeln('Suggestions:');
            foreach (['high', 'medium', 'low'] as $priority) {
                if (empty($byPriority[$priority])) {
                    continue;
                }
                foreach ($byPriority[$priority] as $suggestion) {
                    $icon = $icons[$priority];
                    $label = $labels[$priority];
                    $message = $suggestion['reason'] ?? $suggestion['message'] ?? 'No message';
                    $this->writeln("  {$icon} {$label}: {$message}");
                    // Show SQL if available
                    if (!empty($suggestion['sql'])) {
                        $this->writeln("     SQL: {$suggestion['sql']}");
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
        $this->writeln("\nSlow Query Log Analysis");
        $this->write(str_repeat('=', 50) . "\n\n");

        $topQueries = $result['top_queries'] ?? [];
        if (!empty($topQueries)) {
            $this->writeln("Top 10 Slowest Queries (by total time):\n");
            $count = 1;
            foreach (array_slice($topQueries, 0, 10) as $query) {
                $this->writeln("{$count}. " . ($query['normalized_sql'] ?? 'Unknown query'));
                $this->writeln('   - Count: ' . ($query['count'] ?? 0));
                $this->write('   - Avg time: ' . ($query['avg_time'] ?? 0) . "s\n");
                $this->write('   - Max time: ' . ($query['max_time'] ?? 0) . "s\n");
                $this->write('   - Total time: ' . ($query['total_time'] ?? 0) . "s\n");
                if (!empty($query['recommendation'])) {
                    $this->writeln("   - Recommendation: {$query['recommendation']}");
                }
                $this->writeln('');
                $count++;
            }
        }

        $summary = $result['summary'] ?? [];
        if (!empty($summary)) {
            $this->writeln('Summary:');
            foreach ($summary as $key => $value) {
                $this->write('  - ' . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n");
            }
        }
    }

    /**
     * Print query report.
     */
    protected function printQueryReport(string $sql, ExplainAnalysis $analysis): void
    {
        $this->writeln("\nQuery Analysis");
        $this->write(str_repeat('=', 50) . "\n\n");
        $this->writeln("SQL: {$sql}\n");

        // EXPLAIN Plan
        if (!empty($analysis->rawExplain)) {
            $this->writeln('EXPLAIN Plan:');
            $this->write(json_encode($analysis->rawExplain, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n\n");
        }

        // Issues
        if (!empty($analysis->issues)) {
            $this->writeln('Issues:');
            foreach ($analysis->issues as $issue) {
                $icon = match ($issue->severity) {
                    'critical' => '🔴',
                    'warning' => '⚠️',
                    default => 'ℹ️',
                };
                $this->writeln("  {$icon} {$issue->description}");
            }
            $this->writeln('');
        }

        // Recommendations
        if (!empty($analysis->recommendations)) {
            $this->writeln('Recommendations:');
            foreach ($analysis->recommendations as $rec) {
                $icon = match ($rec->severity) {
                    'critical' => '🔴',
                    'warning' => '🟡',
                    default => '🟢',
                };
                $label = strtoupper($rec->severity);
                $this->writeln("  {$icon} {$label}: {$rec->message}");
                if ($rec->suggestion !== null) {
                    $this->writeln("     Suggestion: {$rec->suggestion}");
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
                $this->writeln("{$pad}{$key}:");
                $this->printYaml($val, $indent + 1);
            } else {
                $this->writeln("{$pad}{$key}: {$val}");
            }
        }
    }

    protected function showHelp(): int
    {
        $this->writeln("Database Optimization\n");
        $this->writeln("Usage: pdodb optimize <subcommand> [arguments] [options]\n");
        $this->writeln('Subcommands:');
        $this->writeln('  analyze [--schema=SCHEMA] [--format=FORMAT]');
        $this->writeln("    Holistic schema analysis (all tables, PKs, indexes, FKs)\n");
        $this->writeln('  structure [--table=TABLE] [--format=FORMAT]');
        $this->writeln("    Structure analysis (PK, redundant indexes, FK indexes)\n");
        $this->writeln('  logs --file=FILE [--format=FORMAT]');
        $this->writeln("    Analyze slow query logs\n");
        $this->writeln('  query "SELECT ..." [--format=FORMAT]');
        $this->writeln("    EXPLAIN + recommendations for single query\n");
        $this->writeln('  db --memory=SIZE --cpu-cores=N [OPTIONS] [--format=FORMAT]');
        $this->writeln("    Database configuration optimization recommendations\n");
        $this->writeln('Options:');
        $this->writeln('  --format=table|json|yaml    Output format (default: table)');
        $this->writeln('  --schema=SCHEMA              Schema name (for analyze)');
        $this->writeln('  --table=TABLE                Table name (for structure)');
        $this->writeln('  --file=FILE                  Slow query log file path (for logs)');
        $this->writeln('  --memory=SIZE                Available memory (e.g., 5G, 512M) (for db)');
        $this->writeln('  --cpu-cores=N                Number of CPU cores (for db)');
        $this->writeln('  --workload=TYPE              Workload type: oltp|olap|mixed (default: oltp) (for db)');
        $this->writeln('  --disk-type=TYPE             Disk type: ssd|hdd|nvme (default: ssd) (for db)');
        $this->writeln("  --connections=N              Expected number of connections (for db)\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb optimize analyze');
        $this->writeln('  pdodb optimize analyze --schema=public --format=json');
        $this->writeln('  pdodb optimize structure --table=users');
        $this->writeln('  pdodb optimize logs --file=/var/log/mysql/slow.log');
        $this->writeln('  pdodb optimize query "SELECT * FROM users WHERE id = 1"');
        $this->writeln('  pdodb optimize db --memory=5G --cpu-cores=32');
        $this->writeln('  pdodb optimize db --memory=8G --cpu-cores=16 --workload=olap --disk-type=nvme');
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function knownOptions(): array
    {
        return [
            'connections',
            'cpu-cores',
            'disk-type',
            'file',
            'format',
            'memory',
            'schema',
            'table',
            'workload',
        ];
    }
}
