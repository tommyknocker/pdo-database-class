<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\InitConfigGenerator;

/**
 * Tests for InitConfigGenerator class.
 */
final class InitConfigGeneratorTests extends BaseSharedTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
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

    public function testGenerateEnvBasic(): void
    {
        $config = [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'testdb',
            'username' => 'testuser',
            'password' => 'testpass',
            'charset' => 'utf8mb4',
        ];
        $structure = [];
        $path = $this->tempDir . '/.env';

        InitConfigGenerator::generateEnv($config, $structure, $path);

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('PDODB_DRIVER=mysql', $content);
        $this->assertStringContainsString('PDODB_HOST=localhost', $content);
        $this->assertStringContainsString('PDODB_PORT=3306', $content);
        $this->assertStringContainsString('PDODB_DATABASE=testdb', $content);
        $this->assertStringContainsString('PDODB_USERNAME=testuser', $content);
        $this->assertStringContainsString('PDODB_PASSWORD=testpass', $content);
        $this->assertStringContainsString('PDODB_CHARSET=utf8mb4', $content);
    }

    public function testGenerateEnvWithSqlite(): void
    {
        $config = [
            'driver' => 'sqlite',
            'path' => '/path/to/database.db',
        ];
        $structure = [];
        $path = $this->tempDir . '/.env';

        InitConfigGenerator::generateEnv($config, $structure, $path);

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('PDODB_DRIVER=sqlite', $content);
        $this->assertStringContainsString('PDODB_PATH=/path/to/database.db', $content);
    }

    public function testGenerateEnvWithCacheFilesystem(): void
    {
        $config = [
            'driver' => 'mysql',
            'cache' => [
                'enabled' => true,
                'type' => 'filesystem',
                'default_lifetime' => 7200,
                'prefix' => 'app_',
                'directory' => '/tmp/cache',
            ],
        ];
        $structure = [];
        $path = $this->tempDir . '/.env';

        InitConfigGenerator::generateEnv($config, $structure, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('PDODB_CACHE_ENABLED=true', $content);
        $this->assertStringContainsString('PDODB_CACHE_TYPE=filesystem', $content);
        $this->assertStringContainsString('PDODB_CACHE_TTL=7200', $content);
        $this->assertStringContainsString('PDODB_CACHE_PREFIX=app_', $content);
        $this->assertStringContainsString('PDODB_CACHE_DIRECTORY=/tmp/cache', $content);
    }

    public function testGenerateEnvWithCacheRedis(): void
    {
        $config = [
            'driver' => 'mysql',
            'cache' => [
                'enabled' => true,
                'type' => 'redis',
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 1,
                'password' => 'redispass',
            ],
        ];
        $structure = [];
        $path = $this->tempDir . '/.env';

        InitConfigGenerator::generateEnv($config, $structure, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('PDODB_CACHE_TYPE=redis', $content);
        $this->assertStringContainsString('PDODB_CACHE_REDIS_HOST=127.0.0.1', $content);
        $this->assertStringContainsString('PDODB_CACHE_REDIS_PORT=6379', $content);
        $this->assertStringContainsString('PDODB_CACHE_REDIS_DATABASE=1', $content);
        $this->assertStringContainsString('PDODB_CACHE_REDIS_PASSWORD=redispass', $content);
    }

    public function testGenerateEnvWithCacheMemcached(): void
    {
        $config = [
            'driver' => 'mysql',
            'cache' => [
                'enabled' => true,
                'type' => 'memcached',
                'servers' => [
                    ['127.0.0.1', 11211],
                    ['127.0.0.1', 11212],
                ],
            ],
        ];
        $structure = [];
        $path = $this->tempDir . '/.env';

        InitConfigGenerator::generateEnv($config, $structure, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('PDODB_CACHE_TYPE=memcached', $content);
        $this->assertStringContainsString('PDODB_CACHE_MEMCACHED_SERVERS=127.0.0.1:11211,127.0.0.1:11212', $content);
    }

    public function testGenerateEnvWithProjectStructure(): void
    {
        $config = ['driver' => 'mysql'];
        $structure = [
            'migrations' => 'migrations',
            'models' => 'models',
            'repositories' => 'repositories',
            'services' => 'services',
            'seeds' => 'seeds',
        ];
        $path = $this->tempDir . '/.env';

        InitConfigGenerator::generateEnv($config, $structure, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('PDODB_MIGRATION_PATH=migrations', $content);
        $this->assertStringContainsString('PDODB_MODEL_PATH=models', $content);
        $this->assertStringContainsString('PDODB_REPOSITORY_PATH=repositories', $content);
        $this->assertStringContainsString('PDODB_SERVICE_PATH=services', $content);
        $this->assertStringContainsString('PDODB_SEED_PATH=seeds', $content);
    }

    public function testGenerateConfigPhpBasic(): void
    {
        $config = [
            'driver' => 'mysql',
            'host' => 'localhost',
            'database' => 'testdb',
            'username' => 'testuser',
            'password' => 'testpass',
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString('declare(strict_types=1);', $content);
        $this->assertStringContainsString("'driver' => 'mysql'", $content);
        $this->assertStringContainsString("'host' => 'localhost'", $content);
        $this->assertStringContainsString("'database' => 'testdb'", $content);
        $this->assertStringContainsString("'username' => 'testuser'", $content);
        $this->assertStringContainsString("'password' => 'testpass'", $content);
    }

    public function testGenerateConfigPhpWithMultipleConnections(): void
    {
        $config = [
            'default' => 'primary',
            'connections' => [
                'primary' => [
                    'driver' => 'mysql',
                    'host' => 'localhost',
                    'database' => 'primary_db',
                ],
                'secondary' => [
                    'driver' => 'postgresql',
                    'host' => 'localhost',
                    'database' => 'secondary_db',
                ],
            ],
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString("'default' => 'primary'", $content);
        $this->assertStringContainsString("'connections' => [", $content);
        $this->assertStringContainsString("'primary' => [", $content);
        $this->assertStringContainsString("'secondary' => [", $content);
    }

    public function testGenerateConfigPhpWithCache(): void
    {
        $config = [
            'driver' => 'mysql',
            'cache' => [
                'enabled' => true,
                'type' => 'filesystem',
                'default_lifetime' => 3600,
            ],
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString("'cache' => [", $content);
        $this->assertStringContainsString("'enabled' => true", $content);
        $this->assertStringContainsString("'type' => 'filesystem'", $content);
    }

    public function testGenerateConfigPhpWithAdvancedOptions(): void
    {
        $config = [
            'driver' => 'mysql',
            'prefix' => 'app_',
            'enable_regexp' => true,
            'retry' => [
                'max_attempts' => 3,
                'delay' => 1000,
            ],
            'compilation_cache' => [
                'enabled' => true,
            ],
            'stmt_pool' => [
                'enabled' => true,
            ],
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString("'prefix' => 'app_'", $content);
        $this->assertStringContainsString("'enable_regexp' => true", $content);
        $this->assertStringContainsString("'retry' => [", $content);
        $this->assertStringContainsString("'compilation_cache' => [", $content);
        $this->assertStringContainsString("'stmt_pool' => [", $content);
    }

    public function testGenerateConfigPhpWithSqlite(): void
    {
        $config = [
            'driver' => 'sqlite',
            'path' => ':memory:',
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString("'driver' => 'sqlite'", $content);
        $this->assertStringContainsString("'path' => ':memory:'", $content);
        // SQLite should not have dbname
        $this->assertStringNotContainsString("'dbname'", $content);
    }

    public function testGenerateConfigPhpWithMssql(): void
    {
        $config = [
            'driver' => 'mssql',
            'host' => 'localhost',
            'database' => 'testdb',
            'trust_server_certificate' => true,
            'encrypt' => false,
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString("'driver' => 'mssql'", $content);
        $this->assertStringContainsString("'trust_server_certificate' => true", $content);
        $this->assertStringContainsString("'encrypt' => false", $content);
    }

    public function testGenerateConfigPhpArrayToStringWithNestedArrays(): void
    {
        $config = [
            'driver' => 'mysql',
            'cache' => [
                'enabled' => true,
                'options' => [
                    'nested' => [
                        'deep' => 'value',
                    ],
                ],
            ],
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString("'cache' => [", $content);
        $this->assertStringContainsString("'options' => [", $content);
        $this->assertStringContainsString("'nested' => [", $content);
        $this->assertStringContainsString("'deep' => 'value'", $content);
    }

    public function testGenerateConfigPhpArrayToStringWithBooleanAndNull(): void
    {
        $config = [
            'driver' => 'mysql',
            'enable_regexp' => true,
            'trust_server_certificate' => false,
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $content = file_get_contents($path);
        $this->assertStringContainsString("'enable_regexp' => true", $content);
        $this->assertStringContainsString("'trust_server_certificate' => false", $content);
    }

    public function testGenerateConfigPhpArrayToStringWithEscapedStrings(): void
    {
        $config = [
            'driver' => 'mysql',
            'password' => "test'password\"with\"quotes",
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $content = file_get_contents($path);
        // Should properly escape quotes
        $this->assertStringContainsString("'password' => 'test\\'password\\\"with\\\"quotes'", $content);
    }

    public function testGenerateConfigPhpWithDbnameForNonSqlite(): void
    {
        $config = [
            'driver' => 'mysql',
            'database' => 'testdb',
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $content = file_get_contents($path);
        // MySQL should have dbname set from database
        $this->assertStringContainsString("'dbname' => 'testdb'", $content);
    }

    public function testGenerateConfigPhpWithExplicitDbname(): void
    {
        $config = [
            'driver' => 'mysql',
            'database' => 'testdb',
            'dbname' => 'explicit_dbname',
        ];
        $path = $this->tempDir . '/config.php';

        InitConfigGenerator::generateConfigPhp($config, $path);

        $content = file_get_contents($path);
        // Should use explicit dbname
        $this->assertStringContainsString("'dbname' => 'explicit_dbname'", $content);
    }
}
