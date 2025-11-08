<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

/**
 * MySQL-specific tests for INSERT ... SELECT functionality.
 */
final class InsertFromTests extends BaseMySQLTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create tables for INSERT ... SELECT tests
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_from_target');
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_from_source');

        self::$db->rawQuery('
            CREATE TABLE insert_from_target (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100),
                age INT,
                status VARCHAR(50),
                UNIQUE KEY uniq_name (name)
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE insert_from_source (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100),
                age INT,
                status VARCHAR(50)
            )
        ');
    }

    public function setUp(): void
    {
        parent::setUp();
        // Clean up tables before each test
        self::$db->rawQuery('SET FOREIGN_KEY_CHECKS=0');
        self::$db->rawQuery('TRUNCATE TABLE insert_from_target');
        self::$db->rawQuery('TRUNCATE TABLE insert_from_source');
        self::$db->rawQuery('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testInsertFromWithOnDuplicate(): void
    {
        // Insert initial target data
        self::$db->find()->table('insert_from_target')->insert(['name' => 'Alice', 'age' => 25, 'status' => 'old']);

        // Insert source data
        self::$db->find()->table('insert_from_source')->insert(['name' => 'Alice', 'age' => 30, 'status' => 'new']);

        // MySQL uses ON DUPLICATE KEY UPDATE - need to specify columns
        $affected = self::$db->find()
            ->table('insert_from_target')
            ->insertFrom(function ($query) {
                $query->from('insert_from_source')
                    ->select(['name', 'age', 'status']);
            }, ['name', 'age', 'status'], ['age', 'status']);

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
