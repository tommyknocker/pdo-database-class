<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * BasicOperationsTests tests for mssql.
 */
final class BasicOperationsTests extends BaseMSSQLTestCase
{
    public function testInsertWithRawValue(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert([
            'name' => 'raw_now_user',
            'age' => 21,
            'created_at' => Db::now(),
        ]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        // Check what's actually in the database
        $allRows = $db->rawQuery('SELECT * FROM [users] ORDER BY [id]');
        $this->assertNotEmpty($allRows, 'Users table should have at least one row after insert');
        $this->assertIsArray($allRows);

        // Check last insert ID from connection
        $connection = $db->connection;
        assert($connection !== null);
        $lastInsertId = $connection->getLastInsertId();
        $this->assertNotFalse($lastInsertId, 'Last insert ID should be available');

        // Try to find row using the actual last insert ID instead of returned ID
        $checkRow = $db->rawQueryOne('SELECT * FROM [users] WHERE [id] = ?', [$lastInsertId]);
        if ($checkRow === false && $lastInsertId !== (string)$id) {
            // If lastInsertId differs from returned ID, try returned ID
            $checkRow = $db->rawQueryOne('SELECT * FROM [users] WHERE [id] = ?', [$id]);
        }
        $this->assertNotFalse($checkRow, 'Row should exist after insert');
        $this->assertIsArray($checkRow);

        // Use the ID that actually exists
        $actualId = $checkRow['id'] ?? $id;

        $row = $db->find()
            ->from('users')
            ->where('id', $actualId ?? $id)
            ->select(['id', 'name', 'created_at', Db::raw('GETDATE() AS nowcol')])
            ->getOne();

        $this->assertNotFalse($row, 'Row should be found');
        $this->assertIsArray($row);
        $this->assertNotEquals('1900-01-01 00:00:00.000', $row['created_at']);
        $this->assertArrayHasKey('nowcol', $row);
        $this->assertNotEquals('1900-01-01 00:00:00.000', $row['nowcol']);
        $this->assertEquals('raw_now_user', $row['name']);
    }

    public function testInsertWithNullHelper(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert([
                'name' => 'NullUser',
                'company' => 'Acme',
                'age' => 25,
                'status' => Db::null(),
            ]);

        $this->assertIsInt($id);

        $user = $db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();

        $this->assertNull($user['status']);
    }

    public function testUpdate(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'Vasiliy', 'age' => 30]);
        $this->assertIsInt($id);

        self::$db->find()
            ->table('users')
            ->where('id', $id)
            ->update(['age' => 31]);

        $row = $db->find()
            ->from('users')
            ->getOne();
        $this->assertIsArray($row);
        $this->assertEquals('31', $row['age']);
    }

    public function testUpdateWithRawValue(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert([
            'name' => 'update_raw_user',
            'age' => 30,
        ]);
        $this->assertIsInt($id);

        // Update using RawValue for timestamp and expression for value
        $rowCount = $db->find()
            ->from('users')
            ->where('id', $id)
            ->update([
                'created_at' => Db::now(),
                'updated_at' => Db::now(),
                'age' => Db::raw('age + 5'),
            ]);
        $this->assertEquals(1, $rowCount);

        $row = $db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();

        $this->assertNotNull($row['updated_at']);
        $this->assertNotEmpty($row['updated_at']);
        $this->assertEquals(35, (int)$row['age']);
    }

    public function testTruncate(): void
    {
        $db = self::$db;

        $rowCount = self::$db->find()
            ->table('archive_users')
            ->insertMulti([
                ['user_id' => 1],
                ['user_id' => 2],
                ['user_id' => 3],
            ]);
        $this->assertEquals(3, $rowCount);

        $rows = $db->find()
            ->from('archive_users')
            ->get();
        $this->assertCount(3, $rows);

        $result = $db->find()->table('archive_users')->truncate();
        $this->assertTrue($result);

        $rows = $db->find()
            ->from('archive_users')
            ->get();
        $this->assertCount(0, $rows);
    }

    public function testDelete(): void
    {
        $db = self::$db;

        $rowCount = self::$db->find()
            ->table('users')
            ->insertMulti([
                ['name' => 'UserA', 'age' => 20],
                ['name' => 'UserB', 'age' => 20],
                ['name' => 'UserC', 'age' => 30],
                ['name' => 'UserD', 'age' => 40],
                ['name' => 'UserE', 'age' => 50],
            ]);
        $this->assertEquals(5, $rowCount);

        $deleted = self::$db->find()
            ->from('users')
            ->where('age', 20)
            ->delete();
        $this->assertEquals(2, $deleted);

        $rows = $db->find()
            ->table('users')
            ->get();
        $this->assertCount(3, $rows);
    }

    public function testRawQueryOne(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'Test', 'age' => 42]);
        $this->assertEquals(1, $id);

        $row = self::$db->rawQueryOne('SELECT * FROM users WHERE name = ?', ['Test']);
        $this->assertEquals(42, $row['age']);
    }

    public function testRawQueryValue(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'RawVal', 'age' => 55]);
        $this->assertEquals(1, $id);

        $age = self::$db->rawQueryValue('SELECT age FROM users WHERE name = ?', ['RawVal']);
        $this->assertEquals(55, $age);
    }
}
