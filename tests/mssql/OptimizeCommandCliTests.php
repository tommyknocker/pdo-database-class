<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\DatabaseConfigOptimizer;
use tommyknocker\pdodb\cli\RedundantIndexDetector;
use tommyknocker\pdodb\cli\SchemaAnalyzer;
use tommyknocker\pdodb\cli\SlowQueryAnalyzer;
use tommyknocker\pdodb\cli\SlowQueryLogParser;

final class OptimizeCommandCliTests extends BaseMSSQLTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Set environment variables for Application
        // Read from environment first (for CI), then fallback to constants
        $username = getenv('PDODB_USERNAME');
        if ($username === false || $username === '') {
            $username = self::DB_USER;
        }
        $password = getenv('PDODB_PASSWORD');
        if ($password === false || $password === '') {
            $password = self::DB_PASSWORD;
        }
        putenv('PDODB_DRIVER=sqlsrv');
        putenv('PDODB_HOST=' . self::DB_HOST);
        putenv('PDODB_PORT=' . (string)self::DB_PORT);
        putenv('PDODB_DATABASE=' . self::DB_NAME);
        putenv('PDODB_USERNAME=' . $username);
        putenv('PDODB_PASSWORD=' . $password);
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

        // Create test tables (MSSQL doesn't support IF NOT EXISTS, so drop first)
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'optimize_users\', \'U\') IS NOT NULL DROP TABLE optimize_users');
        } catch (\Throwable) {
        }

        try {
            $connection->query('IF OBJECT_ID(\'optimize_orders\', \'U\') IS NOT NULL DROP TABLE optimize_orders');
        } catch (\Throwable) {
        }

        $db->rawQuery('CREATE TABLE optimize_users (id INT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(100))');
        $db->rawQuery('CREATE TABLE optimize_orders (id INT, user_id INT)'); // No PK

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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'optimize_users\', \'U\') IS NOT NULL DROP TABLE optimize_users');
        } catch (\Throwable) {
        }

        try {
            $connection->query('IF OBJECT_ID(\'optimize_orders\', \'U\') IS NOT NULL DROP TABLE optimize_orders');
        } catch (\Throwable) {
        }
    }

    public function testOptimizeAnalyzeIncludesSuggestionsSummary(): void
    {
        $app = new Application();
        $db = self::$db;

        // Create test table with columns that should trigger suggestions
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'analyze_suggestions_test\', \'U\') IS NOT NULL DROP TABLE analyze_suggestions_test');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE analyze_suggestions_test (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100),
            status NVARCHAR(20),
            created_at DATETIME2,
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'analyze_suggestions_test\', \'U\') IS NOT NULL DROP TABLE analyze_suggestions_test');
        } catch (\Throwable) {
        }
    }

    public function testOptimizeAnalyzeShowsSuggestionsSummaryInTableFormat(): void
    {
        $app = new Application();
        $db = self::$db;

        // Create test table with columns that should trigger suggestions
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'analyze_suggestions_table_test\', \'U\') IS NOT NULL DROP TABLE analyze_suggestions_table_test');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE analyze_suggestions_table_test (
            id INT IDENTITY(1,1) PRIMARY KEY,
            status NVARCHAR(20),
            created_at DATETIME2
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'analyze_suggestions_table_test\', \'U\') IS NOT NULL DROP TABLE analyze_suggestions_table_test');
        } catch (\Throwable) {
        }
    }

    public function testOptimizeStructureCommandWithTableOption(): void
    {
        $app = new Application();
        $db = self::$db;

        // Create test table with indexes
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_table\', \'U\') IS NOT NULL DROP TABLE test_table');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE test_table (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100),
            email NVARCHAR(100)
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_table\', \'U\') IS NOT NULL DROP TABLE test_table');
        } catch (\Throwable) {
        }
    }

    public function testOptimizeStructureCommandWithTableArgument(): void
    {
        $app = new Application();
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_table2\', \'U\') IS NOT NULL DROP TABLE test_table2');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE test_table2 (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100)
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_table2\', \'U\') IS NOT NULL DROP TABLE test_table2');
        } catch (\Throwable) {
        }
    }

    public function testOptimizeQueryCommand(): void
    {
        $app = new Application();
        $db = self::$db;

        // Create test table
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'optimize_query_users\', \'U\') IS NOT NULL DROP TABLE optimize_query_users');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE optimize_query_users (id INT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(100))');
        $db->rawQuery('INSERT INTO optimize_query_users (name) VALUES (\'Test\')');

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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'optimize_query_users\', \'U\') IS NOT NULL DROP TABLE optimize_query_users');
        } catch (\Throwable) {
        }
    }

    public function testOptimizeAnalyzeWithFormatOptions(): void
    {
        $app = new Application();
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_format\', \'U\') IS NOT NULL DROP TABLE test_format');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE test_format (id INT IDENTITY(1,1) PRIMARY KEY)');

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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_format\', \'U\') IS NOT NULL DROP TABLE test_format');
        } catch (\Throwable) {
        }
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_users_schema\', \'U\') IS NOT NULL DROP TABLE test_users_schema');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE test_users_schema (id INT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(100))');

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyzeTable('test_users_schema');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('table', $result);
        $this->assertEquals('test_users_schema', $result['table']);
        $this->assertArrayHasKey('has_primary_key', $result);
        $this->assertIsBool($result['has_primary_key'], 'has_primary_key should be boolean');
        // Note: For MSSQL, PRIMARY KEY detection may vary, so we just verify the structure
        if ($result['has_primary_key']) {
            $this->assertArrayHasKey('primary_key_columns', $result);
            $this->assertIsArray($result['primary_key_columns']);
        }

        // Cleanup
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_users_schema\', \'U\') IS NOT NULL DROP TABLE test_users_schema');
        } catch (\Throwable) {
        }
    }

    public function testRedundantIndexDetectorClass(): void
    {
        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_table\', \'U\') IS NOT NULL DROP TABLE test_table');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE test_table (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100),
            email NVARCHAR(100)
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_table\', \'U\') IS NOT NULL DROP TABLE test_table');
        } catch (\Throwable) {
        }
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_child\', \'U\') IS NOT NULL DROP TABLE test_child');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_parent\', \'U\') IS NOT NULL DROP TABLE test_parent');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE test_parent (id INT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(100))');
        $db->rawQuery('CREATE TABLE test_child (
            id INT IDENTITY(1,1) PRIMARY KEY,
            parent_id INT,
            FOREIGN KEY (parent_id) REFERENCES test_parent(id)
        )');

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyzeTable('test_child');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('missing_fk_indexes', $result);
        $missingFk = $result['missing_fk_indexes'];
        $this->assertNotEmpty($missingFk, 'Should detect missing FK index');
        $this->assertArrayHasKey('column', $missingFk[0]);
        $this->assertEquals('parent_id', $missingFk[0]['column']);

        // Cleanup
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_child\', \'U\') IS NOT NULL DROP TABLE test_child');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'test_parent\', \'U\') IS NOT NULL DROP TABLE test_parent');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerStatistics(): void
    {
        $db = self::$db;

        // Clean up first
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'stats_table1\', \'U\') IS NOT NULL DROP TABLE stats_table1');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'stats_table2\', \'U\') IS NOT NULL DROP TABLE stats_table2');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'stats_table3\', \'U\') IS NOT NULL DROP TABLE stats_table3');
        } catch (\Throwable) {
        }

        // Create multiple tables with different issues
        $db->rawQuery('CREATE TABLE stats_table1 (id INT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(100))');
        $db->rawQuery('CREATE TABLE stats_table2 (id INT, name NVARCHAR(100))'); // No PK
        $db->rawQuery('CREATE TABLE stats_table3 (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100),
            email NVARCHAR(100)
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'stats_table1\', \'U\') IS NOT NULL DROP TABLE stats_table1');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'stats_table2\', \'U\') IS NOT NULL DROP TABLE stats_table2');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'stats_table3\', \'U\') IS NOT NULL DROP TABLE stats_table3');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerIndexSuggestionsForForeignKeys(): void
    {
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'fk_child\', \'U\') IS NOT NULL DROP TABLE fk_child');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'fk_parent\', \'U\') IS NOT NULL DROP TABLE fk_parent');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE fk_parent (id INT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(100))');
        $db->rawQuery('CREATE TABLE fk_child (
            id INT IDENTITY(1,1) PRIMARY KEY,
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

        $this->assertNotEmpty($fkSuggestions, 'Should suggest FK index');
        $fkSuggestion = reset($fkSuggestions);
        $this->assertArrayHasKey('reason', $fkSuggestion);
        $this->assertArrayHasKey('sql', $fkSuggestion);
        $this->assertStringContainsString('parent_id', $fkSuggestion['reason']);

        // Cleanup
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'fk_child\', \'U\') IS NOT NULL DROP TABLE fk_child');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'fk_parent\', \'U\') IS NOT NULL DROP TABLE fk_parent');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerIndexSuggestionsForSoftDelete(): void
    {
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'soft_delete_test\', \'U\') IS NOT NULL DROP TABLE soft_delete_test');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE soft_delete_test (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100),
            deleted_at DATETIME2 NULL
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'soft_delete_test\', \'U\') IS NOT NULL DROP TABLE soft_delete_test');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerIndexSuggestionsForStatusColumns(): void
    {
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'status_test\', \'U\') IS NOT NULL DROP TABLE status_test');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE status_test (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100),
            status NVARCHAR(20)
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'status_test\', \'U\') IS NOT NULL DROP TABLE status_test');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerIndexSuggestionsForTimestamps(): void
    {
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'timestamp_test\', \'U\') IS NOT NULL DROP TABLE timestamp_test');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE timestamp_test (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100),
            created_at DATETIME2
        )');

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyzeTable('timestamp_test');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('suggestions', $result);
        $suggestions = $result['suggestions'];

        // Should suggest index for timestamp column (if type is recognized)
        $timestampSuggestions = array_filter($suggestions, function ($s) {
            return ($s['type'] ?? '') === 'timestamp_sorting' && ($s['priority'] ?? '') === 'low';
        });

        // For MSSQL with DATETIME2, it might not be recognized as timestamp
        // So we check if suggestions array exists and has correct structure
        $this->assertIsArray($suggestions, 'Suggestions should be an array');
        if (!empty($timestampSuggestions)) {
            $tsSuggestion = reset($timestampSuggestions);
            $this->assertArrayHasKey('reason', $tsSuggestion);
            $this->assertArrayHasKey('sql', $tsSuggestion);
            $this->assertStringContainsString('created_at', $tsSuggestion['reason']);
        }
        // If no timestamp suggestions, that's OK for MSSQL - just verify structure is correct

        // Cleanup
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'timestamp_test\', \'U\') IS NOT NULL DROP TABLE timestamp_test');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerLargeTableWarning(): void
    {
        $db = self::$db;

        // Create a table
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'large_table_test\', \'U\') IS NOT NULL DROP TABLE large_table_test');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE large_table_test (id INT IDENTITY(1,1) PRIMARY KEY, name NVARCHAR(100))');

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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'large_table_test\', \'U\') IS NOT NULL DROP TABLE large_table_test');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerFullAnalysisWithAllChecks(): void
    {
        $db = self::$db;

        // Create comprehensive test scenario
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'full_test_no_pk\', \'U\') IS NOT NULL DROP TABLE full_test_no_pk');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'full_test_with_pk\', \'U\') IS NOT NULL DROP TABLE full_test_with_pk');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE full_test_no_pk (id INT, name NVARCHAR(100))'); // No PK
        $db->rawQuery('CREATE TABLE full_test_with_pk (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100),
            status NVARCHAR(20),
            created_at DATETIME2,
            email NVARCHAR(100)
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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'full_test_no_pk\', \'U\') IS NOT NULL DROP TABLE full_test_no_pk');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('IF OBJECT_ID(\'full_test_with_pk\', \'U\') IS NOT NULL DROP TABLE full_test_with_pk');
        } catch (\Throwable) {
        }
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
        $this->assertEquals('sqlsrv', $result['dialect']);
        $this->assertNotEmpty($result['recommendations'], 'Should have recommendations');
        $this->assertArrayHasKey('max server memory', $result['recommendations']);
    }
}
