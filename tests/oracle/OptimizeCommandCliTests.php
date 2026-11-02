<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\commands\OptimizeCommand;
use tommyknocker\pdodb\cli\DatabaseConfigOptimizer;
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
        parent::tearDown();
    }

    public function testOptimizeAnalyzeCommand(): void
    {
        $db = self::$db;
        $tables = ['optimize_users', 'optimize_orders'];
        foreach ($tables as $table) {
            try {
                $db->rawQuery('DROP TABLE "' . $table . '" CASCADE CONSTRAINTS');
            } catch (\Throwable) {
                // The fixture may not exist yet.
            }
        }

        try {
            $db->rawQuery('CREATE TABLE "optimize_users" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100), "STATUS" VARCHAR2(20), "CREATED_AT" TIMESTAMP)');
            $db->rawQuery('CREATE TABLE "optimize_orders" ("ID" NUMBER, "USER_ID" NUMBER)');
            $db->rawQuery('CREATE INDEX PDODB_OPT_NAME ON "optimize_users" ("NAME")');
            $db->rawQuery('CREATE INDEX PDODB_OPT_NAME_STATUS ON "optimize_users" ("NAME", "STATUS")');
            ob_start();

            try {
                $code = (new Application())->run(['pdodb', 'optimize', 'analyze', '--format=json']);
                $out = ob_get_clean();
            } finally {
                if (ob_get_level() > 0 && !isset($out)) {
                    ob_end_clean();
                }
            }

            $this->assertSame(0, $code);
            $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('critical_issues', $data);
            $issues = array_filter($data['critical_issues'], static fn (array $issue): bool => ($issue['type'] ?? '') === 'missing_primary_key');
            $issueTables = array_map(static fn (array $issue): string => strtolower($issue['table']), $issues);
            $this->assertContains('optimize_orders', $issueTables);
            $this->assertNotContains('optimize_users', $issueTables);
            $this->assertNotContains('', $issueTables);
            $this->assertGreaterThanOrEqual(2, $data['statistics']['total_tables']);
            $this->assertGreaterThanOrEqual(1, $data['statistics']['tables_with_issues']);
            $this->assertIsInt($data['statistics']['total_indexes']);
            $this->assertIsInt($data['statistics']['redundant_indexes']);
            $redundant = array_filter($data['warnings'], static fn (array $issue): bool => strtolower($issue['table'] ?? '') === 'optimize_users' && ($issue['type'] ?? '') === 'redundant_index');
            $this->assertNotEmpty($redundant);
            $this->assertGreaterThanOrEqual(1, $data['statistics']['redundant_indexes']);
            $this->assertIsArray($data['info']);
            foreach (['tables_needing_indexes', 'high_priority_suggestions', 'medium_priority_suggestions', 'low_priority_suggestions'] as $key) {
                $this->assertArrayHasKey($key, $data['suggestions_summary']);
            }
            $this->assertGreaterThanOrEqual(1, $data['suggestions_summary']['tables_needing_indexes']);
        } finally {
            foreach ($tables as $table) {
                try {
                    $db->rawQuery('DROP TABLE "' . $table . '" CASCADE CONSTRAINTS');
                } catch (\Throwable) {
                    // Keep cleanup independent for both fixtures.
                }
            }
        }
    }

    public function testAnalyzeReportFormats(): void
    {
        $command = new class () extends OptimizeCommand {
            public function renderReport(array $result): void
            {
                $this->printAnalyzeReport($result);
            }

            public function renderYaml(array $result): void
            {
                $this->printYaml($result);
            }
        };
        $result = [
            'critical_issues' => [['message' => 'Table orders has no PRIMARY KEY']],
            'warnings' => [],
            'info' => [],
            'statistics' => ['total_tables' => 2],
            'suggestions_summary' => [
                'tables_needing_indexes' => 1,
                'high_priority_suggestions' => 0,
                'medium_priority_suggestions' => 1,
                'low_priority_suggestions' => 0,
                'foreign_key_indexes_needed' => 0,
                'soft_delete_indexes_needed' => 0,
                'status_column_indexes_needed' => 1,
                'timestamp_indexes_needed' => 0,
            ],
        ];

        ob_start();
        $command->renderReport($result);
        $report = ob_get_clean();
        $this->assertStringContainsString('Table orders has no PRIMARY KEY', $report);
        $this->assertStringContainsString('Index Suggestions Summary', $report);
        $this->assertStringContainsString('Tables needing indexes: 1', $report);
        $this->assertStringContainsString('Tip: Run', $report);

        ob_start();
        $command->renderYaml($result);
        $yaml = ob_get_clean();
        $this->assertStringContainsString('critical_issues:', $yaml);
        $this->assertStringContainsString('Table orders has no PRIMARY KEY', $yaml);
        $this->assertStringContainsString('tables_needing_indexes: 1', $yaml);
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

        ob_start();

        try {
            // Test with --table option
            $code = $app->run(['pdodb', 'optimize', 'structure', '--table=test_table', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
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

        ob_start();

        try {
            // Test with table as argument
            $code = $app->run(['pdodb', 'optimize', 'structure', 'test_table2', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
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

        try {
            $db->rawQuery('DROP TABLE "optimize_query_users" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // Ignore if table doesn't exist
        }
        $db->rawQuery('CREATE TABLE "optimize_query_users" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');
        $db->rawQuery('INSERT INTO "optimize_query_users" ("ID", "NAME") VALUES (1, \'Test\')');

        ob_start();

        try {
            // Use quoted uppercase names for Oracle
            $code = $app->run(['pdodb', 'optimize', 'query', 'SELECT * FROM "optimize_query_users" WHERE "ID" = 1', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
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

    public function testOptimizeHelpCommand(): void
    {
        $app = new Application();

        ob_start();

        try {
            $code = $app->run(['pdodb', 'optimize', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
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

    public function testSchemaAnalyzerCountsRows(): void
    {
        $db = self::$db;

        try {
            $db->rawQuery('DROP TABLE "PDODB_COUNT_TEST" CASCADE CONSTRAINTS');
        } catch (\Throwable) {
        }

        try {
            $db->rawQuery('CREATE TABLE "PDODB_COUNT_TEST" ("ID" NUMBER PRIMARY KEY)');
            $db->rawQuery('INSERT INTO "PDODB_COUNT_TEST" ("ID") VALUES (1)');
            $db->rawQuery('INSERT INTO "PDODB_COUNT_TEST" ("ID") VALUES (2)');
            $method = (new \ReflectionClass(SchemaAnalyzer::class))->getMethod('getTableRowCount');
            $this->assertSame(2, $method->invoke(new SchemaAnalyzer($db), 'PDODB_COUNT_TEST'));
        } finally {
            $db->rawQuery('DROP TABLE "PDODB_COUNT_TEST" CASCADE CONSTRAINTS');
        }
    }

    public function testOptimizeDbCommand(): void
    {
        $app = new Application();
        $db = self::$db;

        ob_start();

        try {
            $exitCode = $app->run(['pdodb', 'optimize', 'db', '--memory=5G', '--cpu-cores=32']);
            ob_get_clean();
            $this->assertEquals(0, $exitCode, 'Command should succeed');
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
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
        $this->assertEquals('oci', $result['dialect']);
        $this->assertNotEmpty($result['recommendations'], 'Should have recommendations');
        $this->assertArrayHasKey('sga_target', $result['recommendations']);
    }

    public function testWholeSchemaAnalysisFindsForeignKeysFromOneQuery(): void
    {
        $db = self::$db;
        foreach (['test_child_all', 'test_parent_all'] as $table) {
            try {
                $db->rawQuery('DROP TABLE "' . $table . '" CASCADE CONSTRAINTS');
            } catch (\Throwable) {
            }
        }
        $db->rawQuery('CREATE TABLE "test_parent_all" ("ID" NUMBER PRIMARY KEY, "NAME" VARCHAR2(100))');
        $db->rawQuery('CREATE TABLE "test_child_all" (
            "ID" NUMBER PRIMARY KEY,
            "PARENT_ID" NUMBER,
            CONSTRAINT fk_test_child_all FOREIGN KEY ("PARENT_ID") REFERENCES "test_parent_all"("ID")
        )');

        try {
            $analyzer = new SchemaAnalyzer($db);
            $perTable = $analyzer->analyzeTable('test_child_all');
            $messages = array_column((new SchemaAnalyzer($db))->analyze()['warnings'], 'message');

            $this->assertNotEmpty($perTable['foreign_keys'], 'the per-table path sees the foreign key');
            $this->assertNotEmpty(
                array_filter($messages, static fn (string $m): bool => str_contains($m, "'test_child_all'") && str_contains($m, 'foreign key')),
                'the whole-schema path, which asks for all foreign keys at once, reports the same missing index'
            );
        } finally {
            foreach (['test_child_all', 'test_parent_all'] as $table) {
                try {
                    $db->rawQuery('DROP TABLE "' . $table . '" CASCADE CONSTRAINTS');
                } catch (\Throwable) {
                }
            }
        }
    }
}
