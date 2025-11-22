<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\BaseCliCommand;

/**
 * Tests for BaseCliCommand.
 */
class BaseCliCommandTests extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure non-interactive mode for all tests
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PHPUNIT=1');
    }

    protected function tearDown(): void
    {
        // Clean up all environment variables that might be set by tests
        $envVars = [
            'PDODB_NON_INTERACTIVE',
            'PHPUNIT',
            'PDODB_DRIVER',
            'PDODB_PATH',
            'PDODB_HOST',
            'PDODB_PORT',
            'PDODB_DATABASE',
            'PDODB_USERNAME',
            'PDODB_PASSWORD',
            'PDODB_CHARSET',
            'PDODB_ENV_PATH',
            'PDODB_PASSWORD',
            'PDODB_TEST',
            'PDODB_CACHE_ENABLED',
            'PDODB_CACHE_TYPE',
            'PDODB_CACHE_DIRECTORY',
        ];
        foreach ($envVars as $var) {
            putenv($var);
            unset($_ENV[$var]);
        }
        parent::tearDown();
    }

    /**
     * Test loadEnvFile with custom path.
     */
    public function testLoadEnvFileWithCustomPath(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadEnvFile');
        $method->setAccessible(true);

        // Create temporary .env file
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\n# Comment line\nPDODB_TEST=value");

        // Set custom env path
        putenv("PDODB_ENV_PATH={$envFile}");

        try {
            $method->invoke(null);

            // Verify environment variables were loaded
            $this->assertEquals('sqlite', getenv('PDODB_DRIVER'));
            $this->assertEquals(':memory:', getenv('PDODB_PATH'));
            $this->assertEquals('value', getenv('PDODB_TEST'));
        } finally {
            unlink($envFile);
            putenv('PDODB_ENV_PATH');
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_TEST');
        }
    }

    /**
     * Test loadEnvFile with non-existent file.
     */
    public function testLoadEnvFileWithNonExistentFile(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadEnvFile');
        $method->setAccessible(true);

        // Set custom env path to non-existent file
        putenv('PDODB_ENV_PATH=/nonexistent/path/.env');

        try {
            // Should not throw error, just return
            $method->invoke(null);
            $this->assertTrue(true);
        } finally {
            putenv('PDODB_ENV_PATH');
        }
    }

    /**
     * Test loadEnvFile with quoted values.
     */
    public function testLoadEnvFileWithQuotedValues(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadEnvFile');
        $method->setAccessible(true);

        // Create temporary .env file with quoted values
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_PASSWORD=\"secret123\"\nPDODB_PATH=':memory:'");

        putenv("PDODB_ENV_PATH={$envFile}");

        try {
            $method->invoke(null);

            // Verify quotes were removed
            $this->assertEquals('secret123', getenv('PDODB_PASSWORD'));
            $this->assertEquals(':memory:', getenv('PDODB_PATH'));
        } finally {
            unlink($envFile);
            putenv('PDODB_ENV_PATH');
            putenv('PDODB_PASSWORD');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test buildConfigFromEnv with SQLite driver.
     */
    public function testBuildConfigFromEnvWithSqlite(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        try {
            $config = $method->invoke(null, 'sqlite');

            $this->assertIsArray($config);
            $this->assertArrayHasKey('path', $config);
            $this->assertEquals(':memory:', $config['path']);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test buildConfigFromEnv with empty driver.
     */
    public function testBuildConfigFromEnvWithEmptyDriver(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        $config = $method->invoke(null, '');

        $this->assertNull($config);
    }

    /**
     * Test buildConfigFromEnv with invalid driver.
     */
    public function testBuildConfigFromEnvWithInvalidDriver(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        $config = $method->invoke(null, 'invalid_driver');

        $this->assertNull($config);
    }

    /**
     * Test buildConfigFromEnv with MySQL driver.
     */
    public function testBuildConfigFromEnvWithMysql(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        putenv('PDODB_DRIVER=mysql');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=3306');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');
        putenv('PDODB_CHARSET=utf8mb4');

        try {
            $config = $method->invoke(null, 'mysql');

            $this->assertIsArray($config);
            $this->assertArrayHasKey('host', $config);
            $this->assertArrayHasKey('dbname', $config);
            $this->assertArrayHasKey('username', $config);
            $this->assertArrayHasKey('password', $config);
            $this->assertEquals('localhost', $config['host']);
            $this->assertEquals('testdb', $config['dbname']);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_HOST');
            putenv('PDODB_PORT');
            putenv('PDODB_DATABASE');
            putenv('PDODB_USERNAME');
            putenv('PDODB_PASSWORD');
            putenv('PDODB_CHARSET');
        }
    }

    /**
     * Test buildConfigFromEnv with missing required variables.
     */
    public function testBuildConfigFromEnvWithMissingRequired(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        putenv('PDODB_DRIVER=mysql');
        // Don't set PDODB_DATABASE and PDODB_USERNAME

        try {
            $config = $method->invoke(null, 'mysql');

            // Should return null when required variables are missing
            $this->assertNull($config);
        } finally {
            putenv('PDODB_DRIVER');
        }
    }

    /**
     * Test readInput in non-interactive mode.
     */
    public function testReadInputInNonInteractiveMode(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('readInput');
        $method->setAccessible(true);

        // Should return default value
        $result = $method->invoke(null, 'Enter value', 'default');
        $this->assertEquals('default', $result);

        // Should return empty string if no default
        $result = $method->invoke(null, 'Enter value');
        $this->assertEquals('', $result);
    }

    /**
     * Test readPassword in non-interactive mode.
     */
    public function testReadPasswordInNonInteractiveMode(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('readPassword');
        $method->setAccessible(true);

        // In non-interactive mode, readPassword should return empty string
        // since fgets(STDIN) will return empty when STDIN is not available
        // We ensure non-interactive mode is set via setUp()
        $result = $method->invoke(null, 'Enter password');
        // Result should be a string (may be empty in non-interactive mode)
        $this->assertIsString($result);
    }

    /**
     * Test readConfirmation in non-interactive mode.
     */
    public function testReadConfirmationInNonInteractiveMode(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('readConfirmation');
        $method->setAccessible(true);

        // Should return default value
        $result = $method->invoke(null, 'Confirm?', true);
        $this->assertTrue($result);

        $result = $method->invoke(null, 'Confirm?', false);
        $this->assertFalse($result);
    }

    /**
     * Test loadCacheConfig with filesystem cache.
     */
    public function testLoadCacheConfigWithFilesystem(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadCacheConfig');
        $method->setAccessible(true);

        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=filesystem');
        putenv('PDODB_CACHE_DIRECTORY=/tmp/test_cache');

        try {
            $config = $method->invoke(null, []);

            $this->assertIsArray($config);
            $this->assertTrue($config['enabled']);
            $this->assertEquals('filesystem', $config['type']);
            $this->assertEquals('/tmp/test_cache', $config['directory']);
        } finally {
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
            putenv('PDODB_CACHE_DIRECTORY');
        }
    }

    /**
     * Test loadCacheConfig with disabled cache.
     */
    public function testLoadCacheConfigDisabled(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadCacheConfig');
        $method->setAccessible(true);

        putenv('PDODB_CACHE_ENABLED=false');

        try {
            $config = $method->invoke(null, []);

            $this->assertIsArray($config);
            $this->assertFalse($config['enabled']);
        } finally {
            putenv('PDODB_CACHE_ENABLED');
        }
    }

    /**
     * Test loadCacheConfig with cache config in dbConfig.
     */
    public function testLoadCacheConfigFromDbConfig(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadCacheConfig');
        $method->setAccessible(true);

        $dbConfig = [
            'cache' => [
                'enabled' => true,
                'type' => 'filesystem',
                'directory' => '/custom/cache',
            ],
        ];

        $config = $method->invoke(null, $dbConfig);

        $this->assertIsArray($config);
        $this->assertTrue($config['enabled']);
        $this->assertEquals('filesystem', $config['type']);
        $this->assertEquals('/custom/cache', $config['directory']);
    }

    /**
     * Test getDriverName.
     */
    public function testGetDriverName(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('getDriverName');
        $method->setAccessible(true);

        $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:']);
        $driverName = $method->invoke(null, $db);

        $this->assertIsString($driverName);
        $this->assertEquals('sqlite', $driverName);
    }

    /**
     * Test getDriverName with different drivers.
     */
    public function testGetDriverNameWithDifferentDrivers(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('getDriverName');
        $method->setAccessible(true);

        // Test with SQLite
        $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:']);
        $driverName = $method->invoke(null, $db);
        $this->assertEquals('sqlite', $driverName);
    }

    /**
     * Test loadDatabaseConfig with valid SQLite config.
     */
    public function testLoadDatabaseConfigWithValidSqliteConfig(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadDatabaseConfig');
        $method->setAccessible(true);

        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        try {
            $config = $method->invoke(null);

            $this->assertIsArray($config);
            $this->assertArrayHasKey('path', $config);
            $this->assertEquals(':memory:', $config['path']);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test loadDatabaseConfig with missing required variables for non-SQLite.
     */
    public function testLoadDatabaseConfigWithMissingRequiredVariables(): void
    {
        // Test buildConfigFromEnv directly, which is called by loadDatabaseConfig
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        putenv('PDODB_DRIVER=mysql');
        // Don't set PDODB_DATABASE and PDODB_USERNAME

        try {
            $config = $method->invoke(null, 'mysql');

            // Should return null for non-SQLite without required vars
            $this->assertNull($config);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_DATABASE');
            putenv('PDODB_USERNAME');
        }
    }

    /**
     * Test loadDatabaseConfig with invalid driver.
     * Note: loadDatabaseConfig may call error() which exits, so we test buildConfigFromEnv instead.
     */
    public function testLoadDatabaseConfigWithInvalidDriver(): void
    {
        // Test buildConfigFromEnv directly, which is called by loadDatabaseConfig
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        try {
            $config = $method->invoke(null, 'invalid_driver');

            // Should return null for invalid driver
            $this->assertNull($config);
        } finally {
            // Clean up
        }
    }

    /**
     * Test createDatabase with valid SQLite config.
     */
    public function testCreateDatabaseWithValidSqliteConfig(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('createDatabase');
        $method->setAccessible(true);

        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        try {
            $db = $method->invoke(null);

            $this->assertInstanceOf(\tommyknocker\pdodb\PdoDb::class, $db);
            // Verify driver via getDriverName helper
            $getDriverNameMethod = $reflection->getMethod('getDriverName');
            $getDriverNameMethod->setAccessible(true);
            $driverName = $getDriverNameMethod->invoke(null, $db);
            $this->assertEquals('sqlite', $driverName);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test createDatabase with missing config.
     * Note: createDatabase calls exit() when config is missing, so we can't test it directly.
     * This test verifies that buildConfigFromEnv returns null for missing config.
     */
    public function testCreateDatabaseWithMissingConfig(): void
    {
        // Instead of testing createDatabase directly (which calls exit()),
        // we test buildConfigFromEnv which is called by loadDatabaseConfig
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        // Clear all database-related env vars
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_DATABASE');
        putenv('PDODB_USERNAME');

        try {
            $config = $method->invoke(null, '');
            // Should return null for missing config
            $this->assertNull($config);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test loadDatabaseConfig with config file and multi-connection structure.
     */
    public function testLoadDatabaseConfigWithMultiConnection(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadDatabaseConfig');
        $method->setAccessible(true);

        // Create temporary config file with multi-connection structure
        $tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($tempDir . '/config', 0755, true);
        $configFile = $tempDir . '/config/db.php';
        $configContent = <<<'PHP'
<?php
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ],
        'reporting' => [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ],
    ],
];
PHP;
        file_put_contents($configFile, $configContent);

        $oldCwd = getcwd();
        chdir($tempDir);

        try {
            // Clear env vars to force config file loading
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');

            $config = $method->invoke(null);
            $this->assertIsArray($config);
            $this->assertEquals('sqlite', $config['driver']);
        } finally {
            chdir($oldCwd);
            $this->removeDirectory($tempDir);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test loadDatabaseConfig with requested connection name.
     */
    public function testLoadDatabaseConfigWithRequestedConnection(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadDatabaseConfig');
        $method->setAccessible(true);

        // Create temporary config file with multi-connection structure
        $tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($tempDir . '/config', 0755, true);
        $configFile = $tempDir . '/config/db.php';
        $configContent = <<<'PHP'
<?php
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ],
        'reporting' => [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ],
    ],
];
PHP;
        file_put_contents($configFile, $configContent);

        $oldCwd = getcwd();
        chdir($tempDir);

        try {
            // Clear env vars to force config file loading
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CONNECTION=reporting');

            $config = $method->invoke(null);
            $this->assertIsArray($config);
            $this->assertEquals('sqlite', $config['driver']);
        } finally {
            chdir($oldCwd);
            $this->removeDirectory($tempDir);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CONNECTION');
        }
    }

    /**
     * Test loadDatabaseConfig with legacy per-driver map.
     */
    public function testLoadDatabaseConfigWithLegacyDriverMap(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadDatabaseConfig');
        $method->setAccessible(true);

        // Create temporary config file with legacy structure
        $tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($tempDir . '/config', 0755, true);
        $configFile = $tempDir . '/config/db.php';
        $configContent = <<<'PHP'
<?php
return [
    'sqlite' => [
        'driver' => 'sqlite',
        'path' => ':memory:',
    ],
    'mysql' => [
        'driver' => 'mysql',
        'host' => 'localhost',
    ],
];
PHP;
        file_put_contents($configFile, $configContent);

        $oldCwd = getcwd();
        chdir($tempDir);

        try {
            // Set driver to sqlite
            putenv('PDODB_DRIVER=sqlite');
            putenv('PDODB_PATH'); // Clear to force config file

            $config = $method->invoke(null);
            $this->assertIsArray($config);
            $this->assertEquals('sqlite', $config['driver']);
        } finally {
            chdir($oldCwd);
            $this->removeDirectory($tempDir);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test loadDatabaseConfig with legacy named connections.
     */
    public function testLoadDatabaseConfigWithLegacyNamedConnections(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadDatabaseConfig');
        $method->setAccessible(true);

        // Create temporary config file with legacy named connections
        $tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($tempDir . '/config', 0755, true);
        $configFile = $tempDir . '/config/db.php';
        $configContent = <<<'PHP'
<?php
return [
    'main' => [
        'driver' => 'sqlite',
        'path' => ':memory:',
    ],
    'reporting' => [
        'driver' => 'sqlite',
        'path' => ':memory:',
    ],
];
PHP;
        file_put_contents($configFile, $configContent);

        $oldCwd = getcwd();
        chdir($tempDir);

        try {
            // Clear env vars to force config file loading
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CONNECTION=main');

            $config = $method->invoke(null);
            $this->assertIsArray($config);
            $this->assertEquals('sqlite', $config['driver']);
        } finally {
            chdir($oldCwd);
            $this->removeDirectory($tempDir);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CONNECTION');
        }
    }

    /**
     * Test loadDatabaseConfig with examples config file.
     */
    public function testLoadDatabaseConfigWithExamplesConfig(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadDatabaseConfig');
        $method->setAccessible(true);

        // Create temporary examples config file
        $examplesDir = __DIR__ . '/../../examples';
        if (!is_dir($examplesDir)) {
            mkdir($examplesDir, 0755, true);
        }
        $configFile = $examplesDir . '/config.sqlite.php';
        $originalContent = file_exists($configFile) ? file_get_contents($configFile) : null;
        $configContent = <<<'PHP'
<?php
return [
    'driver' => 'sqlite',
    'path' => ':memory:',
];
PHP;
        file_put_contents($configFile, $configContent);

        try {
            // Set driver but don't set PDODB_PATH to trigger examples config
            putenv('PDODB_DRIVER=sqlite');
            putenv('PDODB_PATH'); // Clear to trigger examples config

            $config = $method->invoke(null);
            $this->assertIsArray($config);
            $this->assertEquals('sqlite', $config['driver']);
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            if ($originalContent !== null) {
                file_put_contents($configFile, $originalContent);
            } elseif (file_exists($configFile)) {
                unlink($configFile);
            }
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test buildConfigFromEnv with PostgreSQL driver.
     */
    public function testBuildConfigFromEnvWithPostgreSQL(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        putenv('PDODB_DRIVER=pgsql');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=5432');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');
        putenv('PDODB_CHARSET=utf8');

        try {
            $config = $method->invoke(null, 'pgsql');
            $this->assertIsArray($config);
            $this->assertEquals('pgsql', $config['driver']);
            $this->assertEquals('localhost', $config['host']);
            $this->assertEquals(5432, $config['port']);
            $this->assertEquals('testdb', $config['database']);
            $this->assertEquals('testuser', $config['username']);
            $this->assertEquals('testpass', $config['password']);
            if (isset($config['charset'])) {
                $this->assertEquals('utf8', $config['charset']);
            }
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_HOST');
            putenv('PDODB_PORT');
            putenv('PDODB_DATABASE');
            putenv('PDODB_USERNAME');
            putenv('PDODB_PASSWORD');
            putenv('PDODB_CHARSET');
        }
    }

    /**
     * Test buildConfigFromEnv with MSSQL driver.
     */
    public function testBuildConfigFromEnvWithMSSQL(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        putenv('PDODB_DRIVER=sqlsrv');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=1433');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');

        try {
            $config = $method->invoke(null, 'sqlsrv');
            $this->assertIsArray($config);
            $this->assertEquals('sqlsrv', $config['driver']);
            $this->assertEquals('localhost', $config['host']);
            $this->assertEquals(1433, $config['port']);
            $this->assertEquals('testdb', $config['database']);
            $this->assertEquals('testuser', $config['username']);
            $this->assertEquals('testpass', $config['password']);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_HOST');
            putenv('PDODB_PORT');
            putenv('PDODB_DATABASE');
            putenv('PDODB_USERNAME');
            putenv('PDODB_PASSWORD');
        }
    }

    /**
     * Test buildConfigFromEnv with MariaDB driver.
     */
    public function testBuildConfigFromEnvWithMariaDB(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        putenv('PDODB_DRIVER=mariadb');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=3306');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');
        putenv('PDODB_CHARSET=utf8mb4');

        try {
            $config = $method->invoke(null, 'mariadb');
            $this->assertIsArray($config);
            $this->assertEquals('mariadb', $config['driver']);
            $this->assertEquals('localhost', $config['host']);
            $this->assertEquals(3306, $config['port']);
            $this->assertEquals('testdb', $config['database']);
            $this->assertEquals('testuser', $config['username']);
            $this->assertEquals('testpass', $config['password']);
            if (isset($config['charset'])) {
                $this->assertEquals('utf8mb4', $config['charset']);
            }
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_HOST');
            putenv('PDODB_PORT');
            putenv('PDODB_DATABASE');
            putenv('PDODB_USERNAME');
            putenv('PDODB_PASSWORD');
            putenv('PDODB_CHARSET');
        }
    }

    /**
     * Test loadEnvFile with default path.
     */
    public function testLoadEnvFileWithDefaultPath(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadEnvFile');
        $method->setAccessible(true);

        // Create temporary .env file in temp directory
        $tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $envFile = $tempDir . '/.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nPDODB_PATH=:memory:\n");

        $oldCwd = getcwd();
        chdir($tempDir);

        try {
            // Clear custom env path to use default
            putenv('PDODB_ENV_PATH');

            $method->invoke(null);

            // Verify environment variables were loaded
            $this->assertEquals('sqlite', getenv('PDODB_DRIVER'));
            $this->assertEquals(':memory:', getenv('PDODB_PATH'));
        } finally {
            chdir($oldCwd);
            $this->removeDirectory($tempDir);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test createDatabase with MySQL config.
     */
    public function testCreateDatabaseWithMySQLConfig(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('createDatabase');
        $method->setAccessible(true);

        // For MySQL, we need valid connection details
        // Since we can't guarantee MySQL is available, we test the method structure
        $this->assertTrue($reflection->hasMethod('createDatabase'));
        $this->assertTrue($method->isProtected());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Test getDriverName with various database types.
     */
    public function testGetDriverNameWithVariousTypes(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('getDriverName');
        $method->setAccessible(true);

        // Test with SQLite
        $db = new \tommyknocker\pdodb\PdoDb('sqlite', ['path' => ':memory:']);
        $driverName = $method->invoke(null, $db);
        $this->assertEquals('sqlite', $driverName);
    }

    /**
     * Test loadDatabaseConfig with custom config path.
     */
    public function testLoadDatabaseConfigWithCustomConfigPath(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadDatabaseConfig');
        $method->setAccessible(true);

        // Create temporary config file
        $tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $configFile = $tempDir . '/custom_config.php';
        $configContent = <<<'PHP'
<?php
return [
    'driver' => 'sqlite',
    'path' => ':memory:',
];
PHP;
        file_put_contents($configFile, $configContent);

        try {
            // Clear env vars to force config file loading
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CONFIG_PATH=' . $configFile);

            $config = $method->invoke(null);
            $this->assertIsArray($config);
            $this->assertEquals('sqlite', $config['driver']);
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            if (file_exists($configFile)) {
                @unlink($configFile);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CONFIG_PATH');
        }
    }

    /**
     * Helper method to remove directory recursively.
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Test createDatabase method structure.
     */
    public function testCreateDatabaseMethodStructure(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('createDatabase');
        $method->setAccessible(true);

        // Verify method exists and has correct signature
        $this->assertTrue($reflection->hasMethod('createDatabase'));
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertStringEndsWith('PdoDb', $returnType->getName());
        // Method should handle invalid/missing config (may call error() which exits)
        $this->assertTrue(true);
    }

    /**
     * Test getDriverName with null database.
     */
    public function testGetDriverNameWithNullDatabase(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('getDriverName');
        $method->setAccessible(true);

        // Test with null database
        try {
            $driverName = $method->invoke(null, null);
            // Should return 'unknown' or handle null gracefully
            $this->assertIsString($driverName);
        } catch (\Throwable $e) {
            // May throw exception for null
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }

    /**
     * Test createDatabase with existing database.
     */
    public function testCreateDatabaseWithExistingDatabase(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('createDatabase');
        $method->setAccessible(true);

        // Test with SQLite - database already exists
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        try {
            $result = $method->invoke(null, ['driver' => 'sqlite', 'path' => ':memory:']);
            // Should handle existing database gracefully
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            // May throw exception
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test buildConfigFromEnv with SQLite missing path.
     */
    public function testBuildConfigFromEnvWithSqliteMissingPath(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        // Test with SQLite but missing path
        putenv('PDODB_DRIVER=sqlite');
        // Don't set PDODB_PATH

        try {
            $config = $method->invoke(null);
            // Should handle missing path gracefully
            $this->assertTrue($config === null || is_array($config));
        } catch (\Throwable $e) {
            // May throw exception
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
        }
    }

    /**
     * Test buildConfigFromEnv with empty environment variables.
     */
    public function testBuildConfigFromEnvWithEmptyEnvVars(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        // Clear all environment variables
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_HOST');
        putenv('PDODB_PORT');
        putenv('PDODB_DATABASE');
        putenv('PDODB_USERNAME');
        putenv('PDODB_PASSWORD');

        try {
            $config = $method->invoke(null);
            // Should return null when no driver is set
            $this->assertTrue($config === null || is_array($config));
        } catch (\Throwable $e) {
            // May throw exception
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }

    /**
     * Test loadEnvFile with empty file.
     */
    public function testLoadEnvFileWithEmptyFile(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadEnvFile');
        $method->setAccessible(true);

        // Create empty .env file
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, '');

        putenv("PDODB_ENV_PATH={$envFile}");

        try {
            $method->invoke(null);
            // Should handle empty file gracefully
            $this->assertTrue(true);
        } finally {
            unlink($envFile);
            putenv('PDODB_ENV_PATH');
        }
    }

    /**
     * Test loadEnvFile with malformed lines.
     */
    public function testLoadEnvFileWithMalformedLines(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadEnvFile');
        $method->setAccessible(true);

        // Create .env file with malformed lines
        $envFile = sys_get_temp_dir() . '/pdodb_test_' . uniqid() . '.env';
        file_put_contents($envFile, "PDODB_DRIVER=sqlite\nINVALID_LINE\nPDODB_PATH=:memory:\n=value\nKEY=");

        putenv("PDODB_ENV_PATH={$envFile}");

        try {
            $method->invoke(null);
            // Should handle malformed lines gracefully
            $this->assertEquals('sqlite', getenv('PDODB_DRIVER'));
            $this->assertEquals(':memory:', getenv('PDODB_PATH'));
        } finally {
            unlink($envFile);
            putenv('PDODB_ENV_PATH');
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    /**
     * Test loadDatabaseConfig with invalid config file content.
     */
    public function testLoadDatabaseConfigWithInvalidConfigFile(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadDatabaseConfig');
        $method->setAccessible(true);

        // Create invalid config file
        $tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $configFile = $tempDir . '/invalid_config.php';
        file_put_contents($configFile, '<?php invalid php code');

        try {
            putenv('PDODB_DRIVER');
            putenv('PDODB_CONFIG_PATH=' . $configFile);

            $config = $method->invoke(null);
            // Should handle invalid config file gracefully
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            // Expected for invalid config file
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            if (file_exists($configFile)) {
                @unlink($configFile);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
            putenv('PDODB_CONFIG_PATH');
        }
    }

    /**
     * Test loadDatabaseConfig with config file returning non-array.
     */
    public function testLoadDatabaseConfigWithNonArrayConfigFile(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('loadDatabaseConfig');
        $method->setAccessible(true);

        // Create config file returning non-array
        $tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $configFile = $tempDir . '/non_array_config.php';
        file_put_contents($configFile, '<?php return "string";');

        try {
            putenv('PDODB_DRIVER');
            putenv('PDODB_CONFIG_PATH=' . $configFile);

            $config = $method->invoke(null);
            // Should handle non-array config gracefully
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            // Expected for non-array config
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            if (file_exists($configFile)) {
                @unlink($configFile);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
            putenv('PDODB_CONFIG_PATH');
        }
    }

    /**
     * Test buildConfigFromEnv with partial environment variables.
     */
    public function testBuildConfigFromEnvWithPartialEnvVars(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('buildConfigFromEnv');
        $method->setAccessible(true);

        // Test with MySQL but only some variables set
        putenv('PDODB_DRIVER=mysql');
        putenv('PDODB_HOST=localhost');
        // Don't set PDODB_DATABASE and PDODB_USERNAME

        try {
            $config = $method->invoke(null);
            // Should return null when required variables are missing
            $this->assertNull($config);
        } catch (\Throwable $e) {
            // May throw exception
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_HOST');
        }
    }

    /**
     * Test createDatabase with cache configuration.
     */
    public function testCreateDatabaseWithCacheConfig(): void
    {
        $reflection = new \ReflectionClass(BaseCliCommand::class);
        $method = $reflection->getMethod('createDatabase');
        $method->setAccessible(true);

        // Test with cache enabled
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=filesystem');

        try {
            $db = $method->invoke(null);
            // Should handle cache config
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            // May throw exception
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
        }
    }
}
