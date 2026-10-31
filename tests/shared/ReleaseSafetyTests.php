<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use ReviewGenerated\Account;
use ReviewGenerated\Entry;
use ReviewGenerated\Status;
use tommyknocker\pdodb\cli\InitConfigGenerator;
use tommyknocker\pdodb\cli\ModelGenerator;
use tommyknocker\pdodb\exceptions\QueryException;
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

    public function testGeneratedRelationsUseRealAndCustomModelNames(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->rawQuery('CREATE TABLE statuses (id INTEGER PRIMARY KEY, name TEXT)');
        $db->rawQuery('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT)');
        $db->rawQuery('CREATE TABLE entries (id INTEGER PRIMARY KEY, status_id INTEGER REFERENCES statuses(id), author_id INTEGER REFERENCES people(id))');
        $db->rawQuery("INSERT INTO statuses VALUES (1, 'Active')");
        $db->rawQuery("INSERT INTO people VALUES (1, 'Alice')");
        $db->rawQuery('INSERT INTO entries VALUES (1, 1, 1)');
        $path = sys_get_temp_dir() . '/pdodb_models_' . bin2hex(random_bytes(6));
        mkdir($path);

        try {
            ob_start();

            try {
                ModelGenerator::generate('Status', 'statuses', $path, $db, 'ReviewGenerated');
                ModelGenerator::generate('Account', 'people', $path, $db, 'ReviewGenerated');
                ModelGenerator::generate('Entry', 'entries', $path, $db, 'ReviewGenerated', false, ['people' => 'Account']);
            } finally {
                ob_end_clean();
            }
            require $path . '/Status.php';
            require $path . '/Account.php';
            require $path . '/Entry.php';
            Entry::setDb($db);
            Status::setDb($db);
            Account::setDb($db);
            $entry = Entry::findOne(1);
            self::assertNotNull($entry);
            self::assertInstanceOf(Status::class, $entry->status);
            self::assertSame('Active', $entry->status->name);
            self::assertInstanceOf(Account::class, $entry->author);
            self::assertSame('Alice', $entry->author->name);
        } finally {
            foreach (['Status', 'Account', 'Entry'] as $model) {
                if (is_file($path . '/' . $model . '.php')) {
                    unlink($path . '/' . $model . '.php');
                }
            }
            rmdir($path);
        }
    }

    public function testBothModelCommandsAcceptCustomRelationMapping(): void
    {
        $path = sys_get_temp_dir() . '/pdodb_model_cli_' . bin2hex(random_bytes(6));
        mkdir($path);
        $db = new PdoDb('sqlite', ['path' => $path . '/test.sqlite']);
        $db->rawQuery('CREATE TABLE people (id INTEGER PRIMARY KEY)');
        $db->rawQuery('CREATE TABLE entries (id INTEGER PRIMARY KEY, author_id INTEGER REFERENCES people(id))');
        $env = ['PATH' => (string)getenv('PATH'), 'PDODB_DRIVER' => 'sqlite', 'PDODB_PATH' => $path . '/test.sqlite'];
        $bin = dirname(__DIR__, 2) . '/bin/pdodb';

        try {
            foreach ([
                ['model', 'make', 'EntryA', 'entries', $path, '--relation-models={"people":"Account"}'],
                ['generate', 'model', '--model=EntryB', '--table=entries', '--output=' . $path, '--relation-models={"people":"Account"}'],
            ] as $command) {
                $process = proc_open([PHP_BINARY, $bin, ...$command], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $path, $env);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $out . $err);
            }
            foreach (['EntryA', 'EntryB'] as $model) {
                self::assertStringContainsString("'modelClass' => Account::class", (string)file_get_contents($path . '/' . $model . '.php'));
            }
        } finally {
            $db->disconnect();
            foreach (['EntryA.php', 'EntryB.php', 'test.sqlite'] as $file) {
                if (is_file($path . '/' . $file)) {
                    unlink($path . '/' . $file);
                }
            }
            rmdir($path);
        }
    }

    public function testCommonReferencedTableNamesAreSingularized(): void
    {
        $method = (new \ReflectionClass(ModelGenerator::class))->getMethod('tableToModelName');
        foreach (['categories' => 'Category', 'statuses' => 'Status', 'people' => 'Person',
            'addresses' => 'Address', 'news' => 'News', 'businesses' => 'Business'] as $table => $expected) {
            self::assertSame($expected, $method->invoke(null, $table));
        }
    }

    public function testRelatedModelMappingsAreValidated(): void
    {
        self::assertSame(['people' => 'Account'], ModelGenerator::parseRelatedModels('{"people":"Account"}'));
        $this->expectException(QueryException::class);
        ModelGenerator::parseRelatedModels('{"people":"Account;invalid"}');
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
