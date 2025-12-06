<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use Psr\SimpleCache\CacheInterface;
use tommyknocker\pdodb\cache\CacheFactory;
use tommyknocker\pdodb\connection\DialectRegistry;
use tommyknocker\pdodb\connection\EnvConfigLoader;
use tommyknocker\pdodb\connection\EnvLoader;
use tommyknocker\pdodb\connection\ExtensionChecker;
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
        // Load .env file (custom or current working directory)
        static::loadEnvFile();

        // Get driver from environment
        $driver = mb_strtolower(getenv('PDODB_DRIVER') ?: '', 'UTF-8');

        // Try examples config first (for testing/examples) - this takes precedence
        // because examples need to use the same config file that createExampleDb() uses
        // But skip if PDODB_* environment variables are explicitly set (CI or user override)
        $hasEnvConfig = false;
        if ($driver === 'sqlite') {
            $hasEnvConfig = getenv('PDODB_PATH') !== false;
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $hasEnvConfig = getenv('PDODB_DATABASE') !== false && getenv('PDODB_USERNAME') !== false;
        } elseif ($driver === 'pgsql') {
            $hasEnvConfig = getenv('PDODB_DATABASE') !== false && getenv('PDODB_USERNAME') !== false;
        } elseif ($driver === 'sqlsrv') {
            $hasEnvConfig = getenv('PDODB_DATABASE') !== false && getenv('PDODB_USERNAME') !== false;
        } elseif ($driver === 'oci') {
            $hasEnvConfig = getenv('PDODB_SERVICE_NAME') !== false && getenv('PDODB_USERNAME') !== false;
        }

        if ($driver !== '' && !$hasEnvConfig) {
            // Map driver names to config file names (oci -> oracle)
            $configFileMap = ['oci' => 'oracle'];
            $configFileName = $configFileMap[$driver] ?? $driver;
            $configFile = __DIR__ . "/../../examples/config.{$configFileName}.php";
            if (file_exists($configFile)) {
                return require $configFile;
            }
        }

        // Build config from environment variables
        $config = static::buildConfigFromEnv($driver);
        if ($config !== null) {
            // Validate that required extension is available
            if ($driver !== '') {
                try {
                    ExtensionChecker::validate($driver);
                } catch (\InvalidArgumentException $e) {
                    static::error($e->getMessage());
                }
            }
            return $config;
        }

        // Try config file: explicit PDODB_CONFIG_PATH or default config/db.php
        $customConfig = getenv('PDODB_CONFIG_PATH');
        $rootConfig = ($customConfig !== false && is_string($customConfig) && $customConfig !== '')
            ? (string)$customConfig
            : (getcwd() . '/config/db.php');
        if (file_exists($rootConfig)) {
            $config = require $rootConfig;
            $requestedConnection = getenv('PDODB_CONNECTION');

            // 1) Flat config with explicit driver
            if (is_array($config) && isset($config['driver'])) {
                $configDriver = is_string($config['driver']) ? mb_strtolower($config['driver'], 'UTF-8') : '';
                if ($configDriver !== '') {
                    try {
                        ExtensionChecker::validate($configDriver);
                    } catch (\InvalidArgumentException $e) {
                        static::error($e->getMessage());
                    }
                }
                return $config;
            }

            // 2) Modern multi-connection structure:
            // ['default' => 'main', 'connections' => ['main'=>[...], 'reporting'=>[...]]]
            if (is_array($config) && isset($config['connections']) && is_array($config['connections'])) {
                $chosenConfig = null;
                if ($requestedConnection !== false && isset($config['connections'][$requestedConnection])) {
                    /** @var array<string, mixed> $chosenConfig */
                    $chosenConfig = $config['connections'][$requestedConnection];
                } elseif (isset($config['default']) && is_string($config['default'])) {
                    $def = $config['default'];
                    if (isset($config['connections'][$def]) && is_array($config['connections'][$def])) {
                        /** @var array<string, mixed> $chosenConfig */
                        $chosenConfig = $config['connections'][$def];
                    }
                } elseif (count($config['connections']) === 1) {
                    /** @var array<string, mixed> $chosenConfig */
                    $chosenConfig = reset($config['connections']);
                }
                if ($chosenConfig !== null && isset($chosenConfig['driver']) && is_string($chosenConfig['driver'])) {
                    $configDriver = mb_strtolower($chosenConfig['driver'], 'UTF-8');
                    if ($configDriver !== '') {
                        try {
                            ExtensionChecker::validate($configDriver);
                        } catch (\InvalidArgumentException $e) {
                            static::error($e->getMessage());
                        }
                    }
                }
                if ($chosenConfig !== null) {
                    return $chosenConfig;
                }
            }

            // 3) Legacy per-driver map: ['mysql'=>[...], 'pgsql'=>[...]]
            if ($driver !== '' && is_array($config) && isset($config[$driver]) && is_array($config[$driver])) {
                try {
                    ExtensionChecker::validate($driver);
                } catch (\InvalidArgumentException $e) {
                    static::error($e->getMessage());
                }
                /** @var array<string, mixed> $driverConfig */
                $driverConfig = $config[$driver];
                return $driverConfig;
            }

            // 4) Legacy named connections without 'connections' wrapper:
            // ['main'=>['driver'=>...], 'reporting'=>['driver'=>...]]
            if ($requestedConnection !== false && is_array($config) && isset($config[$requestedConnection])
                && is_array($config[$requestedConnection]) && isset($config[$requestedConnection]['driver'])) {
                $chosenDriver = is_string($config[$requestedConnection]['driver'])
                    ? mb_strtolower($config[$requestedConnection]['driver'], 'UTF-8')
                    : '';
                if ($chosenDriver !== '') {
                    try {
                        ExtensionChecker::validate($chosenDriver);
                    } catch (\InvalidArgumentException $e) {
                        static::error($e->getMessage());
                    }
                }
                /** @var array<string, mixed> $chosen */
                $chosen = $config[$requestedConnection];
                return $chosen;
            }

            // 5) Backward-compatibility: if 'sqlite' key exists and no driver provided
            if (is_array($config) && isset($config['sqlite'])) {
                try {
                    ExtensionChecker::validate('sqlite');
                } catch (\InvalidArgumentException $e) {
                    static::error($e->getMessage());
                }
                static::warning('Using SQLite configuration from config/db.php. Consider setting PDODB_DRIVER environment variable or using direct driver configuration.');
                /** @var array<string, mixed> $sqliteCfg */
                $sqliteCfg = $config['sqlite'];
                return $sqliteCfg;
            }
        }

        // Throw error instead of fallback
        static::error("Database configuration not found.\n\n" .
            "Please configure your database connection:\n" .
            "  1. Create a .env file in your project root with:\n" .
            "     PDODB_DRIVER=mysql|mariadb|pgsql|sqlite|sqlsrv|oci\n" .
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
        EnvLoader::load();
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

        try {
            $dialect = DialectRegistry::resolve($driver);
            $envVars = [];
            // Always use getenv() to ensure we get variables set via putenv()
            // $_ENV may not be updated when putenv() is called
            $envVarNames = ['PDODB_HOST', 'PDODB_PORT', 'PDODB_DATABASE', 'PDODB_USERNAME', 'PDODB_PASSWORD', 'PDODB_CHARSET', 'PDODB_PATH', 'PDODB_DRIVER', 'PDODB_SERVICE_NAME', 'PDODB_SID'];
            foreach ($envVarNames as $envVar) {
                $envValue = getenv($envVar);
                if ($envValue !== false) {
                    $envVars[$envVar] = $envValue;
                }
            }

            // Check required variables
            if ($driver === 'oci') {
                // Oracle requires username and either SERVICE_NAME or SID
                if (!isset($envVars['PDODB_USERNAME']) || (!isset($envVars['PDODB_SERVICE_NAME']) && !isset($envVars['PDODB_SID']))) {
                    return null;
                }
            } elseif (!isset($envVars['PDODB_DATABASE']) || !isset($envVars['PDODB_USERNAME'])) {
                // SQLite doesn't require username/database
                if ($driver !== 'sqlite') {
                    return null;
                }
            }

            $config = $dialect->buildConfigFromEnv($envVars);
            return $config;
        } catch (\InvalidArgumentException $e) {
            // Driver not supported
            return null;
        }
    }

    /**
     * Load cache configuration from environment and config.
     *
     * @param array<string, mixed> $dbConfig Database configuration (may contain cache config)
     *
     * @return array<string, mixed> Cache configuration
     */
    protected static function loadCacheConfig(array $dbConfig): array
    {
        // Collect only cache-related environment variables
        // Always use getenv() to ensure we get variables set via putenv()
        // $_ENV may not be updated when putenv() is called
        $cacheEnvVars = [
            'PDODB_CACHE_ENABLED',
            'PDODB_CACHE_TYPE',
            'PDODB_CACHE_TTL',
            'PDODB_CACHE_PREFIX',
            'PDODB_CACHE_DIRECTORY',
            'PDODB_CACHE_REDIS_HOST',
            'PDODB_CACHE_REDIS_PORT',
            'PDODB_CACHE_REDIS_PASSWORD',
            'PDODB_CACHE_REDIS_DATABASE',
            'PDODB_CACHE_MEMCACHED_SERVERS',
            'PDODB_CACHE_NAMESPACE',
        ];

        $envVars = [];
        foreach ($cacheEnvVars as $key) {
            $envValue = getenv($key);
            if ($envValue !== false) {
                $envVars[$key] = $envValue;
            }
        }

        return EnvConfigLoader::loadCacheConfig($envVars, $dbConfig);
    }

    /**
     * Create cache instance from configuration.
     *
     * @param array<string, mixed> $cacheConfig Cache configuration
     *
     * @return CacheInterface|null
     */
    protected static function createCache(array $cacheConfig): ?CacheInterface
    {
        if (!($cacheConfig['enabled'] ?? false)) {
            return null;
        }

        return CacheFactory::create($cacheConfig);
    }

    /**
     * Create database instance from configuration.
     *
     * @return PdoDb
     */
    protected static function createDatabase(): PdoDb
    {
        $config = static::loadDatabaseConfig();
        $driver = isset($config['driver']) && is_string($config['driver']) ? $config['driver'] : null;
        unset($config['driver']);

        // Normalize database/dbname key for compatibility
        // Some configs use 'database', some use 'dbname' - dialects expect 'dbname'
        if (isset($config['database']) && !isset($config['dbname'])) {
            $config['dbname'] = $config['database'];
            unset($config['database']);
        }

        // Load and create cache if configured
        $cacheConfig = static::loadCacheConfig($config);
        $cache = static::createCache($cacheConfig);

        // Merge cache config into main config for PdoDb
        if (!empty($cacheConfig)) {
            $config['cache'] = $cacheConfig;
        }

        // Debug: ensure cache is passed to PdoDb if cacheConfig is enabled
        // PdoDb will create CacheManager only if $cache is not null
        return new PdoDb($driver, $config, [], null, $cache);
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
     * Read password from stdin (hidden input).
     *
     * @param string $prompt Prompt message
     *
     * @return string
     */
    protected static function readPassword(string $prompt): string
    {
        // Check if non-interactive mode is enabled (for tests, CI, etc.)
        $nonInteractive = getenv('PDODB_NON_INTERACTIVE') !== false
            || getenv('PHPUNIT') !== false
            || !stream_isatty(STDIN);

        if ($nonInteractive) {
            // In non-interactive mode, return empty string to avoid blocking on fgets(STDIN)
            // Tests and CI environments should provide passwords via environment variables or other means
            return '';
        }

        // Use stty to hide input on Unix-like systems
        if (PHP_OS_FAMILY !== 'Windows') {
            $sttyMode = shell_exec('stty -g');
            shell_exec('stty -echo');
        }

        echo $prompt . ': ';
        flush();
        $input = trim((string)fgets(STDIN));
        echo "\n";

        // Restore stty mode
        if (PHP_OS_FAMILY !== 'Windows' && isset($sttyMode)) {
            shell_exec("stty {$sttyMode}");
        }

        return $input;
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
        if (getenv('PHPUNIT') === false) {
            echo "✓ {$message}\n";
        }
    }

    /**
     * Display info message.
     *
     * @param string $message Info message
     */
    protected static function info(string $message): void
    {
        if (getenv('PHPUNIT') === false) {
            echo "ℹ {$message}\n";
        }
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
