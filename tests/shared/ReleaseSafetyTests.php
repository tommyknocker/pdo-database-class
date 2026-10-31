<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\InitConfigGenerator;
use tommyknocker\pdodb\cli\ModelGenerator;
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\seeds\SeedRunner;

final class ReleaseSafetyTests extends TestCase
{
    public function testDryRunDoesNotCreateHistoryTables(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $path = sys_get_temp_dir() . '/pdodb_dry_' . bin2hex(random_bytes(6));
        mkdir($path);

        try {
            file_put_contents($path . '/m20261101120000_test.php', '<?php');
            file_put_contents($path . '/ExampleSeed.php', '<?php');
            $migrations = new MigrationRunner($db, $path);
            $seeds = new SeedRunner($db, $path);
            $migrations->setDryRun(true)->migrate();
            $seeds->setDryRun(true)->run();
            self::assertFalse($db->schema()->tableExists('__migrations'));
            self::assertFalse($db->schema()->tableExists('__seeds'));
            $migrations->setDryRun(false)->getMigrationHistory();
            $seeds->setDryRun(false)->getSeedHistory();
            self::assertTrue($db->schema()->tableExists('__migrations'));
            self::assertTrue($db->schema()->tableExists('__seeds'));
        } finally {
            unlink($path . '/m20261101120000_test.php');
            unlink($path . '/ExampleSeed.php');
            rmdir($path);
        }
    }

    public function testLazyLoadingPolicyIsPerModel(): void
    {
        ReviewModelA::preventLazyLoading(true);

        try {
            self::assertTrue(ReviewModelA::lazyLoadingIsPrevented());
            self::assertFalse(ReviewModelB::lazyLoadingIsPrevented());
        } finally {
            ReviewModelA::preventLazyLoading(false);
        }
    }

    public function testGeneratedRelationUsesCategoryModel(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE categories (id INTEGER PRIMARY KEY)');
        $db->rawQuery('CREATE TABLE posts (id INTEGER PRIMARY KEY, category_id INTEGER REFERENCES categories(id))');
        $path = sys_get_temp_dir() . '/pdodb_model_' . bin2hex(random_bytes(6));
        mkdir($path);

        try {
            ob_start();

            try {
                $file = ModelGenerator::generate('Post', 'posts', $path, $db, 'app\\models');
            } finally {
                ob_end_clean();
            }
            self::assertStringContainsString("'modelClass' => Category::class", (string) file_get_contents($file));
        } finally {
            if (is_file($path . '/Post.php')) {
                unlink($path . '/Post.php');
            }
            rmdir($path);
        }
    }

    public function testMultipleConnectionsKeepPrimaryOptions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdodb_config_');
        self::assertIsString($path);

        try {
            $main = ['driver' => 'sqlite', 'path' => ':memory:', 'prefix' => 'app_', 'ai' => ['provider' => 'openai']];
            InitConfigGenerator::generateConfigPhp(['default' => 'main', 'connections' => ['main' => $main, 'other' => ['driver' => 'sqlite', 'path' => ':memory:']]], $path);
            $config = require $path;
            self::assertSame('app_', $config['connections']['main']['prefix']);
            self::assertSame('openai', $config['connections']['main']['ai']['provider']);
            InitConfigGenerator::generateEnv($main, [], $path);
            self::assertStringContainsString('PDODB_PREFIX=app_', (string) file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}

final class ReviewModelA extends Model
{
}
final class ReviewModelB extends Model
{
}
