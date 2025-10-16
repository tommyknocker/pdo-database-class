<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\tests;

use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\helpers\RawValue;
use tommyknocker\pdodb\PdoDb;

final class PdoDbSqliteTest extends TestCase
{
    private static PdoDb $db;
    public function setUp(): void
    {
        self::$db = new PdoDb('sqlite', ['path' => ':memory:']);
        self::$db->rawQuery("CREATE TABLE IF NOT EXISTS users (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT,
              company TEXT,
              age INTEGER,
              status TEXT DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME DEFAULT NULL,
              UNIQUE(name)
        );");

        self::$db->rawQuery("CREATE TABLE IF NOT EXISTS orders (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER NOT NULL,
              amount NUMERIC(10,2) NOT NULL,
              FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        );");

        self::$db->rawQuery("
        CREATE TABLE IF NOT EXISTS archive_users (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          user_id INTEGER
        );");
    }

    public function testSqliteMinimalParams(): void
    {
        $dsn = self::$db->connection->getDialect()->buildDsn([
            'driver' => 'sqlite',
            'path' => ':memory:',
        ]);
        $this->assertEquals('sqlite::memory:', $dsn);
    }

    public function testSqliteAllParams(): void
    {
        $dsn = self::$db->connection->getDialect()->buildDsn([
            'driver' => 'sqlite',
            'path' => '/tmp/test.sqlite',
            'mode' => 'rwc',
            'cache' => 'shared',
        ]);
        $this->assertStringContainsString('sqlite:/tmp/test.sqlite', $dsn);
        $this->assertStringContainsString('mode=rwc', $dsn);
        $this->assertStringContainsString('cache=shared', $dsn);
    }

    public function testSqliteMissingParamsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$db->connection->getDialect()->buildDsn(['driver' => 'sqlite']); // no path
    }

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

    public function testInsertWithRawValue(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert([
            'name' => 'raw_now_user',
            'age' => 21,
            'created_at' => new RawValue('CURRENT_TIMESTAMP'),
        ]);

        $this->assertIsInt($id);

        $row = $db->find()
            ->from('users')
            ->where('id', $id)
            ->select(['id', 'name', 'created_at', new RawValue('CURRENT_TIMESTAMP AS nowcol')])
            ->getOne();

        $this->assertNotEquals('0000-00-00 00:00:00', $row['created_at']);
        $this->assertArrayHasKey('nowcol', $row);
        $this->assertNotEquals('0000-00-00 00:00:00', $row['nowcol']);
        $this->assertEquals('raw_now_user', $row['name']);
    }


    public function testInsertMultiWithRawValues(): void
    {
        $db = self::$db;

        $rows = [
            ['name' => 'multi_raw_1', 'age' => 10, 'created_at' => new RawValue('CURRENT_TIMESTAMP')],
            ['name' => 'multi_raw_2', 'age' => 11, 'created_at' => new RawValue('CURRENT_TIMESTAMP')],
        ];

        $rowCount = $db->find()->table('users')->insertMulti($rows);
        $this->assertEquals(2, $rowCount);

        // ON DUPLICATE with RawValue increment for age
        $db->find()->table('users')->onDuplicate([
            'age' => new RawValue('age + 100')
        ])->insert([
            'name' => 'multi_raw_1',
            'age' => 20
        ]);

        $row = $db->find()->from('users')->where('name', 'multi_raw_1')->getOne();
        $this->assertGreaterThanOrEqual(110, (int)$row['age']);
    }


    public function testSelectWithQueryOption(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'Test', 'age' => 20]);
        $this->assertEquals(1, $id);

        $rows = $db->find()
            ->from('users')
            ->option('DISTINCT')
            ->get();
        $this->assertCount(1, $rows);

        $row = $db->find()
            ->from('users')
            ->option('DISTINCT')
            ->getOne();
        $this->assertIsArray($row);
        $this->assertEquals('Test', $row['name']);

        $this->assertStringStartsWith('SELECT DISTINCT * FROM "users"', $db->lastQuery);
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
                'created_at' => new RawValue('CURRENT_TIMESTAMP'),
                'updated_at' => new RawValue('CURRENT_TIMESTAMP'),
                'age' => new RawValue('age + 5'),
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

        $this->assertStringContainsString('UPDATE "users" SET "status" = :upd_status WHERE "id" IN (SELECT "user_id" FROM "orders")', $db->lastQuery);

        $count = $db->find()
            ->from('users')
            ->select(new RawValue('COUNT(*)'))
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
                ['name' => 'UserE', 'age' => 50]
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
                ['id' => 5, 'name' => 'UserE', 'age' => 50]
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


        $this->assertStringContainsString(
            'DELETE FROM "users" WHERE "id" IN (SELECT "user_id" FROM "orders" WHERE "amount" >=',
            $db->lastQuery
        );
        $this->assertStringContainsString(')', $db->lastQuery);


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

        $row = self::$db->rawQueryOne("SELECT * FROM users WHERE name = ?", ['Test']);
        $this->assertEquals(42, $row['age']);
    }

    public function testRawQueryValue(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'RawVal', 'age' => 55]);
        $this->assertEquals(1, $id);


        $age = self::$db->rawQueryValue("SELECT age FROM users WHERE name = ?", ['RawVal']);
        $this->assertEquals(55, $age);
    }

    public function testOrWhere(): void
    {
        $rowCount = self::$db->find()
            ->table('users')
            ->insertMulti([
                ['name' => 'A', 'age' => 10],
                ['name' => 'B', 'age' => 20],
            ]);
        $this->assertEquals(2, $rowCount);

        $rows = self::$db->find()
            ->from('users')
            ->where('age', 10)
            ->orWhere('age', 20)
            ->get();
        $this->assertCount(2, $rows);

        $rows = self::$db->find()
            ->from('users')
            ->where('age', 10)
            ->orWhere('age', 30)
            ->get();
        $this->assertCount(1, $rows);
    }

    public function testAndWhere(): void
    {
        $rowCount = self::$db->find()
            ->table('users')
            ->insertMulti([
                ['name' => 'A', 'age' => 10],
                ['name' => 'B', 'age' => 20],
            ]);
        $this->assertEquals(2, $rowCount);

        $rows = self::$db->find()
            ->from('users')
            ->where('age', 10)
            ->andWhere('name', 'A')
            ->get();
        $this->assertCount(1, $rows);

        $rows = self::$db->find()
            ->from('users')
            ->where('age', 10)
            ->andWhere('name', 'B')
            ->get();
        $this->assertCount(0, $rows);
    }

    public function testInnerJoinAndWhere(): void
    {
        $db = self::$db;

        $uid = $db->find()
            ->table('users')
            ->insert(['name' => 'JoinUser', 'age' => 40]);
        $this->assertEquals(1, $uid);


        self::$db->find()
            ->table('orders')
            ->insert(['user_id' => $uid, 'amount' => 100]);

        $rows = self::$db
            ->find()
            ->from('users u')
            ->innerJoin('orders o', 'u.id = o.user_id')
            ->where('o.amount', 100)
            ->andWhere('o.amount', [100, 200], 'IN')
            ->get();

        $this->assertEquals('JoinUser', $rows[0]['name']);
    }

    public function testComplexWhereAndOrWhere(): void
    {
        $rowCount = self::$db->find()
            ->table('users')
            ->insertMulti([
                ['name' => 'UserA', 'age' => 20],
                ['name' => 'UserB', 'age' => 30],
                ['name' => 'UserC', 'age' => 40],
                ['name' => 'UserD', 'age' => 50]
            ]);
        $this->assertEquals(4, $rowCount);

        // Build query with where + multiple orWhere
        $ages = self::$db
            ->find()
            ->from('users')
            ->select(['age'])
            ->where('age', 20)
            ->orWhere('age', 30)
            ->orWhere('age', 40)
            ->getColumn();

        // Assert that only expected ages are returned
        sort($ages);
        $this->assertEquals([20, 30, 40], $ages);
    }

    public function testGetColumn(): void
    {
        $db = self::$db;

        // prepare data
        $db->find()->table('users')->insert(['id' => 1, 'name' => 'Alice', 'age' => 30]);
        $db->find()->table('users')->insert(['id' => 2, 'name' => 'Bob', 'age' => 25]);

        // 1) simple field
        $columns = $db->find()->from('users')->select(['id'])->getColumn();
        $this->assertIsArray($columns);
        $this->assertCount(2, $columns);
        $this->assertSame([1, 2], array_values($columns));

        // 2) alias support (select expression with alias)
        $columns = $db->find()
            ->from('users')
            ->select(['name AS username'])
            ->getColumn();
        $this->assertIsArray($columns);
        $this->assertCount(2, $columns);
        $this->assertSame(['Alice', 'Bob'], array_values($columns));

        // 3) raw value with alias (must provide alias to be addressable)
        $columns = $db->find()
            ->from('users')
            ->select([new RawValue('CONCAT(name, "_", age) AS name_age')])
            ->getColumn();
        $this->assertIsArray($columns);
        $this->assertCount(2, $columns);
        $this->assertSame(['Alice_30', 'Bob_25'], array_values($columns));
    }

    public function testGetValue(): void
    {
        $db = self::$db;

        // prepare data
        $db->find()->table('users')->insert(['id' => 10, 'name' => 'Carol', 'age' => 40]);

        // 1) simple field -> returns single value from first row
        $val = $db->find()->from('users')->select(['id'])->getValue();
        $this->assertNotFalse($val);
        $this->assertSame(10, $val);

        // 2) alias support
        $val = $db->find()->from('users')->select(['name AS username'])->getValue();
        $this->assertNotFalse($val);
        $this->assertSame('Carol', $val);

        // 3) raw value with alias
        $val = $db->find()
            ->from('users')
            ->select([new RawValue('CONCAT(name, "-", age) AS n_age')])
            ->getValue();
        $this->assertNotFalse($val);
        $this->assertSame('Carol-40', $val);
    }

    public function testGetAsObject(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Olya', 'age' => 22]);

        // objectBuilder for multiple rows
        $rows = $db->find()
            ->from('users')
            ->asObject()
            ->get();
        $this->assertIsObject($rows[0]);
        $this->assertEquals(22, $rows[0]->age);

        // objectBuilder for single row
        $row = $db->find()
            ->from('users')
            ->asObject()
            ->getOne();
        $this->assertIsObject($row);
        $this->assertEquals(22, $row->age);
    }


    public function testWhereBetweenAndNotBetween(): void
    {
        $db = self::$db;

        // Prepare data
        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'D', 'age' => 50]);

        // BETWEEN
        $ages = $db->find()
            ->from('users')
            ->select('age')
            ->where('age', [20, 40], 'BETWEEN')
            ->orderBy('age', 'ASC')
            ->getColumn();
        $this->assertEquals([25, 30], $ages);

        // NOT BETWEEN
        $ages = $db->find()
            ->from('users')
            ->select('age')
            ->where('age', [20, 40], 'NOT BETWEEN')
            ->orderBy('age', 'ASC')
            ->getColumn();
        $this->assertEquals([10, 50], $ages);
    }

    public function testWhereInAndNotIn(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'E', 'age' => 60]);
        $db->find()->table('users')->insert(['name' => 'F', 'age' => 70]);

        // IN
        $rows = $db->find()
            ->from('users')
            ->where('age', [60, 70], 'IN')
            ->get();
        $ages = array_column($rows, 'age');
        sort($ages);
        $this->assertEquals([60, 70], $ages);

        // NOT IN
        $rows = $db->find()
            ->from('users')
            ->where('age', [60, 70], 'NOT IN')
            ->get();
        $ages = array_column($rows, 'age');
        $this->assertNotContains(60, $ages);
        $this->assertNotContains(70, $ages);
    }

    public function testWhereInWithEmptyArray(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'EmptyIn', 'age' => 99]);

        $rows = $db->find()
            ->from('users')
            ->where('age', [], 'IN')
            ->get();

        $this->assertCount(0, $rows, 'IN [] must return no rows');
    }

    public function testWhereNotInWithEmptyArray(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert(['name' => 'EmptyNotIn', 'age' => 100]);

        $rows = $db->find()
            ->from('users')
            ->where('id', [], 'NOT IN')
            ->get();
        $ids = array_column($rows, 'id');

        $this->assertContains($id, $ids, 'NOT IN [] must not filter out any rows');
    }

    public function testWhereStateIsResetBetweenQueries(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['id' => 1, 'name' => 'Alice']);
        $db->find()->table('users')->insert(['id' => 2, 'name' => 'Bob']);

        $row = $db->find()
            ->from('users')
            ->where('id', 1)
            ->getOne();
        $this->assertEquals('Alice', $row['name']);

        $rows = $db->find()
            ->from('users')
            ->get();
        $this->assertCount(2, $rows);
    }

    public function testWhereInAndRawValueNotBound(): void
    {
        $db = self::$db;

        // prepare table
        $id1 = $db->find()->table('users')->insert(['name' => 'where_raw_1', 'age' => 1]);
        $id2 = $db->find()->table('users')->insert(['name' => 'where_raw_2', 'age' => 3]);

        // Use RawValue in condition so RHS is inlined and not bound as a parameter
        $rows = $db->find()
            ->from('users')
            ->where('id', [$id1, $id2], 'IN')
            ->where('age', new RawValue('2 + 1'), '=')
            ->get();

        $ids = array_column($rows, 'id');
        $this->assertContains($id2, $ids);
    }

    public function testHavingAndOrHaving(): void
    {
        $db = self::$db;

        $u1 = $db->find()->table('users')->insert(['name' => 'H1']);
        $u2 = $db->find()->table('users')->insert(['name' => 'H2']);
        $u3 = $db->find()->table('users')->insert(['name' => 'H3']);

        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 100]);
        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 200]); // total 300
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 300]);
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 400]); // total 700
        $db->find()->table('orders')->insert(['user_id' => $u3, 'amount' => 500]); // total 500

        $rows = $db->find()
            ->from('orders')
            ->groupBy('user_id')
            ->having(new RawValue('SUM(amount)'), 300, '=')
            ->orHaving(new RawValue('SUM(amount)'), 500, '=')
            ->orHaving(new RawValue('SUM(amount)'), 700, '=')
            ->select(['user_id', 'SUM(amount) AS total'])
            ->get();

        $totals = array_column($rows, 'total');
        sort($totals);
        $this->assertEquals([300, 500, 700], $totals);
    }

    public function testComplexHavingAndOrHaving(): void
    {
        $db = self::$db;


        $u1 = $db->find()->table('users')->insert(['name' => 'User1', 'age' => 20]);
        $u2 = $db->find()->table('users')->insert(['name' => 'User2', 'age' => 30]);
        $u3 = $db->find()->table('users')->insert(['name' => 'User3', 'age' => 40]);

        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 100]);
        $db->find()->table('orders')->insert(['user_id' => $u1, 'amount' => 200]); // total 300
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 300]);
        $db->find()->table('orders')->insert(['user_id' => $u2, 'amount' => 400]); // total 700
        $db->find()->table('orders')->insert(['user_id' => $u3, 'amount' => 500]); // total 500

        $rows = $db->find()
            ->from('orders')
            ->select(['user_id', 'SUM(amount) AS total'])
            ->groupBy('user_id')
            ->having(new RawValue('SUM(amount)'), 300, '>=')
            ->orHaving(new RawValue('SUM(amount)'), 500, '=')
            ->orHaving(new RawValue('SUM(amount)'), 700, '=')
            ->get();

        $totals = array_column($rows, 'total');
        sort($totals);
        $this->assertEquals([300, 500, 700], $totals);
    }

    public function testGroupBy(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insertMulti([
            ['name' => 'A', 'age' => 10],
            ['name' => 'B', 'age' => 10],
        ]);

        $rows = $db->find()
            ->from('users')
            ->groupBy('age')
            ->select(['age', 'COUNT(*) AS cnt'])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals(10, $rows[0]['age']);
        $this->assertEquals(2, $rows[0]['cnt']);
    }

    public function testTransaction(): void
    {
        $db = self::$db;

        // First transaction: insert and rollback
        $db->startTransaction();
        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        $db->rollback();

        $exists = $db->find()
            ->from('users')
            ->where('name', 'Alice')
            ->exists();
        $this->assertFalse($exists, 'Rolled back insert should not exist');

        // Second transaction: insert and commit
        $db->startTransaction();
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 40]);
        $db->commit();

        $exists = $db->find()
            ->from('users')
            ->where('name', 'Bob')
            ->exists();
        $this->assertTrue($exists, 'Committed insert should exist');
    }

    public function testLockUnlock(): void
    {
        $this->expectException(RuntimeException::class);
        $ok = self::$db->lock('users');
        $this->assertTrue($ok);

        $ok = self::$db->unlock();
        $this->assertTrue($ok);
    }

    public function testLockMultipleTableWrite()
    {
        $db = self::$db;

        $this->expectException(RuntimeException::class);
        $this->assertTrue($db->setLockMethod('WRITE')->lock(['users', 'orders']));

        $this->assertSame(
            'LOCK TABLES `users` WRITE, `orders` WRITE',
            $db->lastQuery
        );

        $ok = self::$db->unlock();
        $this->assertTrue($ok);
    }

    public function testEscape(): void
    {
        $unsafe = "O'Reilly";
        $safe = self::$db->escape($unsafe);
        $this->assertStringContainsString("'O''Reilly'", $safe);
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

    public function testInsertOnDuplicate(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Eve', 'age' => 20]);

        // Use fluent builder to set ON DUPLICATE behavior and perform insert
        $db->find()
            ->table('users')
            ->onDuplicate(['age'])
            ->insert(['name' => 'Eve', 'age' => 21]);

        $this->assertStringContainsString(
            'INSERT INTO "users" ("name","age") VALUES (:name,:age) ON CONFLICT ("name") DO UPDATE SET "age" = excluded."age"',
            $db->lastQuery
        );

        $row = $db->find()
            ->from('users')
            ->where('name', 'Eve')
            ->getOne();

        $this->assertEquals(21, $row['age']);
    }


    public function testSubQueryInWhere(): void
    {
        $db = self::$db;

        $rowCount = self::$db->find()
            ->table('users')
            ->insertMulti([
                ['name' => 'UserA', 'age' => 20],
                ['name' => 'UserB', 'age' => 20],
                ['name' => 'UserC', 'age' => 30],
                ['name' => 'UserD', 'age' => 40],
                ['name' => 'UserE', 'age' => 50]
            ]);
        $this->assertEquals(5, $rowCount);

        // prepare subquery that selects ids of users older than 30 and aliases it as u
        $sub = $db->find()->from('users u')
            ->select(['u.id'])
            ->where('age', 30, '>');

        // main query joins the subquery alias u to the main users table and returns up to 5 rows
        $rows = $db->find()
            ->from('users main')
            ->join('users u', 'u.id = main.id')
            ->select(['main.name', 'u.age'])
            ->where('u.id', $sub, 'IN')
            ->get();

        $this->assertStringContainsString('IN (SELECT', $db->lastQuery);
        $this->assertStringContainsString(')', $db->lastQuery);

        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
    }


    public function testDisconnectAndPing(): void
    {
        self::$db->disconnect();
        $this->assertFalse(self::$db->ping());
        self::$db = new PdoDb('sqlite', [
            'path' => ':memory:',
        ]);
        $this->assertTrue(self::$db->ping());
    }

    public function testTableExists(): void
    {
        $this->assertTrue(self::$db->tableExists('users'));
        $this->assertFalse(self::$db->tableExists('nonexistent'));
    }

    public function testInvalidSqlLogsErrorAndException(): void
    {
        $sql = 'INSERT INTO users (non_existing_column) VALUES (:v)';
        $params = ['v' => 'X'];

        $this->expectException(\PDOException::class);

        $testHandler = new TestHandler();
        $logger = new Logger('test-db');
        $logger->pushHandler($testHandler);
        $db = new PdoDb('sqlite', ['path' => ':memory:'], [], $logger);

        try {
            $db->connection->prepare($sql)->execute();
        } finally {
            $hasOpError = false;
            foreach ($testHandler->getRecords() as $rec) {
                if (($rec['message'] ?? '') === 'operation.error'
                    && ($rec['context']['operation'] ?? '') === 'execute'
                    && ($rec['context']['exception'] ?? new \StdClass()) instanceof \PDOException
                ) {
                    $hasOpError = true;
                }
            }
            $this->assertTrue($hasOpError, 'Expected operation.error for invalid SQL');
        }
    }

    public function testTransactionBeginCommitRollbackLogging(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test-db');
        $logger->pushHandler($testHandler);
        $db = new PdoDb('sqlite', ['path' => ':memory:'], [], $logger);

        // Begin
        $db->startTransaction();
        $foundBegin = false;
        foreach ($testHandler->getRecords() as $rec) {
            if (($rec['message'] ?? '') === 'operation.start'
                && ($rec['context']['operation'] ?? '') === 'transaction.begin'
            ) {
                $foundBegin = true;
                break;
            }
        }
        $this->assertTrue($foundBegin, 'transaction.begin not logged');

        // Commit
        $db->connection->commit();
        $foundCommit = false;
        foreach ($testHandler->getRecords() as $rec) {
            if (($rec['message'] ?? '') === 'operation.end'
                && ($rec['context']['operation'] ?? '') === 'transaction.commit'
            ) {
                $foundCommit = true;
                break;
            }
        }
        $this->assertTrue($foundCommit, 'transaction.commit not logged');

        // Rollback
        $db->connection->transaction();
        $db->connection->rollBack();
        $foundRollback = false;
        foreach ($testHandler->getRecords() as $rec) {
            if (($rec['message'] ?? '') === 'operation.end'
                && ($rec['context']['operation'] ?? '') === 'transaction.rollback'
            ) {
                $foundRollback = true;
                break;
            }
        }
        $this->assertTrue($foundRollback, 'transaction.rollback not logged');
    }

    public function testAddConnectionAndSwitch(): void
    {
        self::$db->addConnection('secondary', [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ]);

        $pdoDb = self::$db->connection('secondary');
        $this->assertInstanceOf(PdoDb::class, $pdoDb);

        $pdoDb = self::$db->connection('default');
        $this->assertInstanceOf(PdoDb::class, $pdoDb);
    }

    public function testLoadXml(): void
    {
        $file = sys_get_temp_dir() . '/users.xml';
        file_put_contents($file, <<<XML
            <users>
             <user>
                <name>XMLUser 1</name>
                <age>45</age>
              </user>
              <user>
                <name>XMLUser 2</name>
                <age>44</age>
              </user>
            </users>
XML
        );

        $ok = self::$db->loadXml('users', $file, '<user>', 1);
        $this->assertTrue($ok);

        $row = self::$db->find()->from('users')->where('name', 'XMLUser 2')->getOne();
        $this->assertEquals('XMLUser 2', $row['name']);
        $this->assertEquals(44, $row['age']);

        unlink($file);
    }

    public function testLoadCsv()
    {
        $db = self::$db;

        $tmpFile = sys_get_temp_dir() . '/users.csv';
        file_put_contents($tmpFile, "4,Dave,new,30\n5,Eve,new,40\n");

        $ok = $db->loadCsv('users', $tmpFile, [
            'fieldChar' => ',',
            'fields' => ['id', 'name', 'status', 'age'],
            'local' => true
        ]);

        $this->assertTrue($ok, 'loadData() returned false');

        $names =$db->find()
            ->from('users')
            ->select(['name'])
            ->getColumn();

        $this->assertContains('Dave', $names);
        $this->assertContains('Eve', $names);

        unlink($tmpFile);
    }

    public function testFuncNowIncDec(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert(['name' => 'FuncTest', 'age' => 1]);

        // now
        $expr = $db->now('', 'CURRENT_TIMESTAMP');
        $this->assertEquals('CURRENT_TIMESTAMP', $expr);

        $exprPlus = $db->now('1 DAY');
        $this->assertEquals("DATETIME('now','1 DAY')", (string) $exprPlus);

        // inc
        $db->find()
            ->from('users')
            ->where('id', $id)
            ->update(['age' => $db->inc()]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertEquals(2, $row['age']);

        // dec
        $db->find()
            ->from('users')
            ->where('id', $id)
            ->update(['age' => $db->dec()]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertEquals(1, $row['age']);

        // now() into updated_at
        $db->find()
            ->from('users')
            ->where('id', $id)
            ->update(['updated_at' => $db->now()]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertNotEmpty($row['updated_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['updated_at']);
        $this->assertNotEquals('0000-00-00 00:00:00', $row['updated_at']);

        // func MAX(age)
        $row = $db->find()->from('users')->select(['MAX(age) as max_age'])->getOne();
        $this->assertEquals(1, (int)$row['max_age']);
    }

    public function testDescribeUsers(): void
    {
        $db = self::$db;
        $columns = $db->describe('users');
        $this->assertNotEmpty($columns);
        
        $columnNames = array_column($columns, 'name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('company', $columnNames);
        $this->assertContains('age', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);
        
        $idColumn = $columns[0];
        $this->assertEquals('id', $idColumn['name']);
        $this->assertEquals('INTEGER', $idColumn['type']);
        $this->assertEquals(0, (int)$idColumn['notnull']); // 0 = false (nullable)
        $this->assertEquals(1, (int)$idColumn['pk']); // Primary key
        if (isset($idColumn['auto_increment'])) {
            $this->assertEquals(1, (int)$idColumn['auto_increment']); // Auto increment
        }
        
        $nameColumn = null;
        foreach ($columns as $column) {
            if ($column['name'] === 'name') {
                $nameColumn = $column;
                break;
            }
        }
        $this->assertNotNull($nameColumn);
        $this->assertEquals('TEXT', $nameColumn['type']);
        $this->assertEquals(0, (int)$nameColumn['notnull']);
        $this->assertEquals(0, (int)$nameColumn['pk']);
        
        foreach ($columns as $column) {
            $this->assertArrayHasKey('name', $column);
            $this->assertArrayHasKey('type', $column);
            $this->assertArrayHasKey('notnull', $column);
            $this->assertArrayHasKey('pk', $column);
        }
    }

    public function testDescribeOrders(): void
    {
        $db = self::$db;
        $columns = $db->describe('orders');
        $this->assertNotEmpty($columns);
        
        $columnNames = array_column($columns, 'name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('user_id', $columnNames);
        $this->assertContains('amount', $columnNames);
        
        $userIdColumn = null;
        foreach ($columns as $column) {
            if ($column['name'] === 'user_id') {
                $userIdColumn = $column;
                break;
            }
        }
        $this->assertNotNull($userIdColumn);
        $this->assertEquals('INTEGER', $userIdColumn['type']);
        $this->assertEquals(1, (int)$userIdColumn['notnull']); // NOT NULL
        
        $amountColumn = null;
        foreach ($columns as $column) {
            if ($column['name'] === 'amount') {
                $amountColumn = $column;
                break;
            }
        }
        $this->assertNotNull($amountColumn);
        $this->assertEquals('NUMERIC(10,2)', $amountColumn['type']);
        $this->assertEquals(1, (int)$amountColumn['notnull']); // NOT NULL
    }

    public function testPrefixMethod(): void
    {
        $db = self::$db;
        
        $queryBuilder = $db->find()->prefix('test_')->from('users');
        
        $db->rawQuery("CREATE TABLE IF NOT EXISTS test_prefixed_table (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT
        )");
        
        $id = $queryBuilder->table('prefixed_table')->insert(['name' => 'Test User']);
        $this->assertIsInt($id);
        
        $user = $queryBuilder->table('prefixed_table')->where('id', $id)->getOne();
        $this->assertEquals('Test User', $user['name']);
        
        $this->assertStringContainsString('"test_prefixed_table"', $db->lastQuery);
        
        $db->rawQuery("DROP TABLE IF EXISTS test_prefixed_table");
    }

    public function testLeftJoin(): void
    {
        $db = self::$db;
        
        $userId = $db->find()->table('users')->insert(['name' => 'Left Join User', 'age' => 30]);
        $db->find()->table('orders')->insert(['user_id' => $userId, 'amount' => 150.50]);
        
        $results = $db->find()
            ->from('users u')
            ->leftJoin('orders o', 'u.id = o.user_id')
            ->select(['u.name', 'o.amount'])
            ->get();
        
        $this->assertNotEmpty($results);
        $this->assertEquals('Left Join User', $results[0]['name']);
        $this->assertEquals('150.50', $results[0]['amount']);
        
        $this->assertStringContainsString('LEFT JOIN', $db->lastQuery);
    }

    public function testRightJoin(): void
    {
        $db = self::$db;
        
        $userId = $db->find()->table('users')->insert(['name' => 'Right Join User', 'age' => 25]);
        $db->find()->table('orders')->insert(['user_id' => $userId, 'amount' => 200.75]);
        
        $results = $db->find()
            ->from('users u')
            ->rightJoin('orders o', 'u.id = o.user_id')
            ->select(['u.name', 'o.amount'])
            ->get();
        
        $this->assertNotEmpty($results);
        $this->assertEquals('Right Join User', $results[0]['name']);
        $this->assertEquals('200.75', $results[0]['amount']);
        
        $this->assertStringContainsString('RIGHT JOIN', $db->lastQuery);
    }


    public function testExplainSelectUsers(): void
    {
        $db = self::$db;
        $plan = $db->explain("SELECT * FROM users WHERE status = 'active'");
        $this->assertNotEmpty($plan);
        $this->assertIsArray($plan[0]);
    }

    public function testExplainAnalyzeSelectUsers(): void
    {
        $db = self::$db;
        $plan = $db->explainAnalyze('SELECT * FROM users WHERE status = "active"');
        $this->assertNotEmpty($plan);
        $this->assertArrayHasKey('detail', $plan[0]);
    }
}