<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\helpers\Db;

/**
 * PostgreSQL-specific tests for MERGE statement.
 */
final class MergeTests extends BasePostgreSQLTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$db->rawQuery('DROP TABLE IF EXISTS merge_target');
        self::$db->rawQuery('DROP TABLE IF EXISTS merge_source');

        self::$db->rawQuery('
            CREATE TABLE merge_target (
                id INTEGER PRIMARY KEY,
                name VARCHAR(100),
                value INTEGER,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE merge_source (
                id INTEGER,
                name VARCHAR(100),
                value INTEGER
            )
        ');
    }

    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DELETE FROM merge_target');
        self::$db->rawQuery('DELETE FROM merge_source');
    }

    public function testPostgreSQLNativeMerge(): void
    {
        // Insert initial data
        self::$db->find()->table('merge_target')->insert(['id' => 1, 'name' => 'Existing', 'value' => 10]);
        self::$db->find()->table('merge_target')->insert(['id' => 3, 'name' => 'ToDelete', 'value' => 5]);

        // Insert source data
        self::$db->find()->table('merge_source')->insert(['id' => 1, 'name' => 'Updated', 'value' => 20]);
        self::$db->find()->table('merge_source')->insert(['id' => 2, 'name' => 'New', 'value' => 30]);

        // Perform MERGE with UPDATE and INSERT (DELETE requires PostgreSQL 15+)
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
        $this->assertEquals('Updated', $updated['name']);
        $this->assertEquals(20, $updated['value']);

        // Check inserted row
        $inserted = self::$db->find()->from('merge_target')->where('id', 2)->getOne();
        $this->assertEquals('New', $inserted['name']);
        $this->assertEquals(30, $inserted['value']);

        // Note: DELETE with WHEN NOT MATCHED BY SOURCE requires PostgreSQL 15+
        // For now, we skip testing this feature
    }

    public function testPostgreSQLMergeGeneratedSql(): void
    {
        // Just check that SQL is generated (don't execute)
        $reflection = new \ReflectionClass(self::$db->find());
        $prop = $reflection->getProperty('dmlQueryBuilder');
        $prop->setAccessible(true);
        $dml = $prop->getValue(self::$db->find());
        $dml->setTable('merge_target');
        $srcProp = new \ReflectionProperty($dml, 'mergeSource');
        $srcProp->setAccessible(true);
        $srcProp->setValue($dml, 'merge_source');
        $onProp = new \ReflectionProperty($dml, 'mergeOnConditions');
        $onProp->setAccessible(true);
        $onProp->setValue($dml, ['target.id = source.id']);
        $clauseProp = new \ReflectionProperty($dml, 'mergeClause');
        $clauseProp->setAccessible(true);
        $clause = new \tommyknocker\pdodb\query\MergeClause();
        $clause->whenMatched = ['name' => Db::raw('source.name')];
        $clause->whenNotMatched = ['id' => Db::raw('source.id'), 'name' => Db::raw('source.name')];
        $clauseProp->setValue($dml, $clause);
        $buildMethod = new \ReflectionMethod($dml, 'buildMergeSql');
        $buildMethod->setAccessible(true);
        [$sql] = $buildMethod->invoke($dml);

        $this->assertStringContainsString('MERGE INTO', $sql);
        $this->assertStringContainsString('USING', $sql);
        $this->assertStringContainsString('WHEN MATCHED', $sql);
        $this->assertStringContainsString('WHEN NOT MATCHED', $sql);
    }
}
