<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

/**
 * MSSQL-specific tests for INSERT ... SELECT functionality.
 */
final class InsertFromTests extends BaseMSSQLTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $connection = self::$db->connection;
        assert($connection !== null);

        // Create tables for INSERT ... SELECT tests
        // Drop constraint if exists (constraint name might persist even after table drop)
        $connection->query('IF OBJECT_ID(\'uniq_name\', \'UQ\') IS NOT NULL BEGIN DECLARE @tableName NVARCHAR(128); SELECT @tableName = OBJECT_SCHEMA_NAME(parent_object_id) + \'.\' + OBJECT_NAME(parent_object_id) FROM sys.objects WHERE name = \'uniq_name\' AND type = \'UQ\'; EXEC(\'ALTER TABLE \' + @tableName + \' DROP CONSTRAINT uniq_name\'); END');
        $connection->query('IF OBJECT_ID(\'insert_from_target\', \'U\') IS NOT NULL DROP TABLE insert_from_target');
        $connection->query('IF OBJECT_ID(\'insert_from_source\', \'U\') IS NOT NULL DROP TABLE insert_from_source');

        $connection->query('
            CREATE TABLE insert_from_target (
                id INT IDENTITY(1,1) PRIMARY KEY,
                name NVARCHAR(100),
                age INT,
                status NVARCHAR(50),
                CONSTRAINT uniq_name UNIQUE (name)
            )
        ');

        $connection->query('
            CREATE TABLE insert_from_source (
                id INT IDENTITY(1,1) PRIMARY KEY,
                name NVARCHAR(100),
                age INT,
                status NVARCHAR(50)
            )
        ');
    }

    public function setUp(): void
    {
        parent::setUp();
        $connection = self::$db->connection;
        assert($connection !== null);
        // Clean up tables before each test
        $connection->query('DELETE FROM insert_from_target');
        $connection->query('DELETE FROM insert_from_source');
        // Reset IDENTITY seed
        $connection->query('DBCC CHECKIDENT(\'insert_from_target\', RESEED, 0)');
        $connection->query('DBCC CHECKIDENT(\'insert_from_source\', RESEED, 0)');
    }

    public function testInsertFromWithMerge(): void
    {
        // Insert initial target data
        self::$db->find()->table('insert_from_target')->insert(['name' => 'Alice', 'age' => 25, 'status' => 'old']);

        // Insert source data
        self::$db->find()->table('insert_from_source')->insert(['name' => 'Alice', 'age' => 30, 'status' => 'new']);

        // MSSQL uses MERGE for upsert - need to specify columns
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
