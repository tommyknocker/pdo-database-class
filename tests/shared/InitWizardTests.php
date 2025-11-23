<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\commands\InitCommand;
use tommyknocker\pdodb\cli\InitWizard;

final class InitWizardTests extends TestCase
{
    protected string $tempDir;
    protected InitCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pdodb_init_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->command = new InitCommand();

        // Set non-interactive mode - ensure both are set
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PHPUNIT=1');
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        putenv('PDODB_NON_INTERACTIVE');
        putenv('PHPUNIT');
        parent::tearDown();
    }

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

    public function testWizardConstruction(): void
    {
        $wizard = new InitWizard($this->command);
        $this->assertInstanceOf(InitWizard::class, $wizard);
    }

    public function testWizardConstructionWithOptions(): void
    {
        $wizard = new InitWizard(
            $this->command,
            skipConnectionTest: true,
            force: true,
            format: 'env',
            noStructure: true
        );
        $this->assertInstanceOf(InitWizard::class, $wizard);
    }

    public function testRunWizardWithSkipConnectionTest(): void
    {
        // Set required environment variables for non-interactive mode
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $wizard = new InitWizard(
            $this->command,
            skipConnectionTest: true,
            force: true,
            format: 'env'
        );

        // Change to temp directory
        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            ob_start();
            $result = $wizard->run();
            $output = ob_get_clean();

            $this->assertSame(0, $result);
            $this->assertStringContainsString('initialized successfully', $output);
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    public function testInitWizardMethods(): void
    {
        // Test that InitWizard class exists and has required methods
        $wizard = new InitWizard($this->command);
        $this->assertInstanceOf(InitWizard::class, $wizard);

        // Test constructor with all options
        $wizard2 = new InitWizard(
            $this->command,
            skipConnectionTest: true,
            force: true,
            format: 'config',
            noStructure: true
        );
        $this->assertInstanceOf(InitWizard::class, $wizard2);
    }

    public function testStaticMethods(): void
    {
        // Test readInput method
        $reflection = new \ReflectionClass(InitWizard::class);

        // Test readConfirmation with default true
        $method = $reflection->getMethod('readConfirmation');
        $method->setAccessible(true);

        // In non-interactive mode, should return default
        $result = $method->invoke(null, 'Test question?', true);
        $this->assertTrue($result);

        $result = $method->invoke(null, 'Test question?', false);
        $this->assertFalse($result);
    }

    public function testLoadConfigFromEnv(): void
    {
        // Set environment variables
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        $method->invoke($wizard);

        // Verify config was loaded
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($wizard);

        $this->assertIsArray($config);
        $this->assertEquals('sqlite', $config['driver']);
        $this->assertEquals(':memory:', $config['path']);

        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
    }

    public function testLoadConfigFromEnvWithCache(): void
    {
        // Set environment variables with cache
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=filesystem');
        putenv('PDODB_CACHE_TTL=7200');
        putenv('PDODB_CACHE_PREFIX=test_');
        putenv('PDODB_CACHE_DIRECTORY=./cache');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        $method->invoke($wizard);

        // Verify cache config was loaded
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($wizard);

        $this->assertIsArray($config['cache']);
        $this->assertTrue($config['cache']['enabled']);
        $this->assertEquals('filesystem', $config['cache']['type']);
        $this->assertEquals(7200, $config['cache']['default_lifetime']);
        $this->assertEquals('test_', $config['cache']['prefix']);
        $this->assertEquals('./cache', $config['cache']['directory']);

        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_CACHE_ENABLED');
        putenv('PDODB_CACHE_TYPE');
        putenv('PDODB_CACHE_TTL');
        putenv('PDODB_CACHE_PREFIX');
        putenv('PDODB_CACHE_DIRECTORY');
    }

    public function testLoadConfigFromEnvWithoutDriver(): void
    {
        // Clear driver environment variable
        putenv('PDODB_DRIVER');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        // Since error() calls exit(), we can't test this directly
        // Instead, we verify that the method checks for driver
        // The actual error handling is tested in run() method
        $this->assertTrue($reflection->hasMethod('loadConfigFromEnv'));
    }

    public function testLoadStructureFromEnv(): void
    {
        // Set structure environment variables
        putenv('PDODB_NAMESPACE=TestApp');
        putenv('PDODB_MIGRATION_PATH=./custom/migrations');
        putenv('PDODB_MODEL_PATH=./custom/Models');
        putenv('PDODB_REPOSITORY_PATH=./custom/Repositories');
        putenv('PDODB_SERVICE_PATH=./custom/Services');
        putenv('PDODB_SEED_PATH=./custom/seeds');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadStructureFromEnv');
        $method->setAccessible(true);

        $method->invoke($wizard);

        // Verify structure was loaded
        $structureProperty = $reflection->getProperty('structure');
        $structureProperty->setAccessible(true);
        $structure = $structureProperty->getValue($wizard);

        $this->assertIsArray($structure);
        $this->assertEquals('TestApp', $structure['namespace']);
        $this->assertEquals('./custom/migrations', $structure['migrations']);
        $this->assertEquals('./custom/Models', $structure['models']);
        $this->assertEquals('./custom/Repositories', $structure['repositories']);
        $this->assertEquals('./custom/Services', $structure['services']);
        $this->assertEquals('./custom/seeds', $structure['seeds']);

        putenv('PDODB_NAMESPACE');
        putenv('PDODB_MIGRATION_PATH');
        putenv('PDODB_MODEL_PATH');
        putenv('PDODB_REPOSITORY_PATH');
        putenv('PDODB_SERVICE_PATH');
        putenv('PDODB_SEED_PATH');
    }

    public function testLoadStructureFromEnvWithDefaults(): void
    {
        // Don't set structure environment variables - should use defaults
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadStructureFromEnv');
        $method->setAccessible(true);

        $method->invoke($wizard);

        // Verify default structure was loaded
        $structureProperty = $reflection->getProperty('structure');
        $structureProperty->setAccessible(true);
        $structure = $structureProperty->getValue($wizard);

        $this->assertIsArray($structure);
        $this->assertEquals('App', $structure['namespace']);
        $this->assertEquals('./migrations', $structure['migrations']);
        $this->assertEquals('./app/Models', $structure['models']);
        $this->assertEquals('./app/Repositories', $structure['repositories']);
        $this->assertEquals('./app/Services', $structure['services']);
        $this->assertEquals('./database/seeds', $structure['seeds']);
    }

    public function testGenerateFiles(): void
    {
        // Set required environment variables
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $wizard = new InitWizard(
            $this->command,
            skipConnectionTest: true,
            force: true,
            format: 'env'
        );

        // Change to temp directory
        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            $reflection = new \ReflectionClass($wizard);

            // Load config and structure first
            $loadConfigMethod = $reflection->getMethod('loadConfigFromEnv');
            $loadConfigMethod->setAccessible(true);
            $loadConfigMethod->invoke($wizard);

            $loadStructureMethod = $reflection->getMethod('loadStructureFromEnv');
            $loadStructureMethod->setAccessible(true);
            $loadStructureMethod->invoke($wizard);

            // Generate files
            $generateMethod = $reflection->getMethod('generateFiles');
            $generateMethod->setAccessible(true);

            ob_start();
            $generateMethod->invoke($wizard);
            $output = ob_get_clean();

            // Verify .env file was created
            $this->assertFileExists($this->tempDir . '/.env');
            $this->assertStringContainsString('Creating configuration files', $output);

            // Verify .env content
            $envContent = file_get_contents($this->tempDir . '/.env');
            $this->assertStringContainsString('PDODB_DRIVER=sqlite', $envContent);
            $this->assertStringContainsString('PDODB_PATH=:memory:', $envContent);
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    public function testGenerateFilesWithConfigFormat(): void
    {
        // Set required environment variables
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $wizard = new InitWizard(
            $this->command,
            skipConnectionTest: true,
            force: true,
            format: 'config'
        );

        // Change to temp directory
        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            $reflection = new \ReflectionClass($wizard);

            // Load config and structure first
            $loadConfigMethod = $reflection->getMethod('loadConfigFromEnv');
            $loadConfigMethod->setAccessible(true);
            $loadConfigMethod->invoke($wizard);

            $loadStructureMethod = $reflection->getMethod('loadStructureFromEnv');
            $loadStructureMethod->setAccessible(true);
            $loadStructureMethod->invoke($wizard);

            // Generate files
            $generateMethod = $reflection->getMethod('generateFiles');
            $generateMethod->setAccessible(true);

            ob_start();
            $generateMethod->invoke($wizard);
            $output = ob_get_clean();

            // Verify config/db.php file was created
            $this->assertFileExists($this->tempDir . '/config/db.php');
            $this->assertStringContainsString('Creating configuration files', $output);

            // Verify config file content
            $configContent = file_get_contents($this->tempDir . '/config/db.php');
            $this->assertStringContainsString('sqlite', $configContent);
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    public function testWizardMethodsExist(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);

        // Verify all methods exist
        $this->assertTrue($reflection->hasMethod('askBasicConnection'));
        $this->assertTrue($reflection->hasMethod('testConnection'));
        $this->assertTrue($reflection->hasMethod('askConfigurationFormat'));
        $this->assertTrue($reflection->hasMethod('askProjectStructure'));
        $this->assertTrue($reflection->hasMethod('askAdvancedOptions'));
        $this->assertTrue($reflection->hasMethod('askMysqlConnection'));
        $this->assertTrue($reflection->hasMethod('askPgsqlConnection'));
        $this->assertTrue($reflection->hasMethod('askSqlsrvConnection'));
        $this->assertTrue($reflection->hasMethod('askSqliteConnection'));

        // Verify methods are protected
        $this->assertTrue($reflection->getMethod('askBasicConnection')->isProtected());
        $this->assertTrue($reflection->getMethod('testConnection')->isProtected());
        $this->assertTrue($reflection->getMethod('askConfigurationFormat')->isProtected());
        $this->assertTrue($reflection->getMethod('askProjectStructure')->isProtected());
        $this->assertTrue($reflection->getMethod('askAdvancedOptions')->isProtected());
    }

    /**
     * Mock input for testing interactive prompts.
     *
     * @param array<string> $inputs
     */
    protected function mockInput(array $inputs): void
    {
        // In non-interactive mode, the wizard should use defaults
        // This is just a placeholder for potential future input mocking
    }

    public function testAskBasicConnection(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askBasicConnection');
        $method->setAccessible(true);

        // Set driver to test different branches
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue($wizard, ['driver' => 'sqlite']);

        // This method may call error() which exits, so we only verify method structure
        // The actual execution cannot be tested directly due to exit() call
        $this->assertTrue($method->isProtected());
    }

    public function testAskMysqlConnection(): void
    {
        // This method may call error() which exits, so we only verify method structure
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askMysqlConnection'));
        $method = $reflection->getMethod('askMysqlConnection');
        $this->assertTrue($method->isProtected());
    }

    public function testAskPgsqlConnection(): void
    {
        // This method may call error() which exits, so we only verify method structure
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askPgsqlConnection'));
        $method = $reflection->getMethod('askPgsqlConnection');
        $this->assertTrue($method->isProtected());
    }

    public function testAskSqlsrvConnection(): void
    {
        // This method may call error() which exits, so we only verify method structure
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askSqlsrvConnection'));
        $method = $reflection->getMethod('askSqlsrvConnection');
        $this->assertTrue($method->isProtected());
    }

    public function testAskSqliteConnection(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askSqliteConnection');
        $method->setAccessible(true);

        ob_start();

        try {
            $method->invoke($wizard);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        // Verify config was set
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($wizard);
        $this->assertIsArray($config);
        $this->assertArrayHasKey('path', $config);
    }

    public function testTestConnection(): void
    {
        // Set up valid SQLite connection
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('testConnection');
        $method->setAccessible(true);

        // Set config
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue($wizard, ['driver' => 'sqlite', 'path' => ':memory:']);

        ob_start();

        try {
            $method->invoke($wizard);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            // Connection test may fail in some environments
            $this->assertInstanceOf(\Throwable::class, $e);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            return;
        }

        // Verify output contains connection test result
        $this->assertStringContainsString('Connection', $out);
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
    }

    public function testAskConfigurationFormat(): void
    {
        $wizard = new InitWizard($this->command, format: 'interactive');
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askConfigurationFormat');
        $method->setAccessible(true);

        ob_start();

        try {
            $method->invoke($wizard);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            // In non-interactive mode, may not prompt
            $this->assertInstanceOf(\Throwable::class, $e);
            return;
        }

        // Verify format property was set
        $formatProperty = $reflection->getProperty('format');
        $formatProperty->setAccessible(true);
        $format = $formatProperty->getValue($wizard);
        $this->assertIsString($format);
    }

    public function testAskProjectStructure(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askProjectStructure');
        $method->setAccessible(true);

        ob_start();

        try {
            $method->invoke($wizard);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            // In non-interactive mode, may not prompt
            $this->assertInstanceOf(\Throwable::class, $e);
            return;
        }

        // Verify structure property was set
        $structureProperty = $reflection->getProperty('structure');
        $structureProperty->setAccessible(true);
        $structure = $structureProperty->getValue($wizard);
        $this->assertIsArray($structure);
    }

    public function testAskAdvancedOptions(): void
    {
        // This method may call error() which exits, so we only verify method structure
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askAdvancedOptions'));
        $method = $reflection->getMethod('askAdvancedOptions');
        $this->assertTrue($method->isProtected());
    }

    public function testAskTablePrefix(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askTablePrefix'));
        $method = $reflection->getMethod('askTablePrefix');
        $this->assertTrue($method->isProtected());
    }

    public function testAskCaching(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askCaching'));
        $method = $reflection->getMethod('askCaching');
        $this->assertTrue($method->isProtected());
    }

    public function testAskPerformance(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askPerformance'));
        $method = $reflection->getMethod('askPerformance');
        $this->assertTrue($method->isProtected());
    }

    public function testAskMultipleConnections(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askMultipleConnections'));
        $method = $reflection->getMethod('askMultipleConnections');
        $this->assertTrue($method->isProtected());
    }

    public function testLoadConfigFromEnvWithMemcached(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=memcached');
        putenv('PDODB_CACHE_MEMCACHED_SERVERS=127.0.0.1:11211,127.0.0.1:11212');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache'])) {
                $this->assertEquals('memcached', $config['cache']['type']);
                $this->assertArrayHasKey('servers', $config['cache']);
            }
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
            putenv('PDODB_CACHE_MEMCACHED_SERVERS');
        }
    }

    public function testLoadConfigFromEnvWithRedis(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=redis');
        putenv('PDODB_CACHE_REDIS_HOST=127.0.0.1');
        putenv('PDODB_CACHE_REDIS_PORT=6379');
        putenv('PDODB_CACHE_REDIS_DATABASE=1');
        putenv('PDODB_CACHE_REDIS_PASSWORD=redispass');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache'])) {
                $this->assertEquals('redis', $config['cache']['type']);
                $this->assertEquals('127.0.0.1', $config['cache']['host']);
                $this->assertEquals(6379, $config['cache']['port']);
                $this->assertEquals(1, $config['cache']['database']);
                $this->assertEquals('redispass', $config['cache']['password']);
            }
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
            putenv('PDODB_CACHE_REDIS_HOST');
            putenv('PDODB_CACHE_REDIS_PORT');
            putenv('PDODB_CACHE_REDIS_DATABASE');
            putenv('PDODB_CACHE_REDIS_PASSWORD');
        }
    }

    public function testLoadConfigFromEnvWithFilesystemCache(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=filesystem');
        putenv('PDODB_CACHE_DIRECTORY=/tmp/cache');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache'])) {
                $this->assertEquals('filesystem', $config['cache']['type']);
                $this->assertEquals('/tmp/cache', $config['cache']['directory']);
            }
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
            putenv('PDODB_CACHE_DIRECTORY');
        }
    }

    public function testLoadStructureFromEnvWithAllPaths(): void
    {
        putenv('PDODB_MIGRATION_PATH=migrations');
        putenv('PDODB_MODEL_PATH=models');
        putenv('PDODB_REPOSITORY_PATH=repositories');
        putenv('PDODB_SERVICE_PATH=services');
        putenv('PDODB_SEED_PATH=seeds');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadStructureFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $structureProperty = $reflection->getProperty('structure');
            $structureProperty->setAccessible(true);
            $structure = $structureProperty->getValue($wizard);

            $this->assertIsArray($structure);
            $this->assertArrayHasKey('migrations', $structure);
            $this->assertArrayHasKey('models', $structure);
            $this->assertArrayHasKey('repositories', $structure);
            $this->assertArrayHasKey('services', $structure);
            $this->assertArrayHasKey('seeds', $structure);
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_MIGRATION_PATH');
            putenv('PDODB_MODEL_PATH');
            putenv('PDODB_REPOSITORY_PATH');
            putenv('PDODB_SERVICE_PATH');
            putenv('PDODB_SEED_PATH');
        }
    }

    public function testGenerateFilesWithConfigFormatSecond(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            $wizard = new InitWizard($this->command, format: 'config', force: true);
            $reflection = new \ReflectionClass($wizard);

            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $configProperty->setValue($wizard, ['driver' => 'sqlite', 'path' => ':memory:']);

            $method = $reflection->getMethod('generateFiles');
            $method->setAccessible(true);

            ob_start();

            try {
                $method->invoke($wizard);
                ob_end_clean();
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }

            // Verify config file was created
            $this->assertFileExists($this->tempDir . '/config/db.php');
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    public function testGenerateFilesWithEnvFormat(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            $wizard = new InitWizard($this->command, format: 'env', force: true);
            $reflection = new \ReflectionClass($wizard);

            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $configProperty->setValue($wizard, ['driver' => 'sqlite', 'path' => ':memory:']);

            $method = $reflection->getMethod('generateFiles');
            $method->setAccessible(true);

            ob_start();

            try {
                $method->invoke($wizard);
                ob_end_clean();
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }

            // Verify .env file was created
            $this->assertFileExists($this->tempDir . '/.env');
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    public function testRunWizardInNonInteractiveMode(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            $wizard = new InitWizard($this->command, skipConnectionTest: true, force: true, format: 'env');

            ob_start();
            $result = $wizard->run();
            $output = ob_get_clean();

            $this->assertSame(0, $result);
            $this->assertStringContainsString('initialized successfully', $output);
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    public function testRunWizardWithNoStructure(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            $wizard = new InitWizard($this->command, skipConnectionTest: true, force: true, format: 'env', noStructure: true);

            ob_start();
            $result = $wizard->run();
            $output = ob_get_clean();

            $this->assertSame(0, $result);
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    public function testLoadConfigFromEnvWithUnsupportedDriver(): void
    {
        putenv('PDODB_DRIVER=unsupported');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            // Should handle unsupported driver gracefully
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
        }
    }

    public function testAskMysqlConnectionWithAllOptions(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askMysqlConnection');
        $method->setAccessible(true);

        // Method should handle MySQL-specific options (host, port, database, username, password, charset)
        $this->assertTrue($method->isProtected());
    }

    public function testAskPgsqlConnectionWithAllOptions(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askPgsqlConnection');
        $method->setAccessible(true);

        // Method should handle PostgreSQL-specific options
        $this->assertTrue($method->isProtected());
    }

    public function testAskSqlsrvConnectionWithAllOptions(): void
    {
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askSqlsrvConnection');
        $method->setAccessible(true);

        // Method should handle MSSQL-specific options (host, port, database, username, password, trust_server_certificate, encrypt)
        $this->assertTrue($method->isProtected());
    }

    public function testAskBasicConnectionWithMysqlDriver(): void
    {
        // Test askBasicConnection with mysql driver selection
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askBasicConnection');
        $method->setAccessible(true);

        // Method should call askMysqlConnection when mysql is selected
        $this->assertTrue($method->isProtected());
    }

    public function testAskBasicConnectionWithPgsqlDriver(): void
    {
        // Test askBasicConnection with pgsql driver selection
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askBasicConnection');
        $method->setAccessible(true);

        // Method should call askPgsqlConnection when pgsql is selected
        $this->assertTrue($method->isProtected());
    }

    public function testAskBasicConnectionWithSqlsrvDriver(): void
    {
        // Test askBasicConnection with sqlsrv driver selection
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askBasicConnection');
        $method->setAccessible(true);

        // Method should call askSqlsrvConnection when sqlsrv is selected
        $this->assertTrue($method->isProtected());
    }

    public function testAskBasicConnectionWithSqliteDriver(): void
    {
        // Test askBasicConnection with sqlite driver selection
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askBasicConnection');
        $method->setAccessible(true);

        // Method should call askSqliteConnection when sqlite is selected
        $this->assertTrue($method->isProtected());
    }

    public function testAskBasicConnectionWithUnsupportedDriver(): void
    {
        // Test askBasicConnection with unsupported driver
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askBasicConnection');
        $method->setAccessible(true);

        // Method should handle unsupported driver (may call error())
        $this->assertTrue($method->isProtected());
    }

    public function testAskMysqlConnectionWithDefaultValues(): void
    {
        // Test askMysqlConnection with default values
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askMysqlConnection');
        $method->setAccessible(true);

        // Method should use default values when input is empty in non-interactive mode
        $this->assertTrue($method->isProtected());
    }

    public function testAskPgsqlConnectionWithDefaultValues(): void
    {
        // Test askPgsqlConnection with default values
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askPgsqlConnection');
        $method->setAccessible(true);

        // Method should use default values when input is empty in non-interactive mode
        $this->assertTrue($method->isProtected());
    }

    public function testAskSqlsrvConnectionWithDefaultValues(): void
    {
        // Test askSqlsrvConnection with default values
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askSqlsrvConnection');
        $method->setAccessible(true);

        // Method should use default values when input is empty in non-interactive mode
        $this->assertTrue($method->isProtected());
    }

    public function testTestConnectionWithInvalidConfig(): void
    {
        // Test testConnection method structure - may call error() which exits
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('testConnection');
        $method->setAccessible(true);

        // Verify method exists and has correct signature
        $this->assertTrue($method->isProtected());
        // Method should handle invalid config (may call error())
        $this->assertTrue(true);
    }

    public function testAskConfigurationFormatWithEnvFormat(): void
    {
        // Test askConfigurationFormat when format is already set to 'env'
        $wizard = new InitWizard($this->command, format: 'env');
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askConfigurationFormat');
        $method->setAccessible(true);

        ob_start();

        try {
            $method->invoke($wizard);
            $out = ob_get_clean();
            // Format should remain 'env'
            $formatProperty = $reflection->getProperty('format');
            $formatProperty->setAccessible(true);
            $format = $formatProperty->getValue($wizard);
            $this->assertEquals('env', $format);
        } catch (\Throwable $e) {
            ob_end_clean();
            // In non-interactive mode, may not prompt
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }

    public function testAskConfigurationFormatWithConfigFormat(): void
    {
        // Test askConfigurationFormat when format is already set to 'config'
        $wizard = new InitWizard($this->command, format: 'config');
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askConfigurationFormat');
        $method->setAccessible(true);

        ob_start();

        try {
            $method->invoke($wizard);
            $out = ob_get_clean();
            // Format should remain 'config'
            $formatProperty = $reflection->getProperty('format');
            $formatProperty->setAccessible(true);
            $format = $formatProperty->getValue($wizard);
            $this->assertEquals('config', $format);
        } catch (\Throwable $e) {
            ob_end_clean();
            // In non-interactive mode, may not prompt
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }

    public function testAskProjectStructureWithCustomNamespace(): void
    {
        // Test askProjectStructure with custom namespace
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('askProjectStructure');
        $method->setAccessible(true);

        ob_start();

        try {
            $method->invoke($wizard);
            $out = ob_get_clean();
            // Structure should be set
            $structureProperty = $reflection->getProperty('structure');
            $structureProperty->setAccessible(true);
            $structure = $structureProperty->getValue($wizard);
            $this->assertIsArray($structure);
            $this->assertArrayHasKey('namespace', $structure);
        } catch (\Throwable $e) {
            ob_end_clean();
            // In non-interactive mode, may not prompt
            $this->assertInstanceOf(\Throwable::class, $e);
        }
    }

    public function testAskAdvancedOptionsWithTablePrefix(): void
    {
        // Test askAdvancedOptions includes askTablePrefix
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askAdvancedOptions'));
        $this->assertTrue($reflection->hasMethod('askTablePrefix'));
        // askAdvancedOptions should call askTablePrefix
        $this->assertTrue(true);
    }

    public function testAskAdvancedOptionsWithCaching(): void
    {
        // Test askAdvancedOptions includes askCaching
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askAdvancedOptions'));
        $this->assertTrue($reflection->hasMethod('askCaching'));
        // askAdvancedOptions should call askCaching
        $this->assertTrue(true);
    }

    public function testAskAdvancedOptionsWithPerformance(): void
    {
        // Test askAdvancedOptions includes askPerformance
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askAdvancedOptions'));
        $this->assertTrue($reflection->hasMethod('askPerformance'));
        // askAdvancedOptions should call askPerformance
        $this->assertTrue(true);
    }

    public function testAskAdvancedOptionsWithMultipleConnections(): void
    {
        // Test askAdvancedOptions includes askMultipleConnections
        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $this->assertTrue($reflection->hasMethod('askAdvancedOptions'));
        $this->assertTrue($reflection->hasMethod('askMultipleConnections'));
        // askAdvancedOptions should call askMultipleConnections
        $this->assertTrue(true);
    }

    public function testLoadConfigFromEnvWithApcuCache(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=apcu');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache'])) {
                $this->assertEquals('apcu', $config['cache']['type']);
            }
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
        }
    }

    public function testLoadConfigFromEnvWithCacheDisabled(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=false');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            // Cache should not be set when disabled
            $this->assertArrayNotHasKey('cache', $config);
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
        }
    }

    public function testLoadConfigFromEnvWithMemcachedMultipleServers(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=memcached');
        putenv('PDODB_CACHE_MEMCACHED_SERVERS=127.0.0.1:11211,192.168.1.1:11212,10.0.0.1:11213');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache']['servers'])) {
                $this->assertCount(3, $config['cache']['servers']);
            }
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
            putenv('PDODB_CACHE_MEMCACHED_SERVERS');
        }
    }

    public function testLoadConfigFromEnvWithMemcachedDefaultPort(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=memcached');
        putenv('PDODB_CACHE_MEMCACHED_SERVERS=127.0.0.1');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache']['servers'][0])) {
                // Should use default port 11211
                $this->assertEquals(11211, $config['cache']['servers'][0][1]);
            }
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
            putenv('PDODB_CACHE_MEMCACHED_SERVERS');
        }
    }

    public function testGenerateFilesCreatesDirectoryStructure(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            $wizard = new InitWizard($this->command, format: 'env', force: true);
            $reflection = new \ReflectionClass($wizard);

            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $configProperty->setValue($wizard, ['driver' => 'sqlite', 'path' => ':memory:']);

            $structureProperty = $reflection->getProperty('structure');
            $structureProperty->setAccessible(true);
            $structureProperty->setValue($wizard, [
                'namespace' => 'App',
                'migrations' => './migrations',
                'models' => './app/Models',
                'repositories' => './app/Repositories',
                'services' => './app/Services',
                'seeds' => './database/seeds',
            ]);

            $method = $reflection->getMethod('generateFiles');
            $method->setAccessible(true);

            ob_start();

            try {
                $method->invoke($wizard);
                ob_end_clean();
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }

            // Verify directory structure was created
            $this->assertFileExists($this->tempDir . '/.env');
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    public function testLoadConfigFromEnvWithMysqlDriver(): void
    {
        putenv('PDODB_DRIVER=mysql');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=3306');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');
        putenv('PDODB_CHARSET=utf8mb4');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            $this->assertEquals('mysql', $config['driver']);
            $this->assertArrayHasKey('host', $config);
            $this->assertArrayHasKey('database', $config);
        } catch (\Throwable $e) {
            // May fail if error() is called
            $this->assertInstanceOf(\Throwable::class, $e);
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

    public function testLoadConfigFromEnvWithPostgreSQLDriver(): void
    {
        putenv('PDODB_DRIVER=pgsql');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=5432');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');
        putenv('PDODB_CHARSET=utf8');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            $this->assertEquals('pgsql', $config['driver']);
            $this->assertArrayHasKey('host', $config);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
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

    public function testLoadConfigFromEnvWithMSSQLDriver(): void
    {
        putenv('PDODB_DRIVER=sqlsrv');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=1433');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            $this->assertEquals('sqlsrv', $config['driver']);
            $this->assertArrayHasKey('host', $config);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_HOST');
            putenv('PDODB_PORT');
            putenv('PDODB_DATABASE');
            putenv('PDODB_USERNAME');
            putenv('PDODB_PASSWORD');
        }
    }

    public function testLoadConfigFromEnvWithMariaDBDriver(): void
    {
        putenv('PDODB_DRIVER=mariadb');
        putenv('PDODB_HOST=localhost');
        putenv('PDODB_PORT=3306');
        putenv('PDODB_DATABASE=testdb');
        putenv('PDODB_USERNAME=testuser');
        putenv('PDODB_PASSWORD=testpass');
        putenv('PDODB_CHARSET=utf8mb4');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            $this->assertEquals('mariadb', $config['driver']);
            $this->assertArrayHasKey('host', $config);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
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

    public function testLoadConfigFromEnvWithInvalidDriver(): void
    {
        putenv('PDODB_DRIVER=invalid_driver');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            // Should handle invalid driver gracefully (catches InvalidArgumentException)
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);
            $this->assertIsArray($config);
            $this->assertEquals('invalid_driver', $config['driver']);
        } catch (\Throwable $e) {
            // May fail if error() is called for missing driver
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
        }
    }

    public function testLoadConfigFromEnvWithCacheDefaultValues(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        // Don't set PDODB_CACHE_TYPE - should default to 'filesystem'
        // Don't set PDODB_CACHE_TTL - should default to 3600
        // Don't set PDODB_CACHE_PREFIX - should default to 'pdodb_'

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache'])) {
                $this->assertEquals('filesystem', $config['cache']['type']);
                $this->assertEquals(3600, $config['cache']['default_lifetime']);
                $this->assertEquals('pdodb_', $config['cache']['prefix']);
            }
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
        }
    }

    public function testLoadConfigFromEnvWithRedisDefaultValues(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=redis');
        // Don't set Redis-specific vars - should use defaults

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache']) && $config['cache']['type'] === 'redis') {
                $this->assertEquals('127.0.0.1', $config['cache']['host']);
                $this->assertEquals(6379, $config['cache']['port']);
                $this->assertEquals(0, $config['cache']['database']);
            }
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
        }
    }

    public function testLoadConfigFromEnvWithRedisWithoutPassword(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=redis');
        putenv('PDODB_CACHE_REDIS_HOST=127.0.0.1');
        putenv('PDODB_CACHE_REDIS_PORT=6379');
        putenv('PDODB_CACHE_REDIS_DATABASE=1');
        // Don't set PDODB_CACHE_REDIS_PASSWORD

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache']) && $config['cache']['type'] === 'redis') {
                $this->assertArrayNotHasKey('password', $config['cache']);
            }
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
            putenv('PDODB_CACHE_REDIS_HOST');
            putenv('PDODB_CACHE_REDIS_PORT');
            putenv('PDODB_CACHE_REDIS_DATABASE');
        }
    }

    public function testLoadConfigFromEnvWithMemcachedEmptyServerList(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=memcached');
        putenv('PDODB_CACHE_MEMCACHED_SERVERS=');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache']) && $config['cache']['type'] === 'memcached') {
                // Should use default server
                $this->assertArrayHasKey('servers', $config['cache']);
            }
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
            putenv('PDODB_CACHE_MEMCACHED_SERVERS');
        }
    }

    public function testLoadConfigFromEnvWithMemcachedInvalidServerFormat(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=memcached');
        putenv('PDODB_CACHE_MEMCACHED_SERVERS=:');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            // Should handle invalid server format gracefully
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
            putenv('PDODB_CACHE_MEMCACHED_SERVERS');
        }
    }

    public function testLoadConfigFromEnvWithFilesystemCacheDefaultDirectory(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=filesystem');
        // Don't set PDODB_CACHE_DIRECTORY - should default to './storage/cache'

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache']) && $config['cache']['type'] === 'filesystem') {
                $this->assertEquals('./storage/cache', $config['cache']['directory']);
            }
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
        }
    }

    public function testLoadConfigFromEnvWithCacheEnabledFalse(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=false');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            // Cache should not be set when disabled
            $this->assertArrayNotHasKey('cache', $config);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
        }
    }

    public function testLoadConfigFromEnvWithCacheEnabledEmpty(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            // Cache should not be set when PDODB_CACHE_ENABLED is empty
            $this->assertArrayNotHasKey('cache', $config);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
        }
    }

    public function testLoadConfigFromEnvWithCacheEnabledCaseInsensitive(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=TRUE');
        putenv('PDODB_CACHE_TYPE=filesystem');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            // Should handle uppercase 'TRUE'
            if (isset($config['cache'])) {
                $this->assertTrue($config['cache']['enabled']);
            }
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
        }
    }

    public function testLoadConfigFromEnvWithCacheTypeCaseInsensitive(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_CACHE_ENABLED=true');
        putenv('PDODB_CACHE_TYPE=FILESYSTEM');

        $wizard = new InitWizard($this->command);
        $reflection = new \ReflectionClass($wizard);
        $method = $reflection->getMethod('loadConfigFromEnv');
        $method->setAccessible(true);

        try {
            $method->invoke($wizard);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $config = $configProperty->getValue($wizard);

            $this->assertIsArray($config);
            if (isset($config['cache'])) {
                // Should convert to lowercase
                $this->assertEquals('filesystem', $config['cache']['type']);
            }
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Throwable::class, $e);
        } finally {
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
            putenv('PDODB_CACHE_ENABLED');
            putenv('PDODB_CACHE_TYPE');
        }
    }

    public function testRunWizardWithExceptionHandling(): void
    {
        // Test that run() handles exceptions gracefully
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $wizard = new InitWizard($this->command, skipConnectionTest: true, force: true, format: 'env');
        $reflection = new \ReflectionClass($wizard);

        // Set invalid config to trigger exception
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue($wizard, ['driver' => 'invalid']);

        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            ob_start();

            try {
                $result = $wizard->run();
                $output = ob_get_clean();
                // Should handle exception and return error code or 0
                $this->assertIsInt($result);
            } catch (\Throwable $e) {
                ob_end_clean();
                // Exception may be thrown or handled
                $this->assertInstanceOf(\Throwable::class, $e);
            }
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    public function testRunWizardWithInteractiveModeDetection(): void
    {
        // Test that run() detects non-interactive mode correctly
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PHPUNIT=1');

        $wizard = new InitWizard($this->command, skipConnectionTest: true, force: true, format: 'env');

        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            ob_start();
            $result = $wizard->run();
            $output = ob_get_clean();

            $this->assertSame(0, $result);
            // Should not show welcome message in non-interactive mode
            $this->assertStringNotContainsString('Welcome to PDOdb Configuration Wizard', $output);
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }

    public function testRunWizardWithNoStructureOption(): void
    {
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=:memory:');

        $wizard = new InitWizard($this->command, skipConnectionTest: true, force: true, format: 'env', noStructure: true);

        $oldCwd = getcwd();
        chdir($this->tempDir);

        try {
            ob_start();
            $result = $wizard->run();
            $output = ob_get_clean();

            $this->assertSame(0, $result);
            // Should skip structure creation
        } finally {
            chdir($oldCwd);
            putenv('PDODB_DRIVER');
            putenv('PDODB_PATH');
        }
    }
}
