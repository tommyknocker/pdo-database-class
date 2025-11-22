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
