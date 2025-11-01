<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\helpers\Db;

/**
 * Shared tests for MERGE statement functionality.
 */
final class MergeTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();

        // Create tables for MERGE tests
        self::$db->rawQuery('DROP TABLE IF EXISTS merge_target');
        self::$db->rawQuery('DROP TABLE IF EXISTS merge_source');

        if ($driverName === 'pgsql') {
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
        } elseif ($driverName === 'mysql') {
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
        } else {
            // SQLite
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
    }

    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DELETE FROM merge_target');
        self::$db->rawQuery('DELETE FROM merge_source');
    }

    public function testSupportsMergeCheck(): void
    {
        $driverName = self::$db->find()->getConnection()->getDialect()->getDriverName();
        $supportsMerge = self::$db->find()->getConnection()->getDialect()->supportsMerge();

        if ($driverName === 'pgsql') {
            $this->assertTrue($supportsMerge, 'PostgreSQL should support MERGE');
        } elseif ($driverName === 'mysql') {
            $this->assertTrue($supportsMerge, 'MySQL should support MERGE (emulated)');
        } else {
            $this->assertTrue($supportsMerge, 'SQLite should support MERGE (emulated)');
        }
    }

    public function testBasicMergeWithTableSource(): void
    {
        // Insert initial data
        self::$db->find()->table('merge_target')->insert(['id' => 1, 'name' => 'Existing', 'value' => 10]);

        // Insert source data
        self::$db->find()->table('merge_source')->insert(['id' => 1, 'name' => 'Updated', 'value' => 20]);
        self::$db->find()->table('merge_source')->insert(['id' => 2, 'name' => 'New', 'value' => 30]);

        // Perform MERGE - use simple values, not Db::raw for whenNotMatched
        $affected = self::$db->find()
            ->table('merge_target')
            ->merge(
                'merge_source',
                'target.id = source.id',
                ['name' => Db::raw('source.name'), 'value' => Db::raw('source.value')],
                ['id' => 999, 'name' => 'Placeholder', 'value' => 999]
            );

        $this->assertGreaterThan(0, $affected);

        // Check updated row
        $updated = self::$db->find()
            ->from('merge_target')
            ->where('id', 1)
            ->getOne();
        $this->assertEquals('Updated', $updated['name']);
        $this->assertEquals(20, $updated['value']);

        // Check inserted row
        $inserted = self::$db->find()
            ->from('merge_target')
            ->where('id', 2)
            ->getOne();
        $this->assertEquals('New', $inserted['name']);
        $this->assertEquals(30, $inserted['value']);
    }

    public function testMergeWithClosureSource(): void
    {
        // Insert initial data
        self::$db->find()->table('merge_target')->insert(['id' => 1, 'name' => 'Existing', 'value' => 10]);

        // Insert source data
        self::$db->find()->table('merge_source')->insert(['id' => 1, 'name' => 'Updated', 'value' => 20]);
        self::$db->find()->table('merge_source')->insert(['id' => 2, 'name' => 'New', 'value' => 30]);

        // Perform MERGE with Closure source
        $affected = self::$db->find()
            ->table('merge_target')
            ->merge(
                function ($q) {
                    $q->table('merge_source')->select('*')->where('id', 0, '>');
                },
                'target.id = source.id',
                ['name' => Db::raw('source.name'), 'value' => Db::raw('source.value')],
                ['id' => 999, 'name' => 'Placeholder', 'value' => 999]
            );

        $this->assertGreaterThan(0, $affected);

        // Verify results
        $updated = self::$db->find()->from('merge_target')->where('id', 1)->getOne();
        $this->assertEquals('Updated', $updated['name']);
    }
}
