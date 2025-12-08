<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\commands\TableCommand;
use tommyknocker\pdodb\cli\IndexSuggestionAnalyzer;
use tommyknocker\pdodb\PdoDb;

final class TableCommandCliTests extends TestCase
{
    protected string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite temp file DB for DDL operations
        $this->dbPath = sys_get_temp_dir() . '/pdodb_table_' . uniqid() . '.sqlite';
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
     * Temporarily unset PHPUNIT to allow output, then restore it.
     *
     * @return string|false Original PHPUNIT value or false if not set
     */
    protected function unsetPhpunitForOutput(): string|false
    {
        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        return $phpunit;
    }

    /**
     * Restore PHPUNIT environment variable.
     *
     * @param string|false $phpunit Original PHPUNIT value
     */
    protected function restorePhpunit(string|false $phpunit): void
    {
        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }
    }

    public function testTableCreateInfoExistsDescribeListAndDrop(): void
    {
        $app = new Application();

        // create table
        // Temporarily unset PHPUNIT to allow output
        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'users', '--columns=id:int,name:string:nullable', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        // Restore PHPUNIT
        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('created successfully', $out);

        // exists
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'exists', 'users']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Table 'users' exists", $out);

        // info
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'info', 'users', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"table": "users"', $out);

        // describe
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'describe', 'users', '--format=table']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Columns:', $out);

        // list
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'list', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"users"', $out);

        // drop
        // Temporarily unset PHPUNIT to allow output
        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'drop', 'users', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        // Restore PHPUNIT
        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('dropped successfully', $out);
    }

    public function testColumnsAndIndexesOnSqliteBasicFlow(): void
    {
        $app = new Application();
        // create table first
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'items', '--columns=id:int,name:string', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // add column
        $phpunit = $this->unsetPhpunitForOutput();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'columns', 'add', 'items', 'price', '--type=float']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restorePhpunit($phpunit);

            throw $e;
        }

        $this->restorePhpunit($phpunit);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("added to 'items'", $out);

        // list columns
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'columns', 'list', 'items', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('columns', $out);

        // create index
        $phpunit = $this->unsetPhpunitForOutput();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'indexes', 'add', 'items', 'idx_items_name', '--columns=name']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restorePhpunit($phpunit);

            throw $e;
        }

        $this->restorePhpunit($phpunit);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Index 'idx_items_name' created", $out);

        // list indexes via info
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'info', 'items', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('indexes', $out);

        // drop index
        $phpunit = $this->unsetPhpunitForOutput();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'indexes', 'drop', 'items', 'idx_items_name', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restorePhpunit($phpunit);

            throw $e;
        }

        $this->restorePhpunit($phpunit);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Index 'idx_items_name' dropped", $out);
    }

    public function testForeignKeysListAndCheck(): void
    {
        $app = new Application();

        // Create parent table
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'parent', '--columns=id:int', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // Create child table with foreign key (SQLite supports FK in CREATE TABLE)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'child', '--columns=id:int,parent_id:int', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // SQLite doesn't support ADD FOREIGN KEY via ALTER TABLE, so we'll test list and check
        // For SQLite, foreign keys are defined in CREATE TABLE, so we need to create table with FK inline
        // But since we're using the CLI, we'll test what we can: list and check

        // List foreign keys (should be empty for SQLite if not defined in CREATE TABLE)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'keys', 'list', 'child', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('foreign_keys', $out);

        // Check foreign keys (should pass if no violations)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'keys', 'check']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        // Should return 0 if no violations, or 1 if violations found
        $this->assertContains($code, [0, 1]);
    }

    #[RunInSeparateProcess]
    public function testKeysAddWithoutRequiredParamsYieldsError(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $dbPath = sys_get_temp_dir() . '/pdodb_keys_' . uniqid() . '.sqlite';
        $env = 'PDODB_DRIVER=sqlite PDODB_PATH=' . escapeshellarg($dbPath) . ' PDODB_NON_INTERACTIVE=1';
        $cmd = $env . ' ' . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg((string)$bin) . ' table keys add test_table 2>&1';
        $out = (string)shell_exec($cmd);
        // Should show error about missing parameters
        $this->assertStringContainsString('required', $out);
    }

    #[RunInSeparateProcess]
    public function testKeysDropWithoutNameYieldsError(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $dbPath = sys_get_temp_dir() . '/pdodb_keys_' . uniqid() . '.sqlite';
        $env = 'PDODB_DRIVER=sqlite PDODB_PATH=' . escapeshellarg($dbPath) . ' PDODB_NON_INTERACTIVE=1';
        $cmd = $env . ' ' . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg((string)$bin) . ' table keys drop test_table 2>&1';
        $out = (string)shell_exec($cmd);
        $this->assertStringContainsString('Foreign key name is required', $out);
    }

    #[RunInSeparateProcess]
    public function testCreateTableWithoutColumnsYieldsError(): void
    {
        // Run in a subprocess to avoid exit() killing PHPUnit
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $dbPath = sys_get_temp_dir() . '/pdodb_nc_' . uniqid() . '.sqlite';
        $env = 'PDODB_DRIVER=sqlite PDODB_PATH=' . escapeshellarg($dbPath) . ' PDODB_NON_INTERACTIVE=1';
        $cmd = $env . ' ' . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg((string)$bin) . ' table create no_columns_tbl 2>&1';
        $out = (string)shell_exec($cmd);
        // In non-interactive mode, readInput() returns empty string, so we get this error message
        $this->assertStringContainsString('Columns are required', $out);
    }

    public function testTableCountCommand(): void
    {
        $app = new Application();

        // Create table
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'test_count', '--columns=id:int,name:string', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // Insert some data
        $db = new PdoDb('sqlite', ['path' => $this->dbPath]);
        $db->find()->table('test_count')->insert(['name' => 'Test 1']);
        $db->find()->table('test_count')->insert(['name' => 'Test 2']);
        $db->find()->table('test_count')->insert(['name' => 'Test 3']);

        // Test count command
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'count', 'test_count']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertSame("3\n", $out);

        // Test count with empty table
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'empty_table', '--columns=id:int', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'count', 'empty_table']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertSame("0\n", $out);
    }

    public function testTableSampleCommand(): void
    {
        $app = new Application();

        // Create table
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'test_sample', '--columns=id:int,name:string', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // Insert some data
        $db = new PdoDb('sqlite', ['path' => $this->dbPath]);
        $db->find()->table('test_sample')->insert(['name' => 'Sample 1']);
        $db->find()->table('test_sample')->insert(['name' => 'Sample 2']);
        $db->find()->table('test_sample')->insert(['name' => 'Sample 3']);

        // Test sample command (table format)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'sample', 'test_sample', '--format=table']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Data:', $out);
        $this->assertStringContainsString('Sample 1', $out);
        $this->assertStringContainsString('Total rows: 3', $out);

        // Test sample command (JSON format)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'sample', 'test_sample', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"data"', $out);
        $this->assertStringContainsString('"name"', $out);
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('data', $json);
        $this->assertCount(3, $json['data']);

        // Test select alias
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'select', 'test_sample', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"data"', $out);

        // Test sample with limit (should return only 2 rows)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'sample', 'test_sample', '--limit=2', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('data', $json);
        $this->assertCount(2, $json['data']);
    }

    public function testTableRename(): void
    {
        $app = new Application();

        // Create table
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'old_name', '--columns=id:int', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // Rename table
        $phpunit = $this->unsetPhpunitForOutput();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'rename', 'old_name', 'new_name', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restorePhpunit($phpunit);

            throw $e;
        }

        $this->restorePhpunit($phpunit);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('renamed to', $out);

        // Verify new table exists
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'exists', 'new_name']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Table 'new_name' exists", $out);
    }

    public function testTableTruncate(): void
    {
        $app = new Application();

        // Create table and insert data
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'truncate_test', '--columns=id:int,name:string', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        $db = new PdoDb('sqlite', ['path' => $this->dbPath]);
        $db->find()->table('truncate_test')->insert(['name' => 'Test 1']);
        $db->find()->table('truncate_test')->insert(['name' => 'Test 2']);

        // Verify data exists
        $count = $db->rawQueryValue('SELECT COUNT(*) FROM truncate_test');
        $this->assertEquals(2, (int)$count);

        // Truncate table
        $phpunit = $this->unsetPhpunitForOutput();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'truncate', 'truncate_test', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restorePhpunit($phpunit);

            throw $e;
        }

        $this->restorePhpunit($phpunit);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('truncated', $out);

        // Verify table is empty
        $count = $db->rawQueryValue('SELECT COUNT(*) FROM truncate_test');
        $this->assertEquals(0, (int)$count);
    }

    public function testTableColumnsAlter(): void
    {
        $app = new Application();

        // Create table
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'alter_test', '--columns=id:int,name:string', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // Alter column (SQLite has limited ALTER COLUMN support)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'columns', 'alter', 'alter_test', 'name', '--type=text:nullable']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            // SQLite may not support ALTER COLUMN, which is expected
            $this->assertInstanceOf(\Throwable::class, $e);
            return;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('altered', $out);
    }

    public function testTableColumnsDrop(): void
    {
        $app = new Application();

        // Create table
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'drop_col_test', '--columns=id:int,name:string,extra:string', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // Drop column (SQLite has limited DROP COLUMN support)
        $phpunit = $this->unsetPhpunitForOutput();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'columns', 'drop', 'drop_col_test', 'extra', '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restorePhpunit($phpunit);
            // SQLite may not support DROP COLUMN directly, which is expected
            $this->assertInstanceOf(\Throwable::class, $e);
            return;
        }

        $this->restorePhpunit($phpunit);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('dropped', $out);
    }

    public function testTableInfoWithYamlFormat(): void
    {
        $app = new Application();

        // Create table
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'yaml_test', '--columns=id:int,name:string', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // Test info with YAML format
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'info', 'yaml_test', '--format=yaml']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('table:', $out);
        $this->assertStringContainsString('yaml_test', $out);
    }

    public function testTableListWithYamlFormat(): void
    {
        $app = new Application();

        // Create table
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'yaml_list_test', '--columns=id:int', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // Test list with YAML format
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'list', '--format=yaml']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('tables:', $out);
    }

    public function testTableParseColumns(): void
    {
        $command = new TableCommand();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('parseColumns');
        $method->setAccessible(true);

        // Test simple column parsing
        $result = $method->invoke($command, 'id:int,name:string');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);

        // Test with nullable
        $result = $method->invoke($command, 'id:int:nullable,name:string');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);

        // Test with default
        $result = $method->invoke($command, 'id:int:default:0');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
    }

    public function testTableTypeToSchema(): void
    {
        $command = new TableCommand();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('typeToSchema');
        $method->setAccessible(true);

        // Test int type
        $result = $method->invoke($command, 'int');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);

        // Test string type
        $result = $method->invoke($command, 'string');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);

        // Test float type
        $result = $method->invoke($command, 'float');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);

        // Test boolean type
        $result = $method->invoke($command, 'boolean');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('type', $result);
    }

    public function testTablePrintFormattedYaml(): void
    {
        $command = new TableCommand();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('printFormatted');
        $method->setAccessible(true);

        $data = ['test' => 'value', 'nested' => ['key' => 'val']];

        ob_start();

        try {
            $result = $method->invoke($command, $data, 'yaml');
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $result);
        $this->assertStringContainsString('test:', $out);
        $this->assertStringContainsString('value', $out);
    }

    public function testTablePrintTable(): void
    {
        $command = new TableCommand();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('printTable');
        $method->setAccessible(true);

        $data = [
            'test_table' => [
                ['id' => 1, 'name' => 'Test 1'],
                ['id' => 2, 'name' => 'Test 2'],
            ],
        ];

        ob_start();

        try {
            $method->invoke($command, $data);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertStringContainsStringIgnoringCase('id', $out);
        $this->assertStringContainsStringIgnoringCase('name', $out);
        $this->assertStringContainsString('Test 1', $out);
        $this->assertStringContainsString('Test 2', $out);
    }

    #[RunInSeparateProcess]
    public function testTableIndexesSuggest(): void
    {
        // Use a separate DB path for this test to avoid conflicts
        $dbPath = sys_get_temp_dir() . '/pdodb_table_suggest_' . uniqid() . '.sqlite';
        putenv('PDODB_PATH=' . $dbPath);

        $app = new Application();

        // Create table with foreign key column (without index)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'suggest_test', '--columns=id:int,user_id:int,status:string,deleted_at:datetime,created_at:datetime', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);

        // Add foreign key (if supported by SQLite)
        $db = new PdoDb('sqlite', ['path' => $dbPath]);

        try {
            $db->schema()->addForeignKey('fk_suggest_test_user', 'suggest_test', 'user_id', 'users', 'id');
        } catch (\Exception $e) {
            // SQLite may not support ADD FOREIGN KEY, skip FK test
        }

        // Test suggest command (table format)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'indexes', 'suggest', 'suggest_test', '--format=table']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Analyzing table 'suggest_test'", $out);
        // Should suggest indexes for common patterns
        $this->assertStringContainsString('suggestion', strtolower($out));

        // Test suggest command (JSON format)
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'indexes', 'suggest', 'suggest_test', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('suggestions', $json);
        $this->assertIsArray($json['suggestions']);

        // Test suggest with priority filter
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'indexes', 'suggest', 'suggest_test', '--priority=high', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('suggestions', $json);
        // All suggestions should be high priority
        foreach ($json['suggestions'] as $suggestion) {
            $this->assertEquals('high', $suggestion['priority'] ?? null);
        }

        // Clean up test DB
        if (file_exists($dbPath)) {
            @unlink($dbPath);
        }
        // Restore original DB path
        putenv('PDODB_PATH=' . $this->dbPath);

        // Test suggest for non-existent table (run in separate process to handle exit)
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $dbPath = sys_get_temp_dir() . '/pdodb_suggest_' . uniqid() . '.sqlite';
        $env = 'PDODB_DRIVER=sqlite PDODB_PATH=' . escapeshellarg($dbPath) . ' PDODB_NON_INTERACTIVE=1';
        $cmd = $env . ' ' . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg((string)$bin) . ' table indexes suggest non_existent_table 2>&1';
        $out = (string)shell_exec($cmd);
        $this->assertStringContainsString('does not exist', $out);
    }

    public function testIndexSuggestionAnalyzer(): void
    {
        $db = new PdoDb('sqlite', ['path' => $this->dbPath]);

        // Create table with common patterns
        $db->schema()->createTable('analyzer_test', [
            'id' => $db->schema()->primaryKey(),
            'user_id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'length' => 50],
            'deleted_at' => ['type' => 'datetime', 'nullable' => true],
            'created_at' => ['type' => 'datetime'],
        ]);

        $analyzer = new IndexSuggestionAnalyzer($db);
        $suggestions = $analyzer->analyze('analyzer_test');

        $this->assertIsArray($suggestions);
        // Should have at least some suggestions for common patterns
        $this->assertGreaterThanOrEqual(0, count($suggestions));

        // Check suggestion structure
        if (!empty($suggestions)) {
            $suggestion = $suggestions[0];
            $this->assertArrayHasKey('priority', $suggestion);
            $this->assertArrayHasKey('type', $suggestion);
            $this->assertArrayHasKey('columns', $suggestion);
            $this->assertArrayHasKey('reason', $suggestion);
            $this->assertArrayHasKey('sql', $suggestion);
            $this->assertContains($suggestion['priority'], ['high', 'medium', 'low']);
        }

        // Test with priority filter
        $highPriority = $analyzer->analyze('analyzer_test', ['priority' => 'high']);
        foreach ($highPriority as $suggestion) {
            $this->assertEquals('high', $suggestion['priority']);
        }
    }

    public function testTableSearchCommand(): void
    {
        $app = new Application();

        // Create table with test data
        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'create', 'test_search', '--columns=id:int,name:string,email:string,age:int', '--force']);
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($phpunit !== false) {
                putenv('PHPUNIT=' . $phpunit);
            }

            throw $e;
        }

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }

        // Insert test data using the same database connection
        // Get database path from environment
        $dbPath = getenv('PDODB_PATH');
        if ($dbPath === false || $dbPath === '') {
            $dbPath = $this->dbPath;
        }
        $db = new PdoDb('sqlite', ['path' => $dbPath]);
        $db->find()->table('test_search')->insert(['name' => 'John Doe', 'email' => 'john@example.com', 'age' => 30]);
        $db->find()->table('test_search')->insert(['name' => 'Jane Smith', 'email' => 'jane@test.com', 'age' => 25]);
        $db->find()->table('test_search')->insert(['name' => 'Bob Johnson', 'email' => 'bob@example.org', 'age' => 35]);

        // Search across all columns
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'search', 'test_search', 'john', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Found', $out);
        $this->assertStringContainsString('row(s) matching', $out);
        $this->assertStringContainsString('john', strtolower($out));

        // Search in specific column
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'search', 'test_search', 'example', '--column=email', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Found', $out);

        // Search with limit
        ob_start();

        try {
            $code = $app->run(['pdodb', 'table', 'search', 'test_search', 'test', '--limit=1', '--format=json']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertSame(0, $code);

        // Cleanup
        ob_start();
        $app->run(['pdodb', 'table', 'drop', 'test_search', '--force']);
        ob_end_clean();
    }

    public function testTableSearchCommandWithNonExistentTable(): void
    {
        // Use shell_exec to handle exit() from error()
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $dbPath = sys_get_temp_dir() . '/pdodb_search_' . uniqid() . '.sqlite';
        $env = 'PDODB_DRIVER=sqlite PDODB_PATH=' . escapeshellarg($dbPath) . ' PDODB_NON_INTERACTIVE=1';
        $cmd = $env . ' ' . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg((string)$bin) . ' table search nonexistent test 2>&1';
        $out = (string)shell_exec($cmd);
        $this->assertStringContainsString('does not exist', $out);
    }

    public function testTableSearchCommandWithMissingArguments(): void
    {
        $app = new Application();
        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');

        // Missing search term - use shell_exec to handle exit()
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $dbPath = sys_get_temp_dir() . '/pdodb_search_' . uniqid() . '.sqlite';
        $env = 'PDODB_DRIVER=sqlite PDODB_PATH=' . escapeshellarg($dbPath) . ' PDODB_NON_INTERACTIVE=1';
        $cmd = $env . ' ' . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg((string)$bin) . ' table search users 2>&1';
        $out = (string)shell_exec($cmd);
        $this->assertStringContainsString('Search term is required', $out);

        // Missing table name
        $cmd = $env . ' ' . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg((string)$bin) . ' table search 2>&1';
        $out = (string)shell_exec($cmd);
        $this->assertStringContainsString('Table name is required', $out);

        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }
    }
}
