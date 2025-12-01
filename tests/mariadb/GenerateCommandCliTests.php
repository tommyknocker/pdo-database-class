<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\cli\Application;

final class GenerateCommandCliTests extends BaseMariaDBTestCase
{
    protected string $outputDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->outputDir = sys_get_temp_dir() . '/pdodb_generate_output_' . uniqid();
        mkdir($this->outputDir, 0755, true);
        putenv('PDODB_DRIVER=mariadb');
        putenv('PDODB_HOST=' . self::DB_HOST);
        putenv('PDODB_PORT=' . (string)self::DB_PORT);
        putenv('PDODB_DATABASE=' . self::DB_NAME);
        putenv('PDODB_USERNAME=' . self::DB_USER);
        putenv('PDODB_PASSWORD=' . self::DB_PASSWORD);
        putenv('PDODB_NON_INTERACTIVE=1');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDir)) {
            $this->deleteDirectory($this->outputDir);
        }
        putenv('PDODB_DRIVER');
        putenv('PDODB_HOST');
        putenv('PDODB_PORT');
        putenv('PDODB_DATABASE');
        putenv('PDODB_USERNAME');
        putenv('PDODB_PASSWORD');
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
        static::$db->schema()->dropTableIfExists('test_users');
        static::$db->schema()->createTable('test_users', [
            'id' => static::$db->schema()->primaryKey(),
            'status' => static::$db->schema()->enum(['active', 'inactive', 'pending'])->notNull()->defaultValue('active'),
            'name' => static::$db->schema()->string(100)->notNull(),
            'email' => static::$db->schema()->string(100),
        ]);
    }

    public function testGenerateEnumWithTableAndColumn(): void
    {
        $this->createTestTable();
        $app = new Application();
        ob_start();

        try {
            $code = $app->run(['pdodb', 'generate', 'enum', '--table=test_users', '--column=status', '--output=' . $this->outputDir, '--force']);
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Enum file created', $out);
        $this->assertFileExists($this->outputDir . '/TestUsersStatusEnum.php');
    }
}
