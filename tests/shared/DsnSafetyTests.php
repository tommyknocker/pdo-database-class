<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\mariadb\MariaDBDialect;
use tommyknocker\pdodb\dialects\mssql\MSSQLDialect;
use tommyknocker\pdodb\dialects\mysql\MySQLDialect;
use tommyknocker\pdodb\dialects\oracle\OracleDialect;
use tommyknocker\pdodb\dialects\postgresql\PostgreSQLDialect;

final class DsnSafetyTests extends TestCase
{
    /**
     * @return array<string, array{0: DialectInterface, 1: array<string, string>}>
     */
    public static function dialects(): array
    {
        $base = ['host' => 'db.internal', 'dbname' => 'app', 'username' => 'u', 'password' => 'p'];

        return [
            'mysql' => [new MySQLDialect(), $base],
            'mariadb' => [new MariaDBDialect(), $base],
            'pgsql' => [new PostgreSQLDialect(), $base],
            'sqlsrv' => [new MSSQLDialect(), $base],
            'oci' => [new OracleDialect(), $base + ['service_name' => 'ORCL']],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('dialects')]
    public function testSeparatorInADatabaseNameIsRejected(DialectInterface $dialect, array $params): void
    {
        $this->assertNotSame('', $dialect->buildDsn($params));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain');
        $dialect->buildDsn(['dbname' => 'app;host=elsewhere', 'service_name' => 'ORCL;host=elsewhere'] + $params);
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('dialects')]
    public function testSeparatorInAHostIsRejected(DialectInterface $dialect, array $params): void
    {
        $this->expectException(InvalidArgumentException::class);
        $dialect->buildDsn(['host' => "db.internal;dbname=other\n"] + $params);
    }
}
