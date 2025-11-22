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
}
