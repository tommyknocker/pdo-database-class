<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * ConnectionTests tests for sqlite.
 */
final class ConnectionTests extends BaseSqliteTestCase
{
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

    public function testDisconnectAndPing(): void
    {
    self::$db->disconnect();
    $this->assertFalse(self::$db->ping());
    self::$db = new PdoDb('sqlite', [
    'path' => ':memory:',
    ]);
    $this->assertTrue(self::$db->ping());
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
}
