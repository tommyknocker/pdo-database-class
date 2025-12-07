<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\RedundantIndexDetector;
use tommyknocker\pdodb\cli\SchemaAnalyzer;
use tommyknocker\pdodb\cli\SlowQueryAnalyzer;
use tommyknocker\pdodb\cli\SlowQueryLogParser;

final class OptimizeCommandCliTests extends BaseOracleTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Set environment variables for Application
        $host = getenv('PDODB_HOST') ?: self::DB_HOST;
        $port = (int)(getenv('PDODB_PORT') ?: (string)self::DB_PORT);
        $user = getenv('PDODB_USERNAME') ?: self::DB_USER;
        $password = getenv('PDODB_PASSWORD') ?: self::DB_PASSWORD;
        $serviceName = getenv('PDODB_SERVICE_NAME') ?: getenv('PDODB_SID') ?: self::DB_SERVICE_NAME;
        putenv('PDODB_DRIVER=oci');
        putenv('PDODB_HOST=' . $host);
        putenv('PDODB_PORT=' . (string)$port);
        putenv('PDODB_SERVICE_NAME=' . $serviceName);
        putenv('PDODB_USERNAME=' . $user);
        putenv('PDODB_PASSWORD=' . $password);
        putenv('PDODB_NON_INTERACTIVE=1');
    }

    public function tearDown(): void
    {
        // Clean up environment variables to avoid affecting other tests
        putenv('PDODB_DRIVER');
        putenv('PDODB_HOST');
        putenv('PDODB_PORT');
        putenv('PDODB_SERVICE_NAME');
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
        $db->rawQuery('CREATE TABLE "optimize_users" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');
        $db->rawQuery('CREATE TABLE "optimize_orders" ("ID" NUMBER, "USER_ID" NUMBER)'); // No PK

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
        // Verify that table names are correctly extracted (not empty)
        $data = json_decode($out, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('critical_issues', $data);
        $critical = $data['critical_issues'];
        // Should detect optimize_orders table with missing PK
        $foundOptimizeOrders = false;
        foreach ($critical as $issue) {
            if (isset($issue['table']) && $issue['table'] === 'optimize_orders') {
                $foundOptimizeOrders = true;
                break;
            }
        }
        $this->assertTrue($foundOptimizeOrders, 'Should detect optimize_orders table with missing PK');
        // Verify no empty table names
        foreach ($critical as $issue) {
            if (isset($issue['table'])) {
                $this->assertNotEmpty($issue['table'], 'Table name should not be empty');
            }
        }

        // Cleanup
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "optimize_users" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "optimize_orders" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
    }

    public function testListTablesReturnsNonEmptyNames(): void
    {
        $db = self::$db;
        $dialect = $db->schema()->getDialect();

        // Create a test table
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "test_list_tables" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "test_list_tables" ("ID" NUMBER PRIMARY KEY)');

        // Test listTables method
        $tables = $dialect->listTables($db);

        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables, 'Should return at least one table');

        // Verify all table names are non-empty
        foreach ($tables as $tableName) {
            $this->assertIsString($tableName, 'Table name should be a string');
            $this->assertNotEmpty($tableName, 'Table name should not be empty');
        }

        // Verify our test table is in the list
        $found = false;
        foreach ($tables as $tableName) {
            if (strtoupper($tableName) === 'TEST_LIST_TABLES') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should find test_list_tables in the list');

        // Cleanup
        try {
            $connection->query('DROP TABLE "test_list_tables" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
    }

    public function testGetPrimaryKeyColumnsDetectsPrimaryKey(): void
    {
        $db = self::$db;
        $dialect = $db->schema()->getDialect();

        // Create a table with PRIMARY KEY
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "test_pk_detection" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "test_pk_detection" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');

        // Test getPrimaryKeyColumns method
        // Try both quoted and unquoted table name (Oracle stores quoted names case-sensitively)
        $pkColumns = $dialect->getPrimaryKeyColumns($db, 'test_pk_detection');

        // If empty, try uppercase version
        if (empty($pkColumns)) {
            $pkColumns = $dialect->getPrimaryKeyColumns($db, 'TEST_PK_DETECTION');
        }

        $this->assertIsArray($pkColumns);
        $this->assertNotEmpty($pkColumns, 'Should detect PRIMARY KEY columns');
        // Column name might be in different case
        $foundId = false;
        foreach ($pkColumns as $col) {
            if (strtoupper($col) === 'ID') {
                $foundId = true;
                break;
            }
        }
        $this->assertTrue($foundId, 'Should detect ID as PRIMARY KEY column');

        // Test with SchemaAnalyzer
        $analyzer = new SchemaAnalyzer($db);
        // Try both quoted and unquoted table name
        $result = $analyzer->analyzeTable('test_pk_detection');
        if (isset($result['error'])) {
            $result = $analyzer->analyzeTable('TEST_PK_DETECTION');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('has_primary_key', $result);
        $this->assertTrue($result['has_primary_key'], 'Table with PRIMARY KEY should be detected');
        $this->assertArrayHasKey('primary_key_columns', $result);
        $this->assertNotEmpty($result['primary_key_columns'], 'Should have PRIMARY KEY columns');
        $this->assertContains('ID', $result['primary_key_columns'], 'Should detect ID as PRIMARY KEY column');

        // Cleanup
        try {
            $connection->query('DROP TABLE "test_pk_detection" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
    }

    public function testOptimizeAnalyzeDetectsPrimaryKeysCorrectly(): void
    {
        $app = new Application();
        $db = self::$db;

        // Create tables: one with PK, one without
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "test_with_pk" CASCADE CONSTRAINTS');
            $connection->query('DROP TABLE "test_without_pk" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "test_with_pk" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');
        $db->rawQuery('CREATE TABLE "test_without_pk" ("ID" NUMBER, "NAME" VARCHAR2(100))'); // No PK

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
        $this->assertArrayHasKey('critical_issues', $data);

        $critical = $data['critical_issues'];

        // Should detect test_without_pk as having no PK
        $foundWithoutPk = false;
        $foundWithPk = false;
        foreach ($critical as $issue) {
            if (isset($issue['table'])) {
                $tableName = $issue['table'];
                if (strtoupper($tableName) === 'TEST_WITHOUT_PK') {
                    $foundWithoutPk = true;
                }
                if (strtoupper($tableName) === 'TEST_WITH_PK') {
                    $foundWithPk = true;
                }
            }
        }

        $this->assertTrue($foundWithoutPk, 'Should detect test_without_pk as having no PRIMARY KEY');
        $this->assertFalse($foundWithPk, 'Should NOT detect test_with_pk as having no PRIMARY KEY (it has PK)');

        // Cleanup
        try {
            $connection->query('DROP TABLE "test_with_pk" CASCADE CONSTRAINTS');
            $connection->query('DROP TABLE "test_without_pk" CASCADE CONSTRAINTS');
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
            $connection->query('DROP TABLE "analyze_suggestions_test" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "analyze_suggestions_test" (
            "ID" NUMBER PRIMARY KEY,
            "NAME" VARCHAR2(100),
            "STATUS" VARCHAR2(20),
            "CREATED_AT" TIMESTAMP,
            "PARENT_ID" NUMBER
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
            $connection->query('DROP TABLE "analyze_suggestions_test" CASCADE CONSTRAINTS');
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
            $connection->query('DROP TABLE "analyze_suggestions_table_test" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "analyze_suggestions_table_test" (
            "ID" NUMBER PRIMARY KEY,
            "STATUS" VARCHAR2(20),
            "CREATED_AT" TIMESTAMP
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
            $connection->query('DROP TABLE "analyze_suggestions_table_test" CASCADE CONSTRAINTS');
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
            $connection->query('DROP TABLE "test_table" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "test_table" (
            "ID" NUMBER PRIMARY KEY,
            "NAME" VARCHAR2(100),
            "EMAIL" VARCHAR2(100)
        )');
        // Drop indexes if they exist
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP INDEX "idx_name"');
        } catch (\Throwable) {
        }

        try {
            $connection->query('DROP INDEX "idx_name_email"');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE INDEX "idx_name" ON "test_table"("NAME")');
        $db->rawQuery('CREATE INDEX "idx_name_email" ON "test_table"("NAME", "EMAIL")'); // Redundant

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
            $connection->query('DROP TABLE "test_table" CASCADE CONSTRAINTS');
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
            $connection->query('DROP TABLE "test_table2" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "test_table2" (
            "ID" NUMBER PRIMARY KEY,
            "NAME" VARCHAR2(100)
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
            $connection->query('DROP TABLE "test_table2" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
    }

    public function testOptimizeQueryCommand(): void
    {
        $app = new Application();
        // Use self::$db - Application uses env variables set in setUp which point to the same DB
        $db = self::$db;

        // Create test table
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "optimize_query_users" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "optimize_query_users" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');
        $db->rawQuery('INSERT INTO "optimize_query_users" ("ID", "NAME") VALUES (1, \'Test\')');

        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            // Use quoted uppercase names for Oracle
            $code = $app->run(['pdodb', 'optimize', 'query', 'SELECT * FROM "optimize_query_users" WHERE "ID" = 1', '--format=json']);
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
            $connection->query('DROP TABLE "optimize_query_users" CASCADE CONSTRAINTS');
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
            $connection->query('DROP TABLE "test_format" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "test_format" ("ID" NUMBER PRIMARY KEY)');

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
            $connection->query('DROP TABLE "test_format" CASCADE CONSTRAINTS');
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
            $connection->query('DROP TABLE "test_users_schema" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "test_users_schema" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');

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
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "test_users_schema" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
    }

    public function testRedundantIndexDetectorClass(): void
    {
        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "test_table" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "test_table" (
            "ID" NUMBER PRIMARY KEY,
            "NAME" VARCHAR2(100),
            "EMAIL" VARCHAR2(100)
        )');
        $db->rawQuery('CREATE INDEX "idx_name" ON "test_table"("NAME")');
        $db->rawQuery('CREATE INDEX "idx_name_email" ON "test_table"("NAME", "EMAIL")');

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
            $connection->query('DROP TABLE "test_table" CASCADE CONSTRAINTS');
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
            $connection->query('DROP TABLE "test_child" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "test_parent" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "test_parent" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');
        $db->rawQuery('CREATE TABLE "test_child" (
            "ID" NUMBER PRIMARY KEY,
            "PARENT_ID" NUMBER,
            CONSTRAINT fk_test_child_parent FOREIGN KEY ("PARENT_ID") REFERENCES "test_parent"("ID")
        )');

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyzeTable('test_child');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('missing_fk_indexes', $result);
        $missingFk = $result['missing_fk_indexes'];
        $this->assertNotEmpty($missingFk, 'Should detect missing FK index');
        $this->assertArrayHasKey('column', $missingFk[0]);
        // Oracle returns column names in uppercase, so check lowercase
        $this->assertEquals('parent_id', strtolower($missingFk[0]['column']));

        // Cleanup
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "test_child" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "test_parent" CASCADE CONSTRAINTS');
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
            $connection->query('DROP TABLE "stats_table1" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "stats_table2" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "stats_table3" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }

        // Create multiple tables with different issues
        $db->rawQuery('CREATE TABLE "stats_table1" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');
        $db->rawQuery('CREATE TABLE "stats_table2" ("ID" NUMBER, "NAME" VARCHAR2(100))'); // No PK
        $db->rawQuery('CREATE TABLE "stats_table3" (
            "ID" NUMBER PRIMARY KEY,
            "NAME" VARCHAR2(100),
            "EMAIL" VARCHAR2(100)
        )');
        // Drop indexes if they exist
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP INDEX idx_name');
        } catch (\Throwable) {
        }

        try {
            $connection->query('DROP INDEX idx_name_email');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE INDEX idx_name ON "stats_table3"("NAME")');
        $db->rawQuery('CREATE INDEX idx_name_email ON "stats_table3"("NAME", "EMAIL")'); // Redundant

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
            $connection->query('DROP TABLE "stats_table1" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "stats_table2" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "stats_table3" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerIndexSuggestionsForForeignKeys(): void
    {
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "fk_child" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "fk_parent" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "fk_parent" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');
        $db->rawQuery('CREATE TABLE "fk_child" (
            "ID" NUMBER PRIMARY KEY,
            "PARENT_ID" NUMBER,
            CONSTRAINT fk_child_parent FOREIGN KEY ("PARENT_ID") REFERENCES "fk_parent"("ID")
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
        // Oracle returns column names in uppercase, so check lowercase
        $this->assertStringContainsString('parent_id', strtolower($fkSuggestion['reason']));

        // Cleanup
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "fk_child" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "fk_parent" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerIndexSuggestionsForSoftDelete(): void
    {
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "soft_delete_test" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "soft_delete_test" (
            "ID" NUMBER PRIMARY KEY,
            "NAME" VARCHAR2(100),
            "DELETED_AT" TIMESTAMP NULL
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
        // Oracle returns column names in uppercase, so check lowercase
        $this->assertStringContainsString('deleted_at', strtolower($sdSuggestion['reason']));

        // Cleanup
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "soft_delete_test" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerIndexSuggestionsForStatusColumns(): void
    {
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "status_test" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "status_test" (
            "ID" NUMBER PRIMARY KEY,
            "NAME" VARCHAR2(100),
            "STATUS" VARCHAR2(20)
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
        // Oracle returns column names in uppercase, so check lowercase
        $this->assertStringContainsString('status', strtolower($statusSuggestion['reason']));

        // Cleanup
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "status_test" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
    }

    public function testSchemaAnalyzerIndexSuggestionsForTimestamps(): void
    {
        $db = self::$db;

        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "timestamp_test" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "timestamp_test" (
            "ID" NUMBER PRIMARY KEY,
            "NAME" VARCHAR2(100),
            "CREATED_AT" TIMESTAMP
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
        // Oracle returns column names in uppercase, so check lowercase
        $this->assertStringContainsString('created_at', strtolower($tsSuggestion['reason']));

        // Cleanup
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "timestamp_test" CASCADE CONSTRAINTS');
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
            $connection->query('DROP TABLE "large_table_test" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "large_table_test" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');

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
            $connection->query('DROP TABLE "large_table_test" CASCADE CONSTRAINTS');
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
            $connection->query('DROP TABLE "full_test_no_pk" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "full_test_with_pk" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $db->rawQuery('CREATE TABLE "full_test_no_pk" ("ID" NUMBER, "NAME" VARCHAR2(100))'); // No PK
        $db->rawQuery('CREATE TABLE "full_test_with_pk" (
            "ID" NUMBER PRIMARY KEY,
            "NAME" VARCHAR2(100),
            "STATUS" VARCHAR2(20),
            "CREATED_AT" TIMESTAMP,
            "EMAIL" VARCHAR2(100)
        )');
        $db->rawQuery('CREATE INDEX "idx_name" ON "full_test_with_pk"("NAME")');
        $db->rawQuery('CREATE INDEX "idx_name_email" ON "full_test_with_pk"("NAME", "EMAIL")'); // Redundant

        $analyzer = new SchemaAnalyzer($db);
        $result = $analyzer->analyze();

        // Check critical issues
        $this->assertArrayHasKey('critical_issues', $result);
        $critical = $result['critical_issues'];
        $noPkIssues = array_filter($critical, function ($issue) {
            return strtolower($issue['table'] ?? '') === 'full_test_no_pk' && ($issue['type'] ?? '') === 'missing_primary_key';
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
            $connection->query('DROP TABLE "full_test_no_pk" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
        $connection = $db->connection;
        assert($connection !== null);

        try {
            $connection->query('DROP TABLE "full_test_with_pk" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }
    }
}
