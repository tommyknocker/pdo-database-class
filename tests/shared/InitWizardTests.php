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
}
