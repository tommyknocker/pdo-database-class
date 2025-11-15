<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PdoDb;

/**
 * Base class for CLI commands.
 *
 * Provides common functionality for all CLI commands including
 * database connection loading and configuration management.
 */
abstract class BaseCliCommand
{
    /**
     * Load database configuration from .env file, config files or environment.
     *
     * @return array<string, mixed>
     */
    protected static function loadDatabaseConfig(): array
    {
        // Load .env file from current working directory
        static::loadEnvFile();

        // Get driver from environment
        $driver = mb_strtolower(getenv('PDODB_DRIVER') ?: '', 'UTF-8');

        // Build config from environment variables
        $config = static::buildConfigFromEnv($driver);
        if ($config !== null) {
            return $config;
        }

        // Try config/db.php
        $rootConfig = getcwd() . '/config/db.php';
        if (file_exists($rootConfig)) {
            $config = require $rootConfig;
            // Config should directly contain driver and connection settings
            if (isset($config['driver'])) {
                return $config;
            }
            // Backward compatibility: check if it's an array with driver keys
            if (isset($config[$driver])) {
                return $config[$driver];
            }
            // If driver not specified, check if sqlite config exists (for backward compatibility)
            if (isset($config['sqlite'])) {
                static::warning('Using SQLite configuration from config/db.php. Consider setting PDODB_DRIVER environment variable or using direct driver configuration.');
                return $config['sqlite'];
            }
        }

        // Try examples config (for testing only)
        if ($driver !== '') {
            $configFile = __DIR__ . "/../../examples/config.{$driver}.php";
            if (file_exists($configFile)) {
                return require $configFile;
            }
        }

        // Throw error instead of fallback
        static::error("Database configuration not found.\n\n" .
            "Please configure your database connection:\n" .
            "  1. Create a .env file in your project root with:\n" .
            "     PDODB_DRIVER=mysql|mariadb|pgsql|sqlite|sqlsrv\n" .
            "     PDODB_HOST=localhost\n" .
            "     PDODB_DATABASE=your_database\n" .
            "     PDODB_USERNAME=your_username\n" .
            "     PDODB_PASSWORD=your_password\n\n" .
            "  2. Or create a config/db.php file in your project root with:\n" .
            "     <?php\n" .
            "     return [\n" .
            "         'driver' => 'mysql',\n" .
            "         'host' => 'localhost',\n" .
            "         'database' => 'your_database',\n" .
            "         'username' => 'your_username',\n" .
            "         'password' => 'your_password',\n" .
            "     ];\n\n" .
            "  3. Or set PDODB_DRIVER environment variable\n\n" .
            'For more information, see: documentation/05-advanced-features/21-cli-tools.md');
    }

    /**
     * Load .env file from current working directory.
     */
    protected static function loadEnvFile(): void
    {
        $envFile = getcwd() . '/.env';
        if (!file_exists($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments
            if (str_starts_with($line, '#')) {
                continue;
            }
            // Parse KEY=VALUE
            $equalsPos = strpos($line, '=');
            if ($equalsPos !== false && $equalsPos > 0) {
                $keyPart = substr($line, 0, $equalsPos);
                $valuePart = substr($line, $equalsPos + 1);
                // substr can only return false if start > length, which is impossible here
                /** @var string $keyPart */
                /** @var string $valuePart */
                $key = trim($keyPart);
                $value = trim($valuePart);
                // Remove quotes if present
                $value = trim($value, '"\'');
                // Set environment variable if not already set
                if (getenv($key) === false) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    /**
     * Build configuration from environment variables.
     *
     * @param string $driver Database driver
     *
     * @return array<string, mixed>|null
     */
    protected static function buildConfigFromEnv(string $driver): ?array
    {
        // Return null if driver is empty
        if ($driver === '') {
            return null;
        }

        $config = ['driver' => $driver];

        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                $host = getenv('PDODB_HOST');
                $host = $host !== false ? $host : 'localhost';
                $port = getenv('PDODB_PORT');
                $port = $port !== false ? $port : '3306';
                $database = getenv('PDODB_DATABASE');
                $username = getenv('PDODB_USERNAME');
                $password = getenv('PDODB_PASSWORD');
                $charset = getenv('PDODB_CHARSET');
                $charset = $charset !== false ? $charset : 'utf8mb4';

                if ($database === false || $username === false) {
                    return null;
                }

                $config['host'] = $host;
                $config['port'] = (int)$port;
                $config['database'] = $database;
                $config['username'] = $username;
                $config['password'] = $password !== false ? $password : '';
                $config['charset'] = $charset;
                break;

            case 'pgsql':
                $host = getenv('PDODB_HOST');
                $host = $host !== false ? $host : 'localhost';
                $port = getenv('PDODB_PORT');
                $port = $port !== false ? $port : '5432';
                $database = getenv('PDODB_DATABASE');
                $username = getenv('PDODB_USERNAME');
                $password = getenv('PDODB_PASSWORD');

                if ($database === false || $username === false) {
                    return null;
                }

                $config['host'] = $host;
                $config['port'] = (int)$port;
                $config['database'] = $database;
                $config['username'] = $username;
                $config['password'] = $password !== false ? $password : '';
                break;

            case 'sqlsrv':
                $host = getenv('PDODB_HOST');
                $host = $host !== false ? $host : 'localhost';
                $port = getenv('PDODB_PORT');
                $port = $port !== false ? $port : '1433';
                $database = getenv('PDODB_DATABASE');
                $username = getenv('PDODB_USERNAME');
                $password = getenv('PDODB_PASSWORD');

                if ($database === false || $username === false) {
                    return null;
                }

                $config['host'] = $host;
                $config['port'] = (int)$port;
                $config['database'] = $database;
                $config['username'] = $username;
                $config['password'] = $password !== false ? $password : '';
                break;

            case 'sqlite':
                $path = getenv('PDODB_PATH');
                $path = $path !== false ? $path : ':memory:';
                $config['path'] = $path;
                break;
        }

        return $config;
    }

    /**
     * Create database instance from configuration.
     *
     * @return PdoDb
     */
    protected static function createDatabase(): PdoDb
    {
        $config = static::loadDatabaseConfig();
        $driver = $config['driver'];
        unset($config['driver']);

        return new PdoDb($driver, $config);
    }

    /**
     * Get database driver name.
     *
     * @param PdoDb $db Database instance
     *
     * @return string
     */
    protected static function getDriverName(PdoDb $db): string
    {
        return $db->schema()->getDialect()->getDriverName();
    }

    /**
     * Read input from stdin.
     *
     * @param string $prompt Prompt message
     * @param string|null $default Default value
     *
     * @return string
     */
    protected static function readInput(string $prompt, ?string $default = null): string
    {
        // Check if non-interactive mode is enabled (for tests, CI, etc.)
        // Check for PDODB_NON_INTERACTIVE env var or if STDIN is not a tty
        $nonInteractive = getenv('PDODB_NON_INTERACTIVE') !== false
            || getenv('PHPUNIT') !== false
            || !stream_isatty(STDIN);

        if ($nonInteractive) {
            if ($default !== null) {
                return $default;
            }
            // If no default and not interactive, return empty string
            return '';
        }

        $defaultText = $default !== null ? " [{$default}]" : '';
        echo $prompt . $defaultText . ': ';
        flush();
        $input = trim((string)fgets(STDIN));
        return $input !== '' ? $input : ($default ?? '');
    }

    /**
     * Read yes/no confirmation from stdin.
     *
     * @param string $prompt Prompt message
     * @param bool $default Default value
     *
     * @return bool
     */
    protected static function readConfirmation(string $prompt, bool $default = true): bool
    {
        // Check if non-interactive mode is enabled (for tests, CI, etc.)
        // Check for PDODB_NON_INTERACTIVE env var or if STDIN is not a tty
        $nonInteractive = getenv('PDODB_NON_INTERACTIVE') !== false
            || getenv('PHPUNIT') !== false
            || !stream_isatty(STDIN);

        if ($nonInteractive) {
            return $default;
        }

        $defaultText = $default ? 'Y/n' : 'y/N';
        echo $prompt . " [{$defaultText}]: ";
        flush();
        $input = strtolower(trim((string)fgets(STDIN)));

        if ($input === '') {
            return $default;
        }

        return in_array($input, ['y', 'yes', '1', 'true'], true);
    }

    /**
     * Display error message and exit.
     *
     * @param string $message Error message
     * @param int $code Exit code
     *
     * @return never
     */
    public static function error(string $message, int $code = 1): never
    {
        echo "Error: {$message}\n";
        exit($code);
    }

    /**
     * Display success message.
     *
     * @param string $message Success message
     */
    protected static function success(string $message): void
    {
        echo "✓ {$message}\n";
    }

    /**
     * Display info message.
     *
     * @param string $message Info message
     */
    protected static function info(string $message): void
    {
        echo "ℹ {$message}\n";
    }

    /**
     * Display warning message.
     *
     * @param string $message Warning message
     */
    protected static function warning(string $message): void
    {
        echo "⚠ {$message}\n";
    }
}
