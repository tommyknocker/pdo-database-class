<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * Shared tests for DDL Query Builder functionality.
 *
 * These tests verify that DDL operations work consistently across all dialects.
 */
class DdlQueryBuilderTests extends BaseSharedTestCase
{
    /**
     * Get database instance.
     *
     * @return PdoDb
     */
    protected static function getDb(): PdoDb
    {
        return self::$db;
    }

    /**
     * Test creating a table with ColumnSchema objects.
     */
    public function testCreateTableWithColumnSchema(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_table');

        $schema->createTable('test_ddl_table', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
            'email' => $schema->string(255)->notNull()->unique(),
            'age' => $schema->integer(),
            'created_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->assertTrue($schema->tableExists('test_ddl_table'));

        // Verify table structure
        $columns = $db->describe('test_ddl_table');
        $this->assertNotEmpty($columns);

        // Cleanup
        $schema->dropTable('test_ddl_table');
    }

    /**
     * Test creating a table with array column definitions.
     */
    public function testCreateTableWithArrayDefinitions(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_array');

        $schema->createTable('test_ddl_array', [
            'id' => ['type' => 'INT', 'autoIncrement' => true, 'null' => false],
            'name' => ['type' => 'VARCHAR', 'length' => 100, 'null' => false],
            'value' => ['type' => 'TEXT'],
        ]);

        $this->assertTrue($schema->tableExists('test_ddl_array'));

        // Cleanup
        $schema->dropTable('test_ddl_array');
    }

    /**
     * Test creating a table with string column definitions.
     */
    public function testCreateTableWithStringDefinitions(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_string');

        $schema->createTable('test_ddl_string', [
            'id' => 'INT PRIMARY KEY',
            'name' => 'VARCHAR(100) NOT NULL',
            'description' => 'TEXT',
        ]);

        $this->assertTrue($schema->tableExists('test_ddl_string'));

        // Cleanup
        $schema->dropTable('test_ddl_string');
    }

    /**
     * Test drop table.
     */
    public function testDropTable(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        // Create table first
        $schema->createTable('test_ddl_drop', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        $this->assertTrue($schema->tableExists('test_ddl_drop'));

        // Drop table
        $schema->dropTable('test_ddl_drop');

        $this->assertFalse($schema->tableExists('test_ddl_drop'));
    }

    /**
     * Test drop table if exists.
     */
    public function testDropTableIfExists(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        // Create table first
        $schema->createTable('test_ddl_drop_if', [
            'id' => $schema->primaryKey(),
        ]);

        // Drop table (should not throw error)
        $schema->dropTableIfExists('test_ddl_drop_if');
        $this->assertFalse($schema->tableExists('test_ddl_drop_if'));

        // Drop again (should not throw error)
        $schema->dropTableIfExists('test_ddl_drop_if');
    }

    /**
     * Test create table if not exists.
     */
    public function testCreateTableIfNotExists(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_create_if');

        // Create table
        $schema->createTableIfNotExists('test_ddl_create_if', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        $this->assertTrue($schema->tableExists('test_ddl_create_if'));

        // Try to create again (should not throw error)
        $schema->createTableIfNotExists('test_ddl_create_if', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        // Cleanup
        $schema->dropTable('test_ddl_create_if');
    }

    /**
     * Test rename table.
     */
    public function testRenameTable(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_rename');
        $schema->dropTableIfExists('test_ddl_renamed');

        // Create table
        $schema->createTable('test_ddl_rename', [
            'id' => $schema->primaryKey(),
        ]);

        // Rename table
        $schema->renameTable('test_ddl_rename', 'test_ddl_renamed');

        $this->assertFalse($schema->tableExists('test_ddl_rename'));
        $this->assertTrue($schema->tableExists('test_ddl_renamed'));

        // Cleanup
        $schema->dropTable('test_ddl_renamed');
    }

    /**
     * Test add column.
     */
    public function testAddColumn(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_add_col');

        // Create table
        $schema->createTable('test_ddl_add_col', [
            'id' => $schema->primaryKey(),
        ]);

        // Add column
        $schema->addColumn('test_ddl_add_col', 'name', $schema->string(100)->notNull());

        $columns = $db->describe('test_ddl_add_col');
        $columnNames = array_column($columns, 'Field') ?: array_column($columns, 'column_name') ?: array_column($columns, 'name');
        $this->assertContains('name', $columnNames);

        // Cleanup
        $schema->dropTable('test_ddl_add_col');
    }

    /**
     * Test drop column.
     */
    public function testDropColumn(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $driver = $db->connection->getDialect()->getDriverName();
        if ($driver === 'sqlite') {
            // SQLite DROP COLUMN may not work on older versions
            $this->markTestSkipped('SQLite DROP COLUMN support varies by version');
        }

        $schema->dropTableIfExists('test_ddl_drop_col');

        // Create table with column
        $schema->createTable('test_ddl_drop_col', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        // Drop column
        $schema->dropColumn('test_ddl_drop_col', 'name');

        $columns = $db->describe('test_ddl_drop_col');
        $columnNames = array_column($columns, 'Field') ?: array_column($columns, 'column_name') ?: array_column($columns, 'name');
        $this->assertNotContains('name', $columnNames);

        // Cleanup
        $schema->dropTable('test_ddl_drop_col');
    }

    /**
     * Test rename column.
     */
    public function testRenameColumn(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_rename_col');

        // Create table
        $schema->createTable('test_ddl_rename_col', [
            'id' => $schema->primaryKey(),
            'old_name' => $schema->string(100),
        ]);

        // Rename column
        $schema->renameColumn('test_ddl_rename_col', 'old_name', 'new_name');

        $columns = $db->describe('test_ddl_rename_col');
        $columnNames = array_column($columns, 'Field') ?: array_column($columns, 'column_name') ?: array_column($columns, 'name');
        $this->assertNotContains('old_name', $columnNames);
        $this->assertContains('new_name', $columnNames);

        // Cleanup
        $schema->dropTable('test_ddl_rename_col');
    }

    /**
     * Test create index.
     */
    public function testCreateIndex(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_index');

        // Create table
        $schema->createTable('test_ddl_index', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        // Create index
        $schema->createIndex('idx_test_name', 'test_ddl_index', 'name');

        $indexes = $db->indexes('test_ddl_index');
        $indexNames = array_column($indexes, 'name') ?: array_column($indexes, 'Key_name') ?: array_column($indexes, 'index_name');
        $this->assertContains('idx_test_name', $indexNames);

        // Cleanup - try to drop index (may fail if it doesn't exist, but that's ok)
        try {
            $schema->dropIndex('idx_test_name', 'test_ddl_index');
        } catch (\Throwable $e) {
            // Index might not exist or already dropped, ignore
        }
        $schema->dropTable('test_ddl_index');
    }

    /**
     * Test create unique index.
     */
    public function testCreateUniqueIndex(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_unique_index');

        // Try to drop index if exists (may fail, but that's ok)
        try {
            $schema->dropIndex('idx_test_email_unique', 'test_ddl_unique_index');
        } catch (\Exception $e) {
            // Index might not exist, ignore
        }

        // Create table
        $schema->createTable('test_ddl_unique_index', [
            'id' => $schema->primaryKey(),
            'email' => $schema->string(255),
        ]);

        // Create unique index
        $schema->createIndex('idx_test_email_unique', 'test_ddl_unique_index', 'email', true);

        $indexes = $db->indexes('test_ddl_unique_index');
        $indexNames = array_column($indexes, 'name') ?: array_column($indexes, 'Key_name') ?: array_column($indexes, 'index_name');
        $this->assertContains('idx_test_email_unique', $indexNames);

        // Cleanup - try to drop index (may fail if it doesn't exist, but that's ok)
        try {
            $schema->dropIndex('idx_test_email_unique', 'test_ddl_unique_index');
        } catch (\Throwable $e) {
            // Index might not exist or already dropped, ignore
        }
        $schema->dropTable('test_ddl_unique_index');
    }

    /**
     * Test column schema builders.
     */
    public function testColumnSchemaBuilders(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $this->assertInstanceOf(ColumnSchema::class, $schema->primaryKey());
        $this->assertInstanceOf(ColumnSchema::class, $schema->string(100));
        $this->assertInstanceOf(ColumnSchema::class, $schema->text());
        $this->assertInstanceOf(ColumnSchema::class, $schema->integer());
        $this->assertInstanceOf(ColumnSchema::class, $schema->boolean());
        $this->assertInstanceOf(ColumnSchema::class, $schema->decimal(10, 2));
        $this->assertInstanceOf(ColumnSchema::class, $schema->timestamp());
        $this->assertInstanceOf(ColumnSchema::class, $schema->json());
    }

    /**
     * Test ColumnSchema fluent API.
     */
    public function testColumnSchemaFluentApi(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $colSchema = $schema->string(255)
            ->notNull()
            ->defaultValue('default')
            ->comment('Test column');

        $this->assertTrue($colSchema->isNotNull());
        $this->assertEquals('default', $colSchema->getDefaultValue());
        $this->assertEquals('Test column', $colSchema->getComment());
        $this->assertEquals(255, $colSchema->getLength());
    }

    /**
     * Test truncate table.
     */
    public function testTruncateTable(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_truncate');

        // Create table and insert data
        $schema->createTable('test_ddl_truncate', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        $db->find()->table('test_ddl_truncate')->insert(['name' => 'Test']);
        $countBefore = $db->find()->from('test_ddl_truncate')->select(['cnt' => 'COUNT(*)'])->getValue();
        $this->assertEquals(1, (int)$countBefore);

        // Truncate table
        $schema->truncateTable('test_ddl_truncate');

        $countAfter = $db->find()->from('test_ddl_truncate')->select(['cnt' => 'COUNT(*)'])->getValue();
        $this->assertEquals(0, (int)$countAfter);

        // Cleanup
        $schema->dropTable('test_ddl_truncate');
    }
}
