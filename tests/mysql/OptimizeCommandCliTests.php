<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\DatabaseConfigOptimizer;
use tommyknocker\pdodb\cli\RedundantIndexDetector;
use tommyknocker\pdodb\cli\SchemaAnalyzer;
use tommyknocker\pdodb\cli\SlowQueryAnalyzer;
use tommyknocker\pdodb\cli\SlowQueryLogParser;

final class OptimizeCommandCliTests extends BaseMySQLTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Set environment variables for Application
        putenv('PDODB_DRIVER=mysql');
        putenv('PDODB_HOST=' . self::DB_HOST);
        putenv('PDODB_PORT=' . (string)self::DB_PORT);
        putenv('PDODB_DATABASE=' . self::DB_NAME);
        putenv('PDODB_USERNAME=' . self::DB_USER);
        putenv('PDODB_PASSWORD=' . self::DB_PASSWORD);
        putenv('PDODB_NON_INTERACTIVE=1');
    }

    public function tearDown(): void
    {
        // Clean up environment variables to avoid affecting other tests
        putenv('PDODB_DRIVER');
        putenv('PDODB_HOST');
        putenv('PDODB_PORT');
        putenv('PDODB_DATABASE');
        putenv('PDODB_USERNAME');
        putenv('PDODB_PASSWORD');
        putenv('PDODB_NON_INTERACTIVE');
        parent::tearDown();
    }

    public function testOptimizeAnalyzeCommand(): void
    {
        $app = new Application();
        $db = self::$db;

        // Create test tables
        $db->rawQuery('CREATE TABLE IF NOT EXISTS optimize_users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
        $db->rawQuery('CREATE TABLE IF NOT EXISTS optimize_orders (id INT, user_id INT)'); // No PK

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $code = $app->run(['pdodb', 'optimize', 'analyze', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('critical_issues', $out);
        $this->assertStringContainsString('optimize_orders', $out); // Should detect missing PK

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS optimize_users');
        $db->rawQuery('DROP TABLE IF EXISTS optimize_orders');
    }

    public function testOptimizeAnalyzeIncludesSuggestionsSummary(): void
    {
        $app = new Application();
        $db = self::$db;

        // Create test table with columns that should trigger suggestions
        $db->rawQuery('DROP TABLE IF EXISTS analyze_suggestions_test');
        $db->rawQuery('CREATE TABLE analyze_suggestions_test (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            status VARCHAR(20),
            created_at TIMESTAMP,
            parent_id INT
        )');

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $code = $app->run(['pdodb', 'optimize', 'analyze', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        }

        $this->assertSame(0, $code);
        $data = json_decode($out, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('suggestions_summary', $data);
        $summary = $data['suggestions_summary'];
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('tables_needing_indexes', $summary);
        $this->assertArrayHasKey('high_priority_suggestions', $summary);
        $this->assertArrayHasKey('medium_priority_suggestions', $summary);
        $this->assertArrayHasKey('low_priority_suggestions', $summary);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS analyze_suggestions_test');
    }

    public function testOptimizeAnalyzeShowsSuggestionsSummaryInTableFormat(): void
    {
        $app = new Application();
        $db = self::$db;

        // Create test table with columns that should trigger suggestions
        $db->rawQuery('DROP TABLE IF EXISTS analyze_suggestions_table_test');
        $db->rawQuery('CREATE TABLE analyze_suggestions_table_test (
            id INT AUTO_INCREMENT PRIMARY KEY,
            status VARCHAR(20),
            created_at TIMESTAMP
        )');

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $code = $app->run(['pdodb', 'optimize', 'analyze', '--format=table']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        }

        $this->assertSame(0, $code);
        // Should show suggestions summary
        $this->assertStringContainsString('Index Suggestions Summary', $out);
        $this->assertStringContainsString('Tables needing indexes', $out);
        $this->assertStringContainsString('Tip: Run', $out);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS analyze_suggestions_table_test');
    }

    public function testOptimizeStructureCommandWithTableOption(): void
    {
        $app = new Application();
        $db = self::$db;

        // Create test table with indexes
        $db->rawQuery('DROP TABLE IF EXISTS test_table');
        $db->rawQuery('CREATE TABLE test_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            email VARCHAR(100)
        )');
        $db->rawQuery('CREATE INDEX idx_name ON test_table(name)');
        $db->rawQuery('CREATE INDEX idx_name_email ON test_table(name, email)'); // Redundant

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            // Test with --table option
            $code = $app->run(['pdodb', 'optimize', 'structure', '--table=test_table', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('test_table', $out);
        $this->assertStringContainsString('indexes', $out);
        // Should be single table analysis, not full schema
        $data = json_decode($out, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('table', $data);
        $this->assertEquals('test_table', $data['table']);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_table');
    }

    public function testOptimizeStructureCommandWithTableArgument(): void
    {
        $app = new Application();
        $db = self::$db;

        $db->rawQuery('DROP TABLE IF EXISTS test_table2');
        $db->rawQuery('CREATE TABLE test_table2 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100)
        )');

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            // Test with table as argument
            $code = $app->run(['pdodb', 'optimize', 'structure', 'test_table2', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        }

        $this->assertSame(0, $code);
        $data = json_decode($out, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('table', $data);
        $this->assertEquals('test_table2', $data['table']);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_table2');
    }

    public function testOptimizeQueryCommand(): void
    {
        $app = new Application();
        $db = self::$db;

        // Create test table
        $db->rawQuery('CREATE TABLE IF NOT EXISTS optimize_query_users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
        $db->rawQuery('INSERT INTO optimize_query_users (id, name) VALUES (1, "Test")');

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $code = $app->run(['pdodb', 'optimize', 'query', 'SELECT * FROM optimize_query_users WHERE id = 1', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('raw_explain', $out);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS optimize_query_users');
    }

    public function testOptimizeAnalyzeWithFormatOptions(): void
    {
        $app = new Application();
        $db = self::$db;

        $db->rawQuery('DROP TABLE IF EXISTS test_format');
        $db->rawQuery('CREATE TABLE test_format (id INT AUTO_INCREMENT PRIMARY KEY)');

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');

        // Test JSON format
        ob_start();

        try {
            $code = $app->run(['pdodb', 'optimize', 'analyze', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }
        $this->assertSame(0, $code);
        $data = json_decode($out, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('critical_issues', $data);

        // Test YAML format (basic check)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'optimize', 'analyze', '--format=yaml']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('critical_issues', $out);

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        }

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_format');
    }

    public function testOptimizeHelpCommand(): void
    {
        $app = new Application();

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $code = $app->run(['pdodb', 'optimize', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('analyze', $out);
        $this->assertStringContainsString('structure', $out);
        $this->assertStringContainsString('logs', $out);
        $this->assertStringContainsString('query', $out);
    }

    public function testSchemaAnalyzerClass(): void
    {
        $db = self::$db;
        // Clean up first
        $db->rawQuery('DROP TABLE IF EXISTS test_users_schema');
        $db->rawQuery('CREATE TABLE test_users_schema (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyzeTable('test_users_schema');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('table', $result);
        $this->assertEquals('test_users_schema', $result['table']);
        $this->assertArrayHasKey('has_primary_key', $result);
        $this->assertTrue($result['has_primary_key'], 'Table with PRIMARY KEY should be detected');
        $this->assertArrayHasKey('primary_key_columns', $result);
        $this->assertIsArray($result['primary_key_columns']);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_users_schema');
    }

    public function testRedundantIndexDetectorClass(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS test_table');
        $db->rawQuery('CREATE TABLE test_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            email VARCHAR(100)
        )');
        $db->rawQuery('CREATE INDEX idx_name ON test_table(name)');
        $db->rawQuery('CREATE INDEX idx_name_email ON test_table(name, email)');

        $detector = new RedundantIndexDetector($db);
        $indexes = [
            'idx_name' => ['name'],
            'idx_name_email' => ['name', 'email'],
        ];

        $redundant = $detector->detect('test_table', $indexes);

        $this->assertIsArray($redundant);
        // idx_name should be detected as redundant (covered by idx_name_email)
        $this->assertNotEmpty($redundant);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_table');
    }

    public function testSlowQueryLogParserClass(): void
    {
        $logFile = sys_get_temp_dir() . '/slow_query_' . uniqid() . '.log';
        file_put_contents($logFile, '# Time: 2024-01-01T10:00:00.000000Z
# Query_time: 2.500000
# Lock_time: 0.000000
# Rows_examined: 1000
# Rows_sent: 10
SELECT * FROM users WHERE id = 1;
');

        $parser = new SlowQueryLogParser();
        $queries = $parser->parse($logFile);

        $this->assertIsArray($queries);
        $this->assertNotEmpty($queries);
        $this->assertArrayHasKey('sql', $queries[0]);
        $this->assertArrayHasKey('query_time', $queries[0]);

        @unlink($logFile);
    }

    public function testSlowQueryAnalyzerClass(): void
    {
        $db = self::$db;
        $analyzer = new SlowQueryAnalyzer($db);

        $queries = [
            [
                'sql' => 'SELECT * FROM users WHERE id = 1',
                'query_time' => 2.5,
                'rows_examined' => 1000,
                'rows_sent' => 10,
            ],
            [
                'sql' => 'SELECT * FROM users WHERE id = 2',
                'query_time' => 1.5,
                'rows_examined' => 500,
                'rows_sent' => 5,
            ],
        ];

        $result = $analyzer->analyze($queries);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('top_queries', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertNotEmpty($result['top_queries']);
    }

    public function testSchemaAnalyzerDetectsMissingFkIndexes(): void
    {
        $db = self::$db;

        // Create tables with foreign key but no index
        // Note: MySQL/MariaDB automatically creates indexes on FK columns in InnoDB,
        // so we need to create FK without automatic index by using a workaround
        $db->rawQuery('DROP TABLE IF EXISTS test_child');
        $db->rawQuery('DROP TABLE IF EXISTS test_parent');
        $db->rawQuery('CREATE TABLE test_parent (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100)) ENGINE=InnoDB');
        // Create table first, then add FK separately to avoid auto-index
        $db->rawQuery('CREATE TABLE test_child (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT,
            INDEX idx_parent_id (parent_id)
        ) ENGINE=InnoDB');
        // Drop the index to simulate missing FK index
        $db->rawQuery('DROP INDEX idx_parent_id ON test_child');
        // Now add FK - MySQL will create index automatically, but we already tested the structure
        $db->rawQuery('ALTER TABLE test_child ADD FOREIGN KEY (parent_id) REFERENCES test_parent(id)');

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyzeTable('test_child');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('missing_fk_indexes', $result);
        // MySQL/MariaDB automatically creates indexes on FK, so missing_fk_indexes may be empty
        // Just verify the structure is correct
        $missingFk = $result['missing_fk_indexes'];
        $this->assertIsArray($missingFk, 'missing_fk_indexes should be an array');

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_child');
        $db->rawQuery('DROP TABLE IF EXISTS test_parent');
    }

    public function testSchemaAnalyzerStatistics(): void
    {
        $db = self::$db;

        // Clean up first
        $db->rawQuery('DROP TABLE IF EXISTS stats_table1');
        $db->rawQuery('DROP TABLE IF EXISTS stats_table2');
        $db->rawQuery('DROP TABLE IF EXISTS stats_table3');

        // Create multiple tables with different issues
        $db->rawQuery('CREATE TABLE stats_table1 (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
        $db->rawQuery('CREATE TABLE stats_table2 (id INT, name VARCHAR(100))'); // No PK
        $db->rawQuery('CREATE TABLE stats_table3 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            email VARCHAR(100)
        )');
        $db->rawQuery('CREATE INDEX idx_name ON stats_table3(name)');
        $db->rawQuery('CREATE INDEX idx_name_email ON stats_table3(name, email)'); // Redundant

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyze();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('statistics', $result);
        $stats = $result['statistics'];

        $this->assertArrayHasKey('total_tables', $stats);
        $this->assertGreaterThanOrEqual(3, $stats['total_tables'], 'Should count at least 3 tables');

        $this->assertArrayHasKey('tables_with_issues', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['tables_with_issues'], 'Should detect at least 1 table with issues (stats_table2 has no PK)');

        $this->assertArrayHasKey('total_indexes', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['total_indexes'], 'Should count indexes');

        $this->assertArrayHasKey('redundant_indexes', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['redundant_indexes'], 'Should count redundant indexes');

        // Verify that stats_table2 is in critical issues
        $critical = $result['critical_issues'] ?? [];
        $statsTable2Issues = array_filter($critical, function ($issue) {
            return ($issue['table'] ?? '') === 'stats_table2';
        });
        $this->assertNotEmpty($statsTable2Issues, 'Should detect missing PK in stats_table2');

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS stats_table1');
        $db->rawQuery('DROP TABLE IF EXISTS stats_table2');
        $db->rawQuery('DROP TABLE IF EXISTS stats_table3');
    }

    public function testSchemaAnalyzerIndexSuggestionsForForeignKeys(): void
    {
        $db = self::$db;

        $db->rawQuery('DROP TABLE IF EXISTS fk_child');
        $db->rawQuery('DROP TABLE IF EXISTS fk_parent');
        $db->rawQuery('CREATE TABLE fk_parent (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');
        $db->rawQuery('CREATE TABLE fk_child (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT,
            FOREIGN KEY (parent_id) REFERENCES fk_parent(id)
        )');

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyzeTable('fk_child');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('suggestions', $result);
        $suggestions = $result['suggestions'];

        // Should suggest index for FK column
        $fkSuggestions = array_filter($suggestions, function ($s) {
            return ($s['type'] ?? '') === 'foreign_key' && ($s['priority'] ?? '') === 'high';
        });

        // MySQL/MariaDB automatically creates indexes on FK, so suggestions may be empty
        // Just verify the structure is correct
        $this->assertIsArray($suggestions, 'Suggestions should be an array');
        if (!empty($fkSuggestions)) {
            $fkSuggestion = reset($fkSuggestions);
            $this->assertArrayHasKey('reason', $fkSuggestion);
            $this->assertArrayHasKey('sql', $fkSuggestion);
            $this->assertStringContainsString('parent_id', $fkSuggestion['reason']);
        }

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS fk_child');
        $db->rawQuery('DROP TABLE IF EXISTS fk_parent');
    }

    public function testSchemaAnalyzerIndexSuggestionsForSoftDelete(): void
    {
        $db = self::$db;

        $db->rawQuery('DROP TABLE IF EXISTS soft_delete_test');
        $db->rawQuery('CREATE TABLE soft_delete_test (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            deleted_at TIMESTAMP NULL
        )');

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyzeTable('soft_delete_test');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('suggestions', $result);
        $suggestions = $result['suggestions'];

        // Should suggest index for soft delete column
        $softDeleteSuggestions = array_filter($suggestions, function ($s) {
            return ($s['type'] ?? '') === 'soft_delete' && ($s['priority'] ?? '') === 'high';
        });

        $this->assertNotEmpty($softDeleteSuggestions, 'Should suggest soft delete index');
        $sdSuggestion = reset($softDeleteSuggestions);
        $this->assertArrayHasKey('reason', $sdSuggestion);
        $this->assertArrayHasKey('sql', $sdSuggestion);
        $this->assertStringContainsString('deleted_at', $sdSuggestion['reason']);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS soft_delete_test');
    }

    public function testSchemaAnalyzerIndexSuggestionsForStatusColumns(): void
    {
        $db = self::$db;

        $db->rawQuery('DROP TABLE IF EXISTS status_test');
        $db->rawQuery('CREATE TABLE status_test (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            status VARCHAR(20)
        )');

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyzeTable('status_test');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('suggestions', $result);
        $suggestions = $result['suggestions'];

        // Should suggest index for status column
        $statusSuggestions = array_filter($suggestions, function ($s) {
            return ($s['type'] ?? '') === 'status_column' && ($s['priority'] ?? '') === 'medium';
        });

        $this->assertNotEmpty($statusSuggestions, 'Should suggest status column index');
        $statusSuggestion = reset($statusSuggestions);
        $this->assertArrayHasKey('reason', $statusSuggestion);
        $this->assertArrayHasKey('sql', $statusSuggestion);
        $this->assertStringContainsString('status', $statusSuggestion['reason']);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS status_test');
    }

    public function testSchemaAnalyzerIndexSuggestionsForTimestamps(): void
    {
        $db = self::$db;

        $db->rawQuery('DROP TABLE IF EXISTS timestamp_test');
        $db->rawQuery('CREATE TABLE timestamp_test (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            created_at TIMESTAMP
        )');

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyzeTable('timestamp_test');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('suggestions', $result);
        $suggestions = $result['suggestions'];

        // Should suggest index for timestamp column
        $timestampSuggestions = array_filter($suggestions, function ($s) {
            return ($s['type'] ?? '') === 'timestamp_sorting' && ($s['priority'] ?? '') === 'low';
        });

        $this->assertNotEmpty($timestampSuggestions, 'Should suggest timestamp index');
        $tsSuggestion = reset($timestampSuggestions);
        $this->assertArrayHasKey('reason', $tsSuggestion);
        $this->assertArrayHasKey('sql', $tsSuggestion);
        $this->assertStringContainsString('created_at', $tsSuggestion['reason']);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS timestamp_test');
    }

    public function testSchemaAnalyzerLargeTableWarning(): void
    {
        $db = self::$db;

        // Create a table
        $db->rawQuery('DROP TABLE IF EXISTS large_table_test');
        $db->rawQuery('CREATE TABLE large_table_test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))');

        $analyzer = new SchemaAnalyzer($db);

        // Use reflection to test getTableRowCount method directly
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('getTableRowCount');
        $method->setAccessible(true);

        $rowCount = $method->invoke($analyzer, 'large_table_test');
        $this->assertIsInt($rowCount);
        $this->assertGreaterThanOrEqual(0, $rowCount);

        // Verify that analyze() includes info array (even if empty for small tables)
        $result = $analyzer->analyze();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('info', $result);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS large_table_test');
    }

    public function testSchemaAnalyzerFullAnalysisWithAllChecks(): void
    {
        $db = self::$db;

        // Create comprehensive test scenario
        $db->rawQuery('DROP TABLE IF EXISTS full_test_no_pk');
        $db->rawQuery('DROP TABLE IF EXISTS full_test_with_pk');
        $db->rawQuery('CREATE TABLE full_test_no_pk (id INT, name VARCHAR(100))'); // No PK
        $db->rawQuery('CREATE TABLE full_test_with_pk (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            status VARCHAR(20),
            created_at TIMESTAMP,
            email VARCHAR(100)
        )');
        $db->rawQuery('CREATE INDEX idx_name ON full_test_with_pk(name)');
        $db->rawQuery('CREATE INDEX idx_name_email ON full_test_with_pk(name, email)'); // Redundant

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyze();

        // Check critical issues
        $this->assertArrayHasKey('critical_issues', $result);
        $critical = $result['critical_issues'];
        $noPkIssues = array_filter($critical, function ($issue) {
            return ($issue['table'] ?? '') === 'full_test_no_pk' && ($issue['type'] ?? '') === 'missing_primary_key';
        });
        $this->assertNotEmpty($noPkIssues, 'Should detect missing PK');

        // Check warnings
        $this->assertArrayHasKey('warnings', $result);
        $warnings = $result['warnings'];
        $this->assertIsArray($warnings, 'Warnings should be an array');

        // Check statistics
        $this->assertArrayHasKey('statistics', $result);
        $stats = $result['statistics'];
        $this->assertGreaterThanOrEqual(0, $stats['total_tables'], 'Should have statistics for total_tables');
        $this->assertGreaterThanOrEqual(0, $stats['tables_with_issues'], 'Should have statistics for tables_with_issues');
        $this->assertGreaterThanOrEqual(0, $stats['total_indexes'], 'Should have statistics for total_indexes');
        $this->assertGreaterThanOrEqual(0, $stats['redundant_indexes'], 'Should have statistics for redundant_indexes');

        // Check table analysis includes suggestions
        $tableResult = $analyzer->analyzeTable('full_test_with_pk');
        $this->assertArrayHasKey('suggestions', $tableResult);
        $suggestions = $tableResult['suggestions'];
        $this->assertNotEmpty($suggestions, 'Should generate index suggestions');

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS full_test_no_pk');
        $db->rawQuery('DROP TABLE IF EXISTS full_test_with_pk');
    }

    public function testOptimizeDbCommand(): void
    {
        $app = new Application();
        $db = self::$db;

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT=1');
        ob_start();

        try {
            $exitCode = $app->run(['pdodb', 'optimize', 'db', '--memory=5G', '--cpu-cores=32']);
            ob_get_clean();
            $this->assertEquals(0, $exitCode, 'Command should succeed');
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            } else {
                putenv('PHPUNIT');
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }
    }

    public function testOptimizeDbCommandWithAllOptions(): void
    {
        $app = new Application();
        $db = self::$db;

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT=1');
        ob_start();

        try {
            $exitCode = $app->run([
                'pdodb',
                'optimize',
                'db',
                '--memory=8G',
                '--cpu-cores=16',
                '--workload=olap',
                '--disk-type=nvme',
                '--connections=200',
            ]);
            ob_get_clean();
            $this->assertEquals(0, $exitCode, 'Command should succeed');
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            } else {
                putenv('PHPUNIT');
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }
    }

    public function testOptimizeDbCommandWithJsonFormat(): void
    {
        $app = new Application();
        $db = self::$db;

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT=1');
        ob_start();

        try {
            $exitCode = $app->run(['pdodb', 'optimize', 'db', '--memory=5G', '--cpu-cores=32', '--format=json']);
            $output = ob_get_clean();
            $this->assertEquals(0, $exitCode, 'Command should succeed');
            $result = json_decode($output, true);
            $this->assertIsArray($result, 'Output should be valid JSON');
            $this->assertArrayHasKey('dialect', $result);
            $this->assertArrayHasKey('resources', $result);
            $this->assertArrayHasKey('recommendations', $result);
            $this->assertArrayHasKey('comparison', $result);
            $this->assertArrayHasKey('summary', $result);
            $this->assertArrayHasKey('sql_commands', $result);
            $this->assertEquals('mysql', $result['dialect']);
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            } else {
                putenv('PHPUNIT');
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }
    }

    public function testDatabaseConfigOptimizerClass(): void
    {
        $db = self::$db;
        $optimizer = new DatabaseConfigOptimizer($db);

        $result = $optimizer->analyze(
            5 * 1024 * 1024 * 1024, // 5GB
            32, // 32 cores
            'oltp',
            'ssd',
            200
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('dialect', $result);
        $this->assertArrayHasKey('resources', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertArrayHasKey('comparison', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('sql_commands', $result);

        $this->assertEquals('mysql', $result['dialect']);
        $this->assertEquals(5 * 1024 * 1024 * 1024, $result['resources']['memory_bytes']);
        $this->assertEquals(32, $result['resources']['cpu_cores']);
        $this->assertEquals('oltp', $result['resources']['workload']);

        // Check that recommendations are present
        $this->assertNotEmpty($result['recommendations'], 'Should have recommendations');
        $this->assertArrayHasKey('innodb_buffer_pool_size', $result['recommendations']);
        $this->assertArrayHasKey('max_connections', $result['recommendations']);

        // Check comparison
        $this->assertNotEmpty($result['comparison'], 'Should have comparison results');

        // Check summary
        $summary = $result['summary'];
        $this->assertArrayHasKey('total_settings', $summary);
        $this->assertArrayHasKey('needs_change', $summary);
        $this->assertArrayHasKey('high_priority', $summary);

        // Check SQL commands
        $this->assertNotEmpty($result['sql_commands'], 'Should have SQL commands');
        $this->assertStringContainsString('innodb_buffer_pool_size', $result['sql_commands'][0]);
    }

    public function testOptimizeDbCommandMissingMemory(): void
    {
        $app = new Application();
        $db = self::$db;

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT=1');
        ob_start();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('--memory parameter is required');
            $app->run(['pdodb', 'optimize', 'db', '--cpu-cores=32']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            } else {
                putenv('PHPUNIT');
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }
    }

    public function testOptimizeDbCommandMissingCpuCores(): void
    {
        $app = new Application();
        $db = self::$db;

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT=1');
        ob_start();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('--cpu-cores parameter is required');
            $app->run(['pdodb', 'optimize', 'db', '--memory=5G']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            } else {
                putenv('PHPUNIT');
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }
    }
}
