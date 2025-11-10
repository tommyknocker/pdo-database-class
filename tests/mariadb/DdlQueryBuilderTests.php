<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

/**
 * MariaDB-specific DDL Query Builder tests.
 */
final class DdlQueryBuilderTests extends BaseMariaDBTestCase
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
     * Test that TEXT without DEFAULT remains TEXT in MariaDB.
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
}
