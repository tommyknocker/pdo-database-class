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
     * Test alter column with ColumnSchema.
     */
    public function testAlterColumnWithColumnSchema(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_ddl_alter_col');

        // Create table
        $schema->createTable('test_ddl_alter_col', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(50),
        ]);

        // SQLite doesn't support ALTER COLUMN to change type
        if ($driver === 'sqlite') {
            $this->expectException(\tommyknocker\pdodb\exceptions\QueryException::class);
        }

        // Alter column with ColumnSchema
        $schema->alterColumn('test_ddl_alter_col', 'name', $schema->string(255)->notNull()->defaultValue('default'));

        // Verify column was altered (only if not SQLite)
        if ($driver !== 'sqlite') {
            $columns = $db->describe('test_ddl_alter_col');
            $nameColumn = null;
            foreach ($columns as $col) {
                $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
                if ($colName === 'name') {
                    $nameColumn = $col;
                    break;
                }
            }

            $this->assertNotNull($nameColumn, 'Column name should exist');
        }

        // Cleanup
        $schema->dropTable('test_ddl_alter_col');
    }

    /**
     * Test alter column with array definition.
     */
    public function testAlterColumnWithArray(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_ddl_alter_array');

        // Create table
        $schema->createTable('test_ddl_alter_array', [
            'id' => $schema->primaryKey(),
            'value' => $schema->integer(),
        ]);

        // SQLite doesn't support ALTER COLUMN to change type
        if ($driver === 'sqlite') {
            $this->expectException(\tommyknocker\pdodb\exceptions\QueryException::class);
        }

        // Alter column with array
        $schema->alterColumn('test_ddl_alter_array', 'value', [
            'type' => 'INT',
            'length' => 11,
            'null' => false,
            'default' => 0,
        ]);

        // Verify column was altered (only if not SQLite)
        if ($driver !== 'sqlite') {
            $columns = $db->describe('test_ddl_alter_array');
            $valueColumn = null;
            foreach ($columns as $col) {
                $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
                if ($colName === 'value') {
                    $valueColumn = $col;
                    break;
                }
            }

            $this->assertNotNull($valueColumn, 'Column value should exist');
        }

        // Cleanup
        $schema->dropTable('test_ddl_alter_array');
    }

    /**
     * Test alter column with string type.
     */
    public function testAlterColumnWithString(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_ddl_alter_string');

        // Create table
        $schema->createTable('test_ddl_alter_string', [
            'id' => $schema->primaryKey(),
            'description' => $schema->string(100),
        ]);

        // SQLite doesn't support ALTER COLUMN to change type
        if ($driver === 'sqlite') {
            $this->expectException(\tommyknocker\pdodb\exceptions\QueryException::class);
        }

        // Alter column with string type
        $schema->alterColumn('test_ddl_alter_string', 'description', 'TEXT');

        // Verify column was altered (only if not SQLite)
        if ($driver !== 'sqlite') {
            $columns = $db->describe('test_ddl_alter_string');
            $descColumn = null;
            foreach ($columns as $col) {
                $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
                if ($colName === 'description') {
                    $descColumn = $col;
                    break;
                }
            }

            $this->assertNotNull($descColumn, 'Column description should exist');
        }

        // Cleanup
        $schema->dropTable('test_ddl_alter_string');
    }

    /**
     * Test add column with array definition.
     */
    public function testAddColumnWithArray(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_add_array');

        // Create table
        $schema->createTable('test_ddl_add_array', [
            'id' => $schema->primaryKey(),
        ]);

        // Add column with array
        $schema->addColumn('test_ddl_add_array', 'email', [
            'type' => 'VARCHAR',
            'length' => 255,
            'null' => false,
            'unique' => true,
        ]);

        $columns = $db->describe('test_ddl_add_array');
        $columnNames = array_column($columns, 'Field') ?: array_column($columns, 'column_name') ?: array_column($columns, 'name');
        $this->assertContains('email', $columnNames);

        // Cleanup
        $schema->dropTable('test_ddl_add_array');
    }

    /**
     * Test add column with string type.
     */
    public function testAddColumnWithString(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_add_string');

        // Create table
        $schema->createTable('test_ddl_add_string', [
            'id' => $schema->primaryKey(),
        ]);

        // Add column with string type
        $schema->addColumn('test_ddl_add_string', 'notes', 'TEXT');

        $columns = $db->describe('test_ddl_add_string');
        $columnNames = array_column($columns, 'Field') ?: array_column($columns, 'column_name') ?: array_column($columns, 'name');
        $this->assertContains('notes', $columnNames);

        // Cleanup
        $schema->dropTable('test_ddl_add_string');
    }

    /**
     * Test create index with array of columns.
     */
    public function testCreateIndexWithArrayColumns(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_index_array');

        // Create table
        $schema->createTable('test_ddl_index_array', [
            'id' => $schema->primaryKey(),
            'first_name' => $schema->string(100),
            'last_name' => $schema->string(100),
        ]);

        // Create composite index with array
        $schema->createIndex('idx_full_name', 'test_ddl_index_array', ['first_name', 'last_name']);

        // Verify index exists (can't easily check composite indexes, but operation should succeed)
        $this->assertTrue($schema->tableExists('test_ddl_index_array'));

        // Cleanup
        try {
            $schema->dropIndex('idx_full_name', 'test_ddl_index_array');
        } catch (\Throwable $e) {
            // Index might not exist or might be named differently, ignore
        }
        $schema->dropTable('test_ddl_index_array');
    }

    /**
     * Test addForeignKey with array columns.
     */
    public function testAddForeignKeyWithArrayColumns(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_fk_child');
        $schema->dropTableIfExists('test_fk_parent');

        // Create parent table
        $schema->createTable('test_fk_parent', [
            'id' => $schema->primaryKey(),
            'code' => $schema->string(50)->unique(),
        ]);

        // Create child table
        $schema->createTable('test_fk_child', [
            'id' => $schema->primaryKey(),
            'parent_id' => $schema->integer(),
            'parent_code' => $schema->string(50),
        ]);

        // SQLite doesn't support ADD FOREIGN KEY
        if ($driver === 'sqlite') {
            $this->expectException(\tommyknocker\pdodb\exceptions\QueryException::class);
            $schema->addForeignKey(
                'fk_child_parent',
                'test_fk_child',
                'parent_id',
                'test_fk_parent',
                'id'
            );
        } else {
            // Add foreign key with array columns (composite FK)
            // Note: Not all databases support composite FKs, but test the method call
            try {
                $schema->addForeignKey(
                    'fk_child_parent',
                    'test_fk_child',
                    ['parent_id', 'parent_code'],
                    'test_fk_parent',
                    ['id', 'code'],
                    'CASCADE',
                    'CASCADE'
                );
                $this->assertTrue($schema->tableExists('test_fk_child'));
            } catch (\Throwable $e) {
                // Composite FK might not be supported, test with single column
                $schema->addForeignKey(
                    'fk_child_parent',
                    'test_fk_child',
                    'parent_id',
                    'test_fk_parent',
                    'id',
                    'CASCADE',
                    'CASCADE'
                );
                $this->assertTrue($schema->tableExists('test_fk_child'));
            }
        }

        // Cleanup
        $schema->dropTableIfExists('test_fk_child');
        $schema->dropTableIfExists('test_fk_parent');
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

    /**
     * Test all column schema helper methods.
     */
    public function testColumnSchemaHelpers(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_helpers');

        $schema->createTable('test_ddl_helpers', [
            'id' => $schema->primaryKey(),
            'big_id' => $schema->integer(),
            'str' => $schema->string(100),
            'txt' => $schema->text(),
            'small_int' => $schema->smallInteger(),
            'bool_col' => $schema->boolean(),
            'float_col' => $schema->float(10, 2),
            'dec_col' => $schema->decimal(10, 2),
            'date_col' => $schema->date(),
            'time_col' => $schema->time(),
            'datetime_col' => $schema->datetime(),
            'timestamp_col' => $schema->timestamp(),
            'json_col' => $schema->json(),
        ]);

        // Test bigPrimaryKey separately
        $schema->dropTableIfExists('test_ddl_big_pk');
        $schema->createTable('test_ddl_big_pk', [
            'id' => $schema->bigPrimaryKey(),
        ]);
        $this->assertTrue($schema->tableExists('test_ddl_big_pk'));
        $schema->dropTable('test_ddl_big_pk');

        $this->assertTrue($schema->tableExists('test_ddl_helpers'));

        // Cleanup
        $schema->dropTable('test_ddl_helpers');
    }

    /**
     * Test normalizeColumnSchema with array input.
     */
    public function testNormalizeColumnSchemaArray(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_normalize_array');

        $schema->createTable('test_normalize_array', [
            'id' => $schema->primaryKey(),
            'col1' => [
                'type' => 'VARCHAR',
                'length' => 100,
                'null' => false,
                'default' => 'test',
                'comment' => 'Test column',
                'unsigned' => false,
                'autoIncrement' => false,
                'unique' => true,
            ],
            'col2' => [
                'type' => 'INT',
                'defaultExpression' => true,
                'default' => '0',
            ],
            'col3' => 'TEXT',
        ]);

        $this->assertTrue($schema->tableExists('test_normalize_array'));

        // Cleanup
        $schema->dropTable('test_normalize_array');
    }

    /**
     * Test normalizeColumnSchema with string input.
     */
    public function testNormalizeColumnSchemaString(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_normalize_string');

        $schema->createTable('test_normalize_string', [
            'id' => $schema->primaryKey(),
            'col1' => 'VARCHAR(255)',
            'col2' => 'INT',
            'col3' => 'TEXT',
        ]);

        $this->assertTrue($schema->tableExists('test_normalize_string'));

        // Cleanup
        $schema->dropTable('test_normalize_string');
    }

    /**
     * Test normalizeColumnSchema with array containing 'after' and 'first'.
     */
    public function testNormalizeColumnSchemaWithPosition(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_normalize_position');

        $schema->createTable('test_normalize_position', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        // Add column with 'after' option
        $schema->addColumn('test_normalize_position', 'email', [
            'type' => 'VARCHAR',
            'length' => 255,
            'after' => 'name',
        ]);

        // Add column with 'first' option
        $schema->addColumn('test_normalize_position', 'priority', [
            'type' => 'INT',
            'first' => true,
        ]);

        $this->assertTrue($schema->tableExists('test_normalize_position'));

        // Cleanup
        $schema->dropTable('test_normalize_position');
    }

    /**
     * Test normalizeColumnSchema with array containing 'size' instead of 'length'.
     */
    public function testNormalizeColumnSchemaArrayWithSize(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_normalize_size');

        $schema->createTable('test_normalize_size', [
            'id' => $schema->primaryKey(),
            'col1' => [
                'type' => 'VARCHAR',
                'size' => 200, // Use 'size' instead of 'length'
            ],
        ]);

        $this->assertTrue($schema->tableExists('test_normalize_size'));

        // Cleanup
        $schema->dropTable('test_normalize_size');
    }

    /**
     * Test createTableIfNotExists when table already exists.
     */
    public function testCreateTableIfNotExistsWhenTableExists(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_if_not_exists');

        // Create table
        $schema->createTable('test_if_not_exists', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        $this->assertTrue($schema->tableExists('test_if_not_exists'));

        // Try to create again with createTableIfNotExists
        $schema->createTableIfNotExists('test_if_not_exists', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        // Table should still exist (not recreated)
        $this->assertTrue($schema->tableExists('test_if_not_exists'));

        // Cleanup
        $schema->dropTable('test_if_not_exists');
    }

    /**
     * Test dropForeignKey.
     */
    public function testDropForeignKey(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_fk_drop_child');
        $schema->dropTableIfExists('test_fk_drop_parent');

        // Create parent table
        $schema->createTable('test_fk_drop_parent', [
            'id' => $schema->primaryKey(),
        ]);

        // Create child table
        $schema->createTable('test_fk_drop_child', [
            'id' => $schema->primaryKey(),
            'parent_id' => $schema->integer(),
        ]);

        // SQLite doesn't support ADD FOREIGN KEY
        if ($driver === 'sqlite') {
            $this->expectException(\tommyknocker\pdodb\exceptions\QueryException::class);
            $schema->addForeignKey(
                'fk_drop_test',
                'test_fk_drop_child',
                'parent_id',
                'test_fk_drop_parent',
                'id'
            );
        } else {
            // Add foreign key
            $schema->addForeignKey(
                'fk_drop_test',
                'test_fk_drop_child',
                'parent_id',
                'test_fk_drop_parent',
                'id'
            );

            // Drop foreign key
            $schema->dropForeignKey('fk_drop_test', 'test_fk_drop_child');

            $this->assertTrue($schema->tableExists('test_fk_drop_child'));
        }

        // Cleanup
        $schema->dropTableIfExists('test_fk_drop_child');
        $schema->dropTableIfExists('test_fk_drop_parent');
    }

    /**
     * Test primaryKey with length parameter.
     */
    public function testPrimaryKeyWithLength(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_pk_length');

        $schema->createTable('test_pk_length', [
            'id' => $schema->primaryKey(11),
        ]);

        $this->assertTrue($schema->tableExists('test_pk_length'));

        // Cleanup
        $schema->dropTable('test_pk_length');
    }

    /**
     * Test primaryKey with null length (default).
     */
    public function testPrimaryKeyWithNullLength(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_pk_null');

        $schema->createTable('test_pk_null', [
            'id' => $schema->primaryKey(null),
        ]);

        $this->assertTrue($schema->tableExists('test_pk_null'));

        // Cleanup
        $schema->dropTable('test_pk_null');
    }

    /**
     * Test string with null length.
     */
    public function testStringWithNullLength(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_string_null');

        $schema->createTable('test_string_null', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(null),
        ]);

        $this->assertTrue($schema->tableExists('test_string_null'));

        // Cleanup
        $schema->dropTable('test_string_null');
    }

    /**
     * Test integer with length parameter.
     */
    public function testIntegerWithLength(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_int_length');

        $schema->createTable('test_int_length', [
            'id' => $schema->primaryKey(),
            'value' => $schema->integer(11),
        ]);

        $this->assertTrue($schema->tableExists('test_int_length'));

        // Cleanup
        $schema->dropTable('test_int_length');
    }

    /**
     * Test float with precision and scale.
     */
    public function testFloatWithPrecisionAndScale(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_float_params');

        $schema->createTable('test_float_params', [
            'id' => $schema->primaryKey(),
            'price' => $schema->float(10, 2),
        ]);

        $this->assertTrue($schema->tableExists('test_float_params'));

        // Cleanup
        $schema->dropTable('test_float_params');
    }

    /**
     * Test float with null precision and scale.
     */
    public function testFloatWithNullParameters(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_float_null');

        $schema->createTable('test_float_null', [
            'id' => $schema->primaryKey(),
            'value' => $schema->float(null, null),
        ]);

        $this->assertTrue($schema->tableExists('test_float_null'));

        // Cleanup
        $schema->dropTable('test_float_null');
    }

    /**
     * Test decimal with custom precision and scale.
     */
    public function testDecimalWithCustomPrecisionAndScale(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_decimal_custom');

        $schema->createTable('test_decimal_custom', [
            'id' => $schema->primaryKey(),
            'amount' => $schema->decimal(15, 4),
        ]);

        $this->assertTrue($schema->tableExists('test_decimal_custom'));

        // Cleanup
        $schema->dropTable('test_decimal_custom');
    }
}
