<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\helpers\Db;

/**
 * BasicOperationsTests tests for sqlite.
 */
final class BasicOperationsTests extends BaseSqliteTestCase
{
    public function testInsertWithQueryOption(): void
    {
        $db = self::$db;
        $id = $db->find()
        ->table('users')
        ->option('IGNORE')
        ->insert(['name' => 'Alice']);
        $this->assertEquals(1, $id);
        $this->assertStringStartsWith('INSERT OR IGNORE INTO "users"', $db->lastQuery);
    }

    public function testInsertWithMultipleQueryOptions(): void
    {
        $db = self::$db;

        // SQLite supports only one OR clause, but we can test that option() method works
        // with both array and sequential calls
        $id = $db->find()
        ->table('users')
        ->option(['IGNORE'])
        ->insert(['name' => 'Bob']);
        $this->assertIsInt($id);

        $this->assertStringStartsWith('INSERT OR IGNORE INTO "users"', $db->lastQuery);

        // Test sequential option() calls
        $id2 = $db->find()
        ->table('users')
        ->option('IGNORE')
        ->insert(['name' => 'Charlie']);
        $this->assertIsInt($id2);

        $this->assertStringStartsWith('INSERT OR IGNORE INTO "users"', $db->lastQuery);

        // Try to insert duplicate with IGNORE - should be ignored, no new record created
        $countBefore = $db->find()->from('users')->where('name', 'Charlie')->getValue();

        $db->find()
        ->table('users')
        ->option('IGNORE')
        ->insert(['name' => 'Charlie']);

        $countAfter = $db->find()->from('users')->select(Db::raw('COUNT(*)'))->where('name', 'Charlie')->getValue();
        $this->assertEquals(1, $countAfter); // Still only 1 record with name 'Charlie'
    }

    public function testInsertWithRawValue(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert([
        'name' => 'raw_now_user',
        'age' => 21,
        'created_at' => Db::now(),
        ]);

        $this->assertIsInt($id);

        $row = $db->find()
        ->from('users')
        ->where('id', $id)
        ->select(['id', 'name', 'created_at', 'nowcol' => Db::now()])
        ->getOne();

        $this->assertNotEquals('0000-00-00 00:00:00', $row['created_at']);
        $this->assertArrayHasKey('nowcol', $row);
        $this->assertNotEquals('0000-00-00 00:00:00', $row['nowcol']);
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

    public function testInsertMultiWithRawValues(): void
    {
        $db = self::$db;

        $rows = [
        ['name' => 'multi_raw_1', 'age' => 10, 'created_at' => Db::now()],
        ['name' => 'multi_raw_2', 'age' => 11, 'created_at' => Db::now()],
        ];

        $rowCount = $db->find()->table('users')->insertMulti($rows);
        $this->assertEquals(2, $rowCount);

        // ON DUPLICATE with RawValue increment for age
        $db->find()->table('users')->onDuplicate([
        'age' => Db::raw('age + 100'),
        ])->insert([
        'name' => 'multi_raw_1',
        'age' => 20,
        ]);

        $row = $db->find()->from('users')->where('name', 'multi_raw_1')->getOne();
        $this->assertGreaterThanOrEqual(110, (int)$row['age']);
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

    public function testUpdateLimit(): void
    {
        $db = self::$db;

        for ($i = 1; $i <= 7; $i++) {
            $db->find()->table('users')->insert([
            'name' => "Cnt{$i}",
            'company' => 'C',
            'age' => 20 + $i,
            'status' => 'active',
            ]);
        }

        // Update with limit 5
        $db->find()->table('users')
        ->limit(5)
        ->update(['status' => 'inactive']);

        $inactive = $db->find()
        ->from('users')
        ->where('status', 'inactive')
        ->get();

        $this->assertCount(5, $inactive);
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

        $this->assertNotEquals('0000-00-00 00:00:00', $row['updated_at']);
        $this->assertSame($row['created_at'], $row['updated_at']);
        $this->assertNotEmpty($row['updated_at']);
        $this->assertEquals(35, (int)$row['age']);
    }

    public function testUpdateWithSubquery(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['id' => 1, 'name' => 'Alice']);
        $db->find()->table('users')->insert(['id' => 2, 'name' => 'Bob']);
        $db->find()->table('users')->insert(['id' => 3, 'name' => 'Charlie']);

        $db->find()->table('orders')->insert(['user_id' => 1, 'amount' => 100]);
        $db->find()->table('orders')->insert(['user_id' => 1, 'amount' => 200]);
        $db->find()->table('orders')->insert(['user_id' => 2, 'amount' => 50]);

        $sub = $db->find()->from('orders')->select(['user_id']);

        $db->find()
        ->from('users')
        ->where('id', $sub, 'IN')
        ->update(['status' => 'active']);

        $this->assertStringContainsString('UPDATE "users" SET "status"', $db->lastQuery);
        $this->assertStringContainsString('WHERE "id" IN (SELECT "user_id"', $db->lastQuery);
        $this->assertStringContainsString('FROM "orders")', $db->lastQuery);

        $count = $db->find()
        ->from('users')
        ->select(Db::raw('COUNT(*)'))
        ->where('status', 'active')
        ->getValue();
        $this->assertEquals(2, $count);
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

    public function testDeleteWithSubquery(): void
    {
        $db = self::$db;

        $rowCount = self::$db->find()
        ->table('users')
        ->insertMulti([
        ['id' => 1, 'name' => 'UserA', 'age' => 10],
        ['id' => 2, 'name' => 'UserB', 'age' => 20],
        ['id' => 3, 'name' => 'UserC', 'age' => 30],
        ['id' => 4, 'name' => 'UserD', 'age' => 40],
        ['id' => 5, 'name' => 'UserE', 'age' => 50],
        ]);
        $this->assertEquals(5, $rowCount);

        $rowCount = self::$db->find()
        ->table('orders')
        ->insertMulti([
        ['user_id' => 1, 'amount' => 100],
        ['user_id' => 2, 'amount' => 200],
        ]);
        $this->assertEquals(2, $rowCount);

        $subQuery = $db->find()
        ->from('orders')
        ->select('user_id')
        ->where('amount', 100, '>=');

        $db->find()
        ->from('users')
        ->where('id', $subQuery, 'IN')
        ->delete();

        $this->assertStringContainsString('DELETE FROM "users" WHERE "id" IN (SELECT "user_id"', $db->lastQuery);
        $this->assertStringContainsString('FROM "orders" WHERE "amount" >=', $db->lastQuery);

        $rows = $db->find()
        ->table('users')
        ->select('id')
        ->orderBy('id', 'ASC')
        ->getColumn();
        $this->assertCount(3, $rows);
        $this->assertSame([3, 4, 5], $rows);
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

    public function testReplace(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert([
        'name' => 'Diana',
        'age' => 40,
        ]);
        $this->assertIsInt($id);

        $rowCount = $db->find()->table('users')->replace([
        'id' => $id,
        'name' => 'Diana',
        'age' => 41,
        ]);
        $this->assertGreaterThan(0, $rowCount);

        $row = $db->find()
        ->from('users')
        ->where('id', $id)
        ->getOne();

        $this->assertEquals(41, $row['age']);
    }

    public function testReplaceMulti(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insertMulti([
        ['id' => 1, 'name' => 'Alice', 'age' => 25],
        ['id' => 2, 'name' => 'Bob', 'age' => 30],
        ]);

        $all = $db->find()
        ->from('users')
        ->orderBy('id')
        ->get();

        $this->assertCount(2, $all);
        $this->assertEquals(25, $all[0]['age']);
        $this->assertEquals(30, $all[1]['age']);

        $rowCount = $db->find()->table('users')->replaceMulti([
        ['id' => 1, 'name' => 'Alice', 'age' => 26],     // replace existing
        ['id' => 3, 'name' => 'Charlie', 'age' => 35],   // insert new
        ]);
        $this->assertEquals(3, $rowCount);

        $all = $db->find()
        ->from('users')
        ->orderBy('id')
        ->get();

        $this->assertCount(3, $all);
        $this->assertEquals(26, $all[0]['age']);            // Alice replaced
        $this->assertEquals(30, $all[1]['age']);            // Bob unchanged
        $this->assertEquals('Charlie', $all[2]['name']);    // Charlie added
        $this->assertEquals(35, $all[2]['age']);
    }

    public function testInsertWithOnDuplicateParameter(): void
    {
        $db = self::$db;

        // First insert
        $db->find()->table('users')->insert(['name' => 'TestUser', 'age' => 25]);

        // Insert with onDuplicate as second parameter (SQLite uses ON CONFLICT)
        $db->find()->table('users')->insert(['name' => 'TestUser', 'age' => 30], ['age']);

        $this->assertStringContainsString('ON CONFLICT', $db->lastQuery);
        $this->assertStringContainsString('DO UPDATE', $db->lastQuery);

        $row = $db->find()->table('users')->where('name', 'TestUser')->getOne();
        $this->assertEquals(30, $row['age']);
    }

    public function testInsertMultiWithOnDuplicateParameter(): void
    {
        $db = self::$db;

        // Initial data
        $db->find()->table('users')->insert(['name' => 'MultiTest1', 'age' => 20]);

        // insertMulti with onDuplicate as second parameter
        $db->find()->table('users')->insertMulti([
        ['name' => 'MultiTest1', 'age' => 25],
        ['name' => 'MultiTest2', 'age' => 30],
        ], ['age']);

        $this->assertStringContainsString('ON CONFLICT', $db->lastQuery);
        $this->assertStringContainsString('DO UPDATE', $db->lastQuery);

        $row1 = $db->find()->table('users')->where('name', 'MultiTest1')->getOne();
        $this->assertEquals(25, $row1['age']);

        $row2 = $db->find()->table('users')->where('name', 'MultiTest2')->getOne();
        $this->assertEquals(30, $row2['age']);
    }
}
