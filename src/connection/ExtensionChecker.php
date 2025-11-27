<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use InvalidArgumentException;

/**
 * Extension checker for PDO database drivers.
 *
 * Provides methods to check if required PHP extensions are available
 * for specific database drivers.
 */
class ExtensionChecker
{
    /**
     * Map of driver names to required PHP extensions.
     *
     * @var array<string, string>
     */
    protected static array $driverExtensions = [
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'pgsql' => 'pdo_pgsql',
        'sqlite' => 'pdo_sqlite',
        'sqlsrv' => 'pdo_sqlsrv',
        'oci' => 'pdo_oci',
    ];

    /**
     * Check if extension is available for driver.
     *
     * @param string $driver Database driver name
     *
     * @return bool True if extension is available
     */
    public static function isAvailable(string $driver): bool
    {
        $extension = static::getRequiredExtension($driver);
        if ($extension === null) {
            return false;
        }

        return extension_loaded($extension);
    }

    /**
     * Get required extension name for driver.
     *
     * @param string $driver Database driver name
     *
     * @return string|null Extension name or null if driver is not supported
     */
    public static function getRequiredExtension(string $driver): ?string
    {
        $driver = mb_strtolower($driver, 'UTF-8');
        return static::$driverExtensions[$driver] ?? null;
    }

    /**
     * Validate that required extension is available for driver.
     *
     * @param string $driver Database driver name
     *
     * @throws InvalidArgumentException If extension is not available
     */
    public static function validate(string $driver): void
    {
        $extension = static::getRequiredExtension($driver);
        if ($extension === null) {
            throw new InvalidArgumentException("Unsupported driver: {$driver}");
        }

        if (!extension_loaded($extension)) {
            $driverName = ucfirst($driver);
            throw new InvalidArgumentException(
                "PHP extension '{$extension}' is required for {$driverName} driver but is not installed. " .
                "Please install it using: " . static::getInstallCommand($extension)
            );
        }
    }

    /**
     * Get installation command suggestion for extension.
     *
     * @param string $extension Extension name
     *
     * @return string Installation command suggestion
     */
    protected static function getInstallCommand(string $extension): string
    {
        $commands = [
            'pdo_mysql' => 'sudo apt-get install php8.4-mysql (Ubuntu/Debian) or pecl install pdo_mysql',
            'pdo_pgsql' => 'sudo apt-get install php8.4-pgsql (Ubuntu/Debian) or pecl install pdo_pgsql',
            'pdo_sqlite' => 'sudo apt-get install php8.4-sqlite3 (Ubuntu/Debian) or pecl install pdo_sqlite',
            'pdo_sqlsrv' => 'pecl install pdo_sqlsrv (requires Microsoft ODBC Driver)',
            'pdo_oci' => 'pecl install pdo_oci (requires Oracle Instant Client)',
        ];

        return $commands[$extension] ?? "pecl install {$extension}";
    }

    /**
     * Get all available drivers (with extensions loaded).
     *
     * @return array<string> List of available driver names
     */
    public static function getAvailableDrivers(): array
    {
        $available = [];
        foreach (static::$driverExtensions as $driver => $extension) {
            if (extension_loaded($extension)) {
                $available[] = $driver;
            }
        }
        return $available;
    }

    /**
     * Get all supported drivers (regardless of extension availability).
     *
     * @return array<string> List of supported driver names
     */
    public static function getSupportedDrivers(): array
    {
        return array_keys(static::$driverExtensions);
    }
}

