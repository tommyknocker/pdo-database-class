<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use RuntimeException;
use tommyknocker\pdodb\helpers\Db;

/**
 * UpsertTests tests for MSSQL.
 */
final class UpsertTests extends BaseMSSQLTestCase
{
    public function testInsertOnDuplicateThrowsException(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Eve', 'age' => 20]);

        // MSSQL doesn't support ON DUPLICATE KEY UPDATE, should throw exception
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MSSQL does not support UPSERT via INSERT ... ON DUPLICATE KEY UPDATE');

        $db->find()
        ->table('users')
        // @phpstan-ignore argument.type
        ->onDuplicate(['age'])
        ->insert(['name' => 'Eve', 'age' => 21]);
    }

    public function testUpsertWithMerge(): void
    {
        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        // Create temporary source table for MERGE
        $connection->query('IF OBJECT_ID(\'upsert_source\', \'U\') IS NOT NULL DROP TABLE upsert_source');
        $connection->query('
            CREATE TABLE upsert_source (
                name NVARCHAR(100) PRIMARY KEY,
                age INT
            )
        ');

        // Insert initial data
        $db->find()->table('users')->insert(['name' => 'Eve', 'age' => 20]);

        // Insert source data for MERGE
        $db->find()->table('upsert_source')->insert(['name' => 'Eve', 'age' => 21]);

        // Perform MERGE (UPSERT equivalent)
        $affected = $db->find()
            ->table('users')
            ->merge(
                'upsert_source',
                'target.[name] = source.[name]',
                ['age' => Db::raw('source.[age]')],
                ['name' => Db::raw('source.[name]'), 'age' => Db::raw('source.[age]')],
                false
            );

        $this->assertGreaterThan(0, $affected);

        $row = $db->find()
        ->from('users')
        ->where('name', 'Eve')
        ->getOne();

        $this->assertEquals(21, $row['age']);

        $connection->query('DROP TABLE upsert_source');
    }

    public function testUpsertWithMergeAndRawIncrement(): void
    {
        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        // Create temporary source table for MERGE
        $connection->query('IF OBJECT_ID(\'upsert_source2\', \'U\') IS NOT NULL DROP TABLE upsert_source2');
        $connection->query('
            CREATE TABLE upsert_source2 (
                name NVARCHAR(100) PRIMARY KEY,
                age INT
            )
        ');

        // Insert initial data
        $db->find()->table('users')->insert(['name' => 'UpsertTest', 'age' => 10]);

        // Insert source data for MERGE
        $db->find()->table('upsert_source2')->insert(['name' => 'UpsertTest', 'age' => 20]);

        // Perform MERGE with raw increment expression
        $affected = $db->find()
            ->table('users')
            ->merge(
                'upsert_source2',
                'target.[name] = source.[name]',
                ['age' => Db::raw('target.[age] + 5')],
                ['name' => Db::raw('source.[name]'), 'age' => Db::raw('source.[age]')],
                false
            );

        $this->assertGreaterThan(0, $affected);

        $row = $db->find()->from('users')->where('name', 'UpsertTest')->getOne();
        $this->assertEquals(15, $row['age'], 'Age should be incremented by 5 (10 + 5)');

        $connection->query('DROP TABLE upsert_source2');
    }
}
