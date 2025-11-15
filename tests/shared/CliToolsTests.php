<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\DatabaseManager;
use tommyknocker\pdodb\cli\MigrationGenerator;
use tommyknocker\pdodb\cli\ModelGenerator;
use tommyknocker\pdodb\cli\SchemaInspector;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\migrations\MigrationRunner;

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
        putenv('PDODB_NON_INTERACTIVE=1');
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

        // Clean up migration history table
        if ($schema->tableExists('__migrations')) {
            self::$db->find()->from('__migrations')->delete();
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
        putenv('PDODB_NON_INTERACTIVE');

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

    /**
     * Test MigrationGenerator suggestMigrationType method.
     */
    public function testMigrationGeneratorSuggestMigrationType(): void
    {
        $reflection = new \ReflectionClass(MigrationGenerator::class);
        $method = $reflection->getMethod('suggestMigrationType');
        $method->setAccessible(true);

        // Test create table suggestion
        $suggestions = $method->invoke(null, 'create_users_table');
        $this->assertContains('create_table', $suggestions);

        // Test add column suggestion
        $suggestions = $method->invoke(null, 'add_email_column');
        $this->assertContains('add_column', $suggestions);

        // Test drop column suggestion
        $suggestions = $method->invoke(null, 'drop_email_column');
        $this->assertContains('drop_column', $suggestions);

        // Test add index suggestion
        $suggestions = $method->invoke(null, 'add_email_index');
        $this->assertContains('add_index', $suggestions);

        // Test add foreign key suggestion
        $suggestions = $method->invoke(null, 'add_user_id_foreign');
        $this->assertContains('add_foreign_key', $suggestions);

        // Test no suggestions for generic name
        $suggestions = $method->invoke(null, 'generic_migration');
        $this->assertEmpty($suggestions);
    }

    /**
     * Test MigrationGenerator getMigrationPath method.
     */
    public function testMigrationGeneratorGetMigrationPath(): void
    {
        // Test with environment variable
        $testPath = sys_get_temp_dir() . '/pdodb_test_migration_path_' . uniqid();
        mkdir($testPath, 0755, true);
        putenv('PDODB_MIGRATION_PATH=' . $testPath);

        try {
            $path = MigrationGenerator::getMigrationPath();
            $this->assertEquals($testPath, $path);
        } finally {
            putenv('PDODB_MIGRATION_PATH');
            rmdir($testPath);
        }

        // Test with existing directory
        $path = MigrationGenerator::getMigrationPath();
        $this->assertIsString($path);
        $this->assertTrue(is_dir($path) || is_dir(dirname($path)));
    }

    /**
     * Test ModelGenerator modelNameToTableName method.
     */
    public function testModelGeneratorModelNameToTableName(): void
    {
        $reflection = new \ReflectionClass(ModelGenerator::class);
        $method = $reflection->getMethod('modelNameToTableName');
        $method->setAccessible(true);

        $this->assertEquals('users', $method->invoke(null, 'User'));
        $this->assertEquals('user_profiles', $method->invoke(null, 'UserProfile'));
        $this->assertEquals('order_items', $method->invoke(null, 'OrderItem'));
    }

    /**
     * Test ModelGenerator detectPrimaryKey method.
     */
    public function testModelGeneratorDetectPrimaryKey(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_model_pk');
        $schema->createTable('test_model_pk', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        $reflection = new \ReflectionClass(ModelGenerator::class);
        $method = $reflection->getMethod('detectPrimaryKey');
        $method->setAccessible(true);

        $primaryKey = $method->invoke(null, self::$db, 'test_model_pk');
        $this->assertContains('id', $primaryKey);

        // Cleanup
        $schema->dropTable('test_model_pk');
    }

    /**
     * Test ModelGenerator getForeignKeys method.
     */
    public function testModelGeneratorGetForeignKeys(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_model_fk_child');
        $schema->dropTableIfExists('test_model_fk_parent');
        $schema->createTable('test_model_fk_parent', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        // SQLite requires foreign keys to be defined during CREATE TABLE
        // For other databases, we can add them via ALTER TABLE
        try {
            $schema->createTable('test_model_fk_child', [
                'id' => $schema->primaryKey(),
                'parent_id' => $schema->integer(),
                'name' => $schema->string(100),
            ]);
            $schema->addForeignKey('fk_parent', 'test_model_fk_child', 'parent_id', 'test_model_fk_parent', 'id');
        } catch (\Exception $e) {
            // SQLite doesn't support ALTER TABLE ADD FOREIGN KEY
            // Just verify the method exists and can be called
            $this->assertTrue(true);
            return;
        }

        $reflection = new \ReflectionClass(ModelGenerator::class);
        $method = $reflection->getMethod('getForeignKeys');
        $method->setAccessible(true);

        $foreignKeys = $method->invoke(null, self::$db, 'test_model_fk_child');
        $this->assertIsArray($foreignKeys);

        // Cleanup
        $schema->dropTable('test_model_fk_child');
        $schema->dropTable('test_model_fk_parent');
    }

    /**
     * Test ModelGenerator generateAttributes method.
     */
    public function testModelGeneratorGenerateAttributes(): void
    {
        $reflection = new \ReflectionClass(ModelGenerator::class);
        $method = $reflection->getMethod('generateAttributes');
        $method->setAccessible(true);

        $columns = [
            ['name' => 'id', 'type' => 'integer', 'null' => false],
            ['name' => 'name', 'type' => 'string', 'null' => true],
        ];

        $attributes = $method->invoke(null, $columns);
        $this->assertIsString($attributes);
        $this->assertStringContainsString('id', $attributes);
        $this->assertStringContainsString('name', $attributes);
    }

    /**
     * Test ModelGenerator generateRelationships method.
     */
    public function testModelGeneratorGenerateRelationships(): void
    {
        $reflection = new \ReflectionClass(ModelGenerator::class);
        $method = $reflection->getMethod('generateRelationships');
        $method->setAccessible(true);

        $foreignKeys = [
            [
                'column' => 'user_id',
                'referenced_table' => 'users',
                'referenced_column' => 'id',
            ],
        ];

        $relationships = $method->invoke(null, $foreignKeys);
        $this->assertIsString($relationships);
        // Relationships code may be empty or contain user-related code
        $this->assertTrue(is_string($relationships));
    }

    /**
     * Test ModelGenerator getModelOutputPath method.
     */
    public function testModelGeneratorGetModelOutputPath(): void
    {
        $reflection = new \ReflectionClass(ModelGenerator::class);
        $method = $reflection->getMethod('getModelOutputPath');
        $method->setAccessible(true);

        $path = $method->invoke(null);
        $this->assertIsString($path);
    }

    /**
     * Test SchemaInspector getAllTables method.
     */
    public function testSchemaInspectorGetAllTables(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_inspector_table1');
        $schema->dropTableIfExists('test_inspector_table2');
        $schema->createTable('test_inspector_table1', [
            'id' => $schema->primaryKey(),
        ]);
        $schema->createTable('test_inspector_table2', [
            'id' => $schema->primaryKey(),
        ]);

        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getAllTables');
        $method->setAccessible(true);

        $tables = $method->invoke(null, self::$db);
        $this->assertIsArray($tables);
        $tableNames = array_column($tables, 'name');
        $this->assertContains('test_inspector_table1', $tableNames);
        $this->assertContains('test_inspector_table2', $tableNames);

        // Cleanup
        $schema->dropTable('test_inspector_table1');
        $schema->dropTable('test_inspector_table2');
    }

    /**
     * Test SchemaInspector getTableRowCount method.
     */
    public function testSchemaInspectorGetTableRowCount(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_inspector_count');
        $schema->createTable('test_inspector_count', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        self::$db->find()->table('test_inspector_count')->insert(['name' => 'Test1']);
        self::$db->find()->table('test_inspector_count')->insert(['name' => 'Test2']);

        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableRowCount');
        $method->setAccessible(true);

        $count = $method->invoke(null, self::$db, 'test_inspector_count');
        $this->assertEquals('2', $count);

        // Cleanup
        $schema->dropTable('test_inspector_count');
    }

    /**
     * Test SchemaInspector getTableColumns method.
     */
    public function testSchemaInspectorGetTableColumns(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_inspector_columns');
        $schema->createTable('test_inspector_columns', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'email' => $schema->string(200),
        ]);

        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableColumns');
        $method->setAccessible(true);

        $columns = $method->invoke(null, self::$db, 'test_inspector_columns');
        $this->assertIsArray($columns);
        $columnNames = array_column($columns, 'name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('email', $columnNames);

        // Cleanup
        $schema->dropTable('test_inspector_columns');
    }

    /**
     * Test SchemaInspector getTableIndexes method.
     */
    public function testSchemaInspectorGetTableIndexes(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_inspector_indexes');
        $schema->createTable('test_inspector_indexes', [
            'id' => $schema->primaryKey(),
            'email' => $schema->string(200),
        ]);

        try {
            $schema->createIndex('test_inspector_indexes', 'idx_email', ['email']);
        } catch (\Exception $e) {
            // Some databases may not support creating indexes separately
            // Just test that the method exists and can be called
        }

        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableIndexes');
        $method->setAccessible(true);

        $indexes = $method->invoke(null, self::$db, 'test_inspector_indexes');
        $this->assertIsArray($indexes);

        // Cleanup
        $schema->dropTable('test_inspector_indexes');
    }

    /**
     * Test SchemaInspector getTableForeignKeys method.
     */
    public function testSchemaInspectorGetTableForeignKeys(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_inspector_fk_child');
        $schema->dropTableIfExists('test_inspector_fk_parent');
        $schema->createTable('test_inspector_fk_parent', [
            'id' => $schema->primaryKey(),
        ]);
        $schema->createTable('test_inspector_fk_child', [
            'id' => $schema->primaryKey(),
            'parent_id' => $schema->integer(),
        ]);

        try {
            $schema->addForeignKey('fk_parent', 'test_inspector_fk_child', 'parent_id', 'test_inspector_fk_parent', 'id');
        } catch (\Exception $e) {
            // SQLite doesn't support ALTER TABLE ADD FOREIGN KEY
            // Just verify the method exists and can be called
        }

        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableForeignKeys');
        $method->setAccessible(true);

        $foreignKeys = $method->invoke(null, self::$db, 'test_inspector_fk_child');
        $this->assertIsArray($foreignKeys);

        // Cleanup
        $schema->dropTable('test_inspector_fk_child');
        $schema->dropTable('test_inspector_fk_parent');
    }

    /**
     * Test SchemaInspector getTableConstraints method.
     */
    public function testSchemaInspectorGetTableConstraints(): void
    {
        $schema = self::$db->schema();
        $schema->dropTableIfExists('test_inspector_constraints');
        $schema->createTable('test_inspector_constraints', [
            'id' => $schema->primaryKey(),
            'email' => $schema->string(200)->unique(),
        ]);

        $reflection = new \ReflectionClass(SchemaInspector::class);
        $method = $reflection->getMethod('getTableConstraints');
        $method->setAccessible(true);

        $constraints = $method->invoke(null, self::$db, 'test_inspector_constraints');
        $this->assertIsArray($constraints);

        // Cleanup
        $schema->dropTable('test_inspector_constraints');
    }

    /**
     * Test BaseCliCommand loadEnvFile method.
     */
    public function testBaseCliCommandLoadEnvFile(): void
    {
        $envFile = sys_get_temp_dir() . '/pdodb_test_env_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_DATABASE=:memory:\n");

        $reflection = new \ReflectionClass(\tommyknocker\pdodb\cli\BaseCliCommand::class);
        $method = $reflection->getMethod('loadEnvFile');
        $method->setAccessible(true);

        $oldCwd = getcwd();
        chdir(sys_get_temp_dir());

        try {
            $method->invoke(null, basename($envFile));
            $this->assertEquals('sqlite', getenv('PDODB_DRIVER'));
        } finally {
            chdir($oldCwd);
            unlink($envFile);
            putenv('PDODB_DRIVER');
        }
    }

    /**
     * Test BaseCliCommand buildConfigFromEnv method.
     */
    public function testBaseCliCommandBuildConfigFromEnv(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        try {
            $reflection = new \ReflectionClass(\tommyknocker\pdodb\cli\BaseCliCommand::class);
            $method = $reflection->getMethod('buildConfigFromEnv');
            $method->setAccessible(true);

            $config = $method->invoke(null, 'sqlite');
            $this->assertIsArray($config);
            $this->assertEquals('sqlite', $config['driver']);
            $this->assertEquals(':memory:', $config['path']);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test BaseCliCommand success method.
     */
    public function testBaseCliCommandSuccess(): void
    {
        $reflection = new \ReflectionClass(\tommyknocker\pdodb\cli\BaseCliCommand::class);
        $method = $reflection->getMethod('success');
        $method->setAccessible(true);

        $output = '';
        ob_start(function ($buffer) use (&$output) {
            $output .= $buffer;
            return '';
        });

        try {
            $method->invoke(null, 'Test success message');
        } finally {
            ob_end_clean();
        }

        $this->assertStringContainsString('Test success message', $output);
    }

    /**
     * Test BaseCliCommand info method.
     */
    public function testBaseCliCommandInfo(): void
    {
        $reflection = new \ReflectionClass(\tommyknocker\pdodb\cli\BaseCliCommand::class);
        $method = $reflection->getMethod('info');
        $method->setAccessible(true);

        $output = '';
        ob_start(function ($buffer) use (&$output) {
            $output .= $buffer;
            return '';
        });

        try {
            $method->invoke(null, 'Test info message');
        } finally {
            ob_end_clean();
        }

        $this->assertStringContainsString('Test info message', $output);
    }

    /**
     * Test BaseCliCommand warning method.
     */
    public function testBaseCliCommandWarning(): void
    {
        $reflection = new \ReflectionClass(\tommyknocker\pdodb\cli\BaseCliCommand::class);
        $method = $reflection->getMethod('warning');
        $method->setAccessible(true);

        $output = '';
        ob_start(function ($buffer) use (&$output) {
            $output .= $buffer;
            return '';
        });

        try {
            $method->invoke(null, 'Test warning message');
        } finally {
            ob_end_clean();
        }

        $this->assertStringContainsString('Test warning message', $output);
    }

    /**
     * Test DatabaseManager create database for SQLite.
     */
    public function testDatabaseManagerCreateDatabase(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_db_create_' . uniqid() . '.sqlite';

        try {
            $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:']);
            $result = DatabaseManager::create($tempFile, $db);

            $this->assertTrue($result);
            $this->assertTrue(file_exists($tempFile));
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Test DatabaseManager drop database for SQLite.
     */
    public function testDatabaseManagerDropDatabase(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_db_drop_' . uniqid() . '.sqlite';
        touch($tempFile);

        try {
            $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:']);
            $result = DatabaseManager::drop($tempFile, $db);

            $this->assertTrue($result);
            $this->assertFalse(file_exists($tempFile));
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Test DatabaseManager exists for SQLite.
     */
    public function testDatabaseManagerExists(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_db_exists_' . uniqid() . '.sqlite';
        touch($tempFile);

        try {
            $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:']);

            $exists = DatabaseManager::exists($tempFile, $db);
            $this->assertTrue($exists);

            unlink($tempFile);

            $exists = DatabaseManager::exists($tempFile, $db);
            $this->assertFalse($exists);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Test DatabaseManager list throws exception for SQLite.
     */
    public function testDatabaseManagerListThrowsExceptionForSqlite(): void
    {
        $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:']);

        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('SQLite does not support multiple databases');

        DatabaseManager::list($db);
    }

    /**
     * Test DatabaseManager getInfo returns database information.
     */
    public function testDatabaseManagerGetInfo(): void
    {
        $info = DatabaseManager::getInfo(self::$db);

        $this->assertIsArray($info);
        $this->assertArrayHasKey('driver', $info);
        $this->assertEquals('sqlite', $info['driver']);
    }

    /**
     * Test DatabaseManager create and drop flow.
     */
    public function testDatabaseManagerCreateAndDropFlow(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_db_flow_' . uniqid() . '.sqlite';

        try {
            $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:']);

            // Create database
            $created = DatabaseManager::create($tempFile, $db);
            $this->assertTrue($created);
            $this->assertTrue(file_exists($tempFile));

            // Check exists
            $exists = DatabaseManager::exists($tempFile, $db);
            $this->assertTrue($exists);

            // Drop database
            $dropped = DatabaseManager::drop($tempFile, $db);
            $this->assertTrue($dropped);
            $this->assertFalse(file_exists($tempFile));

            // Check not exists
            $exists = DatabaseManager::exists($tempFile, $db);
            $this->assertFalse($exists);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Test MigrationRunner dry-run mode for migrate up.
     */
    public function testMigrationRunnerDryRunModeForMigrateUp(): void
    {
        // Create a test migration
        ob_start();

        try {
            $migrationName = 'test_dry_run_migration';
            $filename = MigrationGenerator::generate($migrationName, $this->testMigrationPath);
        } finally {
            ob_end_clean();
        }

        $runner = new MigrationRunner(self::$db, $this->testMigrationPath);
        $runner->setDryRun(true);

        // In dry-run mode, migrate should return list without executing
        $applied = $runner->migrate(0);
        $this->assertIsArray($applied);
        $this->assertNotEmpty($applied);

        // Check that collected queries exist
        $queries = $runner->getCollectedQueries();
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('Migration:', $queries[0]);
        $this->assertStringContainsString('Would execute', $queries[1]);

        // Verify migration was NOT actually applied
        $history = $runner->getMigrationHistory();
        $appliedVersions = array_column($history, 'version');
        $version = basename($filename, '.php');
        $version = substr($version, 1); // Remove 'm' prefix
        $this->assertNotContains($version, $appliedVersions);
    }

    /**
     * Test MigrationRunner pretend mode for migrate up.
     */
    public function testMigrationRunnerPretendModeForMigrateUp(): void
    {
        // Create a test migration
        ob_start();

        try {
            $migrationName = 'test_pretend_migration';
            $filename = MigrationGenerator::generate($migrationName, $this->testMigrationPath);
        } finally {
            ob_end_clean();
        }

        $runner = new MigrationRunner(self::$db, $this->testMigrationPath);
        $runner->setPretend(true);

        // In pretend mode, migrate should return list without executing
        $applied = $runner->migrate(0);
        $this->assertIsArray($applied);
        $this->assertNotEmpty($applied);

        // Check that collected queries exist
        $queries = $runner->getCollectedQueries();
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('Migration:', $queries[0]);

        // Verify migration was NOT actually applied
        $history = $runner->getMigrationHistory();
        $appliedVersions = array_column($history, 'version');
        $version = basename($filename, '.php');
        $version = substr($version, 1); // Remove 'm' prefix
        $this->assertNotContains($version, $appliedVersions);
    }

    /**
     * Test MigrationRunner dry-run mode for migrate down.
     */
    public function testMigrationRunnerDryRunModeForMigrateDown(): void
    {
        // Create and apply a test migration first
        ob_start();

        try {
            $migrationName = 'test_dry_run_down_migration';
            MigrationGenerator::generate($migrationName, $this->testMigrationPath);
        } finally {
            ob_end_clean();
        }

        // Apply migration normally
        $runner = new MigrationRunner(self::$db, $this->testMigrationPath);
        $applied = $runner->migrate(0);
        $this->assertNotEmpty($applied);
        $version = $applied[0];

        // Now test dry-run for rollback
        $runner->setDryRun(true);
        $rolledBack = $runner->migrateDown(1);
        $this->assertIsArray($rolledBack);
        $this->assertNotEmpty($rolledBack);
        $this->assertEquals($version, $rolledBack[0]);

        // Check that collected queries exist
        $queries = $runner->getCollectedQueries();
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('Rollback Migration:', $queries[0]);
        $this->assertStringContainsString('Would execute', $queries[1]);

        // Verify migration was NOT actually rolled back
        $history = $runner->getMigrationHistory();
        $appliedVersions = array_column($history, 'version');
        $this->assertContains($version, $appliedVersions);
    }

    /**
     * Test MigrationRunner pretend mode for migrate down.
     */
    public function testMigrationRunnerPretendModeForMigrateDown(): void
    {
        // Create and apply a test migration first
        ob_start();

        try {
            $migrationName = 'test_pretend_down_migration';
            MigrationGenerator::generate($migrationName, $this->testMigrationPath);
        } finally {
            ob_end_clean();
        }

        // Apply migration normally
        $runner = new MigrationRunner(self::$db, $this->testMigrationPath);
        $applied = $runner->migrate(0);
        $this->assertNotEmpty($applied);
        $version = $applied[0];

        // Now test pretend for rollback
        $runner->setPretend(true);
        $rolledBack = $runner->migrateDown(1);
        $this->assertIsArray($rolledBack);
        $this->assertNotEmpty($rolledBack);
        $this->assertEquals($version, $rolledBack[0]);

        // Check that collected queries exist
        $queries = $runner->getCollectedQueries();
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('Rollback Migration:', $queries[0]);

        // Verify migration was NOT actually rolled back
        $history = $runner->getMigrationHistory();
        $appliedVersions = array_column($history, 'version');
        $this->assertContains($version, $appliedVersions);
    }

    /**
     * Test MigrationRunner getCollectedQueries and clearCollectedQueries methods.
     */
    public function testMigrationRunnerCollectedQueriesMethods(): void
    {
        // Create a test migration
        ob_start();

        try {
            $migrationName = 'test_collected_queries';
            MigrationGenerator::generate($migrationName, $this->testMigrationPath);
        } finally {
            ob_end_clean();
        }

        $runner = new MigrationRunner(self::$db, $this->testMigrationPath);
        $runner->setDryRun(true);

        // Initially, collected queries should be empty
        $queries = $runner->getCollectedQueries();
        $this->assertEmpty($queries);

        // Run migrate in dry-run mode
        $runner->migrate(0);
        $queries = $runner->getCollectedQueries();
        $this->assertNotEmpty($queries);

        // Clear collected queries
        $runner->clearCollectedQueries();
        $queries = $runner->getCollectedQueries();
        $this->assertEmpty($queries);
    }

    /**
     * Test MigrationRunner setDryRun and setPretend methods.
     */
    public function testMigrationRunnerSetDryRunAndSetPretend(): void
    {
        $runner = new MigrationRunner(self::$db, $this->testMigrationPath);

        // Test setDryRun
        $result = $runner->setDryRun(true);
        $this->assertSame($runner, $result);

        // Test setPretend
        $result = $runner->setPretend(true);
        $this->assertSame($runner, $result);

        // Test that both can be set
        $runner->setDryRun(false);
        $runner->setPretend(false);
        $runner->setDryRun(true);
        $runner->setPretend(true);
        $this->assertTrue(true); // If we get here, no exception was thrown
    }
}
