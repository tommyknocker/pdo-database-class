<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\tests;

use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;

final class PdoDbPostgreSQLTest extends TestCase
{
    private static ?PdoDb $db = null;

    protected const string DB_HOST = 'localhost';
    protected const string DB_NAME = 'testdb';
    protected const string DB_USER = 'testuser';
    protected const string DB_PASSWORD = 'testpass';
    protected const int DB_PORT = 5433;

    public static function setUpBeforeClass(): void
    {
        /**
         * sudo -i -u postgres
         * psql
         * CREATE USER testuser WITH PASSWORD 'testpass';
         * CREATE DATABASE testdb OWNER testuser;
         * GRANT ALL PRIVILEGES ON DATABASE testdb TO testuser;
         * \q
         */
        self::$db = new PdoDb(
            'pgsql',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
            ]
        );

        // users
        self::$db->rawQuery("
            CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) UNIQUE,
                company VARCHAR(100),
                age INT,
                status VARCHAR(20) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT NULL,
                CONSTRAINT uniq_name UNIQUE (name)
            )
        ");

        // orders
        self::$db->rawQuery("
            CREATE TABLE IF NOT EXISTS orders (
                id SERIAL PRIMARY KEY,
                user_id INT NOT NULL,
                amount NUMERIC(10,2) NOT NULL,
                CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
                    REFERENCES users(id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            )
        ");

        // archive_users
        self::$db->rawQuery("
            CREATE TABLE IF NOT EXISTS archive_users (
                id SERIAL PRIMARY KEY,
                user_id INT
            )
        ");

    }

    public function setUp(): void
    {
        self::$db->rawQuery("TRUNCATE TABLE orders, archive_users, users RESTART IDENTITY CASCADE");
        parent::setUp();
    }

    // ---------- PostgreSQL ----------
    public function testPgsqlMinimalParams(): void
    {
        $dsn = self::$db->buildDsn([
            'driver' => 'pgsql',
            'host' => 'localhost',
            'username' => 'testuser',
            'password' => 'testpassword',
            'dbname' => 'testdb',
        ]);
        $this->assertEquals('pgsql:host=localhost;dbname=testdb', $dsn);
    }

    public function testPgsqlAllParams(): void
    {
        $dsn = self::$db->buildDsn([
            'driver' => 'pgsql',
            'host' => 'localhost',
            'username' => 'testuser',
            'password' => 'testpassword',
            'dbname' => 'testdb',
            'port' => 5432,
            'options' => '--client_encoding=UTF8',
            'sslmode' => 'require',
            'sslkey' => '/path/key.pem',
            'sslcert' => '/path/cert.pem',
            'sslrootcert' => '/path/ca.pem',
            'application_name' => 'MyApp',
            'connect_timeout' => 5,
            'hostaddr' => '192.168.1.10',
            'service' => 'myservice',
            'target_session_attrs' => 'read-write',
        ]);
        $this->assertStringContainsString('pgsql:host=localhost;dbname=testdb', $dsn);
        $this->assertStringContainsString('sslmode=require', $dsn);
        $this->assertStringContainsString('application_name=MyApp', $dsn);
    }

    public function testPgsqlMissingParamsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$db->buildDsn(['driver' => 'pgsql']); // no host/dbname
    }

    public function testInsertAndSelectUsers(): void
    {
        $id1 = self::$db->insert('users', [
            'name' => 'Alice',
            'company' => 'Acme',
            'age' => 30,
            'status' => 'active'
        ], 'id');

        $id2 = self::$db->insert('users', [
            'name' => 'Bob',
            'company' => 'Beta',
            'age' => 25,
            'status' => 'inactive'
        ], 'id');

        $rows = self::$db->get('users');
        $this->assertCount(2, $rows);
        $this->assertEquals([1, 2], [$id1, $id2]);
    }

    public function testUpdateUser(): void
    {
        $id = self::$db->insert('users', [
            'name' => 'Charlie',
            'company' => 'Gamma',
            'age' => 40,
            'status' => 'inactive'
        ], 'id');

        self::$db->where('id', $id)->update('users', ['status' => 'active']);
        $status = self::$db->where('id', $id)->getValue('users', 'status');
        $this->assertEquals('active', $status);
    }

    public function testOrdersCascadeDelete(): void
    {
        $uid = self::$db->insert('users', [
            'name' => 'Dave',
            'company' => 'Delta',
            'age' => 28
        ], 'id');

        self::$db->insert('orders', ['user_id' => $uid, 'amount' => 99.99]);
        $this->assertEquals(1, self::$db->getValue('orders', 'COUNT(*) AS cnt'));

        // cascade deletion
        self::$db->where('id', $uid)->delete('users');
        $this->assertEquals(0, self::$db->getValue('orders', 'COUNT(*) AS cnt'));
    }

    public function testArchiveUsers(): void
    {
        $uid = self::$db->insert('users', [
            'name' => 'Eve',
            'company' => 'Echo',
            'age' => 35
        ], 'id');

        self::$db->insert('archive_users', ['user_id' => $uid]);

        $archived = self::$db->get('archive_users');
        $this->assertCount(1, $archived);
        $this->assertEquals($uid, $archived[0]['user_id']);
    }

    public function testPagination()
    {
        for ($i = 1; $i <= 5; $i++) {
            self::$db->insert('users', [
                'name' => "User{$i}",
                'company' => "Comp{$i}",
                'age' => 20 + $i,
                'status' => 'active'
            ]);
        }

        self::$db->orderBy('id', 'ASC');

        self::$db->setPageLimit(2);

        // Page 1 → User1, User2
        $page1 = self::$db->paginate('users', 1, ['id', 'name']);
        $this->assertEquals(['User1', 'User2'], array_column($page1, 'name'));

        // Page 2 → User3, User4
        self::$db->orderBy('id', 'ASC');
        self::$db->setPageLimit(2);
        $page2 = self::$db->paginate('users', 2, ['id', 'name']);
        $this->assertEquals(['User3', 'User4'], array_column($page2, 'name'));

        // Страница 3 → User5
        self::$db->orderBy('id', 'ASC');
        self::$db->setPageLimit(2);
        $page3 = self::$db->paginate('users', 3, ['id', 'name']);
        $this->assertEquals(['User5'], array_column($page3, 'name'));
    }

    public function testWhereOperators(): void
    {
        self::$db->insert('users', ['name' => 'Foo', 'company' => 'A', 'age' => 20, 'status' => 'active']);
        self::$db->insert('users', ['name' => 'Bar', 'company' => 'B', 'age' => 30, 'status' => 'inactive']);

        $rows = self::$db->where('age', 25, '>')->get('users');
        $this->assertCount(1, $rows);
        $this->assertEquals('Bar', $rows[0]['name']);
    }

    public function testAggregateFunctions(): void
    {
        self::$db->insert('users', ['name' => 'Foo', 'company' => 'A', 'age' => 20, 'status' => 'active']);
        self::$db->insert('orders', ['user_id' => 1, 'amount' => 10]);
        self::$db->insert('orders', ['user_id' => 1, 'amount' => 20]);

        $sum = self::$db->getValue('orders', 'SUM(amount) AS total');
        $this->assertEquals(30, $sum);
    }

    public function testJoinUsersAndOrders(): void
    {
        $uid = self::$db->insert('users', ['name' => 'JoinTest', 'company' => 'X', 'age' => 22, 'status' => 'active'],
            'id');
        self::$db->insert('orders', ['user_id' => $uid, 'amount' => 50]);

        self::$db->join('orders o', 'o.user_id=u.id', 'INNER');
        $rows = self::$db->get('users u', null, ['u.name', 'o.amount']);
        $this->assertEquals('JoinTest', $rows[0]['name']);
        $this->assertEquals(50, $rows[0]['amount']);
    }

    public function testGroupByHaving(): void
    {
        $uid = self::$db->insert('users', ['name' => 'GroupTest', 'company' => 'Y', 'age' => 33, 'status' => 'active'],
            'id');
        self::$db->insert('orders', ['user_id' => $uid, 'amount' => 100]);
        self::$db->insert('orders', ['user_id' => $uid, 'amount' => 200]);

        self::$db->groupBy('user_id');
        self::$db->having('SUM(amount)', 150, '>');
        $rows = self::$db->get('orders', null, ['user_id', 'SUM(amount) AS total']);
        $this->assertEquals(300, $rows[0]['total']);
    }

    public function testOrderByMultipleAndDesc(): void
    {
        self::$db->insert('users', ['name' => 'Alex', 'company' => 'Zeta', 'age' => 25, 'status' => 'active']);
        self::$db->insert('users', ['name' => 'Brian', 'company' => 'Zeta', 'age' => 30, 'status' => 'active']);
        self::$db->insert('users', ['name' => 'Carl', 'company' => 'Alpha', 'age' => 35, 'status' => 'active']);

        self::$db->orderBy('company', 'ASC');
        self::$db->orderBy('age', 'DESC');
        $rows = self::$db->get('users', null, ['name', 'company', 'age']);

        $this->assertEquals(['Carl', 'Brian', 'Alex'], array_column($rows, 'name'));
    }

    public function testTransactions(): void
    {
        self::$db->startTransaction();
        $id = self::$db->insert('users', ['name' => 'TxUser', 'company' => 'Tx', 'age' => 50, 'status' => 'active'],
            'id');
        $this->assertIsInt($id);
        self::$db->rollback();
        $count = self::$db->getValue('users', 'COUNT(*) AS cnt');
        $this->assertEquals(0, $count);

        self::$db->startTransaction();
        $id = self::$db->insert('users', ['name' => 'TxUser2', 'company' => 'Tx', 'age' => 51, 'status' => 'active'],
            'id');
        $this->assertIsInt($id);
        self::$db->commit();
        $count = self::$db->getValue('users', 'COUNT(*) AS cnt');
        $this->assertEquals(1, $count);
    }

    public function testInsertViolationThrowsException(): void
    {
        self::$db->insert('users', [
            'name' => 'Duplicate',
            'company' => 'X',
            'age' => 20,
            'status' => 'active'
        ]);

        $this->expectException(PDOException::class);
        self::$db->insert('users', [
            'name' => 'Duplicate',
            'company' => 'Y',
            'age' => 21,
            'status' => 'inactive'
        ]);
    }

    public function testForeignKeyViolationThrowsException(): void
    {
        $this->expectException(PDOException::class);
        // Non-existent record
        self::$db->insert('orders', ['user_id' => 999, 'amount' => 10]);
    }

    public function testJsonFetch(): void
    {
        self::$db->insert('users', ['name' => 'JsonUser', 'company' => 'J', 'age' => 22, 'status' => 'active']);
        self::$db->jsonBuilder();
        $json = self::$db->get('users', 1, ['name', 'age']);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertIsString($json);
        $this->assertEquals('JsonUser', $decoded[0]['name']);
    }

    public function testUpdateMultipleFields(): void
    {
        $id = self::$db->insert('users', ['name' => 'Multi', 'company' => 'Old', 'age' => 20, 'status' => 'inactive'],
            'id');
        self::$db->where('id', $id)->update('users', ['company' => 'New', 'status' => 'active']);
        $row = self::$db->where('id', $id)->getOne('users');
        $this->assertEquals('New', $row['company']);
        $this->assertEquals('active', $row['status']);
    }


    public function testDeleteWithAndWithoutCondition(): void
    {
        $id1 = self::$db->insert('users', ['name' => 'Del1', 'company' => 'C', 'age' => 20, 'status' => 'active'],
            'id');
        $id2 = self::$db->insert('users', ['name' => 'Del2', 'company' => 'C', 'age' => 21, 'status' => 'active'],
            'id');
        $this->assertIsInt($id1);
        $this->assertIsInt($id2);

        // Удаляем одного
        self::$db->where('id', $id1)->delete('users');
        $count = self::$db->getValue('users', 'COUNT(*) AS cnt');
        $this->assertEquals(1, $count);

        // Удаляем всех
        self::$db->delete('users');
        $count = self::$db->getValue('users', 'COUNT(*) AS cnt');
        $this->assertEquals(0, $count);
    }

    public function testWithTotalCount(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            self::$db->insert('users', ['name' => "Cnt{$i}", 'company' => 'C', 'age' => 20 + $i, 'status' => 'active']);
        }
        self::$db->setPageLimit(3);
        $page = self::$db->withTotalCount()->orderBy('id', 'ASC')->paginate('users', 1, ['id', 'name']);
        $this->assertCount(3, $page);
        $this->assertEquals(7, self::$db->totalCount());
    }


}
