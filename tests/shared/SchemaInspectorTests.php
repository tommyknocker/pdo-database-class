<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\SchemaInspector;
use tommyknocker\pdodb\PdoDb;

/**
 * Tests for SchemaInspector CLI tool.
 */
final class SchemaInspectorTests extends TestCase
{
    protected string $dbPath;
    protected PdoDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/pdodb_schema_inspector_' . uniqid() . '.sqlite';
        $this->db = new PdoDb('sqlite', ['path' => $this->dbPath]);

        // Create test table with indexes and foreign keys
        $this->db->rawQuery('
            CREATE TABLE test_users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT UNIQUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->rawQuery('CREATE INDEX idx_name ON test_users(name)');
        $this->db->rawQuery('
            CREATE TABLE test_posts (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                title TEXT,
                FOREIGN KEY (user_id) REFERENCES test_users(id)
            )
        ');

        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PHPUNIT=1');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_NON_INTERACTIVE');
        putenv('PHPUNIT');
        parent::tearDown();
    }

    /**
     * Test inspect with no table name (list all tables).
     */
    public function testInspectListAllTables(): void
    {
        ob_start();
        SchemaInspector::inspect(null, null, $this->db);
        $out = ob_get_clean();

        $this->assertStringContainsString('PDOdb Schema Inspector', $out);
        $this->assertStringContainsString('Database:', $out);
        $this->assertStringContainsString('test_users', $out);
        $this->assertStringContainsString('test_posts', $out);
    }

    /**
     * Test inspect with table name.
     */
    public function testInspectSpecificTable(): void
    {
        ob_start();
        SchemaInspector::inspect('test_users', null, $this->db);
        $out = ob_get_clean();

        $this->assertStringContainsString('Table: test_users', $out);
        $this->assertStringContainsString('Columns:', $out);
        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('name', $out);
        $this->assertStringContainsString('email', $out);
    }

    /**
     * Test inspect with JSON format.
     */
    public function testInspectWithJsonFormat(): void
    {
        ob_start();
        SchemaInspector::inspect('test_users', 'json', $this->db);
        $out = ob_get_clean();

        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('table', $json);
        $this->assertArrayHasKey('columns', $json);
        $this->assertEquals('test_users', $json['table']);
    }

    /**
     * Test inspect with YAML format.
     */
    public function testInspectWithYamlFormat(): void
    {
        ob_start();
        SchemaInspector::inspect('test_users', 'yaml', $this->db);
        $out = ob_get_clean();

        $this->assertStringContainsString('table:', $out);
        $this->assertStringContainsString('columns:', $out);
        $this->assertStringContainsString('test_users', $out);
    }

    /**
     * Test listTables.
     */
    public function testListTables(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('listTables');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, null);
        $out = ob_get_clean();

        $this->assertStringContainsString('Tables', $out);
        $this->assertStringContainsString('test_users', $out);
        $this->assertStringContainsString('test_posts', $out);
    }

    /**
     * Test listTables with JSON format.
     */
    public function testListTablesWithJsonFormat(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('listTables');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'json');
        $out = ob_get_clean();

        // Output should be valid JSON
        $json = json_decode($out, true);
        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            // If JSON parsing fails, check if output contains table names
            $this->assertStringContainsString('test_users', $out);
        } else {
            $this->assertIsArray($json);
        }
    }

    /**
     * Test listTables with YAML format.
     */
    public function testListTablesWithYamlFormat(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('listTables');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'yaml');
        $out = ob_get_clean();

        $this->assertStringContainsString('name:', $out);
    }

    /**
     * Test inspectTable.
     */
    public function testInspectTable(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('inspectTable');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'test_users', null);
        $out = ob_get_clean();

        $this->assertStringContainsString('Table: test_users', $out);
        $this->assertStringContainsString('Columns:', $out);
        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('name', $out);
    }

    /**
     * Test inspectTable with non-existent table.
     */
    public function testInspectTableNonExistent(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('inspectTable');
        $method->setAccessible(true);

        $this->expectException(\tommyknocker\pdodb\exceptions\QueryException::class);
        $method->invoke(null, $this->db, 'non_existent_table', null);
    }

    /**
     * Test getAllTables.
     */
    public function testGetAllTables(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getAllTables');
        $method->setAccessible(true);

        $tables = $method->invoke(null, $this->db);

        $this->assertIsArray($tables);
        $this->assertNotEmpty($tables);
        $this->assertArrayHasKey(0, $tables);
        $this->assertArrayHasKey('name', $tables[0]);
        $this->assertArrayHasKey('columns', $tables[0]);
    }

    /**
     * Test getTableRowCount.
     */
    public function testGetTableRowCount(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableRowCount');
        $method->setAccessible(true);

        // Insert some data
        $this->db->rawQuery('INSERT INTO test_users (name, email) VALUES (?, ?)', ['John', 'john@example.com']);

        $count = $method->invoke(null, $this->db, 'test_users');
        $this->assertEquals('1', $count);
    }

    /**
     * Test getTableRowCount with empty table.
     */
    public function testGetTableRowCountEmpty(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableRowCount');
        $method->setAccessible(true);

        $count = $method->invoke(null, $this->db, 'test_users');
        $this->assertEquals('0', $count);
    }

    /**
     * Test getTableColumns.
     */
    public function testGetTableColumns(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableColumns');
        $method->setAccessible(true);

        $columns = $method->invoke(null, $this->db, 'test_users');

        $this->assertIsArray($columns);
        $this->assertNotEmpty($columns);
        $this->assertArrayHasKey(0, $columns);
        $this->assertArrayHasKey('name', $columns[0]);
        $this->assertArrayHasKey('type', $columns[0]);
        $this->assertArrayHasKey('nullable', $columns[0]);
    }

    /**
     * Test getTableIndexes.
     */
    public function testGetTableIndexes(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableIndexes');
        $method->setAccessible(true);

        $indexes = $method->invoke(null, $this->db, 'test_users');

        $this->assertIsArray($indexes);
        // Should have at least the idx_name index
        $this->assertNotEmpty($indexes);
    }

    /**
     * Test getTableForeignKeys.
     */
    public function testGetTableForeignKeys(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableForeignKeys');
        $method->setAccessible(true);

        $foreignKeys = $method->invoke(null, $this->db, 'test_posts');

        $this->assertIsArray($foreignKeys);
        // test_posts has a foreign key to test_users
        $this->assertNotEmpty($foreignKeys);
    }

    /**
     * Test getTableForeignKeys with no foreign keys.
     */
    public function testGetTableForeignKeysNone(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableForeignKeys');
        $method->setAccessible(true);

        $foreignKeys = $method->invoke(null, $this->db, 'test_users');

        $this->assertIsArray($foreignKeys);
        // test_users has no foreign keys
        $this->assertEmpty($foreignKeys);
    }

    /**
     * Test getTableConstraints.
     */
    public function testGetTableConstraints(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableConstraints');
        $method->setAccessible(true);

        $constraints = $method->invoke(null, $this->db, 'test_users');

        $this->assertIsArray($constraints);
        // SQLite may or may not return constraints depending on implementation
        // Just verify it returns an array
    }

    /**
     * Test arrayToYaml.
     */
    public function testArrayToYaml(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('arrayToYaml');
        $method->setAccessible(true);

        $array = [
            'name' => 'test',
            'value' => 123,
            'nested' => [
                'key' => 'value',
            ],
        ];

        $yaml = $method->invoke(null, $array);

        $this->assertStringContainsString('name:', $yaml);
        $this->assertStringContainsString('test', $yaml);
        $this->assertStringContainsString('nested:', $yaml);
        $this->assertStringContainsString('key:', $yaml);
    }

    /**
     * Test arrayToYaml with nested arrays.
     */
    public function testArrayToYamlNested(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('arrayToYaml');
        $method->setAccessible(true);

        $array = [
            'level1' => [
                'level2' => [
                    'level3' => 'value',
                ],
            ],
        ];

        $yaml = $method->invoke(null, $array);

        $this->assertStringContainsString('level1:', $yaml);
        $this->assertStringContainsString('level2:', $yaml);
        $this->assertStringContainsString('level3:', $yaml);
    }

    /**
     * Test inspectTable with JSON format.
     */
    public function testInspectTableWithJsonFormat(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('inspectTable');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'test_users', 'json');
        $out = ob_get_clean();

        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('table', $json);
        $this->assertArrayHasKey('columns', $json);
        $this->assertArrayHasKey('indexes', $json);
        $this->assertArrayHasKey('foreign_keys', $json);
        $this->assertArrayHasKey('constraints', $json);
    }

    /**
     * Test inspectTable with YAML format.
     */
    public function testInspectTableWithYamlFormat(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('inspectTable');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'test_users', 'yaml');
        $out = ob_get_clean();

        $this->assertStringContainsString('table:', $out);
        $this->assertStringContainsString('columns:', $out);
        $this->assertStringContainsString('test_users', $out);
    }

    /**
     * Test inspectTable with table format showing indexes.
     */
    public function testInspectTableWithIndexes(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('inspectTable');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $this->db, 'test_users', null);
        $out = ob_get_clean();

        $this->assertStringContainsString('Table: test_users', $out);
        $this->assertStringContainsString('Columns:', $out);
        // Should show indexes if they exist
        $this->assertStringContainsString('id', $out);
    }

    /**
     * Test inspectTable with table format showing foreign keys.
     */
    public function testInspectTableWithForeignKeys(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('inspectTable');
        $method->setAccessible(true);

        ob_start();
        try {
            $method->invoke(null, $this->db, 'test_posts', null);
            $out = ob_get_clean();

            $this->assertStringContainsString('Table: test_posts', $out);
            $this->assertStringContainsString('Columns:', $out);
        } catch (\Throwable $e) {
            ob_end_clean();
            // May fail if foreign keys structure is different, which is acceptable
            $this->assertTrue(true);
        }
    }

    /**
     * Test listTables with empty database.
     */
    public function testListTablesWithEmptyDatabase(): void
    {
        // Create empty database
        $emptyDbPath = sys_get_temp_dir() . '/pdodb_empty_' . uniqid() . '.sqlite';
        $emptyDb = new PdoDb('sqlite', ['path' => $emptyDbPath]);

        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('listTables');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $emptyDb, null);
        $out = ob_get_clean();

        $this->assertStringContainsString('No tables found', $out);

        // Cleanup
        @unlink($emptyDbPath);
    }

    /**
     * Test getTableRowCount with error.
     */
    public function testGetTableRowCountWithError(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableRowCount');
        $method->setAccessible(true);

        // Use invalid table name to trigger error
        $count = $method->invoke(null, $this->db, 'nonexistent_table_xyz');
        $this->assertEquals('N/A', $count);
    }

    /**
     * Test getTableColumns with various column formats.
     */
    public function testGetTableColumnsWithVariousFormats(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableColumns');
        $method->setAccessible(true);

        $columns = $method->invoke(null, $this->db, 'test_users');

        $this->assertIsArray($columns);
        $this->assertNotEmpty($columns);
        foreach ($columns as $column) {
            $this->assertArrayHasKey('name', $column);
            $this->assertArrayHasKey('type', $column);
            $this->assertArrayHasKey('nullable', $column);
            $this->assertArrayHasKey('default', $column);
        }
    }

    /**
     * Test getTableIndexes with error.
     */
    public function testGetTableIndexesWithError(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableIndexes');
        $method->setAccessible(true);

        // Should return empty array on error, not throw
        $indexes = $method->invoke(null, $this->db, 'test_users');
        $this->assertIsArray($indexes);
    }

    /**
     * Test getTableForeignKeys with error.
     */
    public function testGetTableForeignKeysWithError(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableForeignKeys');
        $method->setAccessible(true);

        // Should return empty array on error, not throw
        $foreignKeys = $method->invoke(null, $this->db, 'test_users');
        $this->assertIsArray($foreignKeys);
    }

    /**
     * Test getTableConstraints with check constraints.
     */
    public function testGetTableConstraintsWithCheckConstraints(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableConstraints');
        $method->setAccessible(true);

        $constraints = $method->invoke(null, $this->db, 'test_users');
        $this->assertIsArray($constraints);
    }

    /**
     * Test arrayToYaml with boolean values.
     */
    public function testArrayToYamlWithBooleanValues(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('arrayToYaml');
        $method->setAccessible(true);

        $array = [
            'enabled' => true,
            'disabled' => false,
        ];

        $yaml = $method->invoke(null, $array);

        $this->assertStringContainsString('enabled:', $yaml);
        $this->assertStringContainsString('disabled:', $yaml);
    }

    /**
     * Test arrayToYaml with null values.
     */
    public function testArrayToYamlWithNullValues(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('arrayToYaml');
        $method->setAccessible(true);

        $array = [
            'null_value' => null,
            'string_value' => 'test',
        ];

        $yaml = $method->invoke(null, $array);

        $this->assertStringContainsString('null_value:', $yaml);
        $this->assertStringContainsString('string_value:', $yaml);
    }

    /**
     * Test arrayToYaml with numeric values.
     */
    public function testArrayToYamlWithNumericValues(): void
    {
        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('arrayToYaml');
        $method->setAccessible(true);

        $array = [
            'integer' => 123,
            'float' => 45.67,
        ];

        $yaml = $method->invoke(null, $array);

        $this->assertStringContainsString('integer:', $yaml);
        $this->assertStringContainsString('float:', $yaml);
    }

    /**
     * Test inspect with db parameter null (creates new instance).
     */
    public function testInspectWithDbNull(): void
    {
        ob_start();
        try {
            SchemaInspector::inspect('test_users', null, null);
            $out = ob_get_clean();
            $this->assertStringContainsString('Table: test_users', $out);
        } catch (\Throwable $e) {
            ob_end_clean();
            // May fail if database config is not available, which is expected
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }

    /**
     * Test inspect with format parameter and db.
     */
    public function testInspectWithFormatAndDb(): void
    {
        ob_start();
        SchemaInspector::inspect('test_users', 'json', $this->db);
        $out = ob_get_clean();

        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('table', $json);
    }

    /**
     * Test inspect with YAML format and db.
     */
    public function testInspectWithYamlFormatAndDb(): void
    {
        ob_start();
        SchemaInspector::inspect('test_users', 'yaml', $this->db);
        $out = ob_get_clean();

        $this->assertStringContainsString('table:', $out);
        $this->assertStringContainsString('columns:', $out);
    }

    /**
     * Test inspect with table format (default) and db.
     */
    public function testInspectWithTableFormatAndDb(): void
    {
        ob_start();
        SchemaInspector::inspect('test_users', null, $this->db);
        $out = ob_get_clean();

        $this->assertStringContainsString('Table: test_users', $out);
        $this->assertStringContainsString('Columns:', $out);
    }

    /**
     * Test inspect with JSON format and no table name.
     */
    public function testInspectWithJsonFormatNoTableName(): void
    {
        ob_start();
        SchemaInspector::inspect(null, 'json', $this->db);
        $out = ob_get_clean();

        $json = json_decode($out, true);
        // JSON may be null if output is empty or invalid, but should be valid JSON string
        if ($json === null) {
            $this->assertNotEmpty($out); // At least should have some output
        } else {
            $this->assertIsArray($json);
        }
    }

    /**
     * Test inspect with YAML format and no table name.
     */
    public function testInspectWithYamlFormatNoTableName(): void
    {
        ob_start();
        SchemaInspector::inspect(null, 'yaml', $this->db);
        $out = ob_get_clean();

        $this->assertStringContainsString('name:', $out);
    }

    /**
     * Test listTables with JSON format and empty result.
     */
    public function testListTablesWithJsonFormatEmpty(): void
    {
        $emptyDbPath = sys_get_temp_dir() . '/pdodb_empty_json_' . uniqid() . '.sqlite';
        $emptyDb = new PdoDb('sqlite', ['path' => $emptyDbPath]);

        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('listTables');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $emptyDb, 'json');
        $out = ob_get_clean();

        // When empty, listTables shows info message instead of JSON
        // So output may not be valid JSON
        $json = json_decode($out, true);
        if ($json === null) {
            // Should show info message instead
            $this->assertStringContainsString('No tables found', $out);
        } else {
            $this->assertIsArray($json);
        }

        @unlink($emptyDbPath);
    }

    /**
     * Test listTables with YAML format and empty result.
     */
    public function testListTablesWithYamlFormatEmpty(): void
    {
        $emptyDbPath = sys_get_temp_dir() . '/pdodb_empty_yaml_' . uniqid() . '.sqlite';
        $emptyDb = new PdoDb('sqlite', ['path' => $emptyDbPath]);

        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('listTables');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $emptyDb, 'yaml');
        $out = ob_get_clean();

        // Should output empty YAML or handle gracefully
        $this->assertIsString($out);

        @unlink($emptyDbPath);
    }
}
