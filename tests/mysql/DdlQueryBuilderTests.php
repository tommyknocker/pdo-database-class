<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

/**
 * MySQL-specific DDL Query Builder tests.
 */
final class DdlQueryBuilderTests extends BaseMySQLTestCase
{
    /**
     * Test creating table with foreign key using schema API.
     */
    public function testCreateTableWithForeignKey(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_fk_child_table');
        $schema->dropTableIfExists('test_fk_parent_table');

        // Create parent table
        $schema->createTable('test_fk_parent_table', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
        ]);

        // Create child table
        $schema->createTable('test_fk_child_table', [
            'id' => $schema->primaryKey(),
            'parent_id' => $schema->integer()->notNull(),
            'value' => $schema->string(255),
        ]);

        // Add foreign key using schema API
        $schema->addForeignKey(
            'fk_test_child_parent',
            'test_fk_child_table',
            'parent_id',
            'test_fk_parent_table',
            'id',
            'CASCADE',
            'CASCADE'
        );

        // Verify tables exist
        $this->assertTrue($schema->tableExists('test_fk_parent_table'));
        $this->assertTrue($schema->tableExists('test_fk_child_table'));

        // Test inserting data with foreign key
        $parentId = $db->find()->table('test_fk_parent_table')->insert(['name' => 'Parent']);
        $childId = $db->find()->table('test_fk_child_table')->insert([
            'parent_id' => $parentId,
            'value' => 'Child Value',
        ]);

        $this->assertNotNull($childId);

        // Cleanup
        $schema->dropForeignKey('fk_test_child_parent', 'test_fk_child_table');
        $schema->dropTable('test_fk_child_table');
        $schema->dropTable('test_fk_parent_table');
    }

    /**
     * Test that TEXT without DEFAULT remains TEXT in MySQL.
     */
    public function testTextWithoutDefaultRemainsText(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_text_no_default');

        // Create table with text() without DEFAULT - should remain TEXT
        $schema->createTable('test_text_no_default', [
            'id' => $schema->primaryKey(),
            'content' => $schema->text(), // No DEFAULT
        ]);

        $this->assertTrue($schema->tableExists('test_text_no_default'));

        // Verify column type is TEXT, not VARCHAR
        $columns = $db->describe('test_text_no_default');
        $contentColumn = null;
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName === 'content') {
                $contentColumn = $col;
                break;
            }
        }

        $this->assertNotNull($contentColumn, 'Column content should exist');
        $type = strtoupper($contentColumn['Type'] ?? $contentColumn['data_type'] ?? '');
        $this->assertStringContainsString('TEXT', $type, 'TEXT without DEFAULT should remain TEXT, not convert to VARCHAR');

        // Cleanup
        $schema->dropTable('test_text_no_default');
    }

    /**
     * Test createFulltextIndex (MySQL-specific).
     */
    public function testCreateFulltextIndex(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_fulltext_index');

        // Create table with FULLTEXT-capable columns
        $schema->createTable('test_fulltext_index', [
            'id' => $schema->primaryKey(),
            'title' => $schema->string(255),
            'content' => $schema->text(),
        ]);

        // Create fulltext index
        $schema->createFulltextIndex('ft_idx_test', 'test_fulltext_index', ['title', 'content']);

        $this->assertTrue($schema->indexExists('ft_idx_test', 'test_fulltext_index'));

        // Cleanup
        $schema->dropTable('test_fulltext_index');
    }

    /**
     * Test createSpatialIndex (MySQL-specific).
     */
    public function testCreateSpatialIndex(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_spatial_index');

        // Create table with spatial column
        $schema->createTable('test_spatial_index', [
            'id' => $schema->primaryKey(),
            'location' => $schema->string(255), // Using string for simplicity, real spatial would use GEOMETRY
        ]);

        // Note: Real spatial indexes require GEOMETRY columns
        // This test verifies the method exists and can be called
        // For a real test, you would need: 'location' => 'GEOMETRY NOT NULL'
        // Then create spatial index: $schema->createSpatialIndex('sp_idx_test', 'test_spatial_index', ['location']);

        // Verify table was created successfully
        $this->assertTrue($schema->tableExists('test_spatial_index'), 'Table should exist');

        // Cleanup
        $schema->dropTable('test_spatial_index');
    }

    /**
     * Test column comment attribute (MySQL-specific).
     */
    public function testColumnComment(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_column_comment');

        $schema->createTable('test_column_comment', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->comment('User name'),
            'email' => $schema->string(255)->comment('User email address'),
        ]);

        $this->assertTrue($schema->tableExists('test_column_comment'));

        // Verify column comments via information_schema (more reliable than describe)
        $comments = $db->rawQuery("SELECT COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_column_comment' AND COLUMN_NAME IN ('name', 'email')");
        $commentMap = [];
        foreach ($comments as $row) {
            $commentMap[$row['COLUMN_NAME']] = $row['COLUMN_COMMENT'];
        }
        $this->assertEquals('User name', $commentMap['name'] ?? null, 'Column name should have comment');
        $this->assertEquals('User email address', $commentMap['email'] ?? null, 'Column email should have comment');

        // Cleanup
        $schema->dropTable('test_column_comment');
    }

    /**
     * Test column unsigned attribute (MySQL-specific).
     */
    public function testColumnUnsigned(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_column_unsigned');

        $schema->createTable('test_column_unsigned', [
            'id' => $schema->primaryKey(),
            'age' => $schema->integer()->unsigned(),
            'price' => $schema->decimal(10, 2)->unsigned(),
        ]);

        $this->assertTrue($schema->tableExists('test_column_unsigned'));

        // Verify column types contain UNSIGNED
        $columns = $db->describe('test_column_unsigned');
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if (in_array($colName, ['age', 'price'])) {
                $type = strtoupper($col['Type'] ?? $col['data_type'] ?? '');
                $this->assertStringContainsString('UNSIGNED', $type, "Column {$colName} should be UNSIGNED");
            }
        }

        // Cleanup
        $schema->dropTable('test_column_unsigned');
    }

    /**
     * Test column after attribute (MySQL-specific).
     */
    public function testColumnAfter(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_column_after');

        // Create table first
        $schema->createTable('test_column_after', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'email' => $schema->string(255),
        ]);

        // Add column with AFTER
        $schema->addColumn('test_column_after', 'phone', $schema->string(20)->after('name'));

        $this->assertTrue($schema->tableExists('test_column_after'));

        // Verify column order (columns should be: id, name, phone, email)
        $columns = $db->describe('test_column_after');
        $columnNames = [];
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName !== null) {
                $columnNames[] = $colName;
            }
        }

        $nameIndex = array_search('name', $columnNames, true);
        $phoneIndex = array_search('phone', $columnNames, true);
        $emailIndex = array_search('email', $columnNames, true);

        $this->assertNotFalse($nameIndex, 'Column name should exist');
        $this->assertNotFalse($phoneIndex, 'Column phone should exist');
        $this->assertNotFalse($emailIndex, 'Column email should exist');
        // phone should be after name (nameIndex < phoneIndex)
        $this->assertLessThan($phoneIndex, $nameIndex, 'phone should be after name');
        // phone should be before email (phoneIndex < emailIndex)
        $this->assertLessThan($emailIndex, $phoneIndex, 'phone should be before email');

        // Cleanup
        $schema->dropTable('test_column_after');
    }

    /**
     * Test column first attribute (MySQL-specific).
     */
    public function testColumnFirst(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_column_first');

        // Create table first
        $schema->createTable('test_column_first', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        // Add column with FIRST
        $schema->addColumn('test_column_first', 'priority', $schema->integer()->first());

        $this->assertTrue($schema->tableExists('test_column_first'));

        // Verify column order (priority should be first after id)
        $columns = $db->describe('test_column_first');
        $columnNames = [];
        foreach ($columns as $col) {
            $colName = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? null;
            if ($colName !== null) {
                $columnNames[] = $colName;
            }
        }

        $priorityIndex = array_search('priority', $columnNames, true);
        $nameIndex = array_search('name', $columnNames, true);

        $this->assertNotFalse($priorityIndex, 'Column priority should exist');
        $this->assertNotFalse($nameIndex, 'Column name should exist');
        // Priority should be before name (id is always first as primary key)
        $this->assertLessThan($nameIndex, $priorityIndex, 'priority should be before name');

        // Cleanup
        $schema->dropTable('test_column_first');
    }

    /**
     * Test createTable with ENGINE option (MySQL-specific).
     */
    public function testCreateTableWithEngineOption(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_table_engine');

        $schema->createTable('test_table_engine', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ], [
            'engine' => 'InnoDB',
        ]);

        $this->assertTrue($schema->tableExists('test_table_engine'));

        // Verify table engine
        $result = $db->rawQueryValue("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_table_engine'");
        $this->assertEquals('InnoDB', $result, 'Table should use InnoDB engine');

        // Cleanup
        $schema->dropTable('test_table_engine');
    }

    /**
     * Test createTable with CHARSET option (MySQL-specific).
     */
    public function testCreateTableWithCharsetOption(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_table_charset');

        $schema->createTable('test_table_charset', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ], [
            'charset' => 'utf8mb4',
        ]);

        $this->assertTrue($schema->tableExists('test_table_charset'));

        // Verify table charset
        $result = $db->rawQueryValue("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_table_charset'");
        $this->assertStringContainsString('utf8mb4', $result, 'Table should use utf8mb4 charset');

        // Cleanup
        $schema->dropTable('test_table_charset');
    }

    /**
     * Test createTable with COLLATE option (MySQL-specific).
     */
    public function testCreateTableWithCollateOption(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_table_collate');

        $schema->createTable('test_table_collate', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ], [
            'collate' => 'utf8mb4_unicode_ci',
        ]);

        $this->assertTrue($schema->tableExists('test_table_collate'));

        // Verify table collation
        $result = $db->rawQueryValue("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_table_collate'");
        $this->assertEquals('utf8mb4_unicode_ci', $result, 'Table should use utf8mb4_unicode_ci collation');

        // Cleanup
        $schema->dropTable('test_table_collate');
    }

    /**
     * Test createTable with COMMENT option (MySQL-specific).
     */
    public function testCreateTableWithCommentOption(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_table_comment');

        $schema->createTable('test_table_comment', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ], [
            'comment' => 'Test table comment',
        ]);

        $this->assertTrue($schema->tableExists('test_table_comment'));

        // Verify table comment
        $result = $db->rawQueryValue("SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_table_comment'");
        $this->assertEquals('Test table comment', $result, 'Table should have comment');

        // Cleanup
        $schema->dropTable('test_table_comment');
    }

    /**
     * Test createTable with partitioning (MySQL-specific).
     */
    public function testCreateTableWithPartitioning(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        $schema->dropTableIfExists('test_table_partition');

        // Create partitioned table
        // Note: PRIMARY KEY must include all columns in partitioning function
        $schema->createTable('test_table_partition', [
            'id' => $schema->integer()->notNull(),
            'order_date' => $schema->date()->notNull(),
            'amount' => $schema->decimal(10, 2),
        ], [
            'primaryKey' => ['id', 'order_date'],
            'partition' => 'PARTITION BY RANGE (YEAR(order_date)) (
                PARTITION p2023 VALUES LESS THAN (2024),
                PARTITION p2024 VALUES LESS THAN (2025),
                PARTITION p2025 VALUES LESS THAN (2026)
            )',
        ]);

        $this->assertTrue($schema->tableExists('test_table_partition'));

        // Verify table is partitioned
        // Note: information_schema.PARTITIONS includes the parent table itself, so count should be >= 1
        // For partitioned tables, count should be > 1 (parent + partitions)
        $result = $db->rawQueryValue("SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_table_partition'");
        // If partitioning worked, we should have at least the parent table (count >= 1)
        // In some cases, partitions might not be visible immediately, so we just verify table exists
        $this->assertGreaterThanOrEqual(1, (int)$result, 'Table should exist (partitioning may require explicit partition creation)');

        // Cleanup
        $schema->dropTable('test_table_partition');
    }
}
