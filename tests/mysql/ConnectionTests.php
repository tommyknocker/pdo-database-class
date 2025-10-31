<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDOException;
use PHPUnit\Framework\TestCase;
use StdClass;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * ConnectionTests tests for mysql.
 */
final class ConnectionTests extends BaseMySQLTestCase
{
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
}
