<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\InitDirectoryStructure;

/**
 * Tests for InitDirectoryStructure class.
 */
final class InitDirectoryStructureTests extends BaseSharedTestCase
{
    private string $tempDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pdodb_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        chdir(sys_get_temp_dir());
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

    public function testCreateEmptyStructure(): void
    {
        ob_start();
        InitDirectoryStructure::create([]);
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testCreateBasicStructure(): void
    {
        $structure = [
            'migrations' => 'migrations',
            'models' => 'models',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        $output = ob_get_clean();

        $this->assertTrue(is_dir($this->tempDir . '/migrations'));
        $this->assertTrue(is_dir($this->tempDir . '/models'));
        $this->assertStringContainsString('Creating directory structure', $output);
        $this->assertStringContainsString('Created directory: migrations', $output);
        $this->assertStringContainsString('Created directory: models', $output);
    }

    public function testCreateFullStructure(): void
    {
        $structure = [
            'migrations' => 'migrations',
            'models' => 'models',
            'repositories' => 'repositories',
            'services' => 'services',
            'seeds' => 'seeds',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        $output = ob_get_clean();

        $this->assertTrue(is_dir($this->tempDir . '/migrations'));
        $this->assertTrue(is_dir($this->tempDir . '/models'));
        $this->assertTrue(is_dir($this->tempDir . '/repositories'));
        $this->assertTrue(is_dir($this->tempDir . '/services'));
        $this->assertTrue(is_dir($this->tempDir . '/seeds'));
    }

    public function testCreateWithAbsolutePath(): void
    {
        $absolutePath = $this->tempDir . '/absolute_test';
        $structure = [
            'migrations' => $absolutePath,
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        $output = ob_get_clean();

        $this->assertTrue(is_dir($absolutePath));
    }

    public function testCreateWithRelativePath(): void
    {
        $structure = [
            'migrations' => './relative_migrations',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        $output = ob_get_clean();

        $this->assertTrue(is_dir($this->tempDir . '/relative_migrations'));
    }

    public function testCreateWithNestedPath(): void
    {
        $structure = [
            'migrations' => 'app/database/migrations',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        $output = ob_get_clean();

        $this->assertTrue(is_dir($this->tempDir . '/app/database/migrations'));
    }

    public function testCreateWithExistingDirectory(): void
    {
        mkdir($this->tempDir . '/existing', 0755, true);

        $structure = [
            'migrations' => 'existing',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        $output = ob_get_clean();

        $this->assertTrue(is_dir($this->tempDir . '/existing'));
        $this->assertStringContainsString('Directory already exists', $output);
    }

    public function testCreateWithEmptyPath(): void
    {
        $structure = [
            'migrations' => '',
            'models' => 'models',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        $output = ob_get_clean();

        $this->assertFalse(is_dir($this->tempDir . '/migrations'));
        $this->assertTrue(is_dir($this->tempDir . '/models'));
    }

    public function testCreateWithNullPath(): void
    {
        $structure = [
            'migrations' => null,
            'models' => 'models',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        $output = ob_get_clean();

        $this->assertFalse(is_dir($this->tempDir . '/migrations'));
        $this->assertTrue(is_dir($this->tempDir . '/models'));
    }

    public function testCreateWithGitkeepFiles(): void
    {
        $structure = [
            'migrations' => 'migrations',
            'models' => 'models',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        ob_end_clean();

        $this->assertTrue(file_exists($this->tempDir . '/migrations/.gitkeep'));
        $this->assertTrue(file_exists($this->tempDir . '/models/.gitkeep'));
    }

    public function testCreateWithWindowsPath(): void
    {
        $structure = [
            'migrations' => 'C:\\test\\migrations',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        $output = ob_get_clean();

        // On non-Windows systems, this will be treated as relative path
        // On Windows, it would create absolute path
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertTrue(is_dir('C:\\test\\migrations'));
        } else {
            // On Unix, it treats as relative path
            $this->assertTrue(is_dir($this->tempDir . '/C:\\test\\migrations') || is_dir('C:\\test\\migrations'));
        }
    }

    public function testCreateWithTrailingSlash(): void
    {
        $structure = [
            'migrations' => 'migrations/',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        ob_end_clean();

        $this->assertTrue(is_dir($this->tempDir . '/migrations'));
    }

    public function testCreateWithBackslash(): void
    {
        $structure = [
            'migrations' => 'migrations\\',
        ];

        ob_start();
        InitDirectoryStructure::create($structure);
        ob_end_clean();

        $this->assertTrue(is_dir($this->tempDir . '/migrations'));
    }
}
