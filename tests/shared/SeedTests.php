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
}
