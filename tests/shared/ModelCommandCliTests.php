<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\PdoDb;

final class ModelCommandCliTests extends TestCase
{
    protected string $modelsDir;
    protected \tommyknocker\pdodb\PdoDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('PDODB_DRIVER=sqlite');
        putenv('PDODB_NON_INTERACTIVE=1');
        $dbPath = sys_get_temp_dir() . '/mc_models_' . uniqid() . '.sqlite';
        putenv('PDODB_PATH=' . $dbPath);
        $this->modelsDir = sys_get_temp_dir() . '/pdodb_models_' . uniqid();
        mkdir($this->modelsDir, 0755, true);
        putenv('PDODB_MODEL_PATH=' . $this->modelsDir);

        $this->db = new PdoDb('sqlite', ['path' => $dbPath]);
        $this->db->rawQuery('CREATE TABLE mc_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    protected function tearDown(): void
    {
        putenv('PDODB_DRIVER');
        putenv('PDODB_NON_INTERACTIVE');
        putenv('PDODB_MODEL_PATH');
        if (is_dir($this->modelsDir)) {
            foreach (glob($this->modelsDir . '/*.php') as $f) {
                @unlink($f);
            }
            @rmdir($this->modelsDir);
        }
        parent::tearDown();
    }

    public function testMakeModelWithNamespaceAndForce(): void
    {
        $app = new Application();
        $model = 'User';
        $table = 'mc_users';

        // First generate without existing file
        ob_start();

        try {
            $code1 = $app->run(['pdodb', 'model', 'make', $model, $table, $this->modelsDir, '--namespace=App\\Entities']);
            $out1 = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code1);
        $file = $this->modelsDir . '/' . $model . '.php';
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $this->assertIsString($content);
        $this->assertStringContainsString('namespace App\\Entities;', $content);
        $this->assertStringContainsString('class User extends Model', $content);

        // Regenerate with --force should overwrite without prompt and update namespace
        ob_start();

        try {
            $code2 = $app->run(['pdodb', 'model', 'make', $model, $table, $this->modelsDir, '--namespace=App\\Models', '--force']);
            $out2 = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
        $this->assertSame(0, $code2);
        $content2 = file_get_contents($file);
        $this->assertIsString($content2);
        $this->assertStringContainsString('namespace App\\Models;', $content2);
    }
}
