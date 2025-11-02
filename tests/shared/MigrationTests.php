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

    /**
     * Test get migration version.
     */
    public function testGetMigrationVersion(): void
    {
        $db = self::getDb();
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        $db->rawQuery('DROP TABLE IF EXISTS test_version_table');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_test7';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $testMigrationContent = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120003Testversion extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_version_table', ['id' => $this->schema()->primaryKey()]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_version_table');
    }
}
PHP;
        file_put_contents($migrationPath . '/m20251101120003_testversion.php', $testMigrationContent);

        $runner = new MigrationRunner($db, $migrationPath);

        // Initially no version
        $version = $runner->getMigrationVersion();
        $this->assertNull($version);

        // Apply migration
        $runner->migrate();

        // Now should have version
        $version = $runner->getMigrationVersion();
        $this->assertNotNull($version);
        $this->assertEquals('20251101120003_testversion', $version);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_version_table');
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/m20251101120003_testversion.php');
    }

    /**
     * Test migrate with limit.
     */
    public function testMigrateWithLimit(): void
    {
        $db = self::getDb();
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_test8';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Create two migrations
        $testMigration1 = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120004Testlimit1 extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_limit_table1', ['id' => $this->schema()->primaryKey()]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_limit_table1');
    }
}
PHP;
        $testMigration2 = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120005Testlimit2 extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_limit_table2', ['id' => $this->schema()->primaryKey()]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_limit_table2');
    }
}
PHP;
        file_put_contents($migrationPath . '/m20251101120004_testlimit1.php', $testMigration1);
        file_put_contents($migrationPath . '/m20251101120005_testlimit2.php', $testMigration2);

        $runner = new MigrationRunner($db, $migrationPath);

        // Migrate with limit 1
        $applied = $runner->migrate(1);
        $this->assertCount(1, $applied);
        $this->assertContains('20251101120004_testlimit1', $applied);

        // Migrate remaining
        $applied = $runner->migrate(0);
        $this->assertCount(1, $applied);
        $this->assertContains('20251101120005_testlimit2', $applied);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_limit_table1');
        $db->rawQuery('DROP TABLE IF EXISTS test_limit_table2');
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/m20251101120004_testlimit1.php');
        unlink($migrationPath . '/m20251101120005_testlimit2.php');
    }

    /**
     * Test migrate to specific version.
     */
    public function testMigrateTo(): void
    {
        $db = self::getDb();
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_test9';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Create three migrations
        $testMigration1 = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120006Testto1 extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_to_table1', ['id' => $this->schema()->primaryKey()]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_to_table1');
    }
}
PHP;
        $testMigration2 = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120007Testto2 extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_to_table2', ['id' => $this->schema()->primaryKey()]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_to_table2');
    }
}
PHP;
        $testMigration3 = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120008Testto3 extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_to_table3', ['id' => $this->schema()->primaryKey()]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_to_table3');
    }
}
PHP;
        file_put_contents($migrationPath . '/m20251101120006_testto1.php', $testMigration1);
        file_put_contents($migrationPath . '/m20251101120007_testto2.php', $testMigration2);
        file_put_contents($migrationPath . '/m20251101120008_testto3.php', $testMigration3);

        $runner = new MigrationRunner($db, $migrationPath);

        // Migrate to version 2
        $runner->migrateTo('20251101120007_testto2');

        $this->assertTrue($db->schema()->tableExists('test_to_table1'));
        $this->assertTrue($db->schema()->tableExists('test_to_table2'));
        $this->assertFalse($db->schema()->tableExists('test_to_table3'));

        // Migrate to version 3
        $runner->migrateTo('20251101120008_testto3');
        $this->assertTrue($db->schema()->tableExists('test_to_table3'));

        // Verify we have all 3 migrations
        $historyBefore = $runner->getMigrationHistory();
        $appliedVersionsBefore = array_column($historyBefore, 'version');
        $this->assertCount(3, $appliedVersionsBefore);

        // Rollback to version 1
        $runner->migrateTo('20251101120006_testto1');

        // Verify rollback happened - check version changed
        $versionAfter = $runner->getMigrationVersion();
        // After rollback, version should be 1
        $this->assertEquals('20251101120006_testto1', $versionAfter);

        // Verify version 1 is in history
        $historyAfter = $runner->getMigrationHistory();
        $appliedVersionsAfter = array_column($historyAfter, 'version');
        $this->assertContains('20251101120006_testto1', $appliedVersionsAfter);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_to_table1');
        $db->rawQuery('DROP TABLE IF EXISTS test_to_table2');
        $db->rawQuery('DROP TABLE IF EXISTS test_to_table3');
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/m20251101120006_testto1.php');
        unlink($migrationPath . '/m20251101120007_testto2.php');
        unlink($migrationPath . '/m20251101120008_testto3.php');
    }

    /**
     * Test migrateUp for already applied migration.
     */
    public function testMigrateUpAlreadyApplied(): void
    {
        $db = self::getDb();
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_test10';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $testMigrationContent = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120009Testalready extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_already_table', ['id' => $this->schema()->primaryKey()]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_already_table');
    }
}
PHP;
        file_put_contents($migrationPath . '/m20251101120009_testalready.php', $testMigrationContent);

        $runner = new MigrationRunner($db, $migrationPath);

        // Apply migration
        $runner->migrateUp('20251101120009_testalready');
        $this->assertTrue($db->schema()->tableExists('test_already_table'));

        // Try to apply again (should be no-op)
        $runner->migrateUp('20251101120009_testalready');
        // Should not throw exception

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_already_table');
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/m20251101120009_testalready.php');
    }
}
