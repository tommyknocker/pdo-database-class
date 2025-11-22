<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\seeds\Seed;
use tommyknocker\pdodb\seeds\SeedRunner;

/**
 * Shared tests for Seed system.
 */
class SeedTests extends BaseSharedTestCase
{
    /** @var string Test seed path */
    protected string $testSeedPath;

    /**
     * Set up test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->testSeedPath = sys_get_temp_dir() . '/pdodb_seeds_test_' . uniqid();
        if (!is_dir($this->testSeedPath)) {
            mkdir($this->testSeedPath, 0755, true);
        }
    }

    /**
     * Clean up test environment.
     */
    protected function tearDown(): void
    {
        // Clean up test files
        if (is_dir($this->testSeedPath)) {
            $files = glob($this->testSeedPath . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->testSeedPath);
        }

        // Clean up test tables
        self::$db->rawQuery('DROP TABLE IF EXISTS __seeds');
        self::$db->rawQuery('DROP TABLE IF EXISTS test_seed_table');
        self::$db->rawQuery('DROP TABLE IF EXISTS test_users');

        parent::tearDown();
    }

    /**
     * Get database instance.
     *
     * @return PdoDb
     */
    protected static function getDb(): PdoDb
    {
        return self::$db;
    }

    /**
     * Test seed runner creation.
     */
    public function testSeedRunnerCreation(): void
    {
        $db = self::getDb();
        $runner = new SeedRunner($db, $this->testSeedPath);

        $this->assertInstanceOf(SeedRunner::class, $runner);
    }

    /**
     * Test seed history table creation.
     */
    public function testSeedTableCreation(): void
    {
        $db = self::getDb();
        $db->rawQuery('DROP TABLE IF EXISTS __seeds');

        $runner = new SeedRunner($db, $this->testSeedPath);

        // Table should be created automatically
        $this->assertTrue($db->schema()->tableExists('__seeds'));
    }

    /**
     * Test get all seeds.
     */
    public function testGetAllSeeds(): void
    {
        $db = self::getDb();
        $runner = new SeedRunner($db, $this->testSeedPath);

        // Create test seed files
        $this->createTestSeedFile('users_table');
        $this->createTestSeedFile('categories_table');

        $allSeeds = $runner->getAllSeeds();

        $this->assertCount(2, $allSeeds);
        $this->assertContains('s' . date('Ymd') . '000002_users_table_1', $allSeeds);
        $this->assertContains('s' . date('Ymd') . '000003_categories_table_2', $allSeeds);
    }

    /**
     * Test get new seeds.
     */
    public function testGetNewSeeds(): void
    {
        $db = self::getDb();
        $runner = new SeedRunner($db, $this->testSeedPath);

        // Create test seed files
        $this->createTestSeedFile('users_table');
        $this->createTestSeedFile('categories_table');

        $newSeeds = $runner->getNewSeeds();
        $this->assertCount(2, $newSeeds);

        // Execute one seed
        $allSeeds = $runner->getAllSeeds();
        $firstSeed = $allSeeds[0];
        $runner->run($firstSeed);

        $newSeeds = $runner->getNewSeeds();
        $this->assertCount(1, $newSeeds);
        $this->assertContains($allSeeds[1], $newSeeds);
    }

    /**
     * Test create seed file.
     */
    public function testCreateSeed(): void
    {
        $db = self::getDb();
        $runner = new SeedRunner($db, $this->testSeedPath);

        $filename = $runner->create('test_seed');

        $this->assertFileExists($filename);
        $this->assertStringContainsString('test_seed', $filename);
        $this->assertStringContainsString('TestSeedSeed', file_get_contents($filename));
    }

    /**
     * Test seed execution.
     */
    public function testSeedExecution(): void
    {
        $db = self::getDb();
        $runner = new SeedRunner($db, $this->testSeedPath);

        // Create test table first
        $schema = $db->schema();
        $schema->createTable('test_users', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'email' => $schema->string(255),
        ]);

        // Create and execute seed
        $seedFile = $this->createTestSeedFileWithData('users_data');
        $seedName = basename($seedFile, '.php');

        $executed = $runner->run($seedName);
        $this->assertContains($seedName, $executed);

        // Check if data was inserted
        $users = $db->find()->table('test_users')->get();
        $this->assertCount(2, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
        $this->assertEquals('Jane Smith', $users[1]['name']);

        // Check seed history
        $executedSeeds = $runner->getExecutedSeeds();
        $this->assertContains($seedName, $executedSeeds);
    }

    /**
     * Test seed rollback.
     */
    public function testSeedRollback(): void
    {
        $db = self::getDb();
        $runner = new SeedRunner($db, $this->testSeedPath);

        // Create test table first
        $schema = $db->schema();
        $schema->createTable('test_users', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'email' => $schema->string(255),
        ]);

        // Create and execute seed
        $seedFile = $this->createTestSeedFileWithData('users_data');
        $seedName = basename($seedFile, '.php');

        $runner->run($seedName);

        // Verify data exists
        $users = $db->find()->table('test_users')->get();
        $this->assertCount(2, $users);

        // Rollback seed
        $rolledBack = $runner->rollback($seedName);
        $this->assertContains($seedName, $rolledBack);

        // Verify data was removed
        $users = $db->find()->table('test_users')->get();
        $this->assertCount(0, $users);

        // Check seed history
        $executedSeeds = $runner->getExecutedSeeds();
        $this->assertNotContains($seedName, $executedSeeds);
    }

    /**
     * Test seed dry-run mode.
     */
    public function testSeedDryRun(): void
    {
        $db = self::getDb();
        $runner = new SeedRunner($db, $this->testSeedPath);
        $runner->setDryRun(true);

        // Create test table first
        $schema = $db->schema();
        $schema->createTable('test_users', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'email' => $schema->string(255),
        ]);

        // Create seed
        $seedFile = $this->createTestSeedFileWithData('users_data');
        $seedName = basename($seedFile, '.php');

        $executed = $runner->run($seedName);
        $this->assertContains($seedName, $executed); // Seed was processed in dry-run

        // Check collected queries
        $queries = $runner->getCollectedQueries();
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('Seed:', implode("\n", $queries));

        // Verify no data was inserted
        $users = $db->find()->table('test_users')->get();
        $this->assertCount(0, $users);

        // Verify seed was not recorded
        $executedSeeds = $runner->getExecutedSeeds();
        $this->assertNotContains($seedName, $executedSeeds);
    }

    /**
     * Test seed pretend mode.
     */
    public function testSeedPretend(): void
    {
        $db = self::getDb();
        $runner = new SeedRunner($db, $this->testSeedPath);
        $runner->setPretend(true);

        // Create seed
        $seedFile = $this->createTestSeedFileWithData('users_data');
        $seedName = basename($seedFile, '.php');

        $executed = $runner->run($seedName);
        $this->assertContains($seedName, $executed); // Seed was processed in pretend mode

        // Check collected queries
        $queries = $runner->getCollectedQueries();
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('Would execute seed.run()', implode("\n", $queries));

        // Verify seed was not recorded
        $executedSeeds = $runner->getExecutedSeeds();
        $this->assertNotContains($seedName, $executedSeeds);
    }

    /**
     * Test batch rollback.
     */
    public function testBatchRollback(): void
    {
        $db = self::getDb();
        $runner = new SeedRunner($db, $this->testSeedPath);

        // Create test table first
        $schema = $db->schema();
        $schema->createTable('test_users', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'email' => $schema->string(255),
        ]);

        // Create and execute multiple seeds
        $seedFile1 = $this->createTestSeedFileWithData('users_data_1', 'User One', 'user1@example.com');
        $seedFile2 = $this->createTestSeedFileWithData('users_data_2', 'User Two', 'user2@example.com');

        $seedName1 = basename($seedFile1, '.php');
        $seedName2 = basename($seedFile2, '.php');

        // Run all seeds in one batch
        $runner->run();

        // Verify data exists (each seed adds 2 users)
        $users = $db->find()->table('test_users')->get();
        $this->assertCount(4, $users);

        // Rollback last batch (rollback last executed seed)
        $rolledBack = $runner->rollback();
        $this->assertCount(1, $rolledBack);

        // Verify data was partially removed (only last seed rolled back)
        $users = $db->find()->table('test_users')->get();
        $this->assertCount(2, $users); // Only first seed's data remains
    }

    /**
     * Test seed history.
     */
    public function testSeedHistory(): void
    {
        $db = self::getDb();
        $runner = new SeedRunner($db, $this->testSeedPath);

        // Create test table first
        $schema = $db->schema();
        $schema->createTable('test_users', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'email' => $schema->string(255),
        ]);

        // Create and execute seed
        $seedFile = $this->createTestSeedFileWithData('users_data');
        $seedName = basename($seedFile, '.php');

        $runner->run($seedName);

        // Check history
        $history = $runner->getSeedHistory();
        $this->assertCount(1, $history);
        $this->assertEquals($seedName, $history[0]['seed']);
        $this->assertEquals(1, $history[0]['batch']);
        $this->assertArrayHasKey('executed_at', $history[0]);
    }

    /**
     * Create a test seed file.
     *
     * @param string $name Seed name
     *
     * @return string Created filename
     */
    protected function createTestSeedFile(string $name): string
    {
        static $counter = 1;
        $uniqueName = $name . '_' . $counter++;
        $timestamp = date('Ymd') . sprintf('%06d', $counter);
        $filename = "s{$timestamp}_{$uniqueName}.php";
        $filepath = $this->testSeedPath . '/' . $filename;

        $className = ucfirst(str_replace('_', '', $uniqueName)) . 'Seed';
        $content = <<<PHP
<?php
declare(strict_types=1);
use tommyknocker\pdodb\seeds\Seed;

class {$className} extends Seed
{
    public function run(): void
    {
        // Test seed
    }

    public function rollback(): void
    {
        // Test rollback
    }
}
PHP;

        file_put_contents($filepath, $content);
        return $filepath;
    }

    /**
     * Create a test seed file with actual data operations.
     *
     * @param string $name Seed name
     * @param string $userName User name
     * @param string $userEmail User email
     *
     * @return string Created filename
     */
    protected function createTestSeedFileWithData(string $name, string $userName = 'John Doe', string $userEmail = 'john@example.com'): string
    {
        static $counter = 1;
        $uniqueName = $name . '_' . $counter++;
        $timestamp = date('Ymd') . sprintf('%06d', $counter);
        $filename = "s{$timestamp}_{$uniqueName}.php";
        $filepath = $this->testSeedPath . '/' . $filename;

        $className = ucfirst(str_replace('_', '', $uniqueName)) . 'Seed';
        $content = <<<PHP
<?php
declare(strict_types=1);
use tommyknocker\pdodb\seeds\Seed;

class {$className} extends Seed
{
    public function run(): void
    {
        \$this->insert('test_users', [
            'name' => '{$userName}',
            'email' => '{$userEmail}',
        ]);

        \$this->insert('test_users', [
            'name' => 'Jane Smith',
            'email' => 'jane_{$uniqueName}@example.com',
        ]);
    }

    public function rollback(): void
    {
        \$this->delete('test_users', ['email' => '{$userEmail}']);
        \$this->delete('test_users', ['email' => 'jane_{$uniqueName}@example.com']);
    }
}
PHP;

        file_put_contents($filepath, $content);
        return $filepath;
    }

    /**
     * Test Seed helper methods.
     */
    public function testSeedHelperMethods(): void
    {
        $db = self::getDb();

        // Create test table
        $schema = $db->schema();
        $schema->createTable('test_seed_table', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'value' => $schema->integer(),
        ]);

        // Create a concrete seed class for testing
        $seed = new class ($db) extends Seed {
            public function run(): void
            {
                // Test schema() method
                $this->schema()->createTable('test_helper_table', [
                    'id' => $this->schema()->primaryKey(),
                    'name' => $this->schema()->string(100),
                ]);

                // Test find() method
                $this->find()->table('test_seed_table')->get();

                // Test execute() method
                $this->execute('INSERT INTO test_seed_table (name, value) VALUES (?, ?)', ['Test', 123]);
                $this->execute('INSERT INTO test_seed_table (name, value) VALUES (?, ?)', ['Test2', 456]);

                // Test insert() method
                $this->insert('test_seed_table', ['name' => 'Inserted', 'value' => 789]);

                // Test insertMulti() method
                $rows = [
                    ['name' => 'Multi1', 'value' => 100],
                    ['name' => 'Multi2', 'value' => 200],
                ];
                $this->insertMulti('test_seed_table', $rows);

                // Test insertBatch() method (alias for insertMulti)
                $rows2 = [
                    ['name' => 'Batch1', 'value' => 300],
                    ['name' => 'Batch2', 'value' => 400],
                ];
                $this->insertBatch('test_seed_table', $rows2);

                // Test update() method
                $this->update('test_seed_table', ['value' => 999], ['name' => 'Test']);

                // Test delete() method
                $this->delete('test_seed_table', ['name' => 'Test2']);

                // Test raw() method
                $this->raw('NOW()');
            }

            public function rollback(): void
            {
                $this->delete('test_seed_table', []);
                $this->schema()->dropTable('test_helper_table');
            }
        };

        // Execute seed
        $seed->run();

        // Verify operations
        $rows = $db->find()->table('test_seed_table')->get();
        $this->assertGreaterThan(0, count($rows));

        // Verify helper table was created
        $this->assertTrue($db->schema()->tableExists('test_helper_table'));

        // Rollback
        $seed->rollback();

        // Verify rollback
        $rowsAfter = $db->find()->table('test_seed_table')->get();
        $this->assertCount(0, $rowsAfter);
        $this->assertFalse($db->schema()->tableExists('test_helper_table'));
    }

    /**
     * Test Seed schema() method returns DdlQueryBuilder.
     */
    public function testSeedSchemaMethod(): void
    {
        $db = self::getDb();
        $seed = new class ($db) extends Seed {
            public $schemaResult;

            public function run(): void
            {
                $this->schemaResult = $this->schema();
            }

            public function rollback(): void
            {
            }
        };

        $seed->run();
        $this->assertInstanceOf(\tommyknocker\pdodb\query\DdlQueryBuilder::class, $seed->schemaResult);
    }

    /**
     * Test Seed find() method returns QueryBuilder.
     */
    public function testSeedFindMethod(): void
    {
        $db = self::getDb();
        $seed = new class ($db) extends Seed {
            public $queryResult;

            public function run(): void
            {
                $this->queryResult = $this->find();
            }

            public function rollback(): void
            {
            }
        };

        $seed->run();
        $this->assertInstanceOf(\tommyknocker\pdodb\query\QueryBuilder::class, $seed->queryResult);
    }

    /**
     * Test Seed execute() method with parameters.
     */
    public function testSeedExecuteMethod(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $schema->createTable('test_execute', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        $seed = new class ($db) extends Seed {
            public $executeResult;
            public $selectResult;

            public function run(): void
            {
                $this->executeResult = $this->execute('INSERT INTO test_execute (name) VALUES (?)', ['TestName']);
                $this->selectResult = $this->execute('SELECT * FROM test_execute');
            }

            public function rollback(): void
            {
                $this->execute('DELETE FROM test_execute');
            }
        };

        $seed->run();
        $this->assertIsArray($seed->executeResult);
        $this->assertIsArray($seed->selectResult);
        $this->assertCount(1, $seed->selectResult);
        $this->assertEquals('TestName', $seed->selectResult[0]['name']);

        $seed->rollback();

        $rows = $db->find()->table('test_execute')->get();
        $this->assertCount(0, $rows);
    }

    /**
     * Test Seed insert() method.
     */
    public function testSeedInsertMethod(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $schema->createTable('test_insert', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        $seed = new class ($db) extends Seed {
            public $insertId;

            public function run(): void
            {
                $this->insertId = $this->insert('test_insert', ['name' => 'InsertedName']);
            }

            public function rollback(): void
            {
                $this->delete('test_insert', []);
            }
        };

        $seed->run();
        $this->assertIsInt($seed->insertId);
        $this->assertGreaterThan(0, $seed->insertId);

        $rows = $db->find()->table('test_insert')->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('InsertedName', $rows[0]['name']);

        $seed->rollback();
    }

    /**
     * Test Seed insertMulti() method.
     */
    public function testSeedInsertMultiMethod(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $schema->createTable('test_insert_multi', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'value' => $schema->integer(),
        ]);

        $seed = new class ($db) extends Seed {
            public $insertCount;

            public function run(): void
            {
                $rows = [
                    ['name' => 'Row1', 'value' => 1],
                    ['name' => 'Row2', 'value' => 2],
                    ['name' => 'Row3', 'value' => 3],
                ];
                $this->insertCount = $this->insertMulti('test_insert_multi', $rows);
            }

            public function rollback(): void
            {
                $this->delete('test_insert_multi', []);
            }
        };

        $seed->run();
        $this->assertEquals(3, $seed->insertCount);

        $rows = $db->find()->table('test_insert_multi')->get();
        $this->assertCount(3, $rows);

        $seed->rollback();
    }

    /**
     * Test Seed insertBatch() method (alias for insertMulti).
     */
    public function testSeedInsertBatchMethod(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $schema->createTable('test_insert_batch', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        $seed = new class ($db) extends Seed {
            public $insertCount;

            public function run(): void
            {
                $rows = [
                    ['name' => 'Batch1'],
                    ['name' => 'Batch2'],
                ];
                $this->insertCount = $this->insertBatch('test_insert_batch', $rows);
            }

            public function rollback(): void
            {
                $this->delete('test_insert_batch', []);
            }
        };

        $seed->run();
        $this->assertEquals(2, $seed->insertCount);

        $rows = $db->find()->table('test_insert_batch')->get();
        $this->assertCount(2, $rows);

        $seed->rollback();
    }

    /**
     * Test Seed update() method.
     */
    public function testSeedUpdateMethod(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $schema->createTable('test_update', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
            'value' => $schema->integer(),
        ]);

        $db->find()->table('test_update')->insert(['name' => 'Original', 'value' => 100]);

        $seed = new class ($db) extends Seed {
            public $affectedRows;

            public function run(): void
            {
                $this->affectedRows = $this->update('test_update', ['value' => 200], ['name' => 'Original']);
            }

            public function rollback(): void
            {
                $this->update('test_update', ['value' => 100], ['name' => 'Original']);
            }
        };

        $seed->run();
        $this->assertEquals(1, $seed->affectedRows);

        $row = $db->find()->table('test_update')->where('name', 'Original')->first();
        $this->assertEquals(200, $row['value']);

        $seed->rollback();

        $row = $db->find()->table('test_update')->where('name', 'Original')->first();
        $this->assertEquals(100, $row['value']);
    }

    /**
     * Test Seed delete() method.
     */
    public function testSeedDeleteMethod(): void
    {
        $db = self::getDb();
        $schema = $db->schema();
        $schema->createTable('test_delete', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ]);

        $db->find()->table('test_delete')->insert(['name' => 'ToDelete']);
        $db->find()->table('test_delete')->insert(['name' => 'ToKeep']);

        $seed = new class ($db) extends Seed {
            public $deletedCount;

            public function run(): void
            {
                $this->deletedCount = $this->delete('test_delete', ['name' => 'ToDelete']);
            }

            public function rollback(): void
            {
                // Nothing to rollback for delete test
            }
        };

        $seed->run();
        $this->assertEquals(1, $seed->deletedCount);

        $rows = $db->find()->table('test_delete')->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('ToKeep', $rows[0]['name']);
    }

    /**
     * Test Seed raw() method.
     */
    public function testSeedRawMethod(): void
    {
        $db = self::getDb();
        $seed = new class ($db) extends Seed {
            public $rawValue;

            public function run(): void
            {
                $this->rawValue = $this->raw('NOW()');
            }

            public function rollback(): void
            {
            }
        };

        $seed->run();
        $this->assertInstanceOf(\tommyknocker\pdodb\helpers\values\RawValue::class, $seed->rawValue);
        $this->assertEquals('NOW()', $seed->rawValue->getValue());
    }
}
