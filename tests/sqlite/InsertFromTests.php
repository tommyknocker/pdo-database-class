<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use PDOException;

/**
 * SQLite-specific tests for INSERT ... SELECT functionality.
 */
final class InsertFromTests extends BaseSqliteTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Create tables for INSERT ... SELECT tests
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_from_target');
        self::$db->rawQuery('DROP TABLE IF EXISTS insert_from_source');

        self::$db->rawQuery('
            CREATE TABLE insert_from_target (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                age INTEGER,
                status TEXT
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE insert_from_source (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                age INTEGER,
                status TEXT
            )
        ');
    }

    public function testInsertFromWithOnDuplicateThrowsException(): void
    {
        // Insert initial target data
        self::$db->find()->table('insert_from_target')->insert(['name' => 'Alice', 'age' => 25, 'status' => 'old']);

        // Insert source data
        self::$db->find()->table('insert_from_source')->insert(['name' => 'Alice', 'age' => 30, 'status' => 'new']);

        // SQLite doesn't support ON DUPLICATE KEY UPDATE with INSERT ... SELECT
        // Should throw an exception
        $this->expectException(PDOException::class);

        self::$db->find()
            ->table('insert_from_target')
            ->insertFrom(function ($query) {
                $query->from('insert_from_source')
                    ->select(['name', 'age', 'status']);
            }, ['name', 'age', 'status'], ['age', 'status']);
    }

    public function testInsertFromWithCte(): void
    {
        // Insert source data
        self::$db->find()->table('insert_from_source')->insert(['name' => 'Grace', 'age' => 32, 'status' => 'active']);

        // SQLite 3.35.0+ supports CTE in INSERT ... SELECT
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
