<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\tests;

use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

final class PdoDbPostgreSQLTest extends TestCase
{
    private static PdoDb $db;
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
         * GRANT pg_read_server_files TO testuser;
         * GRANT pg_write_server_files TO testuser;
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
                name VARCHAR(100) NOT NULL,
                company VARCHAR(100),
                age INT,
                status VARCHAR(20),
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT NULL,
                UNIQUE (name)
            );
        ");

        // orders
        self::$db->rawQuery("
             CREATE TABLE IF NOT EXISTS orders (
                id SERIAL PRIMARY KEY,
                user_id INT NOT NULL,
                amount NUMERIC(10,2) NOT NULL,
                CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
            );
        ");

        // archive_users
        self::$db->rawQuery("
            CREATE TABLE IF NOT EXISTS archive_users (
                id SERIAL PRIMARY KEY,
                user_id INT
            );
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
        $dsn = self::$db->connection->getDialect()->buildDsn([
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
        $dsn = self::$db->connection->getDialect()->buildDsn([
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
        self::$db->connection->getDialect()->buildDsn(['driver' => 'pgsql']); // no host/dbname
    }

    public function testInsertWithQueryOption(): void
    {
        $db = self::$db;
        $id = $db->find()
            ->table('users')
            ->option('OVERRIDING SYSTEM VALUE')
            ->insert(['name' => 'Alice']);
        $this->assertEquals(1, $id);


        $this->assertStringContainsString('OVERRIDING SYSTEM VALUE VALUES ', $db->lastQuery);
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
            ->select(['id', 'name', 'created_at', Db::raw('NOW() AS nowcol')])
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
                'status' => Db::null()
            ]);

        $this->assertIsInt($id);

        $user = $db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();

        $this->assertNull($user['status']);
    }

    public function testLikeAndIlikeHelpers(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'company' => 'WonderCorp', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'Bob', 'company' => 'wondercorp', 'age' => 35]);

        // LIKE
        $likeResults = $db->find()
            ->from('users')
            ->where(Db::like('company', 'Wonder%'))
            ->get();

        $this->assertCount(1, $likeResults);
        $this->assertEquals('Alice', $likeResults[0]['name']);

        // ILIKE
        $ilikeResults = $db->find()
            ->from('users')
            ->where(Db::ilike('company', 'Wonder%'))
            ->get();

        $this->assertCount(2, $ilikeResults);
    }

    public function testNotHelper(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Charlie', 'company' => 'TechX', 'age' => 40]);
        $db->find()->table('users')->insert(['name' => 'Dana', 'company' => 'BizY', 'age' => 45]);

        // NOT LIKE 'Tech%'
        $notLike = Db::not(Db::like('company', 'Tech%'));

        $results = $db->find()
            ->from('users')
            ->where($notLike)
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Dana', $results[0]['name']);
    }

    public function testConfigHelper(): void
    {
        self::$db->rawQuery(Db::config('TIME ZONE', 'UTC', false));
        $this->assertEquals('SET TIME ZONE UTC', self::$db->lastQuery);
        self::$db->rawQuery(Db::config('client_encoding', 'UTF8', true, true));
        $this->assertEquals('SET CLIENT_ENCODING = \'UTF8\'', self::$db->lastQuery);
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
            'age' => 'age + 100'
        ])->insert([
            'name' => 'multi_raw_1',
            'age' => 20
        ]);

        $row = $db->find()->from('users')->where('name', 'multi_raw_1')->getOne();
        $this->assertGreaterThanOrEqual(110, (int)$row['age']);
    }

    /**
     * Test all cases of RawValue with parameters usage
     */
    public function testRawValueWithParameters(): void
    {
        $db = self::$db;

        // 1. INSERT with RawValue containing parameters
        $id = $db->find()->table('users')->insert([
            'name' => Db::raw('CONCAT(:prefix || :name)', [
                ':prefix' => 'Mr_',
                ':name' => 'John'
            ]),
            'age' => 30
        ]);

        $this->assertIsInt($id);
        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertEquals('Mr_John', $row['name']);

        // 2. UPDATE with RawValue containing parameters
        $rowCount = $db->find()
            ->table('users')
            ->where('id', $id)
            ->update([
                'age' => Db::raw('age + :inc', [':inc' => 5]),
                'name' => Db::raw('CONCAT(name || :suffix)', [':suffix' => '_updated'])
            ]);

        $this->assertEquals(1, $rowCount);
        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertEquals(35, (int)$row['age']);
        $this->assertEquals('Mr_John_updated', $row['name']);

        // 3. WHERE condition with RawValue parameters
        $rows = $db->find()
            ->from('users')
            ->where(Db::raw('age BETWEEN :min AND :max', [
                ':min' => 30,
                ':max' => 40
            ]))
            ->get();

        $this->assertNotEmpty($rows);
        $this->assertGreaterThanOrEqual(30, $rows[0]['age']);
        $this->assertLessThanOrEqual(40, $rows[0]['age']);

        // 4. JOIN condition with RawValue parameters
        $orderId = $db->find()->table('orders')->insert([
            'user_id' => $id,
            'amount' => 100
        ]);
        $this->assertIsInt($orderId);

        $result = $db->find()
            ->from('users u')
            ->join('orders o', Db::raw('o.user_id = u.id AND o.amount > :min_amount', [
                ':min_amount' => 50
            ]))
            ->where('u.id', $id)
            ->getOne();

        $this->assertNotNull($result);
        $this->assertEquals(100, (int)$result['amount']);

        // 5. HAVING clause with RawValue parameters
        $results = $db->find()
            ->from('orders')
            ->select(['user_id', 'SUM(amount) as total'])
            ->groupBy('user_id')
            ->having(Db::raw('SUM(amount) > :min_total AND SUM(amount) < :max_total', [
                ':min_total' => 50,
                ':max_total' => 150
            ]))
            ->get();

        $this->assertNotEmpty($results);
        $this->assertGreaterThan(50, $results[0]['total']);
        $this->assertLessThan(150, $results[0]['total']);

        // 6. DELETE with RawValue parameters in WHERE
        $rowCount = $db->find()
            ->table('orders')
            ->where(Db::raw('amount BETWEEN :min AND :max', [
                ':min' => 90,
                ':max' => 110
            ]))
            ->delete();

        $this->assertEquals(1, $rowCount);

        // 7. Multiple RawValues with overlapping parameter names
        $rows = $db->find()
            ->from('users')
            ->where(Db::raw('age > :val', [':val' => 30]))
            ->andWhere(Db::raw('name LIKE :val', [':val' => '%updated%']))
            ->get();

        $this->assertNotEmpty($rows);
        $this->assertGreaterThan(30, $rows[0]['age']);
        $this->assertStringContainsString('updated', $rows[0]['name']);
    }

    public function testSelectWithForUpdate(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'Test', 'age' => 20]);
        $this->assertEquals(1, $id);

        $rows = $db->find()
            ->table('users')
            ->option('FOR UPDATE')
            ->get();
        $this->assertCount(1, $rows);

        $this->assertStringEndsWith('FROM "users" FOR UPDATE', $db->lastQuery);
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
        // @todo Implement update limit logic for PostgreSQL
        $this->markTestSkipped('Logic not implemented for PostgreSQL dialect.');
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

    public function testLimitAndOffset(): void
    {
        $db = self::$db;

        $names = ['Alice', 'Bob', 'Charlie', 'Dana', 'Eve'];
        foreach ($names as $i => $name) {
            $db->find()->table('users')->insert([
                'name' => $name,
                'company' => 'TestCorp',
                'age' => 20 + $i
            ]);
        }

        // first two records (limit = 2)
        $firstTwo = $db->find()
            ->from('users')
            ->orderBy('id', 'ASC')
            ->limit(2)
            ->get();

        $this->assertCount(2, $firstTwo);
        $this->assertEquals('Alice', $firstTwo[0]['name']);
        $this->assertEquals('Bob', $firstTwo[1]['name']);

        // (offset = 2)
        $nextTwo = $db->find()
            ->from('users')
            ->orderBy('id', 'ASC')
            ->limit(2)
            ->offset(2)
            ->get();

        $this->assertCount(2, $nextTwo);
        $this->assertEquals('Charlie', $nextTwo[0]['name']);
        $this->assertEquals('Dana', $nextTwo[1]['name']);
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

        $this->assertStringContainsString('UPDATE "users" SET "status" = :upd_status_0 WHERE "id" IN (SELECT "user_id" FROM "orders")',
            $db->lastQuery);

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
            ->select([Db::raw('CONCAT(name, \'_\', age) AS name_age')])
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
            ->select([Db::raw('CONCAT(name, \'-\', age) AS n_age')])
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
            ->where('age', Db::raw('2 + 1'), '=')
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
            ->having(Db::raw('SUM(amount)'), 300, '=')
            ->orHaving(Db::raw('SUM(amount)'), 500, '=')
            ->orHaving(Db::raw('SUM(amount)'), 700, '=')
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
            ->having(Db::raw('SUM(amount)'), 300, '>=')
            ->orHaving(Db::raw('SUM(amount)'), 500, '=')
            ->orHaving(Db::raw('SUM(amount)'), 700, '=')
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
        $db = self::$db;
        $db->startTransaction();
        $ok = self::$db->lock('users');
        $this->assertTrue($ok);

        $ok = self::$db->unlock();
        $this->assertTrue($ok);
        $db->commit();

    }

    public function testLockMultipleTableWrite(): void
    {
        $db = self::$db;

        $db->startTransaction();
        $this->assertTrue($db->setLockMethod('WRITE')->lock(['users', 'orders']));

        $this->assertSame(
            'LOCK TABLE "users", "orders" IN ACCESS EXCLUSIVE MODE',
            $db->lastQuery
        );

        $ok = self::$db->unlock();
        $this->assertTrue($ok);
        $db->commit();
    }

    public function testEscape(): void
    {
        $id = self::$db->find()
            ->table('users')
            ->insert([
                'name' => Db::escape("O'Reilly"),
                'age' => 30
            ]);
        $this->assertIsInt($id);

        $row = self::$db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();
        $this->assertEquals("O'Reilly", $row['name']);
        $this->assertEquals(30, $row['age']);
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

        $db->find()->table('users')->replaceMulti([
            ['id' => 1, 'name' => 'Alice', 'age' => 26],     // replace existing
            ['id' => 3, 'name' => 'Charlie', 'age' => 35],   // insert new
        ]);


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
            'INSERT INTO "users" ("name", "age") VALUES (:name, :age) ON CONFLICT ("name") DO UPDATE SET "age" = EXCLUDED."age"',
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
        self::$db = new PdoDb('pgsql', [
            'host' => self::DB_HOST,
            'port' => self::DB_PORT,
            'username' => self::DB_USER,
            'password' => self::DB_PASSWORD,
            'dbname' => self::DB_NAME,
        ]);
        $this->assertTrue(self::$db->ping());
    }

    public function testTableExists(): void
    {
        $this->assertTrue(self::$db->find()->table('users')->tableExists());
        $this->assertFalse(self::$db->find()->table('nonexistent')->tableExists());
    }

    public function testInvalidSqlLogsErrorAndException(): void
    {
        $sql = 'INSERT INTO users (non_existing_column) VALUES (:v)';
        $params = ['v' => 'X'];

        $this->expectException(\PDOException::class);

        $testHandler = new TestHandler();
        $logger = new Logger('test-db');
        $logger->pushHandler($testHandler);
        $db = self::$db = new PdoDb(
            'pgsql',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
            ], [], $logger);

        try {
            $db->connection->prepare($sql)->execute($params);
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
        $db = self::$db = new PdoDb(
            'pgsql',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
            ], [], $logger);

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
            'driver' => 'pgsql',
            'host' => self::DB_HOST,
            'port' => self::DB_PORT,
            'username' => self::DB_USER,
            'password' => self::DB_PASSWORD,
            'dbname' => self::DB_NAME,
        ]);

        $pdoDb = self::$db->connection('secondary');
        $this->assertInstanceOf(PdoDb::class, $pdoDb);

        $pdoDb = self::$db->connection('default');
        $this->assertInstanceOf(PdoDb::class, $pdoDb);
    }


    public function testLoadCsv()
    {
        if (!getenv('ALL_TESTS')) {
            $this->markTestSkipped('Github actions run failed');
        }
        $db = self::$db;

        $tmpFile = sys_get_temp_dir() . '/users.csv';
        file_put_contents($tmpFile, "4,Dave,new,30\n5,Eve,new,40\n");

        $ok = $db->find()->table('users')->loadCsv($tmpFile, [
            'fieldChar' => ',',
            'fields' => ['id', 'name', 'status', 'age'],
            'local' => true
        ]);

        $this->assertTrue($ok, 'loadData() returned false');

        $names = array_column($db->find()->from('users')->get(), 'name');
        $this->assertContains('Dave', $names);
        $this->assertContains('Eve', $names);

        unlink($tmpFile);
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

        $ok = self::$db->find()->table('users')->loadXml($file, '<user>', 1);
        $this->assertTrue($ok);

        $row = self::$db->find()->from('users')->where('name', 'XMLUser 2')->getOne();
        $this->assertEquals('XMLUser 2', $row['name']);
        $this->assertEquals(44, $row['age']);

        unlink($file);
    }


    public function testFuncNowIncDec(): void
    {
        $db = self::$db;

        $id = $db->find()->table('users')->insert(['name' => 'FuncTest', 'age' => 1]);

        // inc
        $db->find()
            ->from('users')
            ->where('id', $id)
            ->update(['age' => Db::inc()]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertEquals(2, $row['age']);

        // dec
        $db->find()
            ->from('users')
            ->where('id', $id)
            ->update(['age' => Db::dec()]);

        // now() into updated_at
        $db->find()
            ->from('users')
            ->where('id', $id)
            ->update(['updated_at' => Db::now()]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertNotEmpty($row['updated_at']);
        $this->assertNotEquals('0000-00-00 00:00:00', $row['updated_at']);

        // func MAX(age)
        $row = $db->find()->from('users')->select(['MAX(age) as max_age'])->getOne();
        $this->assertEquals(1, (int)$row['max_age']);
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
        $plan = $db->explainAnalyze('SELECT * FROM users WHERE status = :status', ['status' => 'active']);
        $this->assertNotEmpty($plan);
        $this->assertArrayHasKey('QUERY PLAN', $plan[0]);
    }

    public function testDescribeUsers(): void
    {
        $db = self::$db;
        $columns = $db->describe('users');
        $this->assertNotEmpty($columns);

        $columnNames = array_column($columns, 'column_name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('company', $columnNames);
        $this->assertContains('age', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);

        $idColumn = null;
        foreach ($columns as $column) {
            if ($column['column_name'] === 'id') {
                $idColumn = $column;
                break;
            }
        }
        $this->assertNotNull($idColumn);
        $this->assertEquals('integer', $idColumn['data_type']);
        $this->assertEquals('NO', $idColumn['is_nullable']);

        $nameColumn = null;
        foreach ($columns as $column) {
            if ($column['column_name'] === 'name') {
                $nameColumn = $column;
                break;
            }
        }
        $this->assertNotNull($nameColumn);
        $this->assertEquals('character varying', $nameColumn['data_type']);
        $this->assertContains($nameColumn['is_nullable'], ['YES', 'NO']);

        foreach ($columns as $column) {
            $this->assertArrayHasKey('column_name', $column);
            $this->assertArrayHasKey('data_type', $column);
            $this->assertArrayHasKey('is_nullable', $column);
            $this->assertArrayHasKey('column_default', $column);
        }
    }

    public function testDescribeOrders(): void
    {
        $db = self::$db;
        $columns = $db->describe('orders');
        $this->assertNotEmpty($columns);

        $columnNames = array_column($columns, 'column_name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('user_id', $columnNames);
        $this->assertContains('amount', $columnNames);

        $userIdColumn = null;
        foreach ($columns as $column) {
            if ($column['column_name'] === 'user_id') {
                $userIdColumn = $column;
                break;
            }
        }
        $this->assertNotNull($userIdColumn);
        $this->assertEquals('integer', $userIdColumn['data_type']);
        $this->assertEquals('NO', $userIdColumn['is_nullable']);

        foreach ($columns as $column) {
            if ($column['column_name'] === 'amount') {
                $amountColumn = $column;
                break;
            }
        }
        $this->assertNotNull($amountColumn);
        $this->assertEquals('numeric', $amountColumn['data_type']);
        $this->assertEquals('NO', $amountColumn['is_nullable']);
    }

    public function testPrefixMethod(): void
    {
        $db = self::$db;

        $queryBuilder = $db->find()->prefix('test_')->from('users');

        $db->rawQuery("CREATE TABLE IF NOT EXISTS test_prefixed_table (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100)
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
}
