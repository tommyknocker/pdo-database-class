<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use InvalidArgumentException;
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

    public function testSqliteInvalidCacheThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SQLite cache parameter');
        self::$db->connection->getDialect()->buildDsn([
            'driver' => 'sqlite',
            'path' => ':memory:',
            'cache' => 'invalid_cache_mode',
        ]);
    }

    public function testSqliteInvalidModeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SQLite mode parameter');
        self::$db->connection->getDialect()->buildDsn([
            'driver' => 'sqlite',
            'path' => ':memory:',
            'mode' => 'invalid_mode',
        ]);
    }

    public function testSqliteValidCacheModes(): void
    {
        $dsn1 = self::$db->connection->getDialect()->buildDsn([
            'driver' => 'sqlite',
            'path' => ':memory:',
            'cache' => 'shared',
        ]);
        $this->assertStringContainsString('cache=shared', $dsn1);

        $dsn2 = self::$db->connection->getDialect()->buildDsn([
            'driver' => 'sqlite',
            'path' => ':memory:',
            'cache' => 'private',
        ]);
        $this->assertStringContainsString('cache=private', $dsn2);
    }

    public function testSqliteValidModeValues(): void
    {
        $validModes = ['ro', 'rw', 'rwc', 'memory'];
        foreach ($validModes as $mode) {
            $dsn = self::$db->connection->getDialect()->buildDsn([
                'driver' => 'sqlite',
                'path' => ':memory:',
                'mode' => $mode,
            ]);
            $this->assertStringContainsString("mode={$mode}", $dsn);
        }
    }

    public function testSqliteCacheArrayIgnored(): void
    {
        // Array cache config is for query result caching, not SQLite DSN
        $dsn = self::$db->connection->getDialect()->buildDsn([
            'driver' => 'sqlite',
            'path' => ':memory:',
            'cache' => ['prefix' => 'test_'],
        ]);
        $this->assertStringNotContainsString('cache=', $dsn);
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
