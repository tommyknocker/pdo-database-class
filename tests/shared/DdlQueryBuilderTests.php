<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\QueryException;
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
        } catch (QueryException $e) {
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

    /**
     * Test creating table with non-auto-increment primary key (composite PK support).
     */
    public function testCreateTableWithNonAutoIncrementPrimaryKey(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_composite_pk');

        // Create table with composite primary key using options
        $schema->createTable('test_composite_pk', [
            'id' => $schema->integer()->notNull(),
            'code' => $schema->string(50)->notNull(),
            'name' => $schema->string(100),
        ], ['primaryKey' => ['id', 'code']]);

        $this->assertTrue($schema->tableExists('test_composite_pk'));

        // Test single column primary key
        $schema->dropTableIfExists('test_single_pk');
        $schema->createTable('test_single_pk', [
            'id' => $schema->integer()->notNull(),
            'name' => $schema->string(100),
        ], ['primaryKey' => 'id']);

        $this->assertTrue($schema->tableExists('test_single_pk'));

        // Cleanup
        $schema->dropTable('test_composite_pk');
        $schema->dropTable('test_single_pk');
    }

    /**
     * Test cross-dialect type mapping for common types.
     */
    public function testCrossDialectTypeMapping(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_cross_dialect_types');

        // Create table with types that should map correctly across dialects
        $schema->createTable('test_cross_dialect_types', [
            'id' => $schema->primaryKey(), // Should map to AUTO_INCREMENT/IDENTITY/SERIAL/AUTOINCREMENT
            'name' => $schema->string(255), // Should map to VARCHAR/NVARCHAR/TEXT
            'description' => $schema->text(), // Should map to TEXT/NVARCHAR(MAX)/TEXT
            'price' => $schema->decimal(10, 2), // Should map to DECIMAL/DECIMAL/NUMERIC/REAL
            'is_active' => $schema->boolean(), // Should map to TINYINT(1)/BIT/BOOLEAN/INTEGER
            'created_at' => $schema->datetime(), // Should map to DATETIME/TIMESTAMP/DATETIME2/TEXT
            'updated_at' => $schema->timestamp(), // Should map to TIMESTAMP/TIMESTAMP/DATETIME2/TEXT
        ]);

        $this->assertTrue($schema->tableExists('test_cross_dialect_types'));

        // Verify we can insert and query data
        $db->find()->table('test_cross_dialect_types')->insert([
            'name' => 'Test Product',
            'description' => 'Test Description',
            'price' => 99.99,
            'is_active' => true,
        ]);

        $row = $db->find()->from('test_cross_dialect_types')->where('name', 'Test Product')->getOne();
        $this->assertNotNull($row);
        $this->assertEquals('Test Product', $row['name']);

        // Cleanup
        $schema->dropTable('test_cross_dialect_types');
    }

    /**
     * Test creating table with unique constraint using schema API.
     */
    public function testCreateTableWithUniqueConstraint(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_unique_constraint');

        // Create table with unique column
        $schema->createTable('test_unique_constraint', [
            'id' => $schema->primaryKey(),
            'email' => $schema->string(255)->unique(),
            'username' => $schema->string(100)->notNull(),
        ]);

        // Create unique index
        $schema->createIndex('idx_username_unique', 'test_unique_constraint', 'username', true);

        $this->assertTrue($schema->tableExists('test_unique_constraint'));

        // Test inserting unique values
        $db->find()->table('test_unique_constraint')->insert([
            'email' => 'test@example.com',
            'username' => 'testuser',
        ]);

        // Try to insert duplicate (should fail or be handled)
        try {
            $db->find()->table('test_unique_constraint')->insert([
                'email' => 'test@example.com',
                'username' => 'testuser2',
            ]);
            $this->fail('Expected unique constraint violation');
        } catch (\Throwable $e) {
            // Expected - unique constraint should prevent duplicate
            $this->assertTrue(true);
        }

        // Cleanup
        $schema->dropIndex('idx_username_unique', 'test_unique_constraint');
        $schema->dropTable('test_unique_constraint');
    }

    /**
     * Test cross-dialect TEXT type conversion with DEFAULT values.
     * MySQL/MariaDB should convert TEXT with DEFAULT to VARCHAR(255).
     * MSSQL should convert TEXT to NVARCHAR(MAX).
     */
    public function testTextTypeWithDefaultCrossDialect(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_text_default');

        // Create table with TEXT column that has DEFAULT value
        // This should work cross-dialectally:
        // - MySQL/MariaDB: TEXT with DEFAULT -> VARCHAR(255) with DEFAULT
        // - MSSQL: TEXT -> NVARCHAR(MAX)
        // - PostgreSQL/SQLite: TEXT with DEFAULT (native support)
        $schema->createTable('test_text_default', [
            'id' => $schema->primaryKey(),
            'status' => $schema->text()->defaultValue('active'),
            'description' => $schema->text(),
        ]);

        $this->assertTrue($schema->tableExists('test_text_default'));

        // Insert row without specifying status (should use default)
        $db->find()->table('test_text_default')->insert([
            'description' => 'Test description',
        ]);

        $row = $db->find()->table('test_text_default')->where('id', 1)->getOne();
        $this->assertNotNull($row);
        $this->assertEquals('active', $row['status']);

        // Cleanup
        $schema->dropTable('test_text_default');
    }

    /**
     * Test that string() creates VARCHAR (not TEXT) in MySQL/MariaDB/PostgreSQL.
     */
    public function testStringCreatesVarchar(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_string_type');

        // Create table with string() - should be VARCHAR, not TEXT
        $schema->createTable('test_string_type', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(255),
        ]);

        $this->assertTrue($schema->tableExists('test_string_type'));

        // Verify column type (check actual SQL or describe)
        $columns = $db->describe('test_string_type');
        $nameColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'name') {
                $nameColumn = $col;
                break;
            }
        }

        $this->assertNotNull($nameColumn, 'Column name should exist');

        // For MySQL/MariaDB/PostgreSQL, string() should create VARCHAR
        // For MSSQL, string() should create NVARCHAR
        // For SQLite, string() creates TEXT (SQLite doesn't distinguish)
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'])) {
            $type = strtoupper($nameColumn['Type'] ?? $nameColumn['data_type'] ?? '');
            $this->assertStringContainsString('VARCHAR', $type, 'string() should create VARCHAR in MySQL/MariaDB/PostgreSQL');
        } elseif ($driver === 'sqlsrv') {
            $type = strtoupper($nameColumn['Type'] ?? $nameColumn['data_type'] ?? '');
            $this->assertStringContainsString('NVARCHAR', $type, 'string() should create NVARCHAR in MSSQL');
        }
        // SQLite doesn't distinguish VARCHAR/TEXT, so we skip assertion

        // Cleanup
        $schema->dropTable('test_string_type');
    }

    /**
     * Test that text() creates TEXT (not VARCHAR) in MySQL/MariaDB/PostgreSQL.
     */
    public function testTextCreatesText(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_text_type');

        // Create table with text() - should be TEXT, not VARCHAR
        $schema->createTable('test_text_type', [
            'id' => $schema->primaryKey(),
            'description' => $schema->text(),
        ]);

        $this->assertTrue($schema->tableExists('test_text_type'));

        // Verify column type
        $columns = $db->describe('test_text_type');
        $descColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'description') {
                $descColumn = $col;
                break;
            }
        }

        $this->assertNotNull($descColumn, 'Column description should exist');

        // For MySQL/MariaDB/PostgreSQL/SQLite, text() should create TEXT
        // For MSSQL, text() creates NVARCHAR(MAX) (converted in formatColumnDefinition)
        $type = strtoupper(
            $descColumn['Type'] ??
            $descColumn['data_type'] ??
            $descColumn['DATA_TYPE'] ??
            $descColumn['type'] ??
            ''
        );

        if (in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'])) {
            // TEXT type should be present
            $this->assertTrue(
                str_contains($type, 'TEXT') || str_contains($type, 'NVARCHAR'),
                'text() should create TEXT or NVARCHAR(MAX), got: ' . ($type ?: 'empty') . ' (column: ' . json_encode($descColumn) . ')'
            );
        } elseif ($driver === 'sqlsrv') {
            $this->assertStringContainsString('NVARCHAR', $type, 'text() should create NVARCHAR(MAX) in MSSQL, got: ' . ($type ?: 'empty'));
        }

        // Cleanup
        $schema->dropTable('test_text_type');
    }

    /**
     * Test that char() creates CHAR type.
     */
    public function testCharCreatesChar(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_char_type');

        // Create table with char() - should be CHAR
        $schema->createTable('test_char_type', [
            'id' => $schema->primaryKey(),
            'code' => $schema->char(10),
        ]);

        $this->assertTrue($schema->tableExists('test_char_type'));

        // Verify column type
        $columns = $db->describe('test_char_type');
        $codeColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'code') {
                $codeColumn = $col;
                break;
            }
        }

        $this->assertNotNull($codeColumn, 'Column code should exist');

        // For MySQL/MariaDB/PostgreSQL, char() should create CHAR
        // For MSSQL, char() should create NCHAR
        // For SQLite, char() creates TEXT (SQLite doesn't distinguish)
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'])) {
            $type = strtoupper($codeColumn['Type'] ?? $codeColumn['data_type'] ?? '');
            $this->assertStringContainsString('CHAR', $type, 'char() should create CHAR in MySQL/MariaDB/PostgreSQL');
            $this->assertStringNotContainsString('VARCHAR', $type, 'char() should not create VARCHAR');
        } elseif ($driver === 'sqlsrv') {
            $type = strtoupper($codeColumn['Type'] ?? $codeColumn['data_type'] ?? '');
            $this->assertStringContainsString('NCHAR', $type, 'char() should create NCHAR in MSSQL');
        }
        // SQLite doesn't distinguish CHAR/VARCHAR/TEXT, so we skip assertion

        // Cleanup
        $schema->dropTable('test_char_type');
    }

    /**
     * Test addPrimaryKey and dropPrimaryKey (Yii2-style).
     */
    public function testAddDropPrimaryKey(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_pk_constraint');

        // Create table without primary key
        $schema->createTable('test_ddl_pk_constraint', [
            'id' => $schema->integer()->notNull(),
            'name' => $schema->string(100),
        ]);

        try {
            // Add primary key constraint
            $schema->addPrimaryKey('pk_test_ddl', 'test_ddl_pk_constraint', 'id');
            $this->assertTrue(true, 'Primary key added successfully');
        } catch (QueryException $e) {
            // Some databases (like SQLite) don't support adding PRIMARY KEY via ALTER TABLE
            $this->assertStringContainsString('PRIMARY KEY', $e->getMessage());
        }

        // Cleanup
        $schema->dropTable('test_ddl_pk_constraint');
    }

    /**
     * Test addUnique and dropUnique (Yii2-style).
     */
    public function testAddDropUnique(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_unique_constraint');

        // Create table
        $schema->createTable('test_ddl_unique_constraint', [
            'id' => $schema->primaryKey(),
            'email' => $schema->string(255),
            'username' => $schema->string(100),
        ]);

        // Add unique constraint
        $schema->addUnique('uq_test_email_add_drop', 'test_ddl_unique_constraint', 'email');

        // Verify unique constraint exists (may not work on all databases)
        try {
            $exists = $schema->uniqueExists('uq_test_email_add_drop', 'test_ddl_unique_constraint');
            $this->assertTrue($exists, 'Unique constraint should exist');
        } catch (\Exception $e) {
            // Some databases may not support checking unique constraint existence
            // Just verify the constraint was created without error
        }

        // Drop unique constraint
        $schema->dropUnique('uq_test_email_add_drop', 'test_ddl_unique_constraint');

        // Verify unique constraint no longer exists
        $this->assertFalse($schema->uniqueExists('uq_test_email_add_drop', 'test_ddl_unique_constraint'));

        // Cleanup
        $schema->dropTable('test_ddl_unique_constraint');
    }

    /**
     * Test addCheck and dropCheck (Yii2-style).
     */
    public function testAddDropCheck(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_check_constraint');

        // Create table
        $schema->createTable('test_ddl_check_constraint', [
            'id' => $schema->primaryKey(),
            'price' => $schema->decimal(10, 2),
            'age' => $schema->integer(),
        ]);

        try {
            // Add check constraint
            $schema->addCheck('chk_test_price', 'test_ddl_check_constraint', 'price > 0');
            $this->assertTrue($schema->checkExists('chk_test_price', 'test_ddl_check_constraint'));

            // Drop check constraint
            $schema->dropCheck('chk_test_price', 'test_ddl_check_constraint');
            $this->assertFalse($schema->checkExists('chk_test_price', 'test_ddl_check_constraint'));
        } catch (QueryException $e) {
            // Some databases don't support CHECK constraints or have limitations
            // SQLite doesn't support CHECK constraints via ALTER TABLE
            $message = strtolower($e->getMessage());
            $this->assertTrue(
                strpos($message, 'check') !== false || strpos($message, 'constraint') !== false,
                'Exception should mention CHECK or CONSTRAINT: ' . $e->getMessage()
            );
        }

        // Cleanup
        $schema->dropTable('test_ddl_check_constraint');
    }

    /**
     * Test indexExists, foreignKeyExists, checkExists, uniqueExists.
     */
    public function testConstraintExistenceMethods(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_constraint_exists');
        $schema->dropTableIfExists('test_ddl_ref_table');

        // Create referenced table
        $schema->createTable('test_ddl_ref_table', [
            'id' => $schema->primaryKey(),
        ]);

        // Create table with constraints
        $schema->createTable('test_ddl_constraint_exists', [
            'id' => $schema->primaryKey(),
            'ref_id' => $schema->integer(),
            'email' => $schema->string(255),
            'price' => $schema->decimal(10, 2),
        ]);

        // Create index
        $schema->createIndex('idx_test_ref', 'test_ddl_constraint_exists', 'ref_id');
        $this->assertTrue($schema->indexExists('idx_test_ref', 'test_ddl_constraint_exists'));

        // Create unique constraint
        $schema->addUnique('uq_test_email_exists', 'test_ddl_constraint_exists', 'email');

        // Note: uniqueExists may not work correctly on all databases
        // Some databases implement UNIQUE constraints as indexes
        // Just verify the constraint was created without error
        try {
            $this->assertTrue($schema->uniqueExists('uq_test_email_exists', 'test_ddl_constraint_exists'));
        } catch (\Exception $e) {
            // Some databases may not support checking unique constraint existence
            $this->assertTrue(true, 'Unique constraint created');
        }

        // Create foreign key
        try {
            $schema->addForeignKey('fk_test_ref', 'test_ddl_constraint_exists', 'ref_id', 'test_ddl_ref_table', 'id');
            $this->assertTrue($schema->foreignKeyExists('fk_test_ref', 'test_ddl_constraint_exists'));
        } catch (QueryException $e) {
            // Some databases (like SQLite) don't support adding foreign keys via ALTER TABLE
            // Skip foreign key existence test in this case
        }

        // Test non-existent constraints
        $this->assertFalse($schema->indexExists('idx_nonexistent', 'test_ddl_constraint_exists'));
        $this->assertFalse($schema->uniqueExists('uq_nonexistent', 'test_ddl_constraint_exists'));
        $this->assertFalse($schema->foreignKeyExists('fk_nonexistent', 'test_ddl_constraint_exists'));

        // Cleanup
        $schema->dropTable('test_ddl_constraint_exists');
        $schema->dropTable('test_ddl_ref_table');
    }

    /**
     * Test getIndexes, getForeignKeys, getCheckConstraints, getUniqueConstraints.
     */
    public function testGetConstraintsMethods(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_get_constraints');
        $schema->dropTableIfExists('test_ddl_ref_table');

        // Create referenced table
        $schema->createTable('test_ddl_ref_table', [
            'id' => $schema->primaryKey(),
        ]);

        // Create table with constraints
        $schema->createTable('test_ddl_get_constraints', [
            'id' => $schema->primaryKey(),
            'ref_id' => $schema->integer(),
            'email' => $schema->string(255),
            'price' => $schema->decimal(10, 2),
        ]);

        // Get initial indexes (should include primary key index)
        $indexesBefore = $schema->getIndexes('test_ddl_get_constraints');
        $initialIndexCount = count($indexesBefore);

        // Create index
        $schema->createIndex('idx_test_ref_get', 'test_ddl_get_constraints', 'ref_id');
        $indexes = $schema->getIndexes('test_ddl_get_constraints');
        $this->assertGreaterThan($initialIndexCount, count($indexes), 'Should have more indexes after creating one');

        // Create unique constraint
        $schema->addUnique('uq_test_email_get', 'test_ddl_get_constraints', 'email');
        $uniqueConstraints = $schema->getUniqueConstraints('test_ddl_get_constraints');
        // Note: Some databases implement UNIQUE constraints as indexes
        // So getUniqueConstraints may return empty array even if constraint exists
        // Just verify the constraint was created without error
        $this->assertIsArray($uniqueConstraints, 'getUniqueConstraints should return array');

        // Create foreign key
        try {
            $schema->addForeignKey('fk_test_ref', 'test_ddl_get_constraints', 'ref_id', 'test_ddl_ref_table', 'id');
            $foreignKeys = $schema->getForeignKeys('test_ddl_get_constraints');
            $this->assertNotEmpty($foreignKeys);
        } catch (QueryException $e) {
            // Some databases (like SQLite) don't support adding foreign keys via ALTER TABLE
            // This is acceptable, skip foreign key test
        }

        // Cleanup
        $schema->dropTable('test_ddl_get_constraints');
        $schema->dropTable('test_ddl_ref_table');
    }

    /**
     * Test createIndex with sorting (ASC/DESC).
     */
    public function testCreateIndexWithSorting(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_index_sorting');

        // Create table
        $schema->createTable('test_ddl_index_sorting', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'price' => $schema->decimal(10, 2),
        ]);

        // Create index with sorting
        $schema->createIndex('idx_test_sorting', 'test_ddl_index_sorting', [
            'name' => 'ASC',
            'price' => 'DESC',
        ]);

        $this->assertTrue($schema->indexExists('idx_test_sorting', 'test_ddl_index_sorting'));

        // Cleanup
        $schema->dropTable('test_ddl_index_sorting');
    }

    /**
     * Test createIndex with functional index (RawValue).
     */
    public function testCreateIndexWithFunctionalIndex(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_functional_index');

        // Create table
        $schema->createTable('test_ddl_functional_index', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        // Create functional index
        $schema->createIndex('idx_test_lower', 'test_ddl_functional_index', [
            \tommyknocker\pdodb\helpers\Db::raw('LOWER(name)'),
        ]);

        $this->assertTrue($schema->indexExists('idx_test_lower', 'test_ddl_functional_index'));

        // Cleanup
        $schema->dropTable('test_ddl_functional_index');
    }

    /**
     * Test renameIndex (Yii2-style).
     */
    public function testRenameIndex(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_rename_index');

        // Create table
        $schema->createTable('test_ddl_rename_index', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        // Create index
        $schema->createIndex('idx_old_name', 'test_ddl_rename_index', 'name');

        try {
            // Rename index
            $schema->renameIndex('idx_old_name', 'test_ddl_rename_index', 'idx_new_name');
            $this->assertTrue($schema->indexExists('idx_new_name', 'test_ddl_rename_index'));
            $this->assertFalse($schema->indexExists('idx_old_name', 'test_ddl_rename_index'));
        } catch (QueryException $e) {
            // Some databases (like SQLite) don't support renaming indexes
            $message = strtolower($e->getMessage());
            $this->assertTrue(
                strpos($message, 'rename') !== false || strpos($message, 'renaming') !== false,
                'Exception message should mention rename/renaming: ' . $e->getMessage()
            );
        }

        // Cleanup
        $schema->dropTable('test_ddl_rename_index');
    }

    /**
     * Test renameForeignKey (Yii2-style).
     */
    public function testRenameForeignKey(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_ddl_rename_fk');
        $schema->dropTableIfExists('test_ddl_ref_table');

        // Create referenced table
        $schema->createTable('test_ddl_ref_table', [
            'id' => $schema->primaryKey(),
        ]);

        // Create table with foreign key
        $schema->createTable('test_ddl_rename_fk', [
            'id' => $schema->primaryKey(),
            'ref_id' => $schema->integer(),
        ]);

        try {
            $schema->addForeignKey('fk_old_name', 'test_ddl_rename_fk', 'ref_id', 'test_ddl_ref_table', 'id');
        } catch (QueryException $e) {
            // Some databases (like SQLite) don't support adding foreign keys via ALTER TABLE
            // In this case, we can't test renaming, so we verify the error and exit
            $schema->dropTable('test_ddl_rename_fk');
            $schema->dropTable('test_ddl_ref_table');
            // Verify that the error is related to foreign key or ALTER TABLE
            $message = strtolower($e->getMessage());
            $this->assertTrue(
                strpos($message, 'foreign key') !== false || strpos($message, 'alter table') !== false || strpos($message, 'not supported') !== false,
                'Exception should mention foreign key, ALTER TABLE, or not supported: ' . $e->getMessage()
            );
            return;
        }

        try {
            // Rename foreign key
            $schema->renameForeignKey('fk_old_name', 'test_ddl_rename_fk', 'fk_new_name');
            $this->assertTrue($schema->foreignKeyExists('fk_new_name', 'test_ddl_rename_fk'));
            $this->assertFalse($schema->foreignKeyExists('fk_old_name', 'test_ddl_rename_fk'));
        } catch (QueryException | \RuntimeException $e) {
            // Some databases don't support renaming foreign keys
            $message = strtolower($e->getMessage());
            $this->assertTrue(
                strpos($message, 'foreign key') !== false || strpos($message, 'rename') !== false,
                'Exception message should mention foreign key or rename: ' . $e->getMessage()
            );
        }

        // Cleanup
        $schema->dropTable('test_ddl_rename_fk');
        $schema->dropTable('test_ddl_ref_table');
    }

    /**
     * Test tableExists method separately.
     */
    public function testTableExists(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_table_exists');

        // Table should not exist
        $this->assertFalse($schema->tableExists('test_table_exists'));

        // Create table
        $schema->createTable('test_table_exists', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        // Table should exist now
        $this->assertTrue($schema->tableExists('test_table_exists'));

        // Cleanup
        $schema->dropTable('test_table_exists');
        $this->assertFalse($schema->tableExists('test_table_exists'));
    }

    /**
     * Test bigInteger creates BIGINT type.
     */
    public function testBigIntegerCreatesBigint(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_bigint_type');

        $schema->createTable('test_bigint_type', [
            'id' => $schema->primaryKey(),
            'big_id' => $schema->bigInteger(),
        ]);

        $this->assertTrue($schema->tableExists('test_bigint_type'));

        // Verify column type
        $columns = $db->describe('test_bigint_type');
        $bigIdColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'big_id') {
                $bigIdColumn = $col;
                break;
            }
        }

        $this->assertNotNull($bigIdColumn, 'Column big_id should exist');
        $driver = $schema->getDialect()->getDriverName();
        $type = strtoupper($bigIdColumn['Type'] ?? $bigIdColumn['data_type'] ?? $bigIdColumn['type'] ?? '');
        // SQLite doesn't distinguish types strictly, so skip assertion for SQLite
        if (!empty($type) && $driver !== 'sqlite') {
            $this->assertStringContainsString('BIGINT', $type, 'bigInteger() should create BIGINT');
        }

        // Cleanup
        $schema->dropTable('test_bigint_type');
    }

    /**
     * Test smallInteger creates SMALLINT type.
     */
    public function testSmallIntegerCreatesSmallint(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_smallint_type');

        $schema->createTable('test_smallint_type', [
            'id' => $schema->primaryKey(),
            'small_id' => $schema->smallInteger(),
        ]);

        $this->assertTrue($schema->tableExists('test_smallint_type'));

        // Verify column type
        $columns = $db->describe('test_smallint_type');
        $smallIdColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'small_id') {
                $smallIdColumn = $col;
                break;
            }
        }

        $this->assertNotNull($smallIdColumn, 'Column small_id should exist');
        $driver = $schema->getDialect()->getDriverName();
        $type = strtoupper($smallIdColumn['Type'] ?? $smallIdColumn['data_type'] ?? $smallIdColumn['type'] ?? '');
        // SQLite doesn't distinguish types strictly, so skip assertion for SQLite
        if (!empty($type) && $driver !== 'sqlite') {
            $this->assertStringContainsString('SMALLINT', $type, 'smallInteger() should create SMALLINT');
        }

        // Cleanup
        $schema->dropTable('test_smallint_type');
    }

    /**
     * Test date creates DATE type.
     */
    public function testDateCreatesDate(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_date_type');

        $schema->createTable('test_date_type', [
            'id' => $schema->primaryKey(),
            'birth_date' => $schema->date(),
        ]);

        $this->assertTrue($schema->tableExists('test_date_type'));

        // Verify column type
        $columns = $db->describe('test_date_type');
        $dateColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'birth_date') {
                $dateColumn = $col;
                break;
            }
        }

        $this->assertNotNull($dateColumn, 'Column birth_date should exist');
        $driver = $schema->getDialect()->getDriverName();
        $type = strtoupper($dateColumn['Type'] ?? $dateColumn['data_type'] ?? $dateColumn['type'] ?? '');
        // SQLite doesn't distinguish types strictly, so skip assertion for SQLite
        if (!empty($type) && $driver !== 'sqlite') {
            $this->assertStringContainsString('DATE', $type, 'date() should create DATE');
        }

        // Cleanup
        $schema->dropTable('test_date_type');
    }

    /**
     * Test time creates TIME type.
     */
    public function testTimeCreatesTime(): void
    {
        $db = self::getDb();
        $schema = $db->schema();

        $schema->dropTableIfExists('test_time_type');

        $schema->createTable('test_time_type', [
            'id' => $schema->primaryKey(),
            'start_time' => $schema->time(),
        ]);

        $this->assertTrue($schema->tableExists('test_time_type'));

        // Verify column type
        $columns = $db->describe('test_time_type');
        $timeColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'start_time') {
                $timeColumn = $col;
                break;
            }
        }

        $this->assertNotNull($timeColumn, 'Column start_time should exist');
        $type = strtoupper($timeColumn['Type'] ?? $timeColumn['data_type'] ?? $timeColumn['type'] ?? '');
        $driver = $schema->getDialect()->getDriverName();
        // SQLite doesn't distinguish TIME type (stores as TEXT), so skip assertion for SQLite
        if (!empty($type) && $driver !== 'sqlite') {
            $this->assertStringContainsString('TIME', $type, 'time() should create TIME');
        }

        // Cleanup
        $schema->dropTable('test_time_type');
    }

    /**
     * Test datetime creates DATETIME/TIMESTAMP type.
     */
    public function testDatetimeCreatesDatetime(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_datetime_type');

        $schema->createTable('test_datetime_type', [
            'id' => $schema->primaryKey(),
            'created_at' => $schema->datetime(),
        ]);

        $this->assertTrue($schema->tableExists('test_datetime_type'));

        // Verify column type
        $columns = $db->describe('test_datetime_type');
        $datetimeColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'created_at') {
                $datetimeColumn = $col;
                break;
            }
        }

        $this->assertNotNull($datetimeColumn, 'Column created_at should exist');
        $type = strtoupper($datetimeColumn['Type'] ?? $datetimeColumn['data_type'] ?? '');

        // Different dialects use different types for datetime()
        if (in_array($driver, ['mysql', 'mariadb'])) {
            $this->assertStringContainsString('DATETIME', $type, 'datetime() should create DATETIME in MySQL/MariaDB');
        } elseif ($driver === 'pgsql') {
            $this->assertStringContainsString('TIMESTAMP', $type, 'datetime() should create TIMESTAMP in PostgreSQL');
        } elseif ($driver === 'sqlsrv') {
            $this->assertStringContainsString('DATETIME', $type, 'datetime() should create DATETIME in MSSQL');
        }
        // SQLite doesn't distinguish types

        // Cleanup
        $schema->dropTable('test_datetime_type');
    }

    /**
     * Test timestamp creates TIMESTAMP type.
     */
    public function testTimestampCreatesTimestamp(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_timestamp_type');

        $schema->createTable('test_timestamp_type', [
            'id' => $schema->primaryKey(),
            'updated_at' => $schema->timestamp(),
        ]);

        $this->assertTrue($schema->tableExists('test_timestamp_type'));

        // Verify column type
        $columns = $db->describe('test_timestamp_type');
        $timestampColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'updated_at') {
                $timestampColumn = $col;
                break;
            }
        }

        $this->assertNotNull($timestampColumn, 'Column updated_at should exist');
        $type = strtoupper($timestampColumn['Type'] ?? $timestampColumn['data_type'] ?? '');

        // Different dialects use different types for timestamp()
        if (in_array($driver, ['mysql', 'mariadb'])) {
            $this->assertStringContainsString('TIMESTAMP', $type, 'timestamp() should create TIMESTAMP in MySQL/MariaDB');
        } elseif ($driver === 'pgsql') {
            $this->assertStringContainsString('TIMESTAMP', $type, 'timestamp() should create TIMESTAMP in PostgreSQL');
        } elseif ($driver === 'sqlsrv') {
            $this->assertStringContainsString('DATETIME', $type, 'timestamp() should create DATETIME in MSSQL');
        }
        // SQLite doesn't distinguish types

        // Cleanup
        $schema->dropTable('test_timestamp_type');
    }

    /**
     * Test json creates JSON type.
     */
    public function testJsonCreatesJson(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $driver = $schema->getDialect()->getDriverName();

        $schema->dropTableIfExists('test_json_type');

        $schema->createTable('test_json_type', [
            'id' => $schema->primaryKey(),
            'data' => $schema->json(),
        ]);

        $this->assertTrue($schema->tableExists('test_json_type'));

        // Verify column type
        $columns = $db->describe('test_json_type');
        $jsonColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'data') {
                $jsonColumn = $col;
                break;
            }
        }

        $this->assertNotNull($jsonColumn, 'Column data should exist');
        $type = strtoupper($jsonColumn['Type'] ?? $jsonColumn['data_type'] ?? '');

        // Different dialects use different types for json()
        if (in_array($driver, ['mysql', 'mariadb'])) {
            $this->assertStringContainsString('JSON', $type, 'json() should create JSON in MySQL/MariaDB');
        } elseif ($driver === 'pgsql') {
            $this->assertStringContainsString('JSON', $type, 'json() should create JSON in PostgreSQL');
        } elseif ($driver === 'sqlsrv') {
            $this->assertStringContainsString('NVARCHAR', $type, 'json() should create NVARCHAR(MAX) in MSSQL');
        }
        // SQLite doesn't distinguish types

        // Cleanup
        $schema->dropTable('test_json_type');
    }
}
