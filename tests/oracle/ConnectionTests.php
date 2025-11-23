<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use InvalidArgumentException;
use tommyknocker\pdodb\PdoDb;

/**
 * ConnectionTests tests for Oracle.
 */
final class ConnectionTests extends BaseOracleTestCase
{
    public function testOracleMinimalParams(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dsn = $connection->getDialect()->buildDsn([
            'driver' => 'oci',
            'host' => 'localhost',
            'port' => 1521,
            'username' => 'testuser',
            'password' => 'testpass',
            'service_name' => 'XE',
        ]);
        $this->assertStringContainsString('oci:dbname=//localhost:1521/XE', $dsn);
    }

    public function testOracleAllParams(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dsn = $connection->getDialect()->buildDsn([
            'driver' => 'oci',
            'host' => 'localhost',
            'port' => 1521,
            'username' => 'testuser',
            'password' => 'testpass',
            'service_name' => 'XE',
            'charset' => 'AL32UTF8',
        ]);
        $this->assertStringContainsString('oci:dbname=//localhost:1521/XE', $dsn);
        $this->assertStringContainsString('charset=AL32UTF8', $dsn);
    }

    public function testOracleWithSid(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $dsn = $connection->getDialect()->buildDsn([
            'driver' => 'oci',
            'host' => 'localhost',
            'port' => 1521,
            'username' => 'testuser',
            'password' => 'testpass',
            'sid' => 'ORCL',
        ]);
        $this->assertStringContainsString('oci:dbname=//localhost:1521/ORCL', $dsn);
    }

    public function testOracleMissingParamsThrows(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);
        $this->expectException(InvalidArgumentException::class);
        $connection->getDialect()->buildDsn(['driver' => 'oci']); // no host/service_name
    }

    public function testDisconnectAndPing(): void
    {
        self::$db->disconnect();
        $this->assertFalse(self::$db->ping());
        $host = getenv('PDODB_HOST') ?: self::DB_HOST;
        $port = (int)(getenv('PDODB_PORT') ?: (string)self::DB_PORT);
        $user = getenv('PDODB_USERNAME') ?: self::DB_USER;
        $password = getenv('PDODB_PASSWORD') ?: self::DB_PASSWORD;
        $serviceName = getenv('PDODB_SERVICE_NAME') ?: getenv('PDODB_SID') ?: self::DB_SERVICE_NAME;
        $charset = getenv('PDODB_CHARSET') ?: self::DB_CHARSET;
        
        self::$db = new PdoDb('oci', [
            'host' => $host,
            'port' => $port,
            'username' => $user,
            'password' => $password,
            'service_name' => $serviceName,
            'charset' => $charset,
        ]);
        $this->assertTrue(self::$db->ping());
    }

    public function testAddConnectionAndSwitch(): void
    {
        $host = getenv('PDODB_HOST') ?: self::DB_HOST;
        $port = (int)(getenv('PDODB_PORT') ?: (string)self::DB_PORT);
        $user = getenv('PDODB_USERNAME') ?: self::DB_USER;
        $password = getenv('PDODB_PASSWORD') ?: self::DB_PASSWORD;
        $serviceName = getenv('PDODB_SERVICE_NAME') ?: getenv('PDODB_SID') ?: self::DB_SERVICE_NAME;
        $charset = getenv('PDODB_CHARSET') ?: self::DB_CHARSET;
        
        self::$db->addConnection('secondary', [
            'driver' => 'oci',
            'host' => $host,
            'port' => $port,
            'username' => $user,
            'password' => $password,
            'service_name' => $serviceName,
            'charset' => $charset,
        ]);

        $pdoDb = self::$db->connection('secondary');
        $this->assertSame(self::$db, $pdoDb);

        $pdoDb = self::$db->connection('default');
        $this->assertSame(self::$db, $pdoDb);
    }
}
