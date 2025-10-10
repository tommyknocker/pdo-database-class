<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\tests;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;

final class PdoDbTest extends TestCase
{
    private static ?PdoDb $db = null;

    protected const string DB_HOST = '127.0.0.1';
    protected const string DB_NAME = 'test_db';
    protected const string DB_USER = 'root';
    protected const string DB_PASSWORD = '';

    public static function setUpBeforeClass(): void
    {
        self::$db = new PdoDb(
            host: self::DB_HOST,
            username: self::DB_USER,
            password: self::DB_PASSWORD,
            db: self::DB_NAME
        );

        self::$db->rawQuery("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            company VARCHAR(100),
            age INT,
            status VARCHAR(20) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_name (name)
        ) ENGINE=InnoDB");

        self::$db->rawQuery("CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB;");

        self::$db->rawQuery("
        CREATE TABLE IF NOT EXISTS archive_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT
        )
    ");
    }

    public function setUp(): void
    {
        self::$db->rawQuery("SET FOREIGN_KEY_CHECKS=0");
        self::$db->rawQuery("TRUNCATE TABLE orders");
        self::$db->rawQuery("TRUNCATE TABLE users");
        self::$db->rawQuery("TRUNCATE TABLE archive_users");
        self::$db->rawQuery("SET FOREIGN_KEY_CHECKS=1");
        parent::setUp();
        try {
            self::$db->unlock();
        } catch (\Throwable $e) {
            // ignore, if there was no lock
        }
    }

    public function testRawQueryOne(): void
    {
        self::$db->insert('users', ['name' => 'RawOne', 'age' => 42]);

        $row = self::$db->rawQueryOne("SELECT * FROM users WHERE name = ?", ['RawOne']);
        $this->assertEquals(42, $row['age']);
    }

    public function testOrWhere(): void
    {
        self::$db->insertMulti('users', [
            ['name' => 'A', 'age' => 10],
            ['name' => 'B', 'age' => 20],
        ]);

        $rows = self::$db->where('age', 10)->orWhere('age', 20)->get('users');
        $this->assertCount(2, $rows);
    }

    public function testJoinWhereAndJoinOrWhere(): void
    {
        $uid = self::$db->insert('users', ['name' => 'JoinUser', 'age' => 40]);
        self::$db->insert('orders', ['user_id' => $uid, 'amount' => 100]);

        $rows = self::$db
            ->join('orders o', 'u.id = o.user_id')
            ->joinWhere('o', 'amount', 100)
            ->joinOrWhere('o', 'amount', [100, 200], 'IN') // OR через IN
            ->get('users u', null, ['u.name', 'o.amount']);

        $this->assertEquals('JoinUser', $rows[0]['name']);
    }

    public function testComplexWhereAndOrWhere(): void
    {
        // Prepare test data
        self::$db->insert('users', ['name' => 'UserA', 'age' => 20]);
        self::$db->insert('users', ['name' => 'UserB', 'age' => 30]);
        self::$db->insert('users', ['name' => 'UserC', 'age' => 40]);
        self::$db->insert('users', ['name' => 'UserD', 'age' => 50]);

        // Build query with where + multiple orWhere
        $rows = self::$db
            ->where('age', 20)                       // first condition
            ->orWhere('age', 30)                     // first OR
            ->orWhere('age', 40)                     // second OR
            ->get('users', null, ['name', 'age']);

        // Collect ages from result
        $ages = array_column($rows, 'age');

        // Assert that only expected ages are returned
        sort($ages);
        $this->assertEquals([20, 30, 40], $ages);
    }

    public function testRawQueryValue(): void
    {
        self::$db->insert('users', ['name' => 'RawVal', 'age' => 55]);

        $age = self::$db->rawQueryValue("SELECT age FROM users WHERE name = ?", ['RawVal']);
        $this->assertEquals(55, $age);
    }


    public function testInsertAndGetOne(): void
    {
        $id = self::$db->insert('users', ['name' => 'Vasiliy', 'age' => 30]);
        $this->assertIsInt($id);

        $row = self::$db->getOne('users');
        $this->assertEquals('Vasiliy', $row['name']);
    }

    public function testUpdate(): void
    {
        $id = self::$db->insert('users', ['name' => 'Vasiliy', 'age' => 30]);
        $this->assertIsInt($id);

        self::$db->where('id', $id)->update('users', ['age' => 31]);

        $row = self::$db->where('id', $id)->getOne('users');
        $this->assertEquals(31, $row['age']);
    }


    public function testGetValue(): void
    {
        $id = self::$db->insert('users', ['name' => 'Petya', 'age' => 31]);
        $this->assertIsInt($id);

        $age = self::$db->where('id', $id)->getValue('users', 'age');
        $this->assertEquals(31, $age);
    }

    public function testDelete(): void
    {
        $id = self::$db->insert('users', ['name' => 'ToDelete', 'age' => 99]);
        $this->assertIsInt($id);

        $exists = self::$db->where('id', $id)->has('users');
        $this->assertTrue($exists);

        $deleted = self::$db->where('id', $id)->delete('users');
        $this->assertGreaterThan(0, $deleted);

        $exists = self::$db->where('id', $id)->has('users');
        $this->assertFalse($exists);
    }

    public function testDeleteWithSubquery()
    {
        $db = self::$db;

        // очистим таблицы
        $db->delete('users');
        $db->delete('orders');

        $db->insert('users', ['id' => 1, 'name' => 'Alice']);
        $db->insert('users', ['id' => 2, 'name' => 'Bob']);
        $db->insert('users', ['id' => 3, 'name' => 'Charlie']);

        $db->insert('orders', ['user_id' => 1, 'amount' => 100]);
        $db->insert('orders', ['user_id' => 2, 'amount' => 50]);

        $sub = $db->subQuery();
        $sub->get('orders', null, ['user_id']);

        $db->where('id', $sub, 'IN')->delete('users');

        $this->assertStringContainsString(
            'DELETE FROM users WHERE id IN (SELECT user_id FROM orders)',
            $db->getLastQuery()
        );

        $rows = $db->get('users');
        $this->assertCount(1, $rows);
        $this->assertEquals('Charlie', $rows[0]['name']);
    }



    public function testWhereBetweenAndNotBetween(): void
    {
        // Prepare data
        self::$db->insert('users', ['name' => 'A', 'age' => 10]);
        self::$db->insert('users', ['name' => 'B', 'age' => 25]);
        self::$db->insert('users', ['name' => 'C', 'age' => 30]);
        self::$db->insert('users', ['name' => 'D', 'age' => 50]);

        // BETWEEN
        $rows = self::$db->where('age', [20, 40], 'BETWEEN')->get('users');
        $ages = array_column($rows, 'age');
        sort($ages);
        $this->assertEquals([25, 30], $ages);

        // NOT BETWEEN
        $rows = self::$db->where('age', [20, 40], 'NOT BETWEEN')->get('users');
        $ages = array_column($rows, 'age');
        sort($ages);
        $this->assertEquals([10, 50], $ages);
    }

    public function testWhereInAndNotIn(): void
    {
        self::$db->insert('users', ['name' => 'E', 'age' => 60]);
        self::$db->insert('users', ['name' => 'F', 'age' => 70]);

        // IN
        $rows = self::$db->where('age', [60, 70], 'IN')->get('users');
        $ages = array_column($rows, 'age');
        sort($ages);
        $this->assertEquals([60, 70], $ages);

        // NOT IN
        $rows = self::$db->where('age', [60, 70], 'NOT IN')->get('users');
        $ages = array_column($rows, 'age');
        $this->assertNotContains(60, $ages);
        $this->assertNotContains(70, $ages);
    }

    public function testWhereInWithEmptyArray(): void
    {
        self::$db->insert('users', ['name' => 'EmptyIn', 'age' => 99]);
        $rows = self::$db->where('age', [], 'IN')->get('users');
        $this->assertCount(0, $rows, 'IN [] must return no rows');
    }

    public function testWhereNotInWithEmptyArray(): void
    {
        $id = self::$db->insert('users', ['name' => 'EmptyNotIn', 'age' => 100]);

        $rows = self::$db->where('id', [], 'NOT IN')->get('users');
        $ids = array_column($rows, 'id');

        $this->assertContains($id, $ids, 'NOT IN [] must not filter out any rows');
    }

    public function testWhereStateIsResetBetweenQueries()
    {
        $db = self::$db;

        $db->delete('users');
        $db->insert('users', ['id' => 1, 'name' => 'Alice']);
        $db->insert('users', ['id' => 2, 'name' => 'Bob']);

        $row = $db->where('id', 1)->getOne('users');
        $this->assertEquals('Alice', $row['name']);

        $rows = $db->get('users');
        $this->assertCount(2, $rows);
    }


    public function testHavingAndOrHaving(): void
    {
        $u1 = self::$db->insert('users', ['name' => 'H1']);
        $u2 = self::$db->insert('users', ['name' => 'H2']);
        $u3 = self::$db->insert('users', ['name' => 'H3']);

        self::$db->insert('orders', ['user_id' => $u1, 'amount' => 100]);
        self::$db->insert('orders', ['user_id' => $u1, 'amount' => 200]); // total 300
        self::$db->insert('orders', ['user_id' => $u2, 'amount' => 300]);
        self::$db->insert('orders', ['user_id' => $u2, 'amount' => 400]); // total 700
        self::$db->insert('orders', ['user_id' => $u3, 'amount' => 500]); // total 500

        $rows = self::$db
            ->groupBy('user_id')
            ->having('SUM(amount)', 300, '=')       // first condition
            ->orHaving('SUM(amount)', 500, '=')     // OR
            ->orHaving('SUM(amount)', 700, '=')     // OR
            ->get('orders', null, ['user_id', 'SUM(amount) as total']);

        $totals = array_column($rows, 'total');
        sort($totals);
        $this->assertEquals([300, 500, 700], $totals);
    }


    public function testComplexHavingAndOrHaving(): void
    {
        // Prepare test data
        $u1 = self::$db->insert('users', ['name' => 'User1', 'age' => 20]);
        $u2 = self::$db->insert('users', ['name' => 'User2', 'age' => 30]);
        $u3 = self::$db->insert('users', ['name' => 'User3', 'age' => 40]);

        self::$db->insert('orders', ['user_id' => $u1, 'amount' => 100]);
        self::$db->insert('orders', ['user_id' => $u1, 'amount' => 200]);
        self::$db->insert('orders', ['user_id' => $u2, 'amount' => 300]);
        self::$db->insert('orders', ['user_id' => $u2, 'amount' => 400]);
        self::$db->insert('orders', ['user_id' => $u3, 'amount' => 500]);


        // Build query with group by and multiple having/orHaving
        $rows = self::$db
            ->groupBy('user_id')
            ->having('SUM(amount)', 300, '>=')       // first condition
            ->orHaving('SUM(amount)', 500, '=')      // first OR
            ->orHaving('SUM(amount)', 700, '=')      // second OR
            ->get('orders', null, ['user_id', 'SUM(amount) as total']);

        // Collect totals from result
        $totals = array_column($rows, 'total');

        // Assert that expected totals are present
        sort($totals);
        $this->assertEquals([300, 500, 700], $totals);
    }


    public function testGroupBy(): void
    {
        self::$db->insertMulti('users', [
            ['name' => 'A', 'age' => 10],
            ['name' => 'B', 'age' => 10],
        ]);

        $rows = self::$db->groupBy('age')->get('users', null, ['age', 'COUNT(*) as cnt']);
        $this->assertEquals(2, $rows[0]['cnt']);
    }


    public function testTransaction(): void
    {
        self::$db->startTransaction();
        self::$db->insert('users', ['name' => 'Alice', 'age' => 25]);
        self::$db->rollback();

        $exists = self::$db->where('name', 'Alice')->has('users');
        $this->assertFalse($exists);

        self::$db->startTransaction();
        self::$db->insert('users', ['name' => 'Bob', 'age' => 40]);
        self::$db->commit();

        $exists = self::$db->where('name', 'Bob')->has('users');
        $this->assertTrue($exists);
    }

    public function testLockUnlock(): void
    {
        $ok = self::$db->lock('users');
        $this->assertTrue($ok);

        $ok = self::$db->unlock();
        $this->assertTrue($ok);
    }

    public function testLockMultipleTableWrite()
    {
        $db = self::$db;

        $this->assertTrue($db->setLockMethod('WRITE')->lock(['users', 'orders']));

        $this->assertSame(
            'LOCK TABLES users WRITE, orders WRITE',
            $db->getLastQuery()
        );

        $ok = self::$db->unlock();
        $this->assertTrue($ok);
    }

    public function testEscape(): void
    {
        $unsafe = "O'Reilly";
        $safe = self::$db->escape($unsafe);
        $this->assertStringContainsString("O\\'Reilly", $safe);
    }


    public function testBuilders(): void
    {
        self::$db->insert('users', ['name' => 'Olya', 'age' => 22]);

        // arrayBuilder
        $rows = self::$db->arrayBuilder()->get('users');
        $this->assertIsArray($rows);
        $this->assertEquals('Olya', $rows[0]['name']);

        // objectBuilder
        $rows = self::$db->objectBuilder()->get('users');
        $this->assertIsObject($rows[0]);
        $this->assertEquals(22, $rows[0]->age);

        // jsonBuilder
        $rows = self::$db->jsonBuilder()->get('users');
        $this->assertJson($rows);
        $decoded = json_decode($rows, true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals('Olya', $decoded[0]['name']);
    }


    public function testInsertMulti(): void
    {
        $rows = [
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Charlie', 'age' => 35],
        ];
        $ok = self::$db->insertMulti('users', $rows);
        $this->assertTrue($ok);

        $all = self::$db->get('users');
        $this->assertCount(3, $all);
    }


    public function testReplace(): void
    {
        $id = self::$db->insert('users', ['name' => 'Diana', 'age' => 40]);
        $this->assertIsInt($id);

        $rowCount = self::$db->replace('users', ['id' => $id, 'name' => 'Diana', 'age' => 41]);
        $this->assertGreaterThan(0, $rowCount);

        $row = self::$db->where('id', $id)->getOne('users');
        $this->assertEquals(41, $row['age']);
    }

    public function testReplaceMulti(): void
    {
        self::$db->insertMulti('users', [
            ['id' => 1, 'name' => 'Alice', 'age' => 25],
            ['id' => 2, 'name' => 'Bob', 'age' => 30],
        ]);

        $all = self::$db->orderBy('id')->get('users');
        $this->assertCount(2, $all);
        $this->assertEquals(25, $all[0]['age']);
        $this->assertEquals(30, $all[1]['age']);

        $ok = self::$db->replaceMulti('users', [
            ['id' => 1, 'name' => 'Alice', 'age' => 26], // replace existent
            ['id' => 3, 'name' => 'Charlie', 'age' => 35], // insert new
        ]);
        $this->assertTrue($ok);

        $all = self::$db->orderBy('id')->get('users');
        $this->assertCount(3, $all);

        // Alice replaced
        $this->assertEquals(26, $all[0]['age']);
        // Bob remains the same
        $this->assertEquals(30, $all[1]['age']);
        // Charlie added
        $this->assertEquals('Charlie', $all[2]['name']);
        $this->assertEquals(35, $all[2]['age']);
    }

    public function testOnDuplicate(): void
    {
        self::$db->insert('users', ['name' => 'Eve', 'age' => 20]);

        self::$db->onDuplicate(['age'])->insert('users', ['name' => 'Eve', 'age' => 21]);

        $row = self::$db->where('name', 'Eve')->getOne('users');
        $this->assertEquals(21, $row['age']);
    }


    public function testPaginate(): void
    {
        for ($i = 0; $i < 15; $i++) {
            self::$db->insert('users', ['name' => 'User' . $i, 'age' => 20 + $i]);
        }

        $page1 = self::$db->withTotalCount()->paginate('users', 1, ['id', 'name']);
        $this->assertLessThanOrEqual(20, count($page1));
        $this->assertGreaterThan(0, self::$db->totalCount());

        $page2 = self::$db->paginate('users', 2, ['id', 'name']);
        $this->assertIsArray($page2);
    }

    public function testSubQuery(): void
    {
        $sub = self::$db->subQuery('u');
        $sub->where('age', 30, '>')->get('users', null, ['id']);

        $rows = self::$db
            ->join('users u', 'u.id = main.id')
            ->get('users main', 5, ['main.name', 'u.age']);

        $this->assertIsArray($rows);
    }

    public function testExistsSubquery(): void
    {
        $u1 = self::$db->insert('users', ['name' => 'SubUser', 'company' => 'testCompany']);
        $u2 = self::$db->insert('users', ['name' => 'OtherUser', 'company' => 'otherCompany']);

        self::$db->insert('orders', ['user_id' => $u1, 'amount' => 100]);
        self::$db->insert('orders', ['user_id' => $u2, 'amount' => 200]);

        // Subquery string
        $sub = "SELECT id FROM users WHERE company = 'testCompany' AND users.id = o.user_id";

        $rows = self::$db
            ->where(null, $sub, 'EXISTS')
            ->get('orders o', null, ['o.user_id', 'o.amount']);

        $userIds = array_column($rows, 'user_id');
        $this->assertContains($u1, $userIds);
        $this->assertNotContains($u2, $userIds);
    }

    public function testSubQueryWithJoin(): void
    {
        $aliceId = self::$db->insert('users', ['name' => 'Alice', 'age' => 25]);
        $bobId = self::$db->insert('users', ['name' => 'Bob', 'age' => 30]);

        self::$db->insertMulti('orders', [
            ['user_id' => $aliceId, 'amount' => 50.00],
            ['user_id' => $aliceId, 'amount' => 150.00],
            ['user_id' => $bobId, 'amount' => 20.00],
        ]);

        // Subquery: select `user_id` values of users who have orders greater than 100.
        $sub = self::$db->subQuery('o');
        $sub->where('amount', 100, '>');
        $sub->get('orders', null, ['user_id']);

        // Main query: select the names of users who have such orders.
        $rows = self::$db
            ->join('orders o', 'u.id = o.user_id')
            ->where('o.amount', 100, '>')
            ->get('users u', null, ['u.name']);

        $this->assertNotEmpty($rows);
        $this->assertEquals('Alice', $rows[0]['name']);
    }

    public function testSubqueryInSelectWhereIn(): void
    {
        $u1 = self::$db->insert('users', ['name' => 'SubSel1']);
        $u2 = self::$db->insert('users', ['name' => 'SubSel2']);

        self::$db->insert('orders', ['user_id' => $u1, 'amount' => 10]);
        self::$db->insert('orders', ['user_id' => $u1, 'amount' => 20]);
        self::$db->insert('orders', ['user_id' => $u1, 'amount' => 30]);

        self::$db->insert('orders', ['user_id' => $u2, 'amount' => 40]);

        $ids = self::$db->subQuery();
        $ids->groupBy('user_id');
        $ids->having('COUNT(*)', 2, '>');
        $ids->get('orders', null, ['user_id']);

        $rows = self::$db->where('id', $ids, 'IN')->get('users');
        $idsResult = array_column($rows, 'id');

        $this->assertContains($u1, $idsResult);
        $this->assertNotContains($u2, $idsResult);
    }

    public function testInsertWithSubquery()
    {
        $db = self::$db;

        $db->delete('users');
        $db->delete('archive_users');

        $db->insert('users', ['name' => 'Alice']);
        $db->insert('users', ['name' => 'Bob']);
        $db->insert('users', ['name' => 'Charlie']);

        $sub = $db->subQuery();
        $sub->get('users', null, ['id']);

        $db->insert('archive_users', [
            'user_id' => $sub
        ]);

        $this->assertSame(
            'INSERT INTO archive_users (user_id) SELECT id FROM users',
            $db->getLastQuery()
        );

        $count = $db->getValue('archive_users', 'COUNT(*)');
        $this->assertEquals(3, $count);
    }

    public function testInsertOnDuplicateKeyUpdate()
    {
        $db = self::$db;

        $db->delete('users');

        $db->insert('users', ['id' => 1, 'name' => 'Alice', 'status' => 'old']);

        $db->onDuplicate(['status'])
            ->insert('users', ['id' => 1, 'name' => 'Alice', 'status' => 'new']);

        $this->assertStringContainsString(
            'ON DUPLICATE KEY UPDATE status = VALUES(status)',
            $db->getLastQuery()
        );

        $status = $db->where('id', 1)->getValue('users', 'status');
        $this->assertEquals('new', $status);
    }


    public function testUpdateWithSubquery()
    {
        $db = self::$db;

        $db->delete('users');
        $db->delete('orders');

        $db->insert('users', ['id' => 1, 'name' => 'Alice']);
        $db->insert('users', ['id' => 2, 'name' => 'Bob']);
        $db->insert('users', ['id' => 3, 'name' => 'Charlie']);

        $db->insert('orders', ['user_id' => 1, 'amount' => 100]);
        $db->insert('orders', ['user_id' => 1, 'amount' => 200]);
        $db->insert('orders', ['user_id' => 2, 'amount' => 50]);

        $sub = $db->subQuery();
        $sub->get('orders', null, ['user_id']);

        $db->where('id', $sub, 'IN')
            ->update('users', ['status' => 'active']);

        $this->assertStringContainsString(
            'UPDATE users SET status =',
            $db->getLastQuery()
        );
        $this->assertStringContainsString(
            'WHERE id IN (SELECT user_id FROM orders)',
            $db->getLastQuery()
        );

        $count = $db->where('status', 'active')->getValue('users', 'COUNT(*)');
        $this->assertEquals(2, $count);
    }



    public function testDisconnectAndPing(): void
    {
        self::$db->disconnect();
        $this->assertFalse(self::$db->ping());
        self::$db = new PdoDb(
            host: self::DB_HOST,
            username: self::DB_USER,
            password: self::DB_PASSWORD,
            db: self::DB_NAME
        );
        $this->assertTrue(self::$db->ping());
    }

    public function testTableExists(): void
    {
        $this->assertTrue(self::$db->tableExists('users'));
        $this->assertFalse(self::$db->tableExists('nonexistent'));
    }

    public function testTraceAndLastQuery(): void
    {
        self::$db->setTrace(true);

        // INSERT
        self::$db->insert('users', ['name' => 'TraceInsert', 'age' => 10]);
        $lastQuery = self::$db->getLastQuery();
        $this->assertStringContainsString('INSERT INTO', $lastQuery);

        // UPDATE
        self::$db->where('name', 'TraceInsert')
            ->update('users', ['age' => 11]);
        $lastQuery = self::$db->getLastQuery();
        $this->assertStringContainsString('UPDATE', $lastQuery);

        // SELECT (getOne)
        $row = self::$db->where('name', 'TraceInsert')
            ->getOne('users');
        $lastQuery = self::$db->getLastQuery();
        $this->assertStringContainsString('SELECT', $lastQuery);
        $this->assertEquals('TraceInsert', $row['name']);

        // DELETE
        self::$db->delete('users', 'name = :name', ['name' => 'TraceInsert']);
        $lastQuery = self::$db->getLastQuery();
        $this->assertStringContainsString('DELETE', $lastQuery);

        // RAW QUERY
        self::$db->rawQuery("SELECT COUNT(*) AS cnt FROM users");
        $lastQuery = self::$db->getLastQuery();
        $this->assertStringContainsString('SELECT COUNT', $lastQuery);

        // Check trace log
        $trace = self::$db->getLogTrace();
        $this->assertIsArray($trace);
        $this->assertNotEmpty($trace);

        $queries = array_column($trace, 'query');
        $this->assertTrue($this->arrayContainsSubstring($queries, 'INSERT INTO'));
        $this->assertTrue($this->arrayContainsSubstring($queries, 'UPDATE'));
        $this->assertTrue($this->arrayContainsSubstring($queries, 'SELECT'));
        $this->assertTrue($this->arrayContainsSubstring($queries, 'DELETE'));

        $this->assertEmpty(self::$db->getLastError());
        $this->assertEquals(0, self::$db->getLastErrno());
    }

    private function arrayContainsSubstring(array $haystack, string $needle): bool
    {
        return array_any($haystack, static fn($item) => str_contains($item, $needle));
    }

    public function testAddConnectionAndSwitch(): void
    {
        self::$db->addConnection('secondary', [
            'host' => self::DB_HOST,
            'username' => self::DB_USER,
            'password' => self::DB_PASSWORD,
            'db' => self::DB_NAME
        ]);

        $pdoDb = self::$db->connection('secondary');
        $this->assertInstanceOf(PdoDb::class, $pdoDb);

        $pdoDb = self::$db->connection('default');
        $this->assertInstanceOf(PdoDb::class, $pdoDb);
    }


    public function testFuncNowIncDec(): void
    {
        $id = self::$db->insert('users', ['name' => 'FuncTest', 'age' => 1]);

        // now
        $expr = self::$db->now();
        $this->assertEquals('NOW()', $expr);

        $exprPlus = self::$db->now('1 DAY');
        $this->assertEquals('NOW() + INTERVAL 1 DAY', $exprPlus);

        // inc
        self::$db->where('id', $id)->update('users', ['age' => self::$db->inc()]);
        $row = self::$db->where('id', $id)->getOne('users');
        $this->assertEquals(2, $row['age']);

        // dec
        self::$db->where('id', $id)->update('users', ['age' => self::$db->dec()]);
        $row = self::$db->where('id', $id)->getOne('users');
        $this->assertEquals(1, $row['age']);

        // now(): if there is a datetime column, we can check the server-time of the record
        // for example, if there is `updated_at` DATETIME
        self::$db->where('id', $id)->update('users', ['updated_at' => self::$db->now()]);
        $row = self::$db->where('id', $id)->getOne('users');
        $this->assertNotEmpty($row['updated_at']);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['updated_at']);

        // func MAX(age)
        $row = self::$db->getOne('users', ['MAX(age) as max_age']);
        $this->assertEquals(1, (int)$row['max_age']);
    }


    public function testLoadXml(): void
    {
        $file = sys_get_temp_dir() . '/users.xml';
        file_put_contents($file, <<<XML
            <users>
              <user>
                <name>XMLUser</name>
                <age>44</age>
              </user>
            </users>
XML
        );

        $ok = self::$db->loadXml('users', $file, '<user>');
        $this->assertTrue($ok);

        $row = self::$db->getOne('users');
        $this->assertEquals('XMLUser', $row['name']);
        $this->assertEquals(44, $row['age']);

        unlink($file);
    }
}
