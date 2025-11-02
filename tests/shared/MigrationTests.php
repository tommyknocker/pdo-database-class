<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\migrations\Migration;
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\PdoDb;

/**
 * Shared tests for Migration system.
 */
class MigrationTests extends BaseSharedTestCase
{
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
     * Test migration runner creation.
     */
    public function testMigrationRunnerCreation(): void
    {
        $db = self::getDb();
        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_test';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $runner = new MigrationRunner($db, $migrationPath);

        $this->assertInstanceOf(MigrationRunner::class, $runner);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
    }

    /**
     * Test migration history table creation.
     */
    public function testMigrationTableCreation(): void
    {
        $db = self::getDb();
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_test2';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $runner = new MigrationRunner($db, $migrationPath);

        // Table should be created automatically
        $this->assertTrue($db->find()->table('__migrations')->tableExists());

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
    }

    /**
     * Test get new migrations.
     */
    public function testGetNewMigrations(): void
    {
        $db = self::getDb();
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_test3';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Create a test migration file
        $testMigrationContent = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120000Testmigration extends Migration {
    public function up(): void { $this->schema()->createTable('test_mig_table', ['id' => $this->schema()->primaryKey()]); }
    public function down(): void { $this->schema()->dropTable('test_mig_table'); }
}
PHP;
        file_put_contents($migrationPath . '/m20251101120000_testmigration.php', $testMigrationContent);

        $runner = new MigrationRunner($db, $migrationPath);
        $newMigrations = $runner->getNewMigrations();

        $this->assertNotEmpty($newMigrations);
        $this->assertContains('20251101120000_testmigration', $newMigrations);

        // Cleanup
        unlink($migrationPath . '/m20251101120000_testmigration.php');
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        $db->rawQuery('DROP TABLE IF EXISTS test_mig_table');
    }

    /**
     * Test create migration file.
     */
    public function testCreateMigration(): void
    {
        $db = self::getDb();
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_test4';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $runner = new MigrationRunner($db, $migrationPath);
        $filename = $runner->create('add_users_table');

        $this->assertFileExists($filename);
        $this->assertStringContainsString('add_users_table', $filename);

        // Cleanup
        if (file_exists($filename)) {
            unlink($filename);
        }
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
    }

    /**
     * Test migration execution.
     */
    public function testMigrationExecution(): void
    {
        $db = self::getDb();
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        $db->rawQuery('DROP TABLE IF EXISTS test_migration_table');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_test5';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Create a simple migration
        $testMigrationContent = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120001Testmigration extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_migration_table', [
            'id' => $this->schema()->primaryKey(),
            'name' => $this->schema()->string(100),
        ]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_migration_table');
    }
}
PHP;
        file_put_contents($migrationPath . '/m20251101120001_testmigration.php', $testMigrationContent);

        $runner = new MigrationRunner($db, $migrationPath);
        $applied = $runner->migrate();

        $this->assertNotEmpty($applied);
        $this->assertTrue($db->find()->table('test_migration_table')->tableExists());

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_migration_table');
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/m20251101120001_testmigration.php');
    }

    /**
     * Test migration rollback.
     */
    public function testMigrationRollback(): void
    {
        $db = self::getDb();
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        $db->rawQuery('DROP TABLE IF EXISTS test_rollback_table');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_test6';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Create a migration
        $testMigrationContent = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120002Testrollback extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_rollback_table', [
            'id' => $this->schema()->primaryKey(),
        ]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_rollback_table');
    }
}
PHP;
        file_put_contents($migrationPath . '/m20251101120002_testrollback.php', $testMigrationContent);

        $runner = new MigrationRunner($db, $migrationPath);
        $applied = $runner->migrate();
        $this->assertNotEmpty($applied);

        // Rollback
        $rolledBack = $runner->migrateDown(1);
        $this->assertNotEmpty($rolledBack);
        $this->assertFalse($db->find()->table('test_rollback_table')->tableExists());

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/m20251101120002_testrollback.php');
    }
}
