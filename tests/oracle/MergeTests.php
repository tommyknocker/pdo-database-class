<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\helpers\Db;

/**
 * Oracle-specific tests for MERGE statement.
 */
final class MergeTests extends BaseOracleTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        try {
            self::$db->rawQuery('DROP TABLE merge_target CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }

        try {
            self::$db->rawQuery('DROP TABLE merge_source CASCADE CONSTRAINTS');
        } catch (\Throwable) {
            // Table doesn't exist, continue
        }

        self::$db->rawQuery('
            CREATE TABLE merge_target (
                id NUMBER PRIMARY KEY,
                name VARCHAR2(100),
                value NUMBER,
                updated_at TIMESTAMP DEFAULT SYSTIMESTAMP
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE merge_source (
                id NUMBER,
                name VARCHAR2(100),
                value NUMBER
            )
        ');
    }

    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('TRUNCATE TABLE merge_target');
        self::$db->rawQuery('TRUNCATE TABLE merge_source');
    }

    public function testOracleNativeMerge(): void
    {
        // Insert initial data
        self::$db->find()->table('merge_target')->insert(['id' => 1, 'name' => 'Existing', 'value' => 10]);

        // Insert source data
        self::$db->find()->table('merge_source')->insert(['id' => 1, 'name' => 'Updated', 'value' => 20]);
        self::$db->find()->table('merge_source')->insert(['id' => 2, 'name' => 'New', 'value' => 30]);

        // Perform MERGE (Oracle has native MERGE support)
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
        $this->assertEquals('Updated', $updated['NAME']);
        $this->assertEquals(20, (int)$updated['VALUE']);

        // Check inserted row
        $inserted = self::$db->find()->from('merge_target')->where('id', 2)->getOne();
        $this->assertEquals('New', $inserted['NAME']);
        $this->assertEquals(30, (int)$inserted['VALUE']);
    }

    public function testOracleMergeGeneratedSql(): void
    {
        $sql = self::$db->find()
            ->table('merge_target')
            ->merge(
                'merge_source',
                'target.id = source.id',
                ['name' => Db::raw('source.name')],
                ['id' => Db::raw('source.id'), 'name' => Db::raw('source.name')]
            );

        $this->assertStringContainsString('MERGE INTO', self::$db->lastQuery);
        $this->assertStringContainsString('USING', self::$db->lastQuery);
        $this->assertStringContainsString('ON', self::$db->lastQuery);
        $this->assertStringContainsString('WHEN MATCHED THEN', self::$db->lastQuery);
        $this->assertStringContainsString('WHEN NOT MATCHED THEN', self::$db->lastQuery);
    }
}
