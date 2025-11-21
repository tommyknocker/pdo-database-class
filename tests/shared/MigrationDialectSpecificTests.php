<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\MigrationGenerator;
use tommyknocker\pdodb\migrations\Migration;

/**
 * Test that migrations use dialect-specific DDL query builders.
 */
class MigrationDialectSpecificTests extends BaseSharedTestCase
{
    protected string $tempMigrationPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempMigrationPath = sys_get_temp_dir() . '/pdodb_migration_test_' . uniqid();
        mkdir($this->tempMigrationPath, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up migration files
        if (is_dir($this->tempMigrationPath)) {
            $files = glob($this->tempMigrationPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempMigrationPath);
        }
        parent::tearDown();
    }

    public function testMigrationUsesDialectSpecificDdlBuilder(): void
    {
        $driver = static::$db->connection->getDriverName();

        // Create a test migration class
        $migrationContent = $this->generateTestMigrationContent($driver);
        $migrationFile = $this->tempMigrationPath . '/m2025_01_01_000000_test_dialect_specific.php';
        file_put_contents($migrationFile, $migrationContent);

        // Load and execute the migration
        require_once $migrationFile;
        $migrationClass = '\\tommyknocker\\pdodb\\migrations\\m20250101000000TestDialectSpecific';

        /** @var Migration $migration */
        $migration = new $migrationClass(static::$db);

        // This should not throw an exception if the correct dialect-specific builder is used
        $migration->up();

        // Verify table was created by checking if we can query it
        try {
            static::$db->rawQuery('SELECT 1 FROM test_dialect_specific_table LIMIT 1');
            $tableExists = true;
        } catch (\Exception) {
            $tableExists = false;
        }
        static::assertTrue($tableExists, 'Table should exist after migration up');

        // Clean up
        $migration->down();

        // Verify table was dropped
        try {
            static::$db->rawQuery('SELECT 1 FROM test_dialect_specific_table LIMIT 1');
            $tableExists = true;
        } catch (\Exception) {
            $tableExists = false;
        }
        static::assertFalse($tableExists, 'Table should not exist after migration down');
    }

    private function generateTestMigrationContent(string $driver): string
    {
        $dialectSpecificColumn = match ($driver) {
            'mysql', 'mariadb' => "\$this->schema()->enum(['active', 'inactive'])->defaultValue('active')",
            'pgsql' => '$this->schema()->uuid()',
            'sqlsrv' => '$this->schema()->uniqueidentifier()',
            'sqlite' => '$this->schema()->string(255)', // SQLite doesn't have unique dialect-specific types
            default => '$this->schema()->string(255)'
        };

        return <<<PHP
<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

class m20250101000000TestDialectSpecific extends Migration
{
    public function up(): void
    {
        \$this->schema()->createTable('test_dialect_specific_table', [
            'id' => \$this->schema()->primaryKey(),
            'dialect_column' => {$dialectSpecificColumn},
            'created_at' => \$this->schema()->timestamp()->defaultExpression('CURRENT_TIMESTAMP')
        ]);
    }

    public function down(): void
    {
        \$this->schema()->dropTable('test_dialect_specific_table');
    }
}
PHP;
    }

    public function testMigrationGeneratorUsesCurrentConnection(): void
    {
        $driver = static::$db->connection->getDriverName();

        // Mock environment for migration generator
        putenv('PDODB_MIGRATION_PATH=' . $this->tempMigrationPath);

        // This test verifies that MigrationGenerator creates migrations
        // that can access the correct dialect-specific DDL builder
        // The actual generation is tested in CLI tests, here we just verify
        // that the Migration base class provides the correct schema() method

        $migration = new class (static::$db) extends Migration {
            public function up(): void
            {
            }
            public function down(): void
            {
            }

            public function getSchemaBuilder()
            {
                return $this->schema();
            }
        };

        $schema = $migration->getSchemaBuilder();

        // Verify we get the correct dialect-specific builder
        $builderClass = get_class($schema);

        switch ($driver) {
            case 'mysql':
                static::assertStringContainsString('MySQL', $builderClass);
                static::assertTrue(method_exists($schema, 'enum'));
                static::assertTrue(method_exists($schema, 'set'));
                break;
            case 'mariadb':
                static::assertStringContainsString('MariaDB', $builderClass);
                static::assertTrue(method_exists($schema, 'enum'));
                static::assertTrue(method_exists($schema, 'set'));
                break;
            case 'pgsql':
                static::assertStringContainsString('PostgreSQL', $builderClass);
                static::assertTrue(method_exists($schema, 'uuid'));
                static::assertTrue(method_exists($schema, 'jsonb'));
                static::assertTrue(method_exists($schema, 'array'));
                break;
            case 'sqlsrv':
                static::assertStringContainsString('MSSQL', $builderClass);
                static::assertTrue(method_exists($schema, 'uniqueidentifier'));
                static::assertTrue(method_exists($schema, 'nvarchar'));
                break;
            case 'sqlite':
                static::assertStringContainsString('SQLite', $builderClass);
                // SQLite builder should have universal methods
                static::assertTrue(method_exists($schema, 'string'));
                static::assertTrue(method_exists($schema, 'integer'));
                break;
        }

        // Clean up
        putenv('PDODB_MIGRATION_PATH');
    }
}
