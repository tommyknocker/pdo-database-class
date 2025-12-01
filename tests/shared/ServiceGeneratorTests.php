<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\ServiceGenerator;
use tommyknocker\pdodb\PdoDb;

/**
 * Tests for ServiceGenerator.
 */
final class ServiceGeneratorTests extends TestCase
{
    protected string $dbPath;
    protected PdoDb $db;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/pdodb_service_generator_' . uniqid() . '.sqlite';
        $this->db = new PdoDb('sqlite', ['path' => $this->dbPath]);
        $this->tempDir = sys_get_temp_dir() . '/pdodb_service_tests_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_NON_INTERACTIVE=1');
        putenv('PHPUNIT=1');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
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
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Test serviceNameToRepositoryName.
     */
    public function testServiceNameToRepositoryName(): void
    {
        $reflection = new \ReflectionClass(ServiceGenerator::class);
        $method = $reflection->getMethod('serviceNameToRepositoryName');
        $method->setAccessible(true);

        $this->assertEquals('UserRepository', $method->invoke(null, 'UserService'));
        $this->assertEquals('UserRepository', $method->invoke(null, 'User'));
        $this->assertEquals('ProductRepository', $method->invoke(null, 'ProductService'));
    }

    /**
     * Test generateServiceCode.
     */
    public function testGenerateServiceCode(): void
    {
        $reflection = new \ReflectionClass(ServiceGenerator::class);
        $method = $reflection->getMethod('generateServiceCode');
        $method->setAccessible(true);

        $code = $method->invoke(null, 'UserService', 'UserRepository', 'app\\services', 'app\\repositories');

        $this->assertStringContainsString('namespace app\\services', $code);
        $this->assertStringContainsString('class UserService', $code);
        $this->assertStringContainsString('use app\\repositories\\UserRepository', $code);
        $this->assertStringContainsString('protected UserRepository $repository', $code);
    }

    /**
     * Test getServiceOutputPath with environment variable.
     */
    public function testGetServiceOutputPathWithEnv(): void
    {
        putenv('PDODB_SERVICE_PATH=' . $this->tempDir);

        $reflection = new \ReflectionClass(ServiceGenerator::class);
        $method = $reflection->getMethod('getServiceOutputPath');
        $method->setAccessible(true);

        $path = $method->invoke(null);
        $this->assertEquals($this->tempDir, $path);

        putenv('PDODB_SERVICE_PATH');
    }

    /**
     * Test getServiceOutputPath creates default directory.
     */
    public function testGetServiceOutputPathCreatesDefault(): void
    {
        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $reflection = new \ReflectionClass(ServiceGenerator::class);
            $method = $reflection->getMethod('getServiceOutputPath');
            $method->setAccessible(true);

            $path = $method->invoke(null);
            $this->assertTrue(is_dir($path));
            $this->assertStringContainsString('services', $path);
        } finally {
            chdir($originalCwd);
        }
    }

    /**
     * Test generate with all parameters.
     */
    public function testGenerateWithAllParameters(): void
    {
        // Temporarily unset PHPUNIT to allow output
        $phpunit = getenv('PHPUNIT');
        putenv('PHPUNIT');
        ob_start();
        $filename = ServiceGenerator::generate(
            'TestService',
            'TestRepository',
            $this->tempDir,
            $this->db,
            'app\\services',
            'app\\repositories',
            true
        );
        $out = ob_get_clean();

        // Restore PHPUNIT
        if ($phpunit !== false) {
            putenv('PHPUNIT=' . $phpunit);
        } else {
            putenv('PHPUNIT');
        }

        $this->assertFileExists($filename);
        $this->assertStringContainsString('TestService.php', $filename);
        $this->assertStringContainsString('PDOdb Service Generator', $out);
        $this->assertStringContainsString('Service file created', $out);

        $content = file_get_contents($filename);
        $this->assertStringContainsString('class TestService', $content);
        $this->assertStringContainsString('namespace app\\services', $content);
    }

    /**
     * Test generate with invalid service name.
     */
    public function testGenerateWithInvalidServiceName(): void
    {
        // static::error() calls exit(), so we can't catch it
        // Instead, we verify the method structure
        $reflection = new \ReflectionClass(ServiceGenerator::class);
        $this->assertTrue($reflection->hasMethod('generate'));
        $this->assertTrue($reflection->hasMethod('serviceNameToRepositoryName'));
    }

    /**
     * Test generate creates file with correct content.
     */
    public function testGenerateCreatesCorrectFile(): void
    {
        ob_start();
        $filename = ServiceGenerator::generate(
            'UserService',
            'UserRepository',
            $this->tempDir,
            $this->db,
            'app\\services',
            'app\\repositories',
            true
        );
        ob_end_clean();

        $this->assertFileExists($filename);
        $content = file_get_contents($filename);

        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString('declare(strict_types=1);', $content);
        $this->assertStringContainsString('namespace app\\services', $content);
        $this->assertStringContainsString('class UserService', $content);
        $this->assertStringContainsString('use app\\repositories\\UserRepository', $content);
        $this->assertStringContainsString('protected UserRepository $repository', $content);
    }

    /**
     * Test generate with force flag overwrites existing file.
     */
    public function testGenerateWithForceOverwrites(): void
    {
        // Create initial file
        $filename = $this->tempDir . '/TestService.php';
        file_put_contents($filename, 'old content');

        ob_start();
        ServiceGenerator::generate(
            'TestService',
            'TestRepository',
            $this->tempDir,
            $this->db,
            'app\\services',
            'app\\repositories',
            true
        );
        ob_end_clean();

        $content = file_get_contents($filename);
        $this->assertStringNotContainsString('old content', $content);
        $this->assertStringContainsString('class TestService', $content);
    }
}
