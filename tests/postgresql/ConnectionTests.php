<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use InvalidArgumentException;
use tommyknocker\pdodb\PdoDb;

/**
 * ConnectionTests tests for postgresql.
 */
final class ConnectionTests extends BasePostgreSQLTestCase
{
    public function testPgsqlMinimalParams(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dsn = $connection->getDialect()->buildDsn([
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
        $connection = self::$db->connection;
        assert($connection !== null);
        $dsn = $connection->getDialect()->buildDsn([
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
        $connection = self::$db->connection;
        assert($connection !== null);
        $this->expectException(InvalidArgumentException::class);
        $connection->getDialect()->buildDsn(['driver' => 'pgsql']); // no host/dbname
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
}
