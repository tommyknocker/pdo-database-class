<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

/**
 * Oracle-specific DDL Query Builder tests.
 */
final class DdlQueryBuilderTests extends BaseOracleTestCase
{
    /**
     * Test creating table with foreign key using schema API.
     */
    public function testCreateTableWithForeignKey(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        try {
            $schema->dropTable('test_fk_child_table');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }

        try {
            $schema->dropTable('test_fk_parent_table');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }

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
        // Note: Oracle doesn't support ON UPDATE CASCADE
        $schema->addForeignKey(
            'fk_test_child_parent',
            'test_fk_child_table',
            'parent_id',
            'test_fk_parent_table',
            'id',
            'CASCADE',
            null // Oracle doesn't support ON UPDATE
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
     * Test Oracle-specific column types.
     */
    public function testOracleSpecificTypes(): void
    {
        $db = self::$db;
        $schema = $db->schema();

        try {
            $schema->dropTable('test_oracle_types');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }

        // Create table with Oracle-specific types
        $schema->createTable('test_oracle_types', [
            'id' => $schema->primaryKey(),
            'price' => $schema->number(10, 2),
            'description' => $schema->clob(),
            'binary_data' => $schema->blob(),
            'metadata' => $schema->json(),
        ]);

        $this->assertTrue($schema->tableExists('test_oracle_types'));

        // Test inserting data
        $id = $db->find()->table('test_oracle_types')->insert([
            'price' => 99.99,
            'description' => 'Test description',
            'metadata' => '{"key": "value"}',
        ]);

        $this->assertIsInt($id);

        // Cleanup
        $schema->dropTable('test_oracle_types');
    }
}

