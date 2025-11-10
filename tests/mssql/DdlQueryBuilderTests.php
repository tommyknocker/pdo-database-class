<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

/**
 * MSSQL-specific DDL Query Builder tests.
 */
final class DdlQueryBuilderTests extends BaseMSSQLTestCase
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
}
