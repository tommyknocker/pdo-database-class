<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\integration;

use InvalidArgumentException;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\mariadb\BaseMariaDBTestCase;
use tommyknocker\pdodb\tests\mssql\BaseMSSQLTestCase;
use tommyknocker\pdodb\tests\mysql\BaseMySQLTestCase;
use tommyknocker\pdodb\tests\oracle\BaseOracleTestCase;
use tommyknocker\pdodb\tests\postgresql\BasePostgreSQLTestCase;

/**
 * The databases the integration tests run against. Each test names its datasets after them (`with data set "mysql"`), so
 * a CI job that has one database selects its tests with `--filter`.
 *
 * Connection settings come from the per-dialect Base*TestCase, the same ones the dialect suites use.
 */
final class IntegrationDatabases
{
    /** @var array<string, array{driver: string, settings: (callable(): array<string, mixed>)|null}> */
    private const array DATABASES = [
        'sqlite' => ['driver' => 'sqlite', 'settings' => null],
        'mysql' => ['driver' => 'mysql', 'settings' => [BaseMySQLTestCase::class, 'connectionConfig']],
        'mariadb' => ['driver' => 'mariadb', 'settings' => [BaseMariaDBTestCase::class, 'connectionConfig']],
        'postgresql' => ['driver' => 'pgsql', 'settings' => [BasePostgreSQLTestCase::class, 'connectionConfig']],
        'mssql' => ['driver' => 'sqlsrv', 'settings' => [BaseMSSQLTestCase::class, 'connectionConfig']],
        'oracle' => ['driver' => 'oci', 'settings' => [BaseOracleTestCase::class, 'connectionConfig']],
    ];

    /**
     * Data sets for a test, one per database, named after it.
     *
     * @param list<string>|null $only A subset, for a test that only some databases can run
     *
     * @return array<string, array{0: string}>
     */
    public static function provider(?array $only = null): array
    {
        $sets = [];
        foreach ($only ?? array_keys(self::DATABASES) as $name) {
            if (!isset(self::DATABASES[$name])) {
                throw new InvalidArgumentException("Unknown integration database '{$name}'");
            }
            $sets[$name] = [$name];
        }

        return $sets;
    }

    public static function connect(string $name): PdoDb
    {
        $database = self::DATABASES[$name] ?? throw new InvalidArgumentException("Unknown integration database '{$name}'");
        if ($database['settings'] === null) {
            return new PdoDb($database['driver'], ['path' => ':memory:']);
        }

        return new PdoDb($database['driver'], ($database['settings'])() + ['enable_regexp' => false]);
    }
}
