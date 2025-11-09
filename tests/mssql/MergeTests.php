<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * MSSQL-specific tests for MERGE statement.
 */
final class MergeTests extends BaseMSSQLTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $connection = self::$db->connection;
        assert($connection !== null);

        $connection->query('IF OBJECT_ID(\'merge_target\', \'U\') IS NOT NULL DROP TABLE merge_target');
        $connection->query('IF OBJECT_ID(\'merge_source\', \'U\') IS NOT NULL DROP TABLE merge_source');

        $connection->query('
            CREATE TABLE merge_target (
                id INT PRIMARY KEY,
                name NVARCHAR(100),
                value INT,
                updated_at DATETIME2 DEFAULT GETDATE()
            )
        ');

        $connection->query('
            CREATE TABLE merge_source (
                id INT,
                name NVARCHAR(100),
                value INT
            )
        ');
    }

    public function setUp(): void
    {
        parent::setUp();
        $connection = self::$db->connection;
        assert($connection !== null);
        $connection->query('DELETE FROM merge_target');
        $connection->query('DELETE FROM merge_source');
        // Note: merge_target and merge_source don't have IDENTITY columns, so no RESEED needed
    }

    public function testMSSQLNativeMerge(): void
    {
        // Insert initial data
        self::$db->find()->table('merge_target')->insert(['id' => 1, 'name' => 'Existing', 'value' => 10]);
        self::$db->find()->table('merge_target')->insert(['id' => 3, 'name' => 'ToDelete', 'value' => 5]);

        // Insert source data
        self::$db->find()->table('merge_source')->insert(['id' => 1, 'name' => 'Updated', 'value' => 20]);
        self::$db->find()->table('merge_source')->insert(['id' => 2, 'name' => 'New', 'value' => 30]);

        // Perform MERGE with UPDATE and INSERT
        $affected = self::$db->find()
            ->table('merge_target')
            ->merge(
                'merge_source',
                'target.id = source.id',
                ['name' => Db::raw('source.name'), 'value' => Db::raw('source.value')],
                ['id' => Db::raw('source.id'), 'name' => Db::raw('source.name'), 'value' => Db::raw('source.value')],
                false
            );

        $this->assertGreaterThan(0, $affected);

        // Check updated row
        $updated = self::$db->find()->from('merge_target')->where('id', 1)->getOne();
        $this->assertNotFalse($updated);
        $this->assertEquals('Updated', $updated['name']);
        $this->assertEquals(20, $updated['value']);

        // Check inserted row
        $inserted = self::$db->find()->from('merge_target')->where('id', 2)->getOne();
        $this->assertNotFalse($inserted);
        $this->assertEquals('New', $inserted['name']);
        $this->assertEquals(30, $inserted['value']);
    }

    public function testMSSQLMergeWithDelete(): void
    {
        // Insert initial data
        self::$db->find()->table('merge_target')->insert(['id' => 1, 'name' => 'Keep', 'value' => 10]);
        self::$db->find()->table('merge_target')->insert(['id' => 2, 'name' => 'Delete', 'value' => 20]);

        // Insert source data (only id=1, so id=2 should be deleted)
        self::$db->find()->table('merge_source')->insert(['id' => 1, 'name' => 'Updated', 'value' => 15]);

        // Perform MERGE with UPDATE, INSERT, and DELETE
        $affected = self::$db->find()
            ->table('merge_target')
            ->merge(
                'merge_source',
                'target.id = source.id',
                ['name' => Db::raw('source.name'), 'value' => Db::raw('source.value')],
                ['id' => Db::raw('source.id'), 'name' => Db::raw('source.name'), 'value' => Db::raw('source.value')],
                true // Delete rows not matched by source
            );

        $this->assertGreaterThan(0, $affected);

        // Check updated row
        $updated = self::$db->find()->from('merge_target')->where('id', 1)->getOne();
        $this->assertNotFalse($updated);
        $this->assertEquals('Updated', $updated['name']);

        // Check deleted row (should not exist)
        $deleted = self::$db->find()->from('merge_target')->where('id', 2)->getOne();
        $this->assertFalse($deleted);
    }

    public function testMSSQLMergeGeneratedSql(): void
    {
        // Insert source data for SQL generation
        self::$db->find()->table('merge_source')->insert(['id' => 1, 'name' => 'Test', 'value' => 10]);

        // Perform MERGE to generate SQL
        self::$db->find()
            ->table('merge_target')
            ->merge(
                'merge_source',
                'target.id = source.id',
                ['name' => Db::raw('source.name')],
                ['id' => Db::raw('source.id'), 'name' => Db::raw('source.name')]
            );

        $lastQuery = self::$db->lastQuery ?? '';
        $this->assertStringContainsString('MERGE', $lastQuery);
        $this->assertStringContainsString('USING', $lastQuery);
        $this->assertStringContainsString('WHEN MATCHED', $lastQuery);
        $this->assertStringContainsString('WHEN NOT MATCHED', $lastQuery);
    }
}
