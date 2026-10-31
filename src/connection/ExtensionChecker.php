<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use InvalidArgumentException;

/**
 * Extension checker for PDO database drivers.
 */
class ExtensionChecker
{
    /**
     * Check if extension is available for driver.
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
     */
    public static function getRequiredExtension(string $driver): ?string
    {
        $driver = mb_strtolower($driver, 'UTF-8');
        if (!DialectRegistry::isSupported($driver)) {
            return null;
        }

        return DialectRegistry::resolve($driver)->requiredPdoExtension();
    }

    /**
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
                'Please install it using: ' . static::getInstallCommand($extension)
            );
        }
    }

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
     * @return list<string>
     */
    public static function getAvailableDrivers(): array
    {
        $available = [];
        foreach (DialectRegistry::getSupportedDrivers() as $driver) {
            if (static::isAvailable($driver)) {
                $available[] = $driver;
            }
        }

        return $available;
    }

    /**
     * @return list<string>
     */
    public static function getSupportedDrivers(): array
    {
        return DialectRegistry::getSupportedDrivers();
    }
}
