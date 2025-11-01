<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\helpers\Db;

/**
 * MySQL-specific tests for MERGE statement.
 */
final class MergeTests extends BaseMariaDBTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$db->rawQuery('DROP TABLE IF EXISTS merge_target');
        self::$db->rawQuery('DROP TABLE IF EXISTS merge_source');

        self::$db->rawQuery('
            CREATE TABLE merge_target (
                id INT PRIMARY KEY,
                name VARCHAR(100),
                value INT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE merge_source (
                id INT,
                name VARCHAR(100),
                value INT
            )
        ');
    }

    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DELETE FROM merge_target');
        self::$db->rawQuery('DELETE FROM merge_source');
    }

    public function testMySQLEmulatedMerge(): void
    {
        // Insert initial data
        self::$db->find()->table('merge_target')->insert(['id' => 1, 'name' => 'Existing', 'value' => 10]);

        // Insert source data
        self::$db->find()->table('merge_source')->insert(['id' => 1, 'name' => 'Updated', 'value' => 20]);
        self::$db->find()->table('merge_source')->insert(['id' => 2, 'name' => 'New', 'value' => 30]);

        // Perform MERGE (MySQL uses INSERT ... ON DUPLICATE KEY UPDATE)
        $affected = self::$db->find()
            ->table('merge_target')
            ->merge(
                'merge_source',
                'target.id = source.id',
                ['name' => Db::raw('source.name'), 'value' => Db::raw('source.value')],
                ['id' => Db::raw('source.id'), 'name' => Db::raw('source.name'), 'value' => Db::raw('source.value')]
            );

        $this->assertGreaterThan(0, $affected);

        // Check updated row
        $updated = self::$db->find()->from('merge_target')->where('id', 1)->getOne();
        $this->assertEquals('Updated', $updated['name']);
        $this->assertEquals(20, $updated['value']);

        // Check inserted row
        $inserted = self::$db->find()->from('merge_target')->where('id', 2)->getOne();
        $this->assertEquals('New', $inserted['name']);
        $this->assertEquals(30, $inserted['value']);
    }

    public function testMySQLMergeGeneratedSql(): void
    {
        $sql = self::$db->find()
            ->table('merge_target')
            ->merge(
                'merge_source',
                'target.id = source.id',
                ['name' => Db::raw('source.name')],
                ['id' => Db::raw('source.id'), 'name' => Db::raw('source.name')]
            );

        $this->assertStringContainsString('INSERT INTO', self::$db->lastQuery);
        $this->assertStringContainsString('SELECT', self::$db->lastQuery);
        $this->assertStringContainsString('FROM', self::$db->lastQuery);
        $this->assertStringContainsString('AS source', self::$db->lastQuery);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', self::$db->lastQuery);
    }
}
