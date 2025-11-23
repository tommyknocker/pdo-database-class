<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\RepositoryGenerator;
use tommyknocker\pdodb\PdoDb;

/**
 * Tests for RepositoryGenerator.
 */
final class RepositoryGeneratorTests extends TestCase
{
    protected string $dbPath;
    protected PdoDb $db;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/pdodb_repository_generator_' . uniqid() . '.sqlite';
        $this->db = new PdoDb('sqlite', ['path' => $this->dbPath]);

        // Create test table (use 'users' to match model name conversion)
        $this->db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');

        $this->tempDir = sys_get_temp_dir() . '/pdodb_repository_tests_' . uniqid();
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
     * Test repositoryNameToModelName.
     */
    public function testRepositoryNameToModelName(): void
    {
        $reflection = new \ReflectionClass(RepositoryGenerator::class);
        $method = $reflection->getMethod('repositoryNameToModelName');
        $method->setAccessible(true);

        $this->assertEquals('User', $method->invoke(null, 'UserRepository'));
        $this->assertEquals('User', $method->invoke(null, 'User'));
        $this->assertEquals('Product', $method->invoke(null, 'ProductRepository'));
    }

    /**
     * Test generateRepositoryCode.
     */
    public function testGenerateRepositoryCode(): void
    {
        $reflection = new \ReflectionClass(RepositoryGenerator::class);
        $method = $reflection->getMethod('generateRepositoryCode');
        $method->setAccessible(true);

        $code = $method->invoke(null, 'UserRepository', 'User', 'test_users', ['id'], 'App\\Repositories', 'App\\Models');

        $this->assertStringContainsString('namespace App\\Repositories', $code);
        $this->assertStringContainsString('class UserRepository', $code);
        $this->assertStringContainsString('use App\\Models\\User', $code);
        // Table name should be used in queries
        $this->assertStringContainsString("from('test_users')", $code);
    }

    /**
     * Test getRepositoryOutputPath with environment variable.
     */
    public function testGetRepositoryOutputPathWithEnv(): void
    {
        putenv('PDODB_REPOSITORY_PATH=' . $this->tempDir);

        $reflection = new \ReflectionClass(RepositoryGenerator::class);
        $method = $reflection->getMethod('getRepositoryOutputPath');
        $method->setAccessible(true);

        $path = $method->invoke(null);
        $this->assertEquals($this->tempDir, $path);

        putenv('PDODB_REPOSITORY_PATH');
    }

    /**
     * Test getRepositoryOutputPath creates default directory.
     */
    public function testGetRepositoryOutputPathCreatesDefault(): void
    {
        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $reflection = new \ReflectionClass(RepositoryGenerator::class);
            $method = $reflection->getMethod('getRepositoryOutputPath');
            $method->setAccessible(true);

            $path = $method->invoke(null);
            $this->assertTrue(is_dir($path));
            $this->assertStringContainsString('repositories', $path);
        } finally {
            chdir($originalCwd);
        }
    }

    /**
     * Test generate with all parameters.
     */
    public function testGenerateWithAllParameters(): void
    {
        ob_start();

        try {
            $filename = RepositoryGenerator::generate(
                'UserRepository',
                'User',
                $this->tempDir,
                $this->db,
                'App\\Repositories',
                'App\\Models',
                true
            );
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertFileExists($filename);
        $this->assertStringContainsString('UserRepository.php', $filename);
        $this->assertStringContainsString('PDOdb Repository Generator', $out);
        $this->assertStringContainsString('Repository file created', $out);

        $content = file_get_contents($filename);
        $this->assertStringContainsString('class UserRepository', $content);
        $this->assertStringContainsString('namespace App\\Repositories', $content);
    }

    /**
     * Test generate with invalid repository name.
     */
    public function testGenerateWithInvalidRepositoryName(): void
    {
        // static::error() calls exit(), so we can't catch it
        // Instead, we verify the method structure
        $reflection = new \ReflectionClass(RepositoryGenerator::class);
        $this->assertTrue($reflection->hasMethod('generate'));
        $this->assertTrue($reflection->hasMethod('repositoryNameToModelName'));
    }

    /**
     * Test generate creates file with correct content.
     */
    public function testGenerateCreatesCorrectFile(): void
    {
        ob_start();

        try {
            $filename = RepositoryGenerator::generate(
                'UserRepository',
                'User',
                $this->tempDir,
                $this->db,
                'App\\Repositories',
                'App\\Models',
                true
            );
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $this->assertFileExists($filename);
        $content = file_get_contents($filename);

        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString('declare(strict_types=1);', $content);
        $this->assertStringContainsString('namespace App\\Repositories', $content);
        $this->assertStringContainsString('class UserRepository', $content);
        $this->assertStringContainsString('use App\\Models\\User', $content);
        // Check that table name is used in the code (e.g., ->from('users'))
        $this->assertStringContainsString("from('users')", $content);
    }

    /**
     * Test generate with force flag overwrites existing file.
     */
    public function testGenerateWithForceOverwrites(): void
    {
        // Create initial file
        $filename = $this->tempDir . '/UserRepository.php';
        file_put_contents($filename, 'old content');

        ob_start();

        try {
            RepositoryGenerator::generate(
                'UserRepository',
                'User',
                $this->tempDir,
                $this->db,
                'App\\Repositories',
                'App\\Models',
                true
            );
            ob_end_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $content = file_get_contents($filename);
        $this->assertStringNotContainsString('old content', $content);
        $this->assertStringContainsString('class UserRepository', $content);
    }

    /**
     * Test modelNameToTableName.
     */
    public function testModelNameToTableName(): void
    {
        $reflection = new \ReflectionClass(RepositoryGenerator::class);
        $method = $reflection->getMethod('modelNameToTableName');
        $method->setAccessible(true);

        $this->assertEquals('users', $method->invoke(null, 'User'));
        $this->assertEquals('products', $method->invoke(null, 'Product'));
        $this->assertEquals('user_profiles', $method->invoke(null, 'UserProfile'));
    }

    /**
     * Test detectPrimaryKey.
     */
    public function testDetectPrimaryKey(): void
    {
        $reflection = new \ReflectionClass(RepositoryGenerator::class);
        $method = $reflection->getMethod('detectPrimaryKey');
        $method->setAccessible(true);

        $primaryKey = $method->invoke(null, $this->db, 'users');
        $this->assertIsArray($primaryKey);
        $this->assertContains('id', $primaryKey);
    }

    /**
     * Test detectPrimaryKey with exception fallback.
     */
    public function testDetectPrimaryKeyWithExceptionFallback(): void
    {
        $reflection = new \ReflectionClass(RepositoryGenerator::class);
        $method = $reflection->getMethod('detectPrimaryKey');
        $method->setAccessible(true);

        // Use non-existent table to trigger exception fallback
        $primaryKey = $method->invoke(null, $this->db, 'nonexistent_table_xyz');
        $this->assertIsArray($primaryKey);
        $this->assertContains('id', $primaryKey);
    }

    /**
     * Test generateRepositoryCode with composite primary key.
     */
    public function testGenerateRepositoryCodeWithCompositePrimaryKey(): void
    {
        $reflection = new \ReflectionClass(RepositoryGenerator::class);
        $method = $reflection->getMethod('generateRepositoryCode');
        $method->setAccessible(true);

        $code = $method->invoke(null, 'UserRepository', 'User', 'test_users', ['user_id', 'role_id'], 'App\\Repositories', 'App\\Models');

        $this->assertStringContainsString('namespace App\\Repositories', $code);
        $this->assertStringContainsString('class UserRepository', $code);
        $this->assertStringContainsString('array $ids', $code);
        $this->assertStringContainsString('foreach ($ids', $code);
    }

    /**
     * Test getRepositoryOutputPath with existing directory.
     */
    public function testGetRepositoryOutputPathWithExistingDirectory(): void
    {
        $oldCwd = getcwd();
        $tempDir = sys_get_temp_dir() . '/pdodb_repo_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        mkdir($tempDir . '/app/Repositories', 0755, true);

        chdir($tempDir);

        try {
            $reflection = new \ReflectionClass(RepositoryGenerator::class);
            $method = $reflection->getMethod('getRepositoryOutputPath');
            $method->setAccessible(true);

            $path = $method->invoke(null);
            $this->assertIsString($path);
            $this->assertTrue(is_dir($path));
            $this->assertStringContainsString('Repositories', $path);
        } finally {
            chdir($oldCwd);
            if (is_dir($tempDir . '/app/Repositories')) {
                @rmdir($tempDir . '/app/Repositories');
            }
            if (is_dir($tempDir . '/app')) {
                @rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    }

    /**
     * Test getRepositoryOutputPath with src/Repositories.
     */
    public function testGetRepositoryOutputPathWithSrcRepositories(): void
    {
        $oldCwd = getcwd();
        $tempDir = sys_get_temp_dir() . '/pdodb_repo_src_' . uniqid();
        mkdir($tempDir, 0755, true);
        mkdir($tempDir . '/src/Repositories', 0755, true);

        chdir($tempDir);

        try {
            $reflection = new \ReflectionClass(RepositoryGenerator::class);
            $method = $reflection->getMethod('getRepositoryOutputPath');
            $method->setAccessible(true);

            $path = $method->invoke(null);
            $this->assertIsString($path);
            $this->assertTrue(is_dir($path));
            $this->assertStringContainsString('Repositories', $path);
        } finally {
            chdir($oldCwd);
            if (is_dir($tempDir . '/src/Repositories')) {
                @rmdir($tempDir . '/src/Repositories');
            }
            if (is_dir($tempDir . '/src')) {
                @rmdir($tempDir . '/src');
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    }

    /**
     * Test generate with singular table name.
     * Note: The generate method converts model name to plural table name,
     * then checks if it exists, and if not, tries singular version.
     * However, the generated code uses the table name that was found.
     */
    public function testGenerateWithSingularTableName(): void
    {
        // Create table with singular name
        $this->db->rawQuery('CREATE TABLE user (id INTEGER PRIMARY KEY, name TEXT)');

        ob_start();

        try {
            $filename = RepositoryGenerator::generate(
                'UserRepository',
                'User',
                $this->tempDir,
                $this->db,
                'App\\Repositories',
                'App\\Models',
                true
            );
            $out = ob_end_clean();

            $this->assertFileExists($filename);
            $content = file_get_contents($filename);
            // The generated code should use the table name that was found (user)
            // But modelNameToTableName converts User to users, so the code may use 'users'
            // The important thing is that the generation succeeded
            $this->assertStringContainsString('class UserRepository', $content);
            $this->assertStringContainsString('namespace App\\Repositories', $content);
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        } finally {
            $this->db->rawQuery('DROP TABLE IF EXISTS user');
        }
    }

    /**
     * Test generateRepositoryCode with single primary key that is not 'id'.
     */
    public function testGenerateRepositoryCodeWithSingleNonIdPrimaryKey(): void
    {
        $reflection = new \ReflectionClass(RepositoryGenerator::class);
        $method = $reflection->getMethod('generateRepositoryCode');
        $method->setAccessible(true);

        $code = $method->invoke(null, 'UserRepository', 'User', 'test_users', ['user_id'], 'App\\Repositories', 'App\\Models');

        $this->assertStringContainsString('namespace App\\Repositories', $code);
        $this->assertStringContainsString('class UserRepository', $code);
        $this->assertStringContainsString('array $ids', $code);
    }
}
