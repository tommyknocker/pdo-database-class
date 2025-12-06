<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\PdoDb;

final class GenerateCommandCliTests extends TestCase
{
    protected string $dbPath;
    protected string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite temp file DB for DDL operations
        $this->dbPath = sys_get_temp_dir() . '/pdodb_generate_' . uniqid() . '.sqlite';
        $this->outputDir = sys_get_temp_dir() . '/pdodb_generate_output_' . uniqid();
        mkdir($this->outputDir, 0755, true);
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_PATH=' . $this->dbPath);
        putenv('PDODB_NON_INTERACTIVE=1');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        if (is_dir($this->outputDir)) {
            $this->deleteDirectory($this->outputDir);
        }
        putenv('PDODB_DRIVER');
        putenv('PDODB_PATH');
        putenv('PDODB_NON_INTERACTIVE');
        parent::tearDown();
    }

    protected function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    protected function createTestTable(): void
    {
        // SQLite doesn't support ENUM, so create table directly via PdoDb
        $db = new PdoDb('sqlite', ['path' => $this->dbPath]);
        $db->schema()->createTable('test_users', [
            'id' => $db->schema()->primaryKey(),
            'status' => $db->schema()->string(20)->notNull()->defaultValue('active'),
            'name' => $db->schema()->string(100)->notNull(),
            'email' => $db->schema()->string(100),
        ]);
    }

    public function testGenerateHelp(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', '--help']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Extended Code Generation', $out);
        $this->assertStringContainsString('generate api', $out);
        $this->assertStringContainsString('generate tests', $out);
    }

    public function testGenerateApiWithTable(): void
    {
        $this->createTestTable();
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'api', '--table=test_users', '--format=rest', '--output=' . $this->outputDir, '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('API controller file created', $out);
        $this->assertFileExists($this->outputDir . '/TestUserController.php');
    }

    public function testGenerateApiShowsHelpWhenNoTableOrModelWithFormat(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'api', '--format=rest']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate api', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateTestsWithTable(): void
    {
        $this->createTestTable();
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'tests', '--table=test_users', '--type=unit', '--output=' . $this->outputDir, '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Test file created', $out);
        $this->assertFileExists($this->outputDir . '/TestUserTest.php');
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testGenerateTestsShowsHelpWhenNoOptionsWithType(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'tests', '--type=unit']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate tests', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateDtoWithTable(): void
    {
        $this->createTestTable();
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'dto', '--table=test_users', '--output=' . $this->outputDir, '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('DTO file created', $out);
        $this->assertFileExists($this->outputDir . '/TestUserDTO.php');
    }

    public function testGenerateDtoShowsHelpWhenNoTableOrModel(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'dto']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate dto', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateEnumShowsHelpWhenNoTable(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'enum', '--column=status']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate enum', $out);
        $this->assertStringContainsString('--table', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateEnumShowsHelpWhenNoColumn(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'enum', '--table=test_users']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate enum', $out);
        $this->assertStringContainsString('--column', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateDocsWithTable(): void
    {
        $this->createTestTable();
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'docs', '--table=test_users', '--format=openapi', '--output=' . $this->outputDir, '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Documentation file created', $out);
        $this->assertFileExists($this->outputDir . '/test_users_api.yaml');

        // Verify that primary key is marked in schema
        $content = file_get_contents($this->outputDir . '/test_users_api.yaml');
        $this->assertStringContainsString('readOnly: true', $content);
        $this->assertStringContainsString('Primary key', $content);
        $this->assertStringContainsString('API documentation for test_users table', $content);
    }

    public function testGenerateDocsWithModel(): void
    {
        // Create table with name that matches model (User -> users)
        $db = new PdoDb('sqlite', ['path' => $this->dbPath]);
        $db->schema()->createTable('users', [
            'id' => $db->schema()->primaryKey(),
            'status' => $db->schema()->string(20)->notNull()->defaultValue('active'),
            'name' => $db->schema()->string(100)->notNull(),
            'email' => $db->schema()->string(100),
        ]);

        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'docs', '--model=User', '--format=openapi', '--output=' . $this->outputDir, '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Documentation file created', $out);
        $this->assertFileExists($this->outputDir . '/user_api.yaml');

        // Verify that modelName is used in description
        $content = file_get_contents($this->outputDir . '/user_api.yaml');
        $this->assertStringContainsString('API documentation for User model', $content);
        $this->assertStringContainsString('users table', $content);
    }

    public function testGenerateDocsJsonFormat(): void
    {
        $this->createTestTable();
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'docs', '--table=test_users', '--format=json', '--output=' . $this->outputDir, '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Documentation file created', $out);
        $this->assertFileExists($this->outputDir . '/test_users_api.json');

        // Verify that primary key is marked in schema and modelName is used
        $content = file_get_contents($this->outputDir . '/test_users_api.json');
        $json = json_decode($content, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('components', $json);
        $this->assertArrayHasKey('schemas', $json['components']);
        $this->assertArrayHasKey('test_users_api', $json['components']['schemas']);
        $schema = $json['components']['schemas']['test_users_api'];
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('id', $schema['properties']);
        $this->assertArrayHasKey('readOnly', $schema['properties']['id']);
        $this->assertTrue($schema['properties']['id']['readOnly']);
        $this->assertStringContainsString('Primary key', $schema['properties']['id']['description'] ?? '');
    }

    public function testGenerateModel(): void
    {
        $this->createTestTable();
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'model', '--model=TestUser', '--table=test_users', '--output=' . $this->outputDir, '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Model file created', $out);
        $this->assertFileExists($this->outputDir . '/TestUser.php');

        $content = file_get_contents($this->outputDir . '/TestUser.php');
        $this->assertStringContainsString('namespace app\\models', $content);
        $this->assertStringContainsString('class TestUser', $content);
        $this->assertStringContainsString('tableName()', $content);
    }

    public function testGenerateModelShowsHelpWhenNoModelWithTable(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'model', '--table=test_users']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate model', $out);
        $this->assertStringContainsString('--model', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateRepository(): void
    {
        $this->createTestTable();
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'repository', '--repository=TestUserRepository', '--model=TestUser', '--table=test_users', '--output=' . $this->outputDir, '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Repository file created', $out);
        $this->assertFileExists($this->outputDir . '/TestUserRepository.php');

        $content = file_get_contents($this->outputDir . '/TestUserRepository.php');
        $this->assertStringContainsString('namespace app\\repositories', $content);
        $this->assertStringContainsString('class TestUserRepository', $content);
        $this->assertStringContainsString('use app\\models\\TestUser', $content);
    }

    public function testGenerateRepositoryShowsHelpWhenNoRepository(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'repository', '--model=TestUser']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate repository', $out);
        $this->assertStringContainsString('--repository', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateService(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'service', '--service=TestUserService', '--repository=TestUserRepository', '--output=' . $this->outputDir, '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Service file created', $out);
        $this->assertFileExists($this->outputDir . '/TestUserService.php');

        $content = file_get_contents($this->outputDir . '/TestUserService.php');
        $this->assertStringContainsString('namespace app\\services', $content);
        $this->assertStringContainsString('class TestUserService', $content);
        $this->assertStringContainsString('use app\\repositories\\TestUserRepository', $content);
    }

    public function testGenerateServiceShowsHelpWhenNoService(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'service', '--repository=TestUserRepository']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate service', $out);
        $this->assertStringContainsString('--service', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateDocsShowsHelpWhenNoTableOrModel(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'docs', '--format=openapi']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate docs', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testGenerateUnknownSubcommand(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/pdodb');
        $dbPath = sys_get_temp_dir() . '/pdodb_generate_' . uniqid() . '.sqlite';
        $env = 'PDODB_DRIVER=sqlite PDODB_PATH=' . escapeshellarg($dbPath) . ' PDODB_NON_INTERACTIVE=1';
        $cmd = $env . ' ' . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg((string)$bin) . ' generate unknown 2>&1';
        $out = (string)shell_exec($cmd);
        $this->assertStringContainsString('Unknown subcommand: unknown', $out);
    }

    public function testGenerateApiShowsHelpWhenNoParameters(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'api']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate api', $out);
        $this->assertStringContainsString('--table', $out);
        $this->assertStringContainsString('--model', $out);
        $this->assertStringContainsString('Required Options', $out);
        $this->assertStringContainsString('Examples:', $out);
    }

    public function testGenerateTestsShowsHelpWhenNoParameters(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'tests']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate tests', $out);
        $this->assertStringContainsString('--model', $out);
        $this->assertStringContainsString('--table', $out);
        $this->assertStringContainsString('--repository', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateDtoShowsHelpWhenNoParameters(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'dto']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate dto', $out);
        $this->assertStringContainsString('--table', $out);
        $this->assertStringContainsString('--model', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateEnumShowsHelpWhenNoParameters(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'enum']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate enum', $out);
        $this->assertStringContainsString('--table', $out);
        $this->assertStringContainsString('--column', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateDocsShowsHelpWhenNoParameters(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'docs']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate docs', $out);
        $this->assertStringContainsString('--table', $out);
        $this->assertStringContainsString('--model', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateModelShowsHelpWhenNoParameters(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'model']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate model', $out);
        $this->assertStringContainsString('--model', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateRepositoryShowsHelpWhenNoParameters(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'repository']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate repository', $out);
        $this->assertStringContainsString('--repository', $out);
        $this->assertStringContainsString('Required Options', $out);
    }

    public function testGenerateServiceShowsHelpWhenNoParameters(): void
    {
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'service']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Generate service', $out);
        $this->assertStringContainsString('--service', $out);
        $this->assertStringContainsString('Required Options', $out);
    }
}
