<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use InvalidArgumentException;
use tommyknocker\pdodb\PdoDb;

/**
 * ConnectionTests tests for mssql.
 */
final class ConnectionTests extends BaseMSSQLTestCase
{
    public function testMssqlMinimalParams(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dsn = $connection->getDialect()->buildDsn([
            'driver' => 'sqlsrv',
            'host' => 'localhost',
            'username' => 'testuser',
            'password' => 'testpass',
            'dbname' => 'testdb',
        ]);
        $this->assertStringContainsString('sqlsrv:Server=localhost,1433;Database=testdb', $dsn);
        $this->assertStringContainsString('TrustServerCertificate=yes', $dsn);
        $this->assertStringContainsString('Encrypt=yes', $dsn);
    }

    public function testMssqlAllParams(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dsn = $connection->getDialect()->buildDsn([
            'driver' => 'sqlsrv',
            'host' => 'localhost',
            'username' => 'testuser',
            'password' => 'testpass',
            'dbname' => 'testdb',
            'port' => 1433,
            'trust_server_certificate' => false,
            'encrypt' => false,
        ]);
        $this->assertStringContainsString('sqlsrv:Server=localhost,1433;Database=testdb', $dsn);
        $this->assertStringContainsString('TrustServerCertificate=no', $dsn);
        $this->assertStringContainsString('Encrypt=no', $dsn);
    }

    public function testMssqlMissingParamsThrows(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $this->expectException(InvalidArgumentException::class);
        $connection->getDialect()->buildDsn(['driver' => 'sqlsrv']); // no host/dbname
    }

    public function testDisconnectAndPing(): void
    {
        self::$db->disconnect();
        $this->assertFalse(self::$db->ping());
        $username = getenv('DB_USER') ?: self::DB_USER;
        $password = getenv('DB_PASS') ?: self::DB_PASSWORD;
        self::$db = new PdoDb('sqlsrv', [
            'host' => self::DB_HOST,
            'port' => self::DB_PORT,
            'username' => $username,
            'password' => $password,
            'dbname' => self::DB_NAME,
            'trust_server_certificate' => true,
            'encrypt' => true,
        ]);
        $this->assertTrue(self::$db->ping());
    }

    public function testAddConnectionAndSwitch(): void
    {
        $username = getenv('DB_USER') ?: self::DB_USER;
        $password = getenv('DB_PASS') ?: self::DB_PASSWORD;
        self::$db->addConnection('secondary', [
            'driver' => 'sqlsrv',
            'host' => self::DB_HOST,
            'port' => self::DB_PORT,
            'username' => $username,
            'password' => $password,
            'dbname' => self::DB_NAME,
            'trust_server_certificate' => true,
            'encrypt' => true,
        ]);

        $pdoDb = self::$db->connection('secondary');
        $this->assertSame(self::$db, $pdoDb);

        $pdoDb = self::$db->connection('default');
        $this->assertSame(self::$db, $pdoDb);
    }
}
