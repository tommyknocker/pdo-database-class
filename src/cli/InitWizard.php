<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\cache\CacheFactory;
use tommyknocker\pdodb\cli\commands\InitCommand;
use tommyknocker\pdodb\connection\DialectRegistry;
use tommyknocker\pdodb\connection\ExtensionChecker;
use tommyknocker\pdodb\dialects\ConnectionInitHandler;
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
        // Always check environment variables first (fastest and most reliable)
        $nonInteractive = static::isNonInteractive();

        if ($nonInteractive) {
            // Load configuration from environment variables
            $this->loadConfigFromEnv();
            $this->loadStructureFromEnv();
        } else {
            static::cliWriteln('Welcome to PDOdb Configuration Wizard');
            static::cliWriteln("=====================================\n");

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

        // First check if driver is supported by DialectRegistry
        if (!DialectRegistry::isSupported($driver)) {
            static::error("Unsupported driver: {$driver}");
        }

        // Then check if required PHP extension is available
        try {
            ExtensionChecker::validate($driver);
        } catch (\InvalidArgumentException $e) {
            static::error($e->getMessage());
        }

        $this->config['driver'] = $driver;

        // Load driver-specific settings using dialect's buildConfigFromEnv method
        try {
            $dialect = DialectRegistry::resolve($driver);
            $envVars = [];
            // Always use getenv() to ensure we get variables set via putenv()
            // $_ENV may not be updated when putenv() is called
            $envVarNames = ['PDODB_HOST', 'PDODB_PORT', 'PDODB_DATABASE', 'PDODB_USERNAME', 'PDODB_PASSWORD', 'PDODB_CHARSET', 'PDODB_PATH', 'PDODB_PREFIX', 'PDODB_DRIVER'];
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
                'type' => mb_strtolower(getenv('PDODB_CACHE_TYPE') ?: 'filesystem', 'UTF-8'),
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

        // Load AI configuration if enabled
        $aiProvider = getenv('PDODB_AI_PROVIDER');
        if ($aiProvider !== false && $aiProvider !== '') {
            $this->config['ai'] = ['provider' => mb_strtolower($aiProvider, 'UTF-8')];
            $this->loadAiConfigFromEnv();
        }
    }

    /**
     * Load structure from environment variables.
     */
    protected function loadStructureFromEnv(): void
    {
        $this->structure = [
            'namespace' => strtolower(getenv('PDODB_NAMESPACE') ?: 'app'),
            'migrations' => getenv('PDODB_MIGRATION_PATH') ?: './migrations',
            'models' => getenv('PDODB_MODEL_PATH') ?: './app/Models',
            'repositories' => getenv('PDODB_REPOSITORY_PATH') ?: './app/Repositories',
            'services' => getenv('PDODB_SERVICE_PATH') ?: './app/Services',
            'seeds' => getenv('PDODB_SEED_PATH') ?: './database/seeds',
        ];
    }

    /**
     * Load AI configuration from environment variables.
     */
    protected function loadAiConfigFromEnv(): void
    {
        if (!isset($this->config['ai']) || !is_array($this->config['ai'])) {
            $this->config['ai'] = [];
        }

        foreach (['API_KEY' => 'api_key', 'MODEL' => 'model', 'BASE_URL' => 'base_url', 'FOLDER_ID' => 'folder_id'] as $env => $key) {
            $value = getenv('PDODB_AI_' . $env);
            if ($value !== false && $value !== '') {
                $this->config['ai'][$key] = $value;
            }
        }
    }

    /**
     * Ask for basic database connection settings.
     */
    protected function askBasicConnection(): void
    {
        static::cliWriteln('Step 1: Database Connection');
        static::cliWriteln('----------------------------');

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

        $handler = DialectRegistry::resolve($driver)->connectionInitHandler();
        match ($handler) {
            ConnectionInitHandler::MysqlTcp => $this->askMysqlConnection(),
            ConnectionInitHandler::PgsqlTcp => $this->askPgsqlConnection(),
            ConnectionInitHandler::SqlsrvTcp => $this->askSqlsrvConnection(),
            ConnectionInitHandler::SqliteFile => $this->askSqliteConnection(),
            ConnectionInitHandler::OciService => $this->askOciConnection(),
        };
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
        static::cliWriteln('');
        static::cliWriteln('Testing database connection...');
        flush();

        try {
            // Normalize database -> dbname for all dialects (except SQLite which uses 'path')
            // All dialects require 'dbname' parameter for buildDsn()
            $testConfig = $this->config;
            $driver = isset($testConfig['driver']) && is_string($testConfig['driver']) ? $testConfig['driver'] : null;
            if ($driver !== null && $driver !== '') {
                $dialect = DialectRegistry::resolve($driver);
                if (!$dialect->isFileBasedConnection() && isset($testConfig['database']) && !isset($testConfig['dbname'])) {
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
                static::cliWriteln('');
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

        static::cliWriteln('');
        static::cliWriteln('Step 2: Configuration Format');
        static::cliWriteln('-----------------------------');
        static::cliWriteln('Choose configuration format:');
        static::cliWriteln('  1. .env file (simple, environment-based)');
        static::cliWriteln('  2. config/db.php (PHP array, more flexible)');

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
        static::cliWriteln('');
        static::cliWriteln('Step 3: Project Structure');
        static::cliWriteln('--------------------------');

        $createStructure = static::readConfirmation('Create directory structure?', true);
        if (!$createStructure) {
            return;
        }

        $namespace = static::readInput('Namespace prefix', 'app');
        if ($namespace === '') {
            $namespace = 'app';
        }
        $namespace = strtolower($namespace);

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
        // Double-check non-interactive mode to prevent hangs
        $nonInteractive = static::isNonInteractive();
        if ($nonInteractive) {
            return; // Skip in non-interactive mode
        }

        static::cliWriteln('');
        static::cliWriteln('Step 4: Advanced Options (Optional)');
        static::cliWriteln('------------------------------------');

        $configureAdvanced = static::readConfirmation('Configure advanced options?', false);
        if (!$configureAdvanced) {
            return;
        }

        $this->askTablePrefix();
        $this->askCaching();
        $this->askPerformance();
        $this->askAiConfiguration();
        $this->askMultipleConnections();
    }

    /**
     * Ask table prefix option.
     */
    protected function askTablePrefix(): void
    {
        // Double-check non-interactive mode to prevent hangs
        $nonInteractive = static::isNonInteractive();
        if ($nonInteractive) {
            return; // Skip in non-interactive mode
        }

        static::cliWriteln("\n  Table Prefix");
        static::cliWriteln('  ------------');
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
        // Double-check non-interactive mode to prevent hangs
        $nonInteractive = static::isNonInteractive();
        if ($nonInteractive) {
            return; // Skip in non-interactive mode
        }

        static::cliWriteln("\n  Caching");
        static::cliWriteln('  -------');
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
                static::cliWriteln('');
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
                static::cliWriteln('');

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
        // Double-check non-interactive mode to prevent hangs
        $nonInteractive = static::isNonInteractive();
        if ($nonInteractive) {
            return; // Skip in non-interactive mode
        }

        static::cliWriteln("\n  Performance");
        static::cliWriteln('  -----------');

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
     * Ask AI configuration.
     */
    protected function askAiConfiguration(): void
    {
        // Double-check non-interactive mode to prevent hangs
        $nonInteractive = static::isNonInteractive();
        if ($nonInteractive) {
            return; // Skip in non-interactive mode
        }

        static::cliWriteln("\n  AI Configuration");
        static::cliWriteln('  ----------------');
        $enableAi = static::readConfirmation('  Enable AI-powered database analysis?', false);
        if (!$enableAi) {
            return;
        }

        if (!isset($this->config['ai'])) {
            $this->config['ai'] = [];
        }

        $providers = AiConfig::PROVIDERS;
        static::cliWriteln('  Available providers: ' . implode(', ', $providers));
        $provider = mb_strtolower(trim(static::readInput('  Provider', 'openai')), 'UTF-8');
        if (!in_array($provider, $providers, true)) {
            $provider = 'openai';
        }
        $this->config['ai']['provider'] = $provider;
        if ($provider !== 'ollama') {
            $key = static::readPassword('  API key (optional, press Enter to skip)');
            if ($key !== '') {
                $this->config['ai']['api_key'] = $key;
            }
        }
        $model = static::readInput('  Model (optional)', '');
        if ($model !== '') {
            $this->config['ai']['model'] = $model;
        }
        if (in_array($provider, ['ollama', 'openai-compatible', 'microsoft'], true)) {
            $url = static::readInput('  Base URL' . ($provider === 'ollama' ? ' (optional)' : ''), '');
            if ($url !== '') {
                $this->config['ai']['base_url'] = $url;
            }
        }
        if ($provider === 'yandex') {
            $folder = static::readInput('  Folder ID', '');
            if ($folder !== '') {
                $this->config['ai']['folder_id'] = $folder;
            }
        }
    }

    /**
     * Ask multiple connections configuration.
     */
    protected function askMultipleConnections(): void
    {
        // Double-check non-interactive mode to prevent hangs
        $nonInteractive = static::isNonInteractive();
        if ($nonInteractive) {
            return; // Skip in non-interactive mode
        }

        if ($this->format !== 'config') {
            static::cliWriteln("\n  Multiple connections require the PHP config format.");
            return;
        }

        static::cliWriteln("\n  Multiple Connections");
        static::cliWriteln('  --------------------');
        $useMultiple = static::readConfirmation('  Configure multiple database connections?', false);
        if (!$useMultiple) {
            return;
        }

        $primaryName = static::readInput('  Name for current connection', 'main');
        if ($primaryName === '') {
            $primaryName = 'main';
        }

        $primaryConfig = $this->config;

        $secondName = static::readInput('  Second connection name', 'reporting');
        if ($secondName === '' || $secondName === $primaryName) {
            static::warning('  Second connection skipped (invalid or duplicate name).');

            return;
        }

        $this->config = [
            'default' => $primaryName,
            'connections' => [
                $primaryName => $primaryConfig,
            ],
        ];

        static::info("  Configure connection \"{$secondName}\" (driver defaults to primary).");
        $secondConfig = $this->askSecondaryConnectionConfig($primaryConfig);
        $this->config['connections'][$secondName] = $secondConfig;
        static::success('  Multiple connections will be written to config/db.php.');
    }

    /**
     * @param array<string, mixed> $primaryConfig
     *
     * @return array<string, mixed>
     */
    protected function askSecondaryConnectionConfig(array $primaryConfig): array
    {
        $driver = mb_strtolower(
            static::readInput('  Driver', (string)($primaryConfig['driver'] ?? 'mysql')),
            'UTF-8'
        );
        $config = ['driver' => $driver];
        $dialect = DialectRegistry::resolve($driver);

        if ($dialect->isFileBasedConnection()) {
            $path = static::readInput('  Database path', './database-reporting.sqlite');
            $config['path'] = $path !== '' ? $path : './database-reporting.sqlite';

            return $config;
        }

        $config['host'] = static::readInput('  Host', (string)($primaryConfig['host'] ?? 'localhost'));
        $config['port'] = (int)static::readInput(
            '  Port',
            (string)($primaryConfig['port'] ?? $dialect->defaultTcpPort())
        );
        $config['database'] = static::readInput('  Database name', (string)($primaryConfig['database'] ?? ''));
        if ($config['database'] === '') {
            static::warning('  Database name is empty; edit config/db.php before use.');
        }
        if ($dialect->mapsDatabaseToDbnameInInit()) {
            $config['dbname'] = $config['database'];
        }
        $config['username'] = static::readInput('  Username', (string)($primaryConfig['username'] ?? ''));
        $config['password'] = static::readPassword('  Password');

        $charsetDefault = $dialect->initWizardDefaultCharset();
        if ($charsetDefault !== null) {
            $config['charset'] = static::readInput('  Charset', (string)($primaryConfig['charset'] ?? $charsetDefault));
        }

        foreach ($dialect->initWizardConnectionExtras() as $key => $value) {
            $config[$key] = $value;
        }

        return $config;
    }

    /**
     * Generate configuration files.
     */
    protected function generateFiles(): void
    {
        // Double-check non-interactive mode to prevent hangs
        $nonInteractive = static::isNonInteractive();

        static::cliWriteln('');
        static::cliWriteln('Creating configuration files...');

        $cwd = getcwd();
        if ($cwd === false) {
            $cwd = __DIR__ . '/../../..';
        }

        if ($this->format === 'env') {
            $envPath = $cwd . '/.env';
            if (file_exists($envPath) && !$this->force) {
                if ($nonInteractive) {
                    // In non-interactive mode, skip overwrite prompt and skip file creation (default behavior)
                    static::cliWriteln('Skipping .env file creation');
                } else {
                    $overwrite = static::readConfirmation('.env file already exists. Overwrite?', false);
                    if (!$overwrite) {
                        static::cliWriteln('Skipping .env file creation');
                    } else {
                        InitConfigGenerator::generateEnv($this->config, $this->structure, $envPath);
                        static::cliWriteln('✓ .env file created');
                    }
                }
            } else {
                InitConfigGenerator::generateEnv($this->config, $this->structure, $envPath);
                static::cliWriteln('✓ .env file created');
            }
        }

        if ($this->format === 'config') {
            $configDir = $cwd . '/config';
            if (!is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }
            $configPath = $configDir . '/db.php';
            if (file_exists($configPath) && !$this->force) {
                if ($nonInteractive) {
                    // In non-interactive mode, skip overwrite prompt and skip file creation (default behavior)
                    static::cliWriteln('Skipping config/db.php creation');
                } else {
                    $overwrite = static::readConfirmation('config/db.php already exists. Overwrite?', false);
                    if (!$overwrite) {
                        static::cliWriteln('Skipping config/db.php creation');
                    } else {
                        InitConfigGenerator::generateConfigPhp($this->config, $configPath);
                        static::cliWriteln('✓ config/db.php created');
                    }
                }
            } else {
                InitConfigGenerator::generateConfigPhp($this->config, $configPath);
                static::cliWriteln('✓ config/db.php created');
            }
        }

        if (!$this->noStructure && !empty($this->structure)) {
            InitDirectoryStructure::create($this->structure);
        }

        static::cliWriteln('');
        static::cliWriteln('✓ Project initialized successfully!');
        static::cliWriteln('');
        static::cliWriteln('Next steps:');
        static::cliWriteln('  - Run migrations: pdodb migrate up');
        static::cliWriteln('  - Create a model: pdodb model make User');
        static::cliWriteln('  - Create a migration: pdodb migrate create create_users_table');
    }
}
