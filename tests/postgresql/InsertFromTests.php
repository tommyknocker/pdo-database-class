<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\helpers\Db;

/**
 * PostgreSQL-specific tests for INSERT ... SELECT functionality.
 */
final class InsertFromTests extends BasePostgreSQLTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create tables for INSERT ... SELECT tests
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_from_target');
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_from_source');

        self::$db->rawQuery('
            CREATE TABLE insert_from_target (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100),
                age INTEGER,
                status VARCHAR(50)
            )
        ');

        // Create unique index on name for ON CONFLICT to work
        self::$db->rawQuery('CREATE UNIQUE INDEX insert_from_target_name_unique ON insert_from_target(name)');

        self::$db->rawQuery('
            CREATE TABLE insert_from_source (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100),
                age INTEGER,
                status VARCHAR(50)
            )
        ');
    }

    public function setUp(): void
    {
        parent::setUp();
        // Clean up tables before each test - use CASCADE to handle foreign keys
        self::$db->rawQuery('TRUNCATE TABLE insert_from_target, insert_from_source RESTART IDENTITY CASCADE');
    }

    public function testInsertFromWithOnDuplicate(): void
    {
        // Insert initial target data
        self::$db->find()->table('insert_from_target')->insert(['name' => 'Alice', 'age' => 25, 'status' => 'old']);

        // Insert source data
        self::$db->find()->table('insert_from_source')->insert(['name' => 'Alice', 'age' => 30, 'status' => 'new']);

        // PostgreSQL uses ON CONFLICT - need to specify columns
        $affected = self::$db->find()
            ->table('insert_from_target')
            ->insertFrom(function ($query) {
                $query->from('insert_from_source')
                    ->select(['name', 'age', 'status']);
            }, ['name', 'age', 'status'], [
                'age' => Db::raw('EXCLUDED.age'),
                'status' => Db::raw('EXCLUDED.status'),
            ]);

        $this->assertGreaterThanOrEqual(1, $affected);

        $targetRow = self::$db->find()->from('insert_from_target')->where('name', 'Alice')->getOne();
        $this->assertNotNull($targetRow);
        $this->assertEquals('Alice', $targetRow['name']);
        // Age and status should be updated
        $this->assertEquals(30, $targetRow['age']);
        $this->assertEquals('new', $targetRow['status']);
    }

    public function testInsertFromWithCte(): void
    {
        // Insert source data
        self::$db->find()->table('insert_from_source')->insert(['name' => 'Grace', 'age' => 32, 'status' => 'active']);

        // Use CTE in source query - need to specify columns for INSERT
        $affected = self::$db->find()
            ->table('insert_from_target')
            ->insertFrom(function ($query) {
                $query->with('filtered_source', function ($q) {
                    $q->from('insert_from_source')
                        ->where('status', 'active')
                        ->select(['name', 'age', 'status']);
                })
                ->from('filtered_source')
                ->select(['name', 'age', 'status']);
            }, ['name', 'age', 'status']);

        $this->assertEquals(1, $affected);

        $targetRow = self::$db->find()->from('insert_from_target')->where('name', 'Grace')->getOne();
        $this->assertNotNull($targetRow);
        $this->assertEquals('Grace', $targetRow['name']);
    }
}
