<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\MigrationGenerator;
use tommyknocker\pdodb\cli\ModelGenerator;
use tommyknocker\pdodb\cli\SchemaInspector;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Shared tests for CLI tools.
 *
 * These tests verify that CLI tools work correctly across all database dialects.
 */
class CliToolsTests extends BaseSharedTestCase
{
    protected string $testMigrationPath;
    protected string $testModelPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test directories
        $this->testMigrationPath = sys_get_temp_dir() . '/pdodb_test_migrations_' . uniqid();
        $this->testModelPath = sys_get_temp_dir() . '/pdodb_test_models_' . uniqid();
        mkdir($this->testMigrationPath, 0755, true);
        mkdir($this->testModelPath, 0755, true);

        // Set environment variables for CLI tools
        putenv('PDODB_MIGRATION_PATH=' . $this->testMigrationPath);
        putenv('PDODB_MODEL_PATH=' . $this->testModelPath);
        putenv('PDODB_DRIVER=sqlite');
    }

    protected function tearDown(): void
    {
        // Clean up test tables
        $schema = self::$db->schema();
        $tables = ['test_table1', 'test_table2', 'test_users'];
        foreach ($tables as $table) {
            if ($schema->tableExists($table)) {
                $schema->dropTable($table);
            }
        }

        // Clean up test directories
        if (is_dir($this->testMigrationPath)) {
            $files = glob($this->testMigrationPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testMigrationPath);
        }

        if (is_dir($this->testModelPath)) {
            $files = glob($this->testModelPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testModelPath);
        }

        // Clean up environment variables
        putenv('PDODB_MIGRATION_PATH');
        putenv('PDODB_MODEL_PATH');

        parent::tearDown();
    }

    /**
     * Test migration generator creates migration file.
     */
    public function testMigrationGeneratorCreatesFile(): void
    {
        // Suppress output during test
        ob_start();

        try {
            $migrationName = 'test_create_users_table';
            $filename = MigrationGenerator::generate($migrationName, $this->testMigrationPath);
        } finally {
            ob_end_clean();
        }

        $this->assertFileExists($filename);
        $this->assertStringContainsString('test_create_users_table', basename($filename));
        $this->assertStringContainsString('class', file_get_contents($filename));
        $this->assertStringContainsString('extends Migration', file_get_contents($filename));
        $this->assertStringContainsString('public function up()', file_get_contents($filename));
        $this->assertStringContainsString('public function down()', file_get_contents($filename));
    }

    /**
     * Test model generator creates model file from existing table.
     */
    public function testModelGeneratorCreatesFileFromTable(): void
    {
        // Create a test table
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_users');
        $schema->createTable('test_users', [
            'id' => $schema->primaryKey()->autoIncrement(),
            'name' => $schema->string(100)->notNull(),
            'email' => $schema->string(255)->notNull()->unique(),
            'created_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        // Suppress output during test
        ob_start();

        try {
            $modelName = 'TestUser';
            $filename = ModelGenerator::generate($modelName, 'test_users', $this->testModelPath, self::$db);
        } finally {
            ob_end_clean();
        }

        $this->assertFileExists($filename);
        $this->assertStringContainsString('TestUser', file_get_contents($filename));
        $this->assertStringContainsString('extends Model', file_get_contents($filename));
        $this->assertStringContainsString('tableName(): string', file_get_contents($filename));
        $this->assertStringContainsString("'test_users'", file_get_contents($filename));
        $this->assertStringContainsString('primaryKey()', file_get_contents($filename));
    }

    /**
     * Test model generator fails for non-existent table.
     */
    public function testModelGeneratorFailsForNonExistentTable(): void
    {
        $this->expectException(QueryException::class);
        // Suppress output during test
        ob_start();

        try {
            ModelGenerator::generate('TestUser', 'non_existent_table', $this->testModelPath);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Test schema inspector lists tables.
     */
    public function testSchemaInspectorListsTables(): void
    {
        // Create test tables
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_table1');
        $schema->dropTableIfExists('test_table2');
        $schema->createTable('test_table1', [
            'id' => $schema->primaryKey(),
        ]);

        $schema->createTable('test_table2', [
            'id' => $schema->primaryKey(),
        ]);

        // Capture output
        ob_start();

        try {
            SchemaInspector::inspect(null, 'table', self::$db);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertStringContainsString('test_table1', $output);
        $this->assertStringContainsString('test_table2', $output);
    }

    /**
     * Test schema inspector inspects specific table.
     */
    public function testSchemaInspectorInspectsTable(): void
    {
        // Create test table
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_users');
        $schema->createTable('test_users', [
            'id' => $schema->primaryKey()->autoIncrement(),
            'name' => $schema->string(100)->notNull(),
            'email' => $schema->string(255)->notNull()->unique(),
        ]);

        // Capture output
        ob_start();

        try {
            SchemaInspector::inspect('test_users', 'table', self::$db);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertStringContainsString('test_users', $output);
        $this->assertStringContainsString('id', $output);
        $this->assertStringContainsString('name', $output);
        $this->assertStringContainsString('email', $output);
    }

    /**
     * Test schema inspector outputs JSON format.
     */
    public function testSchemaInspectorOutputsJson(): void
    {
        // Create test table
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_users');
        $schema->createTable('test_users', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
        ]);

        // Capture output
        ob_start();

        try {
            SchemaInspector::inspect('test_users', 'json', self::$db);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('table', $data);
        $this->assertArrayHasKey('columns', $data);
        $this->assertEquals('test_users', $data['table']);
    }

    /**
     * Test schema inspector outputs YAML format.
     */
    public function testSchemaInspectorOutputsYaml(): void
    {
        // Create test table
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_users');
        $schema->createTable('test_users', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
        ]);

        // Capture output
        ob_start();

        try {
            SchemaInspector::inspect('test_users', 'yaml', self::$db);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertStringContainsString('table:', $output);
        $this->assertStringContainsString('columns:', $output);
        $this->assertStringContainsString('test_users', $output);
    }

    /**
     * Test schema inspector fails for non-existent table.
     */
    public function testSchemaInspectorFailsForNonExistentTable(): void
    {
        $this->expectException(QueryException::class);
        // Suppress output during test
        ob_start();

        try {
            SchemaInspector::inspect('non_existent_table');
        } finally {
            ob_end_clean();
        }
    }
}
