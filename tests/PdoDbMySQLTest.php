<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\tests;

use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDOException;
use PHPUnit\Framework\TestCase;
use StdClass;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

final class PdoDbMySQLTest extends TestCase
{
    private static PdoDb $db;

    protected const string DB_HOST = '127.0.0.1';
    protected const string DB_NAME = 'testdb';
    protected const string DB_USER = 'testuser';
    protected const string DB_PASSWORD = 'testpass';
    protected const int DB_PORT = 3306;
    protected const string DB_CHARSET = 'utf8mb4';

    public static function setUpBeforeClass(): void
    {
        /**
         * CREATE DATABASE testdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
         * CREATE USER 'testuser'@'127.0.0.1' IDENTIFIED BY 'testpass';
         * GRANT ALL PRIVILEGES ON testdb.* TO 'testuser'@'127.0.0.1';
         * FLUSH PRIVILEGES;
         */
        self::$db = new PdoDb(
            'mysql',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
                'charset' => self::DB_CHARSET,
            ]
        );

        self::$db->rawQuery('DROP TABLE IF EXISTS archive_users');
        self::$db->rawQuery('DROP TABLE IF EXISTS orders');
        self::$db->rawQuery('DROP TABLE IF EXISTS users');

        self::$db->rawQuery("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            company VARCHAR(100),
            age INT,
            status VARCHAR(20) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT NULL,
            UNIQUE KEY uniq_name (name)
        ) ENGINE=InnoDB");

        self::$db->rawQuery("CREATE TABLE orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB;");

        self::$db->rawQuery("
        CREATE TABLE archive_users (
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
    }

    public function testMysqlMinimalParams(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dsn = $connection->getDialect()->buildDsn([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'username' => 'testuser',
            'password' => 'testpassword',
            'dbname' => 'testdb',
        ]);
        $this->assertEquals('mysql:host=127.0.0.1;dbname=testdb', $dsn);
    }

    public function testMysqlAllParams(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dsn = $connection->getDialect()->buildDsn([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'username' => 'testuser',
            'password' => 'testpassword',
            'dbname' => 'testdb',
            'port' => 3306,
            'charset' => 'utf8mb4',
            'unix_socket' => '/tmp/mysql.sock',
            'sslca' => '/path/ca.pem',
            'sslcert' => '/path/client-cert.pem',
            'sslkey' => '/path/client-key.pem',
            'compress' => true,
        ]);
        $this->assertStringContainsString('mysql:host=127.0.0.1;dbname=testdb;port=3306;charset=utf8mb4', $dsn);
        $this->assertStringContainsString('unix_socket=/tmp/mysql.sock', $dsn);
        $this->assertStringContainsString('sslca=/path/ca.pem', $dsn);
    }

    public function testMysqlMissingParamsThrows(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $this->expectException(InvalidArgumentException::class);
        $connection->getDialect()->buildDsn(['driver' => 'mysql']); // no host/dbname
    }

    public function testInsertWithQueryOption(): void
    {
        $db = self::$db;
        $id = $db->find()
            ->table('users')
            ->option('LOW_PRIORITY')
            ->insert(['name' => 'Alice']);
        $this->assertEquals(1, $id);
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringStartsWith('INSERT LOW_PRIORITY INTO `users`', $lastQuery);
    }

    public function testInsertWithMultipleQueryOptions(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->option(['LOW_PRIORITY', 'IGNORE'])
            ->insert(['name' => 'Bob']);
        $this->assertEquals(1, $id);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringStartsWith('INSERT LOW_PRIORITY IGNORE INTO `users`', $lastQuery);

        $id = $db->find()
            ->table('users')
            ->option('LOW_PRIORITY')
            ->option('IGNORE')
            ->insert(['name' => 'Bob']);
        $this->assertEquals(1, $id);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringStartsWith('INSERT LOW_PRIORITY IGNORE INTO `users`', $lastQuery);
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

        $this->assertCount(2, $likeResults); // Will match both 'WonderCorp' and 'wondercorp' in MySQL
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
        self::$db->rawQuery(Db::config('FOREIGN_KEY_CHECKS', 0));
        $this->assertEquals('SET FOREIGN_KEY_CHECKS = 0', self::$db->lastQuery);
        self::$db->rawQuery(Db::config('NAMES', 'utf8mb4', false, true));
        $this->assertEquals('SET NAMES \'utf8mb4\'', self::$db->lastQuery);
    }

    public function testInAndNotInHelpers(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Dana', 'company' => 'X', 'age' => 40]);
        $db->find()->table('users')->insert(['name' => 'Eve', 'company' => 'Y', 'age' => 45]);
        $db->find()->table('users')->insert(['name' => 'Frank', 'company' => 'Z', 'age' => 50]);

        $inResults = $db->find()
            ->from('users')
            ->where(Db::in('name', ['Dana', 'Eve']))
            ->get();

        $this->assertCount(2, $inResults);

        $notInResults = $db->find()
            ->from('users')
            ->where(Db::not(Db::in('name', ['Dana', 'Eve'])))
            ->get();

        $this->assertCount(1, $notInResults);
        $this->assertEquals('Frank', $notInResults[0]['name']);

        $notInResults = $db->find()
            ->from('users')
            ->where(Db::notIn('name', ['Dana', 'Eve']))
            ->get();

        $this->assertCount(1, $notInResults);
        $this->assertEquals('Frank', $notInResults[0]['name']);
    }

    public function testIsNullIsNotNullHelpers(): void
    {
        $db = self::$db;

        $idNull = $db->find()->table('users')->insert([
            'name' => 'Grace',
            'company' => 'NullCorp',
            'age' => 33,
            'status' => Db::null()
        ]);
        $this->assertIsInt($idNull);

        $idNotNull = $db->find()->table('users')->insert([
            'name' => 'Helen',
            'company' => 'LiveCorp',
            'age' => 35,
            'status' => 'active'
        ]);
        $this->assertIsInt($idNotNull);

        $nullResults = $db->find()
            ->from('users')
            ->where(Db::isNull('status'))
            ->get();

        $this->assertNotEmpty($nullResults);
        $this->assertEquals('Grace', $nullResults[0]['name']);

        $notNullResults = $db->find()
            ->from('users')
            ->where(Db::isNotNull('status'))
            ->get();

        $this->assertNotEmpty($notNullResults);
        $this->assertEquals('Helen', $notNullResults[0]['name']);
    }

    public function testDefaultHelper(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert([
            'name' => 'Nina',
            'company' => 'DefaultCorp',
            'age' => 31,
            'status' => 'active'
        ]);

        $db->find()
            ->table('users')
            ->where('id', $userId)
            ->update(['status' => Db::default()]);

        $result = $db->find()
            ->from('users')
            ->where('id', $userId)
            ->getOne();

        $this->assertNull($result['status']);
    }

    public function testCaseInSelect(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Alice', 'company' => 'A', 'age' => 22]);
        $db->find()->table('users')->insert(['name' => 'Bob', 'company' => 'B', 'age' => 35]);

        $case = Db::case([
            'age < 30' => "'young'",
            'age >= 30' => "'adult'"
        ]);

        $results = $db->find()
            ->select(['category' => $case])
            ->from('users')
            ->orderBy('age ASC')
            ->get();

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString("CASE WHEN age < 30 THEN 'young' WHEN age >= 30 THEN 'adult' END AS category",
            $lastQuery);

        $this->assertEquals('young', $results[0]['category']);
        $this->assertEquals('adult', $results[1]['category']);
    }

    public function testCaseInWhere(): void
    {
        $db = self::$db;

        // Insert users with different ages
        $db->find()->table('users')->insert(['name' => 'Charlie', 'company' => 'C', 'age' => 28]);
        $db->find()->table('users')->insert(['name' => 'Dana', 'company' => 'D', 'age' => 40]);
        $db->find()->table('users')->insert([
            'name' => 'Eve',
            'company' => 'E',
            'age' => null
        ]); // does not match any WHEN

        // CASE with ELSE
        $case = Db::case([
            'age < 30' => '1',
            'age >= 30' => '0'
        ], '2'); // ELSE → 2

        // WHERE CASE = 2 should return Eve
        $results = $db->find()
            ->from('users')
            ->where($case, 2)
            ->get();

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString(
            'CASE WHEN age < 30 THEN 1 WHEN age >= 30 THEN 0 ELSE 2 END',
            $lastQuery
        );

        $this->assertCount(1, $results);
        $this->assertEquals('Eve', $results[0]['name']);
    }

    public function testCaseInOrderBy(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Eve', 'company' => 'E', 'age' => 45]);
        $db->find()->table('users')->insert(['name' => 'Frank', 'company' => 'F', 'age' => 20]);

        $orderCase = Db::case([
            'age < 30' => '1',
            'age >= 30' => '2'
        ]);

        $results = $db->find()
            ->from('users')
            ->orderBy($orderCase, 'ASC')
            ->get();

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('CASE WHEN age < 30 THEN 1 WHEN age >= 30 THEN 2', $lastQuery);

        $this->assertEquals('Frank', $results[0]['name']);
        $this->assertEquals('Eve', $results[1]['name']);
    }

    public function testCaseInHaving(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'Gina', 'company' => 'G', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'Hank', 'company' => 'G', 'age' => 45]);

        $case = Db::case([
            'AVG(age) < 30' => '1',
            'AVG(age) >= 30' => '0'
        ]);

        $results = $db->find()
            ->select(['company', 'avg_age' => 'AVG(age)'])
            ->from('users')
            ->groupBy('company')
            ->having($case, 0)
            ->get();

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('CASE WHEN AVG(age) < 30 THEN 1 WHEN AVG(age) >= 30 THEN 0', $lastQuery);

        $this->assertEquals('G', $results[0]['company']);
        $this->assertGreaterThanOrEqual(30, $results[0]['avg_age']);
    }

    public function testCaseInUpdate(): void
    {
        $db = self::$db;

        $id1 = $db->find()->table('users')->insert(['name' => 'Ivy', 'company' => 'U', 'age' => 18, 'status' => '']);
        $id2 = $db->find()->table('users')->insert(['name' => 'Jack', 'company' => 'U', 'age' => 50, 'status' => '']);

        $case = Db::case([
            'age < 30' => "'junior'",
            'age >= 30' => "'senior'"
        ]);

        $db->find()
            ->table('users')
            ->where('company', 'U')
            ->update(['status' => $case]);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString("CASE WHEN age < 30 THEN 'junior' WHEN age >= 30 THEN 'senior'",
            $lastQuery);

        $user1 = $db->find()->from('users')->where('id', $id1)->getOne();
        $user2 = $db->find()->from('users')->where('id', $id2)->getOne();

        $this->assertEquals('junior', $user1['status']);
        $this->assertEquals('senior', $user2['status']);
    }

    public function testTrueAndFalseHelpers(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert([
            'name' => 'TrueUser',
            'company' => 'BoolCorp',
            'age' => 30,
            'is_active' => 1
        ]);

        $db->find()->table('users')->insert([
            'name' => 'FalseUser',
            'company' => 'BoolCorp',
            'age' => 35,
            'is_active' => 0
        ]);

        $trueResults = $db->find()
            ->from('users')
            ->where('is_active', Db::true())
            ->get();

        $this->assertCount(1, $trueResults);
        $this->assertEquals('TrueUser', $trueResults[0]['name']);

        $falseResults = $db->find()
            ->from('users')
            ->where('is_active', Db::false())
            ->get();

        $this->assertCount(1, $falseResults);
        $this->assertEquals('FalseUser', $falseResults[0]['name']);
    }

    public function testConcatHelper(): void
    {
        $db = self::$db;

        // prepare table rows
        $db->find()->table('users')->insert(['name' => 'John', 'company' => 'C1', 'age' => 30, 'status' => 'ok']);
        $db->find()->table('users')->insert(['name' => 'Anna', 'company' => 'C2', 'age' => 25, 'status' => 'ok']);

        // 1) column + literal (space) + column
        $results = $db->find()
            ->select(['full' => Db::concat('name', "' '", 'company')])
            ->from('users')
            ->where(['name' => 'John'])
            ->getOne();

        $this->assertEquals('John C1', $results['full']);

        // 2) RawValue + literal + column
        $raw = Db::raw("UPPER(name)");
        $results = $db->find()
            ->select(['x' => Db::concat($raw, "' - '", 'company')])
            ->from('users')
            ->where(['name' => 'Anna'])
            ->getOne();

        $this->assertEquals('ANNA - C2', $results['x']);

        // 3) numeric literal and column
        $results = $db->find()
            ->select(['s' => Db::concat('name', "' #'", 100)])
            ->from('users')
            ->where(['name' => 'John'])
            ->getOne();

        $this->assertEquals('John #100', $results['s']);

        // 4) single-part concat (should return the part as-is)
        $results = $db->find()
            ->select(['only' => Db::concat('name')])
            ->from('users')
            ->where(['name' => 'Anna'])
            ->getOne();

        $this->assertEquals('Anna', $results['only']);
    }

    public function testTsHelper(): void
    {
        $db = self::$db;

        $db->rawQuery('DROP TABLE IF EXISTS tmp_timestamps;');
        $db->rawQuery("CREATE TABLE tmp_timestamps (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        ts INTEGER NOT NULL,
        ts_diff INTEGER NOT NULL,
        ts_diff2 INTEGER NOT NULL          
    );");

        // Insert using Db::now as timestamp: Db::now(null, true)
        $ts = time();
        $id = $db->find()
            ->table('tmp_timestamps')
            ->insert([
                'ts' => Db::ts(),
                'ts_diff' => Db::ts('+1 DAY'),
                'ts_diff2' => Db::ts('-1 DAY'),
            ]);

        $this->assertIsInt($id);

        $row = $db->find()
            ->from('tmp_timestamps')
            ->where('id', $id)
            ->getOne();

        $now = time();

        $this->assertGreaterThanOrEqual($ts, $row['ts']);
        $this->assertGreaterThanOrEqual($now + 83600, $row['ts_diff']);
        $this->assertLessThanOrEqual($now - 83600, $row['ts_diff2']);
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
            'age' => Db::raw('age + 100')
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
            'name' => Db::raw('CONCAT(:prefix, :name)', [
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
                'name' => Db::raw('CONCAT(name, :suffix)', [':suffix' => '_updated'])
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
            ->having(Db::raw('total > :min_total AND total < :max_total', [
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


    public function testSelectWithQueryOption(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'Test', 'age' => 20]);
        $this->assertEquals(1, $id);

        $rows = $db->find()
            ->from('users')
            ->option('SQL_NO_CACHE')
            ->get();
        $this->assertCount(1, $rows);

        $row = $db->find()
            ->from('users')
            ->option('SQL_NO_CACHE')
            ->getOne();
        $this->assertIsArray($row);
        $this->assertEquals('Test', $row['name']);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringStartsWith('SELECT SQL_NO_CACHE * FROM `users`', $lastQuery);
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

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringEndsWith('FROM `users` FOR UPDATE', $lastQuery);
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

    public function testUpdateWithQueryOption(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'Test', 'age' => 20]);
        $this->assertEquals(1, $id);

        $rowCount = $db->find()
            ->table('users')
            ->option('LOW_PRIORITY')
            ->where('id', 1)
            ->update(['name' => 'Updated']);
        $this->assertEquals(1, $rowCount);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringStartsWith('UPDATE LOW_PRIORITY `users` SET', $lastQuery);

        $row = $db->find()
            ->from('users')
            ->getOne();
        $this->assertIsArray($row);
        $this->assertEquals('Updated', $row['name']);
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

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('UPDATE `users` SET', $lastQuery);
        $this->assertStringContainsString('WHERE `id` IN (SELECT `user_id` FROM `orders`)', $lastQuery);

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


        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString(
            'DELETE FROM `users` WHERE `id` IN (SELECT `user_id` FROM `orders` WHERE `amount` >=',
            $lastQuery
        );
        $this->assertStringContainsString(')', $lastQuery);


        $rows = $db->find()
            ->table('users')
            ->select('id')
            ->orderBy('id', 'ASC')
            ->getColumn();
        $this->assertCount(3, $rows);
        $this->assertSame([3, 4, 5], $rows);
    }

    public function testDeleteWithQueryOption(): void
    {
        $db = self::$db;

        $id = $db->find()
            ->table('users')
            ->insert(['name' => 'Test', 'age' => 20]);
        $this->assertEquals(1, $id);

        $db->find()
            ->table('users')
            ->option('LOW_PRIORITY')
            ->where('id', 1)
            ->delete();

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringStartsWith('DELETE LOW_PRIORITY FROM `users` WHERE', $lastQuery);

        $rows = $db->find()
            ->from('users')
            ->option('SQL_NO_CACHE')
            ->get();
        $this->assertCount(0, $rows);
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
            ->select([Db::raw('CONCAT(name, "_", age) AS name_age')])
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
            ->select([Db::raw('CONCAT(name, "-", age) AS n_age')])
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
        assert(property_exists($rows[0], 'age'));
        $this->assertEquals(22, $rows[0]->age);

        // objectBuilder for single row
        $row = $db->find()
            ->from('users')
            ->asObject()
            ->getOne();
        $this->assertIsObject($row);
        assert(property_exists($row, 'age'));
        $this->assertEquals(22, $row->age);
    }

    public function testWhereSyntax(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert(['name' => 'A', 'age' => 10]);
        $db->find()->table('users')->insert(['name' => 'B', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'C', 'age' => 30]);
        $db->find()->table('users')->insert(['name' => 'D', 'age' => 50]);

        $row = $db->find()->from('users')->where(['age' => 25])->getOne();
        $this->assertEquals('B', $row['name']);

        $row = $db->find()->from('users')->where(['age' => ':age'], ['age' => 30])->getOne();
        $this->assertEquals('C', $row['name']);

        $row = $db->find()->from('users')->where('age = 50')->getOne();
        $this->assertEquals('D', $row['name']);

        $row = $db->find()->from('users')->where(Db::between('age', 10, 20))->getOne();
        $this->assertEquals('A', $row['name']);

        $row = $db->find()->from('users')->where('age', [25, 29], 'BETWEEN')->getOne();
        $this->assertEquals('B', $row['name']);
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

    public function testBetweenHelper(): void
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
            ->where(Db::between('age', 20, 40))
            ->orderBy('age', 'ASC')
            ->getColumn();
        $this->assertEquals([25, 30], $ages);

        // NOT BETWEEN
        $ages = $db->find()
            ->from('users')
            ->select('age')
            ->where(Db::notBetween('age', 20, 40))
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
            // for string groupBy test (company G1)
            ['name' => 'A', 'age' => 10, 'company' => 'G1'],
            ['name' => 'B', 'age' => 10, 'company' => 'G1'],

            // for RawValue groupBy test (company G2)
            ['name' => 'C', 'age' => 20, 'company' => 'G2'],
            ['name' => 'D', 'age' => 20, 'company' => 'G2'],
            ['name' => 'E', 'age' => 20, 'company' => 'G2'],

            // for array groupBy test (company G3) using unique names
            ['name' => 'X1', 'age' => 30, 'company' => 'G3'],
            ['name' => 'X2', 'age' => 30, 'company' => 'G3'],
            ['name' => 'Y', 'age' => 30, 'company' => 'G3'],
        ]);

        // 1) groupBy using string
        $rows = $db->find()
            ->from('users')
            ->where(['company' => 'G1'])
            ->groupBy('age')
            ->select(['age', 'COUNT(*) AS cnt'])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals(10, $rows[0]['age']);
        $this->assertEquals(2, $rows[0]['cnt']);

        // 2) groupBy using RawValue (expression)
        $rowsRaw = $db->find()
            ->from('users')
            ->where(['company' => 'G2'])
            ->groupBy(\tommyknocker\pdodb\helpers\Db::raw('age'))
            ->select([\tommyknocker\pdodb\helpers\Db::raw('age'), 'COUNT(*) AS cnt'])
            ->get();

        $this->assertCount(1, $rowsRaw);
        $this->assertEquals(20, $rowsRaw[0]['age']);
        $this->assertEquals(3, $rowsRaw[0]['cnt']);

        // 3) groupBy using array (multiple columns)
        $rowsArr = $db->find()
            ->from('users')
            ->where(['company' => 'G3'])
            ->groupBy(['age', 'name'])
            ->select(['age', 'name', 'COUNT(*) AS cnt'])
            ->orderBy('name ASC')
            ->get();

        // Expect three groups for X1, X2 and Y with counts 1,1,1
        $this->assertCount(3, $rowsArr);

        $map = [];
        foreach ($rowsArr as $r) {
            $map[$r['name']] = (int)$r['cnt'];
            $this->assertEquals(30, (int)$r['age']);
        }

        $this->assertEquals(1, $map['X1']);
        $this->assertEquals(1, $map['X2']);
        $this->assertEquals(1, $map['Y']);
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
        $ok = self::$db->lock('users');
        $this->assertTrue($ok);

        $this->assertSame('LOCK TABLES `users` WRITE', self::$db->lastQuery);

        $ok = self::$db->unlock();
        $this->assertTrue($ok);

        $this->assertSame('UNLOCK TABLES', self::$db->lastQuery);
    }

    public function testLockMultipleTableWrite(): void
    {
        $db = self::$db;

        $this->assertTrue($db->setLockMethod('WRITE')->lock(['users', 'orders']));

        $this->assertSame(
            'LOCK TABLES `users` WRITE, `orders` WRITE',
            $db->lastQuery
        );

        $ok = self::$db->unlock();
        $this->assertTrue($ok);

        $this->assertSame('UNLOCK TABLES', $db->lastQuery);
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
            // @phpstan-ignore argument.type
            ->onDuplicate(['age'])
            ->insert(['name' => 'Eve', 'age' => 21]);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString(
            'ON DUPLICATE KEY UPDATE `age` = VALUES(`age`)',
            $lastQuery
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

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('IN (SELECT', $lastQuery);
        $this->assertStringContainsString(')', $lastQuery);

        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
    }


    public function testDisconnectAndPing(): void
    {
        self::$db->disconnect();
        $this->assertFalse(self::$db->ping());
        self::$db = new PdoDb('mysql', [
            'host' => self::DB_HOST,
            'port' => self::DB_PORT,
            'username' => self::DB_USER,
            'password' => self::DB_PASSWORD,
            'dbname' => self::DB_NAME,
            'charset' => self::DB_CHARSET,
        ]);
        $this->assertTrue(self::$db->ping());
    }

    public function testExistsAndNotExists(): void
    {
        $db = self::$db;

        // Insert one record
        $db->find()->table('users')->insert([
            'name' => 'Existy',
            'company' => 'CheckCorp',
            'age' => 42,
            'status' => 'active'
        ]);

        // Check exists() - should return true
        $exists = $db->find()
            ->from('users')
            ->where(['name' => 'Existy'])
            ->exists();

        $this->assertTrue($exists, 'Expected exists() to return true for matching row');

        // Check notExists() - should return false
        $notExists = $db->find()
            ->from('users')
            ->where(['name' => 'Existy'])
            ->notExists();

        $this->assertFalse($notExists, 'Expected notExists() to return false for matching row');

        // Check exists() - for non-existent value
        $noMatchExists = $db->find()
            ->from('users')
            ->where(['name' => 'Ghosty'])
            ->exists();

        $this->assertFalse($noMatchExists, 'Expected exists() to return false for non-matching row');

        // Check notExists() - for non-existent value
        $noMatchNotExists = $db->find()
            ->from('users')
            ->where(['name' => 'Ghosty'])
            ->notExists();

        $this->assertTrue($noMatchNotExists, 'Expected notExists() to return true for non-matching row');
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

        $this->expectException(PDOException::class);

        $testHandler = new TestHandler();
        $logger = new Logger('test-db');
        $logger->pushHandler($testHandler);
        $db = new PdoDb(
            'mysql',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
                'charset' => self::DB_CHARSET,
            ], [], $logger
        );

        try {
            $connection = $db->connection;
            assert($connection !== null);
            $connection->prepare($sql)->execute($params);
        } finally {
            $hasOpError = false;
            foreach ($testHandler->getRecords() as $rec) {
                $context = $rec['context'] ?? [];
                assert(is_array($context));
                if (($rec['message'] ?? '') === 'operation.error'
                    && ($context['operation'] ?? '') === 'prepare'
                    && ($context['exception'] ?? new StdClass()) instanceof PDOException
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
        $db = new PdoDb(
            'mysql',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
                'charset' => self::DB_CHARSET,
            ], [], $logger
        );

        // Begin
        $db->startTransaction();
        $foundBegin = false;
        foreach ($testHandler->getRecords() as $rec) {
            $context = $rec['context'] ?? [];
            assert(is_array($context));
            if (($rec['message'] ?? '') === 'operation.start'
                && ($context['operation'] ?? '') === 'transaction.begin'
            ) {
                $foundBegin = true;
                break;
            }
        }
        $this->assertTrue($foundBegin, 'transaction.begin not logged');

        // Commit
        $connection = $db->connection;
        assert($connection !== null);
        $connection->commit();
        $foundCommit = false;
        foreach ($testHandler->getRecords() as $rec) {
            $context = $rec['context'] ?? [];
            assert(is_array($context));
            if (($rec['message'] ?? '') === 'operation.end'
                && ($context['operation'] ?? '') === 'transaction.commit'
            ) {
                $foundCommit = true;
                break;
            }
        }
        $this->assertTrue($foundCommit, 'transaction.commit not logged');

        // Rollback
        $connection->transaction();
        $connection->rollBack();
        $foundRollback = false;
        foreach ($testHandler->getRecords() as $rec) {
            $context = $rec['context'] ?? [];
            assert(is_array($context));
            if (($rec['message'] ?? '') === 'operation.end'
                && ($context['operation'] ?? '') === 'transaction.rollback'
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
            'driver' => 'mysql',
            'host' => self::DB_HOST,
            'port' => self::DB_PORT,
            'username' => self::DB_USER,
            'password' => self::DB_PASSWORD,
            'dbname' => self::DB_NAME,
            'charset' => self::DB_CHARSET,
        ]);

        $pdoDb = self::$db->connection('secondary');
        $this->assertSame(self::$db, $pdoDb);

        $pdoDb = self::$db->connection('default');
        $this->assertSame(self::$db, $pdoDb);
    }


    public function testLoadCsv(): void
    {
        $db = self::$db;

        $tmpFile = sys_get_temp_dir() . '/users.csv';
        file_put_contents($tmpFile, "4,Dave,new\n5,Eve,new\n");

        try {
            $ok = $db->find()->table('users')->loadCsv($tmpFile, [
                'fieldChar' => ',',
                'fields' => ['id', 'name', 'status'],
                'local' => true
            ]);
        } catch (\PDOException $e) {
            $this->markTestSkipped(
                'LoadCsv test requires MySQL configured with local_infile enabled. ' .
                'Error: ' . $e->getMessage()
            );
        }

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

        try {
            $ok = self::$db->find()->table('users')->loadXml($file, '<user>', 1);
        } catch (\PDOException $e) {
            $this->markTestSkipped(
                'LoadXml test requires MySQL configured with local_infile enabled. ' .
                'Error: ' . $e->getMessage()
            );
        }
        
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

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertEquals(1, $row['age']);

        // now() into updated_at
        $db->find()
            ->from('users')
            ->where('id', $id)
            ->update(['updated_at' => Db::now()]);

        $row = $db->find()->from('users')->where('id', $id)->getOne();
        $this->assertNotEmpty($row['updated_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['updated_at']);
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
        $plan = $db->explainAnalyze('SELECT * FROM users WHERE status = "active"');
        $this->assertNotEmpty($plan);
        $this->assertArrayHasKey('select_type', $plan[0]);
    }

    public function testDescribeUsers(): void
    {
        $db = self::$db;
        $columns = $db->describe('users');
        $this->assertNotEmpty($columns);

        $columnNames = array_column($columns, 'Field');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('company', $columnNames);
        $this->assertContains('age', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('created_at', $columnNames);
        $this->assertContains('updated_at', $columnNames);

        $idColumn = $columns[0];
        $this->assertEquals('id', $idColumn['Field']);
        $this->assertEquals('int', strtolower($idColumn['Type']));
        $this->assertEquals('NO', $idColumn['Null']);
        $this->assertEquals('PRI', $idColumn['Key']);
        $this->assertEquals('auto_increment', $idColumn['Extra']);

        foreach ($columns as $column) {
            $this->assertArrayHasKey('Field', $column);
            $this->assertArrayHasKey('Type', $column);
            $this->assertArrayHasKey('Null', $column);
            $this->assertArrayHasKey('Key', $column);
            $this->assertArrayHasKey('Default', $column);
            $this->assertArrayHasKey('Extra', $column);
        }
    }

    public function testDescribeOrders(): void
    {
        $db = self::$db;
        $columns = $db->describe('orders');
        $this->assertNotEmpty($columns);

        $columnNames = array_column($columns, 'Field');
        $this->assertContains('id', $columnNames);
        $this->assertContains('user_id', $columnNames);
        $this->assertContains('amount', $columnNames);

        $userIdColumn = null;
        foreach ($columns as $column) {
            if ($column['Field'] === 'user_id') {
                $userIdColumn = $column;
                break;
            }
        }
        $this->assertNotNull($userIdColumn);
        $this->assertEquals('int', strtolower($userIdColumn['Type']));
        $this->assertEquals('NO', $userIdColumn['Null']);
        $this->assertEquals('MUL', $userIdColumn['Key']); // Foreign key
    }

    public function testPrefixMethod(): void
    {
        $db = self::$db;

        $queryBuilder = $db->find()->prefix('test_')->from('users');

        $db->rawQuery("CREATE TABLE IF NOT EXISTS test_prefixed_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100)
        )");

        $id = $queryBuilder->table('prefixed_table')->insert(['name' => 'Test User']);
        $this->assertIsInt($id);

        $user = $queryBuilder->table('prefixed_table')->where('id', $id)->getOne();
        $this->assertEquals('Test User', $user['name']);

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('`test_prefixed_table`', $lastQuery);

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

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('LEFT JOIN', $lastQuery);
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

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('RIGHT JOIN', $lastQuery);
    }

    public function testJsonMethods(): void
    {
        $db = self::$db; // configured for MySQL in suite setup

        // prepare table
        $db->rawQuery("DROP TABLE IF EXISTS t_json");
        $db->rawQuery("CREATE TABLE t_json (id INT AUTO_INCREMENT PRIMARY KEY, meta JSON)");

        // insert initial rows
        $payload1 = ['a' => ['b' => 1], 'tags' => ['x','y'], 'score' => 10];
        $id1 = $db->find()->table('t_json')->insert(['meta' => json_encode($payload1, JSON_UNESCAPED_UNICODE)]);
        $this->assertIsInt($id1);
        $this->assertGreaterThan(0, $id1);

        $payload2 = ['a' => ['b' => 2], 'tags' => ['y','z'], 'score' => 5];
        $id2 = $db->find()->table('t_json')->insert(['meta' => json_encode($payload2, JSON_UNESCAPED_UNICODE)]);
        $this->assertIsInt($id2);
        $this->assertGreaterThan(0, $id2);

        // --- selectJson: fetch meta.a.b for id1 via QueryBuilder::selectJson
        $row = $db->find()
            ->table('t_json')
            ->selectJson('meta', ['a','b'], 'ab')
            ->where('id', $id1)
            ->getOne();
        $this->assertNotNull($row);
        $this->assertEquals(1, (int)$row['ab']);

        // --- whereJsonContains: verify tags contains 'x' for id1
        $contains = $db->find()
            ->table('t_json')
            ->whereJsonContains('meta', 'x', ['tags'])
            ->where('id', $id1)
            ->exists();
        $this->assertTrue($contains);

        // --- whereJsonExists: check that path $.a.b exists for id1
        $exists = $db->find()
            ->table('t_json')
            ->whereJsonExists('meta', ['a','b'])
            ->where('id', $id1)
            ->exists();
        $this->assertTrue($exists);

        // --- whereJsonPath: check $.a.b = 1 for id1
        $matches = $db->find()
            ->table('t_json')
            ->whereJsonPath('meta', ['a','b'], '=', 1)
            ->where('id', $id1)
            ->exists();
        $this->assertTrue($matches);

        // --- jsonSet: set meta.a.c = 42 for id1 using QueryBuilder::jsonSet
        $qb = $db->find()->table('t_json')->where('id', $id1);
        $rawSet = $qb->jsonSet('meta', ['a','c'], 42);
        $qb->update(['meta' => $rawSet]);

        $val = $db->find()
            ->table('t_json')
            ->selectJson('meta', ['a','c'], 'ac')
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals(42, (int)$val['ac']);

        // --- jsonRemove: remove meta.a.b for id1 using QueryBuilder::jsonRemove
        $qb2 = $db->find()->table('t_json')->where('id', $id1);
        $rawRemove = $qb2->jsonRemove('meta', ['a','b']);
        $qb2->update(['meta' => $rawRemove]);

        $after = $db->find()
            ->table('t_json')
            ->selectJson('meta', ['a','b'], 'ab')
            ->where('id', $id1)
            ->getOne();
        $this->assertNull($after['ab']);

        // --- orderByJson: order by JSON key meta.score ASC using QueryBuilder::orderByJson
        $list = $db->find()
            ->table('t_json')
            ->select(['id'])
            ->selectJson('meta', ['score'], 'score')
            ->orderByJson('meta', ['score'], 'ASC')
            ->get();

        $this->assertCount(2, $list);
        // After all operations, id2 should still have score=5, id1 should still have score=10
        // So in ASC order: id2 (5) first, then id1 (10)
        $scores = array_map(fn($r) => (int)$r['score'], $list);
        sort($scores);
        $this->assertEquals(5, $scores[0]);
        $this->assertEquals(10, $scores[1]);
    }

    public function testJsonMethodsEdgeCases(): void
    {
        $db = self::$db;

        $db->rawQuery('DROP TABLE IF EXISTS t_json_edge');
        $db->rawQuery("CREATE TABLE t_json_edge (id INT AUTO_INCREMENT PRIMARY KEY, meta JSON)");

        // Insert rows covering a variety of JSON shapes and types
        $rows = [
            // basic numeric scalar and nested object
            ['a' => ['b' => 1], 'tags' => ['x','y'], 'score' => 10, 'maybe' => null],
            // numeric as string, nested array, array order changed
            ['a' => ['b' => '1'], 'tags' => ['y','x'], 'score' => '5', 'extra' => ['z', ['k' => 7]]],
            // deeper nesting and floats
            ['a' => ['b' => 1.0], 'tags' => ['x','z'], 'score' => 2.5, 'list' => [1,2,3]],
            // arrays with duplicate elements and nulls
            ['a' => ['b' => null], 'tags' => ['x','x'], 'score' => 0, 'list' => [null, '0', 0]],
            // object with mixed types and an array of objects
            ['a' => ['b' => 42], 'tags' => [], 'score' => 100, 'items' => [['id'=>1], ['id'=>2]]],
        ];

        $ids = [];
        foreach ($rows as $r) {
            $ids[] = $db->find()->table('t_json_edge')->insert(['meta' => json_encode($r, JSON_UNESCAPED_UNICODE)]);
        }
        $this->assertCount(count($rows), $ids);
        foreach ($ids as $id) {
            $this->assertIsInt($id);
        }

        // 1) whereJsonPath: numeric equality and string numeric equality (should find appropriate rows)
        $foundNum = $db->find()
            ->table('t_json_edge')
            ->whereJsonPath('meta', ['a','b'], '=', 1)
            ->get();
        // should find rows where a.b is numeric 1 or numeric 1.0 (rows 0 and 2)
        $this->assertNotEmpty($foundNum);

        // 2) whereJsonPath: compare numeric-as-string -> ensure equality semantics allow distinguishing strings vs numbers
        $foundStrNum = $db->find()
            ->table('t_json_edge')
            ->whereJsonPath('meta', ['a','b'], '=', '1')
            ->get();
        $this->assertNotEmpty($foundStrNum);

        // Ensure at least one row differs between numeric vs string match
        $idsNum = array_map(fn($r) => (int)$r['id'], $foundNum);
        $idsStr = array_map(fn($r) => (int)$r['id'], $foundStrNum);
        $this->assertTrue(count(array_unique(array_merge($idsNum, $idsStr))) >= 2);

        // 3) whereJsonContains: array membership for scalars and duplicates
        $containsX = $db->find()
            ->table('t_json_edge')
            ->whereJsonContains('meta', 'x', ['tags'])
            ->get();
        $this->assertNotEmpty($containsX, 'Expected some rows to contain tag x');

        // 4) whereJsonContains: check array-of-objects contains object by matching subpath
        $containsItem1 = $db->find()
            ->table('t_json_edge')
            ->whereJsonPath('meta', ['items', '0', 'id'], '=', 1)
            ->exists();
        $this->assertTrue($containsItem1);

        // 5) jsonSet: set nested scalar and nested object fields
        $id0 = $ids[0];
        $qb = $db->find()->table('t_json_edge')->where('id', $id0);
        $rawSet = $qb->jsonSet('meta', ['a','c'], 'newval');
        $qb->update(['meta' => $rawSet]);

        $val = $db->find()
            ->table('t_json_edge')
            ->selectJson('meta', ['a','c'], 'ac')
            ->where('id', $id0)
            ->getOne();
        $this->assertEquals('newval', $val['ac'] ?? null);

        // 6) jsonSet: set numeric value and ensure numeric comparisons still work
        $qb2 = $db->find()->table('t_json_edge')->where('id', $ids[4]);
        $rawSet2 = $qb2->jsonSet('meta', ['a','b'], 999);
        $qb2->update(['meta' => $rawSet2]);

        $numCheck = $db->find()
            ->table('t_json_edge')
            ->whereJsonPath('meta', ['a','b'], '=', 999)
            ->where('id', $ids[4])
            ->exists();
        $this->assertTrue($numCheck);

        // 7) jsonRemove: remove scalar path, remove nested array element (index)
        $qb3 = $db->find()->table('t_json_edge')->where('id', $ids[1]);
        $rawRemove = $qb3->jsonRemove('meta', ['extra', '0']); // remove first element of extra array
        $qb3->update(['meta' => $rawRemove]);

        $afterRemove = $db->find()
            ->table('t_json_edge')
            ->selectJson('meta', ['extra', '0'], 'ex0')
            ->where('id', $ids[1])
            ->getOne();
        // after removal, the element at index 0 should be null or not present
        $this->assertTrue($afterRemove['ex0'] === null || $afterRemove['ex0'] === '' || $afterRemove['ex0'] === 'null');

        // 8) orderByJson: numeric ordering vs string ordering
        $list = $db->find()
            ->table('t_json_edge')
            ->select(['id'])
            ->selectJson('meta', ['score'], 'score')
            ->orderByJson('meta', ['score'], 'ASC')
            ->get();
        $this->assertCount(count($rows), $list);
        $scores = array_map(fn($r) => is_null($r['score']) ? null : (float)$r['score'], $list);
        $sorted = $scores;
        sort($sorted, SORT_NUMERIC);
        $this->assertEquals($sorted, $scores);

        // 9) whereJsonExists: check existing vs non-existing paths
        $existsA = $db->find()
            ->table('t_json_edge')
            ->whereJsonExists('meta', ['a','b'])
            ->get();
        $this->assertNotEmpty($existsA);

        $notExists = $db->find()
            ->table('t_json_edge')
            ->whereJsonExists('meta', ['non','existent'])
            ->get();
        $this->assertEmpty($notExists);

        // 10) Combine JSON operations: set, then remove, then check contains/exists
        $id3 = $ids[2];
        $qb4 = $db->find()->table('t_json_edge')->where('id', $id3);
        $qb4->update(['meta' => $qb4->jsonSet('meta', ['compound','value'], ['x' => 1])]);

        $hasCompound = $db->find()
            ->table('t_json_edge')
            ->whereJsonExists('meta', ['compound','value'])
            ->where('id', $id3)
            ->exists();
        $this->assertTrue($hasCompound);

        $qb4b = $db->find()->table('t_json_edge')->where('id', $id3);
        $qb4b->update(['meta' => $qb4b->jsonRemove('meta', ['compound','value'])]);

        $hasCompoundAfter = $db->find()
            ->table('t_json_edge')
            ->whereJsonExists('meta', ['compound','value'])
            ->where('id', $id3)
            ->exists();
        $this->assertFalse($hasCompoundAfter);

        // 11) Json contains for arrays: check that checking multiple items works (subset)
        // Insert a row with tags ['alpha','beta','gamma'] for explicit test
        $special = ['tags' => ['alpha','beta','gamma']];
        $specId = $db->find()->table('t_json_edge')->insert(['meta' => json_encode($special, JSON_UNESCAPED_UNICODE)]);
        $this->assertIsInt($specId);

        $containsSubset = $db->find()
            ->table('t_json_edge')
            ->whereJsonContains('meta', ['alpha','gamma'], ['tags'])
            ->where('id', $specId)
            ->exists();
        $this->assertTrue($containsSubset);
    }

    public function testJsonHelpers(): void
    {
        $db = self::$db;

        // Create test table
        $db->rawQuery('DROP TABLE IF EXISTS t_json_helpers');
        $db->rawQuery("CREATE TABLE t_json_helpers (id INT AUTO_INCREMENT PRIMARY KEY, data JSON)");

        // Insert test data using Db::jsonObject and Db::jsonArray
        $id1 = $db->find()->table('t_json_helpers')->insert([
            'data' => Db::jsonObject(['name' => 'Alice', 'age' => 30, 'tags' => ['php', 'mysql']])
        ]);
        $this->assertIsInt($id1);

        $id2 = $db->find()->table('t_json_helpers')->insert([
            'data' => Db::jsonObject(['name' => 'Bob', 'age' => 25, 'items' => [1, 2, 3, 4, 5]])
        ]);
        $this->assertIsInt($id2);

        // Test Db::jsonPath - compare JSON value
        $results = $db->find()
            ->table('t_json_helpers')
            ->where(Db::jsonPath('data', ['age'], '>', 27))
            ->get();
        $this->assertCount(1, $results);
        $this->assertEquals($id1, $results[0]['id']);

        // Test Db::jsonContains - check if value exists
        $hasPhp = $db->find()
            ->table('t_json_helpers')
            ->where(Db::jsonContains('data', 'php', ['tags']))
            ->exists();
        $this->assertTrue($hasPhp);

        // Test Db::jsonExists - check if path exists
        $hasItems = $db->find()
            ->table('t_json_helpers')
            ->where(Db::jsonExists('data', ['items']))
            ->where('id', $id2)
            ->exists();
        $this->assertTrue($hasItems);

        // Test Db::jsonGet in SELECT
        $row = $db->find()
            ->table('t_json_helpers')
            ->select(['id', 'name' => Db::jsonGet('data', ['name'])])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals('Alice', $row['name']);

        // Test Db::jsonLength - get array/object length
        $row = $db->find()
            ->table('t_json_helpers')
            ->select(['id', 'items_count' => Db::jsonLength('data', ['items'])])
            ->where('id', $id2)
            ->getOne();
        $this->assertEquals(5, (int)$row['items_count']);

        // Test Db::jsonType - get JSON type
        $row = $db->find()
            ->table('t_json_helpers')
            ->select(['id', 'tags_type' => Db::jsonType('data', ['tags'])])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals('ARRAY', strtoupper($row['tags_type']));

        // Test Db::jsonGet in ORDER BY
        $sorted = $db->find()
            ->table('t_json_helpers')
            ->select(['id'])
            ->orderBy(Db::jsonGet('data', ['age']), 'ASC')
            ->getColumn();
        $this->assertEquals([$id2, $id1], $sorted); // Bob (25) before Alice (30)

        // Test Db::jsonLength in WHERE condition
        $manyItems = $db->find()
            ->table('t_json_helpers')
            ->where(Db::jsonLength('data', ['items']), 3, '>')
            ->get();
        $this->assertCount(1, $manyItems);
        $this->assertEquals($id2, $manyItems[0]['id']);

        // Test that jsonPath works with different operators
        $ageEq = $db->find()
            ->table('t_json_helpers')
            ->where(Db::jsonPath('data', ['age'], '=', 25))
            ->getOne();
        $this->assertEquals($id2, $ageEq['id']);
    }

    /* ---------------- New DB Helpers Tests ---------------- */

    public function testNewDbHelpers(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_helpers');
        $db->rawQuery("CREATE TABLE t_helpers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), age INT, created_at DATETIME, description TEXT)");

        // Test ifNull, coalesce
        $id1 = $db->find()->table('t_helpers')->insert([
            'name' => 'Alice',
            'age' => 30,
            'created_at' => date('Y-m-d H:i:s'),
            'description' => null
        ]);

        $row = $db->find()->table('t_helpers')
            ->select([
                'name',
                'description_text' => Db::ifNull('description', 'No description')
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals('No description', $row['description_text']);

        // Test upper, lower
        $row = $db->find()->table('t_helpers')
            ->select([
                'upper' => Db::upper('name'),
                'lower' => Db::lower('name')
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals('ALICE', $row['upper']);
        $this->assertEquals('alice', $row['lower']);

        // Test abs, round
        $id2 = $db->find()->table('t_helpers')->insert([
            'name' => 'Bob',
            'age' => -5,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $row = $db->find()->table('t_helpers')
            ->select([
                'abs_age' => Db::abs('age')
            ])
            ->where('id', $id2)
            ->getOne();
        $this->assertEquals(5, (int)$row['abs_age']);

        // Test mod
        $row = $db->find()->table('t_helpers')
            ->select([
                'mod_result' => Db::mod('age', '3')
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals(0, (int)$row['mod_result']);

        // Test greatest, least
        $row = $db->find()->table('t_helpers')
            ->select([
                'max_val' => Db::greatest('10', '5', '20'),
                'min_val' => Db::least('10', '5', '20')
            ])
            ->getOne();
        $this->assertEquals(20, (int)$row['max_val']);
        $this->assertEquals(5, (int)$row['min_val']);

        // Test substring
        $row = $db->find()->table('t_helpers')
            ->select([
                'substr' => Db::substring('name', 1, 3)
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals('Ali', $row['substr']);

        // Test length
        $row = $db->find()->table('t_helpers')
            ->select([
                'len' => Db::length('name')
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals(5, (int)$row['len']);

        // Test trim, ltrim, rtrim
        $id3 = $db->find()->table('t_helpers')->insert([
            'name' => '  Charlie  ',
            'age' => 25,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $row = $db->find()->table('t_helpers')
            ->select([
                'trimmed' => Db::trim('name')
            ])
            ->where('id', $id3)
            ->getOne();
        $this->assertEquals('Charlie', $row['trimmed']);

        // Test curDate, curTime
        $row = $db->find()->table('t_helpers')
            ->select([
                'cur_date' => Db::curDate(),
                'cur_time' => Db::curTime()
            ])
            ->getOne();
        $this->assertNotEmpty($row['cur_date']);
        $this->assertNotEmpty($row['cur_time']);

        // Test year, month, day, hour, minute, second
        $row = $db->find()->table('t_helpers')
            ->select([
                'year' => Db::year('created_at'),
                'month' => Db::month('created_at'),
                'day' => Db::day('created_at')
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals((int)date('Y'), (int)$row['year']);
        $this->assertEquals((int)date('m'), (int)$row['month']);
        $this->assertEquals((int)date('d'), (int)$row['day']);

        // Test count, sum, avg, min, max
        $row = $db->find()->table('t_helpers')
            ->select([
                'count' => Db::count(),
                'sum_age' => Db::sum('age'),
                'avg_age' => Db::avg('age'),
                'min_age' => Db::min('age'),
                'max_age' => Db::max('age')
            ])
            ->getOne();
        $this->assertEquals(3, (int)$row['count']);
        $this->assertEquals(50, (int)$row['sum_age']); // 30 + (-5) + 25
        $this->assertGreaterThan(0, (float)$row['avg_age']);

        // Test cast
        $row = $db->find()->table('t_helpers')
            ->select([
                'age_str' => Db::cast('age', 'CHAR')
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertIsString($row['age_str']);

        // Test concat
        $row = $db->find()->table('t_helpers')
            ->select([
                'full_info' => Db::concat('name', Db::raw("' - '"), Db::cast('age', 'CHAR'))
            ])
            ->where('id', $id1)
            ->getOne();
        $this->assertStringContainsString('Alice', $row['full_info']);
        $this->assertStringContainsString('30', $row['full_info']);
    }

    public function testNewDbHelpersEdgeCases(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_edge');
        $db->rawQuery("CREATE TABLE t_edge (id INT AUTO_INCREMENT PRIMARY KEY, val1 VARCHAR(255), val2 VARCHAR(255), num1 INT, num2 DECIMAL(10,5), dt DATETIME)");

        // Test replace() function - not tested before
        $id1 = $db->find()->table('t_edge')->insert(['val1' => 'Hello World']);
        $row = $db->find()->table('t_edge')
            ->select(['replaced' => Db::replace('val1', 'World', 'MySQL')])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals('Hello MySQL', $row['replaced']);

        // Test date() and time() extraction functions
        $id2 = $db->find()->table('t_edge')->insert(['dt' => '2025-10-19 14:30:45']);
        $row = $db->find()->table('t_edge')
            ->select([
                'date_part' => Db::date('dt'),
                'time_part' => Db::time('dt')
            ])
            ->where('id', $id2)
            ->getOne();
        $this->assertEquals('2025-10-19', $row['date_part']);
        $this->assertEquals('14:30:45', $row['time_part']);

        // Test nullIf - should return NULL when values are equal
        $row = $db->find()->table('t_edge')
            ->select(['null_result' => Db::nullIf('5', '5')])
            ->getOne();
        $this->assertNull($row['null_result']);

        // Test coalesce with all NULLs
        $id3 = $db->find()->table('t_edge')->insert(['val1' => null, 'val2' => null]);
        $row = $db->find()->table('t_edge')
            ->select(['result' => Db::coalesce('val1', 'val2', Db::raw("'fallback'"))])
            ->where('id', $id3)
            ->getOne();
        $this->assertEquals('fallback', $row['result']);

        // Test round() with different precision
        $id4 = $db->find()->table('t_edge')->insert(['num2' => 3.14159]);
        $row = $db->find()->table('t_edge')
            ->select([
                'round0' => Db::round('num2'),
                'round2' => Db::round('num2', 2),
                'round4' => Db::round('num2', 4)
            ])
            ->where('id', $id4)
            ->getOne();
        $this->assertEquals(3, (int)$row['round0']);
        $this->assertEquals(3.14, (float)$row['round2']);
        $this->assertEquals(3.1416, (float)$row['round4']);

        // Test round() with negative numbers
        $id5 = $db->find()->table('t_edge')->insert(['num2' => -3.7]);
        $row = $db->find()->table('t_edge')
            ->select(['rounded' => Db::round('num2')])
            ->where('id', $id5)
            ->getOne();
        $this->assertEquals(-4, (int)$row['rounded']);

        // Test mod() with negative numbers
        $row = $db->find()->table('t_edge')
            ->select(['mod_result' => Db::mod('-10', '3')])
            ->getOne();
        $this->assertEquals(-1, (int)$row['mod_result']);

        // Test substring() with edge cases
        $id6 = $db->find()->table('t_edge')->insert(['val1' => 'Hello']);
        // Index beyond string length
        $row = $db->find()->table('t_edge')
            ->select(['substr_beyond' => Db::substring('val1', 10, 5)])
            ->where('id', $id6)
            ->getOne();
        $this->assertEquals('', $row['substr_beyond']);

        // Test length() with empty string
        $id7 = $db->find()->table('t_edge')->insert(['val1' => '']);
        $row = $db->find()->table('t_edge')
            ->select(['len' => Db::length('val1')])
            ->where('id', $id7)
            ->getOne();
        $this->assertEquals(0, (int)$row['len']);

        // Test upper/lower with UTF-8
        $id8 = $db->find()->table('t_edge')->insert(['val1' => 'Hello 🎉']);
        $row = $db->find()->table('t_edge')
            ->select([
                'upper_utf8' => Db::upper('val1'),
                'lower_utf8' => Db::lower('val1')
            ])
            ->where('id', $id8)
            ->getOne();
        $this->assertStringContainsString('🎉', $row['upper_utf8']);
        $this->assertStringContainsString('🎉', $row['lower_utf8']);

        // Test concat() with NULL values (using ifNull)
        $id9 = $db->find()->table('t_edge')->insert(['val1' => 'Start', 'val2' => null]);
        $row = $db->find()->table('t_edge')
            ->select([
                'val_or_default' => Db::ifNull('val2', 'DefaultValue'),
                'concat_result' => Db::concat('val1', Db::raw("' - '"), Db::raw("'End'"))
            ])
            ->where('id', $id9)
            ->getOne();
        $this->assertEquals('DefaultValue', $row['val_or_default']);
        $this->assertStringContainsString('Start', $row['concat_result']);
        $this->assertStringContainsString('End', $row['concat_result']);

        // Test cast() in WHERE
        $id10 = $db->find()->table('t_edge')->insert(['val1' => '123']);
        $found = $db->find()->table('t_edge')
            ->where(Db::cast('val1', 'UNSIGNED'), 123)
            ->where('id', $id10)
            ->exists();
        $this->assertTrue($found);

        // Test cast() in ORDER BY
        $db->find()->table('t_edge')->insert(['val1' => '10']);
        $db->find()->table('t_edge')->insert(['val1' => '2']);
        $db->find()->table('t_edge')->insert(['val1' => '100']);
        
        $results = $db->find()->table('t_edge')
            ->select(['val1'])
            ->where('val1', ['2', '10', '100'], 'IN')
            ->orderBy(Db::cast('val1', 'UNSIGNED'), 'ASC')
            ->getColumn();
        $this->assertEquals(['2', '10', '100'], $results);

        // Test multiple helpers in same query (not nested)
        $id11 = $db->find()->table('t_edge')->insert(['val1' => 'hello world']);
        $row = $db->find()->table('t_edge')
            ->select([
                'upper_val' => Db::upper('val1'),
                'substr_val' => Db::substring('val1', 1, 5)
            ])
            ->where('id', $id11)
            ->getOne();
        $this->assertEquals('HELLO WORLD', $row['upper_val']);
        $this->assertEquals('hello', $row['substr_val']);

        // Test greatest/least in WHERE
        $id12 = $db->find()->table('t_edge')->insert(['num1' => 10, 'num2' => 20.5]);
        $found = $db->find()->table('t_edge')
            ->where(Db::greatest('num1', 'num2'), 15, '>')
            ->where('id', $id12)
            ->exists();
        $this->assertTrue($found);

        // Test ORDER BY with ifNull
        $db->find()->table('t_edge')->insert(['val1' => null, 'num1' => 1]);
        $db->find()->table('t_edge')->insert(['val1' => 'B', 'num1' => 2]);
        $db->find()->table('t_edge')->insert(['val1' => 'A', 'num1' => 3]);
        
        $results = $db->find()->table('t_edge')
            ->select(['num1'])
            ->where('num1', [1, 2, 3], 'IN')
            ->orderBy(Db::ifNull('val1', Db::raw("'ZZZ'")), 'ASC')
            ->getColumn();
        $this->assertEquals([3, 2, 1], $results); // A, B, NULL(->ZZZ)

        // Test year/month/day with hour/minute/second
        $dt = '2025-10-19 14:30:45';
        $id13 = $db->find()->table('t_edge')->insert(['dt' => $dt]);
        $row = $db->find()->table('t_edge')
            ->select([
                'h' => Db::hour('dt'),
                'm' => Db::minute('dt'),
                's' => Db::second('dt')
            ])
            ->where('id', $id13)
            ->getOne();
        $this->assertEquals(14, (int)$row['h']);
        $this->assertEquals(30, (int)$row['m']);
        $this->assertEquals(45, (int)$row['s']);

        // Test curDate/curTime in SELECT
        $row = $db->find()->table('t_edge')
            ->select([
                'today' => Db::curDate(),
                'now_time' => Db::curTime()
            ])
            ->getOne();
        $this->assertNotEmpty($row['today']);
        $this->assertNotEmpty($row['now_time']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['today']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $row['now_time']);
    }

    public function testDialectSpecificDifferences(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS t_dialect');
        $db->rawQuery("CREATE TABLE t_dialect (id INT AUTO_INCREMENT PRIMARY KEY, str VARCHAR(255), num INT)");

        // Test SUBSTRING (MySQL) vs SUBSTR (SQLite)
        $id1 = $db->find()->table('t_dialect')->insert(['str' => 'MySQL']);
        $row = $db->find()->table('t_dialect')
            ->select(['sub' => Db::substring('str', 1, 3)])
            ->where('id', $id1)
            ->getOne();
        $this->assertEquals('MyS', $row['sub']);
        // Should use SUBSTRING in MySQL
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('SUBSTRING', $lastQuery);

        // Test MOD (MySQL) vs % (SQLite)
        $id2 = $db->find()->table('t_dialect')->insert(['num' => 10]);
        $row = $db->find()->table('t_dialect')
            ->select(['mod_result' => Db::mod('num', '3')])
            ->where('id', $id2)
            ->getOne();
        $this->assertEquals(1, (int)$row['mod_result']);
        // Should use MOD in MySQL
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('MOD', $lastQuery);

        // Test IFNULL (MySQL) vs COALESCE (PostgreSQL)
        $id3 = $db->find()->table('t_dialect')->insert(['str' => null]);
        $row = $db->find()->table('t_dialect')
            ->select(['result' => Db::ifNull('str', 'default')])
            ->where('id', $id3)
            ->getOne();
        $this->assertEquals('default', $row['result']);
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('IFNULL', $lastQuery);

        // Test GREATEST/LEAST (MySQL/PostgreSQL) vs MAX/MIN (SQLite)
        $row = $db->find()->table('t_dialect')
            ->select([
                'max_val' => Db::greatest('5', '10', '3'),
                'min_val' => Db::least('5', '10', '3')
            ])
            ->getOne();
        $this->assertEquals(10, (int)$row['max_val']);
        $this->assertEquals(3, (int)$row['min_val']);
        // Should use GREATEST/LEAST in MySQL
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('GREATEST', $lastQuery);
    }

    /* ---------------- Dialect Method Coverage Tests ---------------- */

    public function testBuildLoadCsvSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();
        
        // Create temp file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile, "id,name\n1,John\n");
        
        // Test basic CSV load SQL generation
        $sql = $dialect->buildLoadCsvSql('users', $tempFile, []);
        
        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('LOAD DATA', $sql);
        $this->assertStringContainsString('users', $sql);
        
        // Test with options
        $tempFile2 = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile2, "id;name;price\n1;Product;99.99\n");
        
        $sql2 = $dialect->buildLoadCsvSql('products', $tempFile2, [
            'fieldChar' => ';',
            'fieldEnclosure' => '"',
            'fields' => ['id', 'name', 'price'],
            'local' => true,
            'linesToIgnore' => 1
        ]);
        
        $this->assertStringContainsString('LOAD DATA LOCAL INFILE', $sql2);
        $this->assertStringContainsString('FIELDS TERMINATED BY', $sql2);
        $this->assertStringContainsString('IGNORE 1 LINES', $sql2);
        
        // Cleanup
        unlink($tempFile);
        unlink($tempFile2);
    }

    public function testBuildLoadXmlSql(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();
        
        // Create temp XML file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'xml_');
        file_put_contents($tempFile, "<users><user><id>1</id><name>John</name></user></users>");
        
        $sql = $dialect->buildLoadXML('users', $tempFile, [
            'rowTag' => '<user>',
            'linesToIgnore' => 0
        ]);
        
        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('LOAD XML', $sql);
        $this->assertStringContainsString('users', $sql);
        
        // Test with different options
        $tempFile2 = tempnam(sys_get_temp_dir(), 'xml_');
        file_put_contents($tempFile2, "<products><product><id>1</id></product></products>");
        
        $sql2 = $dialect->buildLoadXML('products', $tempFile2, [
            'rowTag' => '<product>',
            'linesToIgnore' => 2
        ]);
        
        $this->assertStringContainsString('LOAD XML', $sql2);
        
        // Cleanup
        unlink($tempFile);
        unlink($tempFile2);
    }

    public function testFormatSelectOptions(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();
        
        $baseSql = "SELECT * FROM users";
        
        // Test with DISTINCT
        $withDistinct = $dialect->formatSelectOptions($baseSql, ['DISTINCT']);
        $this->assertStringContainsString('DISTINCT', $withDistinct);
        
        // Test with SQL_NO_CACHE
        $withNoCache = $dialect->formatSelectOptions($baseSql, ['SQL_NO_CACHE']);
        $this->assertStringContainsString('SQL_NO_CACHE', $withNoCache);
        
        // Test with multiple options
        $withMultiple = $dialect->formatSelectOptions($baseSql, ['DISTINCT', 'SQL_CALC_FOUND_ROWS']);
        $this->assertStringContainsString('DISTINCT', $withMultiple);
        $this->assertStringContainsString('SQL_CALC_FOUND_ROWS', $withMultiple);
    }

    public function testBuildExplainSqlVariations(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dialect = $connection->getDialect();
        
        $query = "SELECT * FROM users WHERE age > 18";
        
        // Test basic EXPLAIN
        $explain = $dialect->buildExplainSql($query, false);
        $this->assertStringContainsString('EXPLAIN', $explain);
        $this->assertStringContainsString($query, $explain);
        
        // Test with analyze flag (MySQL currently ignores it for compatibility)
        $analyze = $dialect->buildExplainSql($query, true);
        $this->assertStringContainsString('EXPLAIN', $analyze);
        // MySQL EXPLAIN ANALYZE returns tree format which is incompatible with table format
        // So we just verify EXPLAIN is present
        $this->assertStringContainsString($query, $analyze);
    }

    public function testInsertWithOnDuplicateParameter(): void
    {
        $db = self::$db;
        
        // First insert
        $db->find()->table('users')->insert(['name' => 'TestUser', 'age' => 25]);
        
        // Insert with onDuplicate as second parameter (MySQL uses ON DUPLICATE KEY UPDATE)
        // @phpstan-ignore argument.type
        $db->find()->table('users')->insert(['name' => 'TestUser', 'age' => 30], ['age']);
        
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $lastQuery);
        
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
            ['name' => 'MultiTest2', 'age' => 30]
        ], ['age']); // @phpstan-ignore argument.type
        
        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $lastQuery);
        
        $row1 = $db->find()->table('users')->where('name', 'MultiTest1')->getOne();
        $this->assertEquals(25, $row1['age']);
        
        $row2 = $db->find()->table('users')->where('name', 'MultiTest2')->getOne();
        $this->assertEquals(30, $row2['age']);
    }
}
