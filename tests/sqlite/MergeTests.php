<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\helpers\Db;

/**
 * SQLite-specific tests for MERGE statement.
 */
final class MergeTests extends BaseSqliteTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        self::$db->rawQuery('DROP TABLE IF EXISTS merge_target');
        self::$db->rawQuery('DROP TABLE IF EXISTS merge_source');

        self::$db->rawQuery('
            CREATE TABLE merge_target (
                id INTEGER PRIMARY KEY,
                name TEXT,
                value INTEGER,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE merge_source (
                id INTEGER,
                name TEXT,
                value INTEGER
            )
        ');
    }

    protected function tearDown(): void
    {
        self::$db->rawQuery('DELETE FROM merge_target');
        self::$db->rawQuery('DELETE FROM merge_source');
        parent::tearDown();
    }

    public function testSQLiteEmulatedMerge(): void
    {
        // Insert initial data
        self::$db->find()->table('merge_target')->insert(['id' => 1, 'name' => 'Existing', 'value' => 10]);

        // Insert source data
        self::$db->find()->table('merge_source')->insert(['id' => 1, 'name' => 'Updated', 'value' => 20]);
        self::$db->find()->table('merge_source')->insert(['id' => 2, 'name' => 'New', 'value' => 30]);

        // Perform MERGE (SQLite uses INSERT ... ON CONFLICT)
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

    public function testSQLiteMergeGeneratedSql(): void
    {
        $sql = self::$db->find()
            ->table('merge_target')
            ->merge(
                'merge_source',
                'target.id = source.id',
                ['name' => Db::raw('source.name')],
                ['id' => Db::raw('source.id'), 'name' => Db::raw('source.name')]
            );

        $this->assertStringContainsString('INSERT OR REPLACE INTO', self::$db->lastQuery);
        $this->assertStringContainsString('SELECT', self::$db->lastQuery);
        $this->assertStringContainsString('FROM', self::$db->lastQuery);
    }
}
