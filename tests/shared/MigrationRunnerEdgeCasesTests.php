<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\migrations\MigrationRunner;

/**
 * Tests for MigrationRunner edge cases and protected methods.
 */
class MigrationRunnerEdgeCasesTests extends BaseSharedTestCase
{
    /**
     * Test getAllMigrationFiles with invalid filenames.
     */
    public function testGetAllMigrationFilesInvalidFilenames(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_edge1';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Create invalid migration files
        file_put_contents($migrationPath . '/minvalid.php', '<?php class minvalid {}');
        file_put_contents($migrationPath . '/not_a_migration.php', '<?php class NotMigration {}');
        file_put_contents($migrationPath . '/m20251101_invalid_format.php', '<?php class m20251101Invalidformat {}');

        $runner = new MigrationRunner($db, $migrationPath);
        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getAllMigrationFiles');
        $method->setAccessible(true);

        $files = $method->invoke($runner);
        // Invalid files should be filtered out
        $this->assertNotContains('20251101_invalid_format', $files);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/minvalid.php');
        unlink($migrationPath . '/not_a_migration.php');
        unlink($migrationPath . '/m20251101_invalid_format.php');
    }

    /**
     * Test getAllMigrationFiles with glob returning false.
     */
    public function testGetAllMigrationFilesGlobFailure(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        // Use non-existent path that will cause glob to return false
        $migrationPath = sys_get_temp_dir() . '/pdodb_nonexistent_migrations_' . time();
        // Don't create directory

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Migration path does not exist');

        new MigrationRunner($db, $migrationPath);
    }

    /**
     * Test sanitizeName method.
     */
    public function testSanitizeName(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_edge2';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $runner = new MigrationRunner($db, $migrationPath);
        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('sanitizeName');
        $method->setAccessible(true);

        $this->assertEquals('test_migration', $method->invoke($runner, 'Test Migration'));
        $this->assertEquals('test_migration', $method->invoke($runner, 'test-migration'));
        $this->assertEquals('test_migration', $method->invoke($runner, 'test__migration'));
        $this->assertEquals('migration', $method->invoke($runner, ''));
        $this->assertEquals('migration', $method->invoke($runner, '___'));

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
    }

    /**
     * Test loadMigration with non-existent file.
     */
    public function testLoadMigrationNonExistentFile(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_edge3';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $runner = new MigrationRunner($db, $migrationPath);
        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('loadMigration');
        $method->setAccessible(true);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Migration file not found');

        $method->invoke($runner, 'nonexistent_migration');

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
    }

    /**
     * Test loadMigration with invalid class name.
     */
    public function testLoadMigrationInvalidClass(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_edge4';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Create migration file with wrong class name
        $testMigrationContent = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class WrongClassName extends Migration {
    public function up(): void {}
    public function down(): void {}
}
PHP;
        file_put_contents($migrationPath . '/m20251101120010_testinvalid.php', $testMigrationContent);

        $runner = new MigrationRunner($db, $migrationPath);
        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('loadMigration');
        $method->setAccessible(true);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Migration class');

        $method->invoke($runner, '20251101120010_testinvalid');

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/m20251101120010_testinvalid.php');
    }

    /**
     * Test getNextBatchNumber when no migrations exist.
     */
    public function testGetNextBatchNumberNoMigrations(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_edge5';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $runner = new MigrationRunner($db, $migrationPath);
        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getNextBatchNumber');
        $method->setAccessible(true);

        $batch = $method->invoke($runner);
        $this->assertEquals(1, $batch);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
    }

    /**
     * Test getNextBatchNumber with existing migrations.
     */
    public function testGetNextBatchNumberWithMigrations(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        $db->rawQuery('DROP TABLE IF EXISTS test_batch_table');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_edge6';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $testMigrationContent = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120011Testbatch extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_batch_table', ['id' => $this->schema()->primaryKey()]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_batch_table');
    }
}
PHP;
        file_put_contents($migrationPath . '/m20251101120011_testbatch.php', $testMigrationContent);

        $runner = new MigrationRunner($db, $migrationPath);

        // Apply migration (batch 1)
        $runner->migrate();
        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('getNextBatchNumber');
        $method->setAccessible(true);

        // Next batch should be 2
        $batch = $method->invoke($runner);
        $this->assertEquals(2, $batch);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_batch_table');
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/m20251101120011_testbatch.php');
    }

    /**
     * Test migrateTo with non-existent version.
     */
    public function testMigrateToNonExistentVersion(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_edge7';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $runner = new MigrationRunner($db, $migrationPath);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Migration version');

        $runner->migrateTo('nonexistent_version');

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
    }

    /**
     * Test migrateTo when already at target version.
     */
    public function testMigrateToAlreadyAtVersion(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        $db->rawQuery('DROP TABLE IF EXISTS test_already_table');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_edge8';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $testMigrationContent = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101120012Testalready extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_already_table', ['id' => $this->schema()->primaryKey()]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_already_table');
    }
}
PHP;
        file_put_contents($migrationPath . '/m20251101120012_testalready.php', $testMigrationContent);

        $runner = new MigrationRunner($db, $migrationPath);

        // Apply migration
        $runner->migrate();

        // Try to migrate to same version (should be no-op)
        $runner->migrateTo('20251101120012_testalready');
        // Should not throw exception
        $version = $runner->getMigrationVersion();
        $this->assertEquals('20251101120012_testalready', $version);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_already_table');
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/m20251101120012_testalready.php');
    }
}
