<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\cache\CacheFactory;
use tommyknocker\pdodb\cli\commands\InitCommand;
use tommyknocker\pdodb\connection\DialectRegistry;
use tommyknocker\pdodb\connection\ExtensionChecker;
use tommyknocker\pdodb\PdoDb;

/**
 * Interactive wizard for PDOdb project initialization.
 */
class InitWizard extends BaseCliCommand
{
    protected InitCommand $command;
    protected bool $skipConnectionTest;
    protected bool $force;
    protected string $format;
    protected bool $noStructure;
    /** @var array<string, mixed> */
    protected array $config = [];
    /** @var array<string, mixed> */
    protected array $structure = [];

    /**
     * Create wizard instance.
     *
     * @param InitCommand $command Command instance
     * @param bool $skipConnectionTest Skip connection test
     * @param bool $force Force overwrite
     * @param string $format Configuration format (interactive|env|config)
     * @param bool $noStructure Don't create directory structure
     */
    public function __construct(
        InitCommand $command,
        bool $skipConnectionTest = false,
        bool $force = false,
        string $format = 'interactive',
        bool $noStructure = false
    ) {
        $this->command = $command;
        $this->skipConnectionTest = $skipConnectionTest;
        $this->force = $force;
        $this->format = $format;
        $this->noStructure = $noStructure;
    }

    /**
     * Run wizard.
     *
     * @return int Exit code
     */
    public function run(): int
    {
        // Check if non-interactive mode
        $nonInteractive = getenv('PDODB_NON_INTERACTIVE') !== false
            || getenv('PHPUNIT') !== false
            || !stream_isatty(STDIN);

        if ($nonInteractive) {
            // Load configuration from environment variables
            $this->loadConfigFromEnv();
            $this->loadStructureFromEnv();
        } else {
            echo "Welcome to PDOdb Configuration Wizard\n";
            echo "=====================================\n\n";

            try {
                $this->askBasicConnection();
                if (!$this->skipConnectionTest) {
                    $this->testConnection();
                }
                $this->askConfigurationFormat();
                if (!$this->noStructure) {
                    $this->askProjectStructure();
                }
                $this->askAdvancedOptions();
            } catch (\Exception $e) {
                static::error('Initialization failed: ' . $e->getMessage());
            }
        }

        $this->generateFiles();
        return 0;
    }

    /**
     * Load configuration from environment variables.
     */
    protected function loadConfigFromEnv(): void
    {
        $driver = mb_strtolower(getenv('PDODB_DRIVER') ?: '', 'UTF-8');
        if ($driver === '') {
            static::error('PDODB_DRIVER environment variable is required in non-interactive mode');
        }

        // Check if required PHP extension is available
        try {
            ExtensionChecker::validate($driver);
        } catch (\InvalidArgumentException $e) {
            static::error($e->getMessage());
        }

        $this->config['driver'] = $driver;

        // Load driver-specific settings using dialect's buildConfigFromEnv method
        try {
            $dialect = \tommyknocker\pdodb\connection\DialectRegistry::resolve($driver);
            $envVars = [];
            // Always use getenv() to ensure we get variables set via putenv()
            // $_ENV may not be updated when putenv() is called
            $envVarNames = ['PDODB_HOST', 'PDODB_PORT', 'PDODB_DATABASE', 'PDODB_USERNAME', 'PDODB_PASSWORD', 'PDODB_CHARSET', 'PDODB_PATH', 'PDODB_DRIVER'];
            foreach ($envVarNames as $envVar) {
                $envValue = getenv($envVar);
                if ($envValue !== false) {
                    $envVars[$envVar] = $envValue;
                }
            }
            $dialectConfig = $dialect->buildConfigFromEnv($envVars);
            $this->config = array_merge($this->config, $dialectConfig);
        } catch (\InvalidArgumentException $e) {
            // Driver not supported, use basic config
        }

        // Load cache configuration if enabled
        $cacheEnabled = getenv('PDODB_CACHE_ENABLED');
        if ($cacheEnabled !== false && mb_strtolower($cacheEnabled, 'UTF-8') === 'true') {
            $this->config['cache'] = [
                'enabled' => true,
                'type' => getenv('PDODB_CACHE_TYPE') ?: 'filesystem',
                'default_lifetime' => (int)(getenv('PDODB_CACHE_TTL') ?: '3600'),
                'prefix' => getenv('PDODB_CACHE_PREFIX') ?: 'pdodb_',
            ];

            $cacheType = mb_strtolower($this->config['cache']['type'], 'UTF-8');
            if ($cacheType === 'filesystem') {
                $this->config['cache']['directory'] = getenv('PDODB_CACHE_DIRECTORY') ?: './storage/cache';
            } elseif ($cacheType === 'redis') {
                $this->config['cache']['host'] = getenv('PDODB_CACHE_REDIS_HOST') ?: '127.0.0.1';
                $this->config['cache']['port'] = (int)(getenv('PDODB_CACHE_REDIS_PORT') ?: '6379');
                $this->config['cache']['database'] = (int)(getenv('PDODB_CACHE_REDIS_DATABASE') ?: '0');
                $password = getenv('PDODB_CACHE_REDIS_PASSWORD');
                if ($password !== false) {
                    $this->config['cache']['password'] = $password;
                }
            } elseif ($cacheType === 'memcached') {
                $servers = getenv('PDODB_CACHE_MEMCACHED_SERVERS') ?: '127.0.0.1:11211';
                $serversList = explode(',', $servers);
                $this->config['cache']['servers'] = [];
                foreach ($serversList as $server) {
                    /** @var array<int, string> $parts */
                    $parts = explode(':', trim($server));
                    if (count($parts) < 1) {
                        continue;
                    }
                    $host = isset($parts[0]) && $parts[0] !== '' ? $parts[0] : '127.0.0.1';
                    $serverPort = isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : 11211;
                    // @phpstan-ignore-next-line
                    $this->config['cache']['servers'][] = [$host, $serverPort];
                }
            }
        }
    }

    /**
     * Load structure from environment variables.
     */
    protected function loadStructureFromEnv(): void
    {
        $this->structure = [
            'namespace' => getenv('PDODB_NAMESPACE') ?: 'App',
            'migrations' => getenv('PDODB_MIGRATION_PATH') ?: './migrations',
            'models' => getenv('PDODB_MODEL_PATH') ?: './app/Models',
            'repositories' => getenv('PDODB_REPOSITORY_PATH') ?: './app/Repositories',
            'services' => getenv('PDODB_SERVICE_PATH') ?: './app/Services',
            'seeds' => getenv('PDODB_SEED_PATH') ?: './database/seeds',
        ];
    }

    /**
     * Ask for basic database connection settings.
     */
    protected function askBasicConnection(): void
    {
        echo "Step 1: Database Connection\n";
        echo "----------------------------\n";

        // Driver selection
        $drivers = DialectRegistry::getSupportedDrivers();
        $driverList = implode('|', $drivers);
        $driver = static::readInput("Database driver ({$driverList})", 'mysql');
        $driver = mb_strtolower(trim($driver), 'UTF-8');

        if (!in_array($driver, $drivers, true)) {
            static::error("Unsupported driver: {$driver}");
        }

        // Check if required PHP extension is available
        try {
            ExtensionChecker::validate($driver);
        } catch (\InvalidArgumentException $e) {
            static::error($e->getMessage());
        }

        $this->config['driver'] = $driver;

        // Driver-specific questions
        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                $this->askMysqlConnection();
                break;
            case 'pgsql':
                $this->askPgsqlConnection();
                break;
            case 'sqlsrv':
                $this->askSqlsrvConnection();
                break;
            case 'sqlite':
                $this->askSqliteConnection();
                break;
            case 'oci':
                $this->askOciConnection();
                break;
        }
    }

    /**
     * Ask MySQL/MariaDB connection settings.
     */
    protected function askMysqlConnection(): void
    {
        $this->config['host'] = static::readInput('Database host', 'localhost');
        $this->config['port'] = (int)static::readInput('Database port', '3306');
        $this->config['database'] = static::readInput('Database name');
        if ($this->config['database'] === '') {
            static::error('Database name is required');
        }
        // MySQL/MariaDB dialect requires 'dbname' parameter for DSN
        $this->config['dbname'] = $this->config['database'];
        $this->config['username'] = static::readInput('Database username');
        if ($this->config['username'] === '') {
            static::error('Database username is required');
        }
        $this->config['password'] = static::readPassword('Database password');
        $this->config['charset'] = static::readInput('Charset', 'utf8mb4');
    }

    /**
     * Ask PostgreSQL connection settings.
     */
    protected function askPgsqlConnection(): void
    {
        $this->config['host'] = static::readInput('Database host', 'localhost');
        $this->config['port'] = (int)static::readInput('Database port', '5432');
        $this->config['database'] = static::readInput('Database name');
        if ($this->config['database'] === '') {
            static::error('Database name is required');
        }
        // PostgreSQL dialect requires 'dbname' parameter for DSN
        $this->config['dbname'] = $this->config['database'];
        $this->config['username'] = static::readInput('Database username');
        if ($this->config['username'] === '') {
            static::error('Database username is required');
        }
        $this->config['password'] = static::readPassword('Database password');
    }

    /**
     * Ask SQL Server connection settings.
     */
    protected function askSqlsrvConnection(): void
    {
        $this->config['host'] = static::readInput('Database host', 'localhost');
        $this->config['port'] = (int)static::readInput('Database port', '1433');
        $this->config['database'] = static::readInput('Database name');
        if ($this->config['database'] === '') {
            static::error('Database name is required');
        }
        $this->config['dbname'] = $this->config['database'];
        $this->config['username'] = static::readInput('Database username');
        if ($this->config['username'] === '') {
            static::error('Database username is required');
        }
        $this->config['password'] = static::readPassword('Database password');
        $this->config['trust_server_certificate'] = true;
        $this->config['encrypt'] = true;
    }

    /**
     * Ask SQLite connection settings.
     */
    protected function askSqliteConnection(): void
    {
        $path = static::readInput('Database path', './database.sqlite');
        if ($path === '') {
            $path = './database.sqlite';
        }
        $this->config['path'] = $path;
    }

    /**
     * Ask Oracle connection settings.
     */
    protected function askOciConnection(): void
    {
        $this->config['host'] = static::readInput('Database host', 'localhost');
        $this->config['port'] = (int)static::readInput('Database port', '1521');
        $serviceName = static::readInput('Service name (or SID)', 'XE');
        if ($serviceName === '') {
            static::error('Service name or SID is required');
        }
        $this->config['service_name'] = $serviceName;
        $this->config['username'] = static::readInput('Database username');
        if ($this->config['username'] === '') {
            static::error('Database username is required');
        }
        $this->config['password'] = static::readPassword('Database password');
        $charset = static::readInput('Charset', 'AL32UTF8');
        if ($charset !== '') {
            $this->config['charset'] = $charset;
        }
    }

    /**
     * Test database connection.
     */
    protected function testConnection(): void
    {
        echo "\n";
        echo "Testing database connection...\n";
        flush();

        try {
            // Normalize database -> dbname for all dialects (except SQLite which uses 'path')
            // All dialects require 'dbname' parameter for buildDsn()
            $testConfig = $this->config;
            $driver = isset($testConfig['driver']) && is_string($testConfig['driver']) ? $testConfig['driver'] : null;
            if ($driver !== 'sqlite') {
                if (isset($testConfig['database']) && !isset($testConfig['dbname'])) {
                    $testConfig['dbname'] = $testConfig['database'];
                }
            }

            $db = new PdoDb($driver, $testConfig);
            // Try a simple query
            $db->rawQuery('SELECT 1');
            static::success('Connection successful!');
        } catch (\Exception $e) {
            static::warning('Connection failed: ' . $e->getMessage());
            $retry = static::readConfirmation('Retry with different settings?', true);
            if ($retry) {
                echo "\n";
                $this->askBasicConnection();
                $this->testConnection();
            } else {
                static::error('Cannot continue without a valid database connection');
            }
        }
    }

    /**
     * Ask configuration format.
     */
    protected function askConfigurationFormat(): void
    {
        if ($this->format !== 'interactive') {
            return;
        }

        echo "\n";
        echo "Step 2: Configuration Format\n";
        echo "-----------------------------\n";
        echo "Choose configuration format:\n";
        echo "  1. .env file (simple, environment-based)\n";
        echo "  2. config/db.php (PHP array, more flexible)\n";

        $choice = static::readInput('Select format', '1');
        switch ($choice) {
            case '2':
                $this->format = 'config';
                break;
            case '1':
            default:
                $this->format = 'env';
                break;
        }
    }

    /**
     * Ask project structure settings.
     */
    protected function askProjectStructure(): void
    {
        echo "\n";
        echo "Step 3: Project Structure\n";
        echo "--------------------------\n";

        $createStructure = static::readConfirmation('Create directory structure?', true);
        if (!$createStructure) {
            return;
        }

        $namespace = static::readInput('Namespace prefix', 'App');
        if ($namespace === '') {
            $namespace = 'App';
        }

        $cwd = getcwd();
        if ($cwd === false) {
            $cwd = __DIR__ . '/../../..';
        }

        $this->structure = [
            'namespace' => $namespace,
            'migrations' => static::readInput('Migrations path', './migrations'),
            'models' => static::readInput('Models path', './app/Models'),
            'repositories' => static::readInput('Repositories path', './app/Repositories'),
            'services' => static::readInput('Services path', './app/Services'),
            'seeds' => static::readInput('Seeds path', './database/seeds'),
        ];
    }

    /**
     * Ask advanced options.
     */
    protected function askAdvancedOptions(): void
    {
        echo "\n";
        echo "Step 4: Advanced Options (Optional)\n";
        echo "------------------------------------\n";

        $configureAdvanced = static::readConfirmation('Configure advanced options?', false);
        if (!$configureAdvanced) {
            return;
        }

        $this->askTablePrefix();
        $this->askCaching();
        $this->askPerformance();
        $this->askMultipleConnections();
    }

    /**
     * Ask table prefix option.
     */
    protected function askTablePrefix(): void
    {
        echo "\n  Table Prefix\n";
        echo "  ------------\n";
        $usePrefix = static::readConfirmation('  Use table prefix?', false);
        if ($usePrefix) {
            $prefix = static::readInput('  Table prefix', 'app_');
            if ($prefix !== '') {
                $this->config['prefix'] = $prefix;
            }
        }
    }

    /**
     * Ask caching configuration.
     */
    protected function askCaching(): void
    {
        echo "\n  Caching\n";
        echo "  -------\n";
        $enableCache = static::readConfirmation('  Enable query result caching?', false);
        if (!$enableCache) {
            return;
        }

        $this->config['cache'] = ['enabled' => true];

        $cacheTypes = ['filesystem', 'redis', 'apcu', 'memcached', 'array'];
        $cacheType = static::readInput('  Cache type (' . implode('|', $cacheTypes) . ')', 'filesystem');
        $cacheType = mb_strtolower(trim($cacheType), 'UTF-8');
        if (!in_array($cacheType, $cacheTypes, true)) {
            $cacheType = 'filesystem';
        }

        switch ($cacheType) {
            case 'filesystem':
                $directory = static::readInput('  Cache directory', './storage/cache');
                $this->config['cache']['directory'] = $directory;
                break;

            case 'redis':
                $this->config['cache']['host'] = static::readInput('  Redis host', '127.0.0.1');
                $this->config['cache']['port'] = (int)static::readInput('  Redis port', '6379');
                $this->config['cache']['database'] = (int)static::readInput('  Redis database', '0');
                $password = static::readPassword('  Redis password (optional, press Enter to skip)');
                if ($password !== '') {
                    $this->config['cache']['password'] = $password;
                }
                break;

            case 'memcached':
                $servers = static::readInput('  Memcached servers', '127.0.0.1:11211');
                $serversList = explode(',', $servers);
                $this->config['cache']['servers'] = [];
                foreach ($serversList as $server) {
                    /** @var array<int, string> $parts */
                    $parts = explode(':', trim($server));
                    if (count($parts) < 1) {
                        continue;
                    }
                    $host = isset($parts[0]) && $parts[0] !== '' ? $parts[0] : '127.0.0.1';
                    $serverPort = isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : 11211;
                    // @phpstan-ignore-next-line
                    $this->config['cache']['servers'][] = [$host, $serverPort];
                }
                break;
        }

        // Validate cache dependencies
        if ($cacheType !== 'array') {
            // $this->config['cache'] is always an array after line 432
            $cacheSection = $this->config['cache'];
            $testCacheConfig = [
                'type' => $cacheType,
                'directory' => isset($cacheSection['directory']) && is_string($cacheSection['directory']) ? $cacheSection['directory'] : null,
                'host' => isset($cacheSection['host']) && is_string($cacheSection['host']) ? $cacheSection['host'] : null,
                'port' => isset($cacheSection['port']) && is_int($cacheSection['port']) ? $cacheSection['port'] : null,
                'database' => isset($cacheSection['database']) && is_int($cacheSection['database']) ? $cacheSection['database'] : null,
                'password' => isset($cacheSection['password']) && is_string($cacheSection['password']) ? $cacheSection['password'] : null,
                'servers' => isset($cacheSection['servers']) && is_array($cacheSection['servers']) ? $cacheSection['servers'] : null,
            ];
            $testCache = CacheFactory::create($testCacheConfig);

            if ($testCache === null) {
                echo "\n";
                static::warning("Warning: Cannot create {$cacheType} cache. Required dependencies may be missing:");
                switch ($cacheType) {
                    case 'filesystem':
                        static::warning('  - symfony/cache package');
                        break;
                    case 'apcu':
                        static::warning('  - ext-apcu extension');
                        static::warning('  - symfony/cache package');
                        break;
                    case 'redis':
                        static::warning('  - ext-redis extension');
                        static::warning('  - symfony/cache package');
                        break;
                    case 'memcached':
                        static::warning('  - ext-memcached extension');
                        static::warning('  - symfony/cache package');
                        break;
                }
                echo "\n";

                $continue = static::readConfirmation('  Continue anyway? (cache will be disabled)', false);
                if (!$continue) {
                    $this->config['cache'] = ['enabled' => false];
                    return;
                }
                // Fallback to array cache
                $cacheType = 'array';
                static::info('  Falling back to array cache (in-memory only)');
            }
        }

        // Ensure cache config is an array (it should be after line 432, but PHPStan needs this)
        if (!is_array($this->config['cache'])) {
            $this->config['cache'] = [];
        }
        /** @var array<string, mixed> $cacheConfig */
        $cacheConfig = $this->config['cache'];
        $cacheConfig['type'] = $cacheType;

        $ttl = static::readInput('  Default cache TTL (seconds)', '3600');
        $cacheConfig['default_lifetime'] = (int)$ttl;

        $prefix = static::readInput('  Cache key prefix', 'pdodb_');
        $cacheConfig['prefix'] = $prefix !== '' ? $prefix : 'pdodb_';

        $this->config['cache'] = $cacheConfig;
    }

    /**
     * Ask performance options.
     */
    protected function askPerformance(): void
    {
        echo "\n  Performance\n";
        echo "  -----------\n";

        // Compilation cache
        $enableCompilationCache = static::readConfirmation('  Enable query compilation cache?', true);
        if ($enableCompilationCache) {
            $this->config['compilation_cache'] = ['enabled' => true];
            $ttl = static::readInput('  Compilation cache TTL (seconds)', '86400');
            $this->config['compilation_cache']['ttl'] = (int)$ttl;
        }

        // Statement pool
        $enablePool = static::readConfirmation('  Enable prepared statement pool?', false);
        if ($enablePool) {
            $this->config['stmt_pool'] = ['enabled' => true];
            $capacity = static::readInput('  Pool capacity', '256');
            $this->config['stmt_pool']['capacity'] = (int)$capacity;
        }

        // Connection retry
        $enableRetry = static::readConfirmation('  Enable connection retry?', false);
        if ($enableRetry) {
            $this->config['retry'] = [
                'enabled' => true,
                'max_attempts' => (int)static::readInput('    Max attempts', '3'),
                'delay_ms' => (int)static::readInput('    Initial delay (ms)', '1000'),
                'backoff_multiplier' => (float)static::readInput('    Backoff multiplier', '2'),
                'max_delay_ms' => (int)static::readInput('    Max delay (ms)', '10000'),
            ];
        }
    }

    /**
     * Ask multiple connections configuration.
     */
    protected function askMultipleConnections(): void
    {
        echo "\n  Multiple Connections\n";
        echo "  --------------------\n";
        $useMultiple = static::readConfirmation('  Configure multiple database connections?', false);
        if (!$useMultiple) {
            return;
        }

        // For now, we'll keep it simple - just note that multiple connections
        // can be configured manually in config/db.php later
        static::info('  Multiple connections are not yet fully supported in the wizard.');
        static::info('  You can configure them manually in config/db.php after initialization.');
        static::info('  Example structure:');
        echo "    return [\n";
        echo "        'default' => 'main',\n";
        echo "        'connections' => [\n";
        echo "            'main' => [ ... ],\n";
        echo "            'reporting' => [ ... ],\n";
        echo "        ],\n";
        echo "    ];\n";
    }

    /**
     * Generate configuration files.
     */
    protected function generateFiles(): void
    {
        echo "\n";
        echo "Creating configuration files...\n";

        $cwd = getcwd();
        if ($cwd === false) {
            $cwd = __DIR__ . '/../../..';
        }

        if ($this->format === 'env') {
            $envPath = $cwd . '/.env';
            if (file_exists($envPath) && !$this->force) {
                $overwrite = static::readConfirmation('.env file already exists. Overwrite?', false);
                if (!$overwrite) {
                    echo "Skipping .env file creation\n";
                } else {
                    InitConfigGenerator::generateEnv($this->config, $this->structure, $envPath);
                    echo "✓ .env file created\n";
                }
            } else {
                InitConfigGenerator::generateEnv($this->config, $this->structure, $envPath);
                echo "✓ .env file created\n";
            }
        }

        if ($this->format === 'config') {
            $configDir = $cwd . '/config';
            if (!is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }
            $configPath = $configDir . '/db.php';
            if (file_exists($configPath) && !$this->force) {
                $overwrite = static::readConfirmation('config/db.php already exists. Overwrite?', false);
                if (!$overwrite) {
                    echo "Skipping config/db.php creation\n";
                } else {
                    InitConfigGenerator::generateConfigPhp($this->config, $configPath);
                    echo "✓ config/db.php created\n";
                }
            } else {
                InitConfigGenerator::generateConfigPhp($this->config, $configPath);
                echo "✓ config/db.php created\n";
            }
        }

        if (!$this->noStructure && !empty($this->structure)) {
            InitDirectoryStructure::create($this->structure);
        }

        echo "\n";
        echo "✓ Project initialized successfully!\n";
        echo "\n";
        echo "Next steps:\n";
        echo "  - Run migrations: pdodb migrate up\n";
        echo "  - Create a model: pdodb model make User\n";
        echo "  - Create a migration: pdodb migrate create create_users_table\n";
    }
}
