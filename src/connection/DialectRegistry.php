<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use InvalidArgumentException;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\mariadb\MariaDBDialect;
use tommyknocker\pdodb\dialects\mssql\MSSQLDialect;
use tommyknocker\pdodb\dialects\mysql\MySQLDialect;
use tommyknocker\pdodb\dialects\oracle\OracleDialect;
use tommyknocker\pdodb\dialects\postgresql\PostgreSQLDialect;
use tommyknocker\pdodb\dialects\sqlite\SqliteDialect;

/**
 * DialectRegistry class.
 *
 * Manages database dialect registration and resolution.
 * Provides extensible dialect resolution following the Open/Closed principle.
 */
class DialectRegistry
{
    /** @var array<string, class-string<DialectInterface>> Registered dialects */
    protected static array $dialects = [
        'mysql' => MySQLDialect::class,
        'mariadb' => MariaDBDialect::class,
        'sqlite' => SqliteDialect::class,
        'pgsql' => PostgreSQLDialect::class,
        'sqlsrv' => MSSQLDialect::class,
        'oci' => OracleDialect::class,
    ];

    /**
     * Register a new dialect for a driver.
     *
     * @param string $driver The driver name
     * @param class-string<DialectInterface> $dialectClass The dialect class
     *
     * @throws InvalidArgumentException If the dialect class doesn't implement DialectInterface
     */
    public static function register(string $driver, string $dialectClass): void
    {
        // Use is_subclass_of with autoload=false to avoid triggering class loading during tests
        // This prevents issues when running multiple test suites (e.g., mariadb + oracle)
        // where class loading order can affect PDO connection initialization
        if (!is_subclass_of($dialectClass, DialectInterface::class)) {
            throw new InvalidArgumentException("Class {$dialectClass} must implement DialectInterface");
        }
        self::$dialects[$driver] = $dialectClass;
    }

    /**
     * Resolve a dialect for the given driver.
     *
     * @param string $driver The driver name
     *
     * @return DialectInterface The resolved dialect instance
     * @throws InvalidArgumentException If the driver is unsupported
     */
    public static function resolve(string $driver): DialectInterface
    {
        if (!isset(self::$dialects[$driver])) {
            throw new InvalidArgumentException('Unsupported driver: ' . $driver);
        }

        $dialectClass = self::$dialects[$driver];
        return new $dialectClass();
    }

    /**
     * Check if a driver is supported.
     *
     * @param string $driver The driver name
     *
     * @return bool True if the driver is supported
     */
    public static function isSupported(string $driver): bool
    {
        return isset(self::$dialects[$driver]);
    }

    /**
     * Get all supported drivers.
     *
     * @return array<string> List of supported driver names
     */
    public static function getSupportedDrivers(): array
    {
        return array_keys(self::$dialects);
    }
}
