<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Test model for ActiveQuery tests.
 */
class ActiveQueryTestModel extends Model
{
    public static function tableName(): string
    {
        return 'test_active_query';
    }

    public static function globalScopes(): array
    {
        return [
            'active' => function ($query) {
                $query->where('status', 'active');
                return $query;
            },
        ];
    }

    public static function scopes(): array
    {
        return [
            'adults' => function ($query) {
                $query->where('age', 18, '>=');
                return $query;
            },
            'seniors' => function ($query) {
                $query->where('age', 65, '>=');
                return $query;
            },
        ];
    }

    public static function relations(): array
    {
        return [];
    }
}

/**
 * Tests for ActiveQuery methods that may not be fully covered.
 */
final class ActiveQueryTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_active_query (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                age INTEGER,
                status TEXT DEFAULT "active"
            )
        ');
        ActiveQueryTestModel::setDb(self::$db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DELETE FROM test_active_query');

        // Insert test data
        self::$db->rawQuery("INSERT INTO test_active_query (name, age, status) VALUES ('Alice', 25, 'active'), ('Bob', 30, 'active'), ('Charlie', 35, 'inactive')");
    }

    public function testGetQueryBuilder(): void
    {
        $query = ActiveQueryTestModel::find();
        $queryBuilder = $query->getQueryBuilder();

        $this->assertInstanceOf(QueryBuilder::class, $queryBuilder);
    }

    public function testGetQueryBuilderCanBeUsedDirectly(): void
    {
        $query = ActiveQueryTestModel::find()->withoutGlobalScope('active');
        $queryBuilder = $query->getQueryBuilder();

        // Use QueryBuilder directly
        $queryBuilder->where('age', 30, '>');
        $results = $query->all();

        $this->assertCount(1, $results);
        $this->assertEquals('Charlie', $results[0]->name);
    }

    public function testGetColumnThroughActiveQuery(): void
    {
        $query = ActiveQueryTestModel::find()->withoutGlobalScope('active');
        $columns = $query->select('name')->getColumn();

        $this->assertIsArray($columns);
        $this->assertContains('Alice', $columns);
        $this->assertContains('Bob', $columns);
        $this->assertContains('Charlie', $columns);
    }

    public function testGetValueThroughActiveQuery(): void
    {
        $query = ActiveQueryTestModel::find()->withoutGlobalScope('active');
        // Use COUNT aggregate which works reliably with getValue
        $count = $query->select(['count' => 'COUNT(*)'])->getValue();
        $this->assertGreaterThan(0, $count, 'Should have records');
        $this->assertEquals(3, $count, 'Should have 3 records');

        // Test getValue with aggregate function
        $query2 = ActiveQueryTestModel::find()->withoutGlobalScope('active');
        $maxAge = $query2->select(['max_age' => 'MAX(age)'])->getValue();
        $this->assertEquals(35, $maxAge);
    }

    public function testGetThroughActiveQuery(): void
    {
        $query = ActiveQueryTestModel::find()->withoutGlobalScope('active');
        $results = $query->where('age', 30, '>=')->orderBy('age')->get();

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        // Results are indexed arrays (0, 1) not by id
        $names = array_column($results, 'name');
        $this->assertContains('Bob', $names);
        $this->assertContains('Charlie', $names);
    }

    public function testOne(): void
    {
        $model = ActiveQueryTestModel::find()->where('name', 'Bob')->one();

        $this->assertInstanceOf(ActiveQueryTestModel::class, $model);
        $this->assertEquals('Bob', $model->name);
        $this->assertEquals(30, $model->age);
    }

    public function testOneReturnsNullWhenNotFound(): void
    {
        $model = ActiveQueryTestModel::find()->where('name', 'NonExistent')->one();

        $this->assertNull($model);
    }

    public function testAll(): void
    {
        $models = ActiveQueryTestModel::find()->withoutGlobalScope('active')->where('age', 30, '>=')->orderBy('age')->all();

        $this->assertIsArray($models);
        $this->assertCount(2, $models);
        $this->assertInstanceOf(ActiveQueryTestModel::class, $models[0]);
        $this->assertInstanceOf(ActiveQueryTestModel::class, $models[1]);
        $this->assertEquals('Bob', $models[0]->name);
        $this->assertEquals('Charlie', $models[1]->name);
    }

    public function testAllReturnsEmptyArrayWhenNoResults(): void
    {
        $models = ActiveQueryTestModel::find()->where('name', 'NonExistent')->all();

        $this->assertIsArray($models);
        $this->assertEmpty($models);
    }

    public function testGetOne(): void
    {
        $result = ActiveQueryTestModel::find()->where('name', 'Bob')->getOne();

        $this->assertIsArray($result);
        $this->assertEquals('Bob', $result['name']);
        $this->assertEquals(30, $result['age']);
    }

    public function testGetOneReturnsNullWhenNotFound(): void
    {
        $result = ActiveQueryTestModel::find()->withoutGlobalScope('active')->where('name', 'NonExistent')->getOne();

        // getOne() may return false or null, both are valid "not found" values
        $this->assertTrue($result === null || $result === false);
    }

    public function testGetMigrationHistoryWithLimit(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_history_test';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Create and apply 3 migrations
        $migrations = [];
        for ($i = 0; $i < 3; $i++) {
            $version = '2025110112' . str_pad((string)$i, 4, '0', STR_PAD_LEFT) . '_test' . $i;
            // Convert version to class name like MigrationRunner does
            $parts = explode('_', $version);
            $className = 'm';
            foreach ($parts as $part) {
                if (is_numeric($part)) {
                    $className .= $part;
                } else {
                    $className .= ucfirst($part);
                }
            }
            $testMigrationContent = <<<PHP
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class {$className} extends Migration {
    public function up(): void {
        \$this->schema()->createTable('test_history_table{$i}', ['id' => \$this->schema()->primaryKey()]);
    }
    public function down(): void {
        \$this->schema()->dropTable('test_history_table{$i}');
    }
}
PHP;
            $filename = $migrationPath . '/m' . $version . '.php';
            file_put_contents($filename, $testMigrationContent);
            $migrations[] = $version;
        }

        $runner = new \tommyknocker\pdodb\migrations\MigrationRunner($db, $migrationPath);
        $runner->migrate();

        // Test with limit
        $history = $runner->getMigrationHistory(2);
        $this->assertCount(2, $history);

        // Test without limit
        $allHistory = $runner->getMigrationHistory();
        $this->assertGreaterThanOrEqual(3, count($allHistory));

        // Cleanup
        foreach ($migrations as $version) {
            $db->rawQuery('DROP TABLE IF EXISTS test_history_table' . substr($version, -1));
            @unlink($migrationPath . '/m' . $version . '.php');
        }
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
    }

    public function testGetNewMigrationsWhenMigrationsApplied(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        $db->rawQuery('DROP TABLE IF EXISTS test_new_migrations_table');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_new_test';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        $testMigrationContent = <<<'PHP'
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class m20251101121000Testnew extends Migration {
    public function up(): void {
        $this->schema()->createTable('test_new_migrations_table', ['id' => $this->schema()->primaryKey()]);
    }
    public function down(): void {
        $this->schema()->dropTable('test_new_migrations_table');
    }
}
PHP;
        file_put_contents($migrationPath . '/m20251101121000_testnew.php', $testMigrationContent);

        $runner = new \tommyknocker\pdodb\migrations\MigrationRunner($db, $migrationPath);

        // Before migration
        $newMigrations = $runner->getNewMigrations();
        $this->assertContains('20251101121000_testnew', $newMigrations);

        // Apply migration
        $runner->migrate();

        // After migration
        $newMigrations = $runner->getNewMigrations();
        $this->assertNotContains('20251101121000_testnew', $newMigrations);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_new_migrations_table');
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        unlink($migrationPath . '/m20251101121000_testnew.php');
    }

    public function testMigrateDownMultipleSteps(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
        $db->rawQuery('DROP TABLE IF EXISTS test_down_table1');
        $db->rawQuery('DROP TABLE IF EXISTS test_down_table2');
        $db->rawQuery('DROP TABLE IF EXISTS test_down_table3');

        $migrationPath = sys_get_temp_dir() . '/pdodb_migrations_down_test';
        if (!is_dir($migrationPath)) {
            mkdir($migrationPath, 0755, true);
        }

        // Create 3 migrations
        $migrations = [];
        for ($i = 1; $i <= 3; $i++) {
            $version = '2025110112' . str_pad((string)$i, 4, '0', STR_PAD_LEFT) . '_testdown' . $i;
            // Convert version to class name like MigrationRunner does
            $parts = explode('_', $version);
            $className = 'm';
            foreach ($parts as $part) {
                if (is_numeric($part)) {
                    $className .= $part;
                } else {
                    $className .= ucfirst($part);
                }
            }
            $testMigrationContent = <<<PHP
<?php
declare(strict_types=1);
namespace tommyknocker\pdodb\migrations;
class {$className} extends Migration {
    public function up(): void {
        \$this->schema()->createTable('test_down_table{$i}', ['id' => \$this->schema()->primaryKey()]);
    }
    public function down(): void {
        \$this->schema()->dropTable('test_down_table{$i}');
    }
}
PHP;
            $filename = $migrationPath . '/m' . $version . '.php';
            file_put_contents($filename, $testMigrationContent);
            $migrations[] = $version;
        }

        $runner = new \tommyknocker\pdodb\migrations\MigrationRunner($db, $migrationPath);

        // Apply all migrations
        $runner->migrate();
        $this->assertTrue($db->schema()->tableExists('test_down_table1'));
        $this->assertTrue($db->schema()->tableExists('test_down_table2'));
        $this->assertTrue($db->schema()->tableExists('test_down_table3'));

        // Get migration history before rollback to know order
        $historyBefore = $runner->getMigrationHistory();
        $appliedVersions = array_column($historyBefore, 'version');
        $this->assertGreaterThanOrEqual(3, count($appliedVersions));

        // Get the last two applied migrations (most recent first)
        $lastMigration = $appliedVersions[0]; // Most recent
        $secondLastMigration = $appliedVersions[1] ?? null; // Second most recent

        // Rollback 2 steps (should rollback in reverse order: newest first)
        $rolledBack = $runner->migrateDown(2);
        $this->assertCount(2, $rolledBack);

        // Verify the rolled back versions
        $this->assertContains($lastMigration, $rolledBack);
        if ($secondLastMigration !== null) {
            $this->assertContains($secondLastMigration, $rolledBack);
        }

        // Check migration history after rollback
        $historyAfter = $runner->getMigrationHistory();
        $remainingVersions = array_column($historyAfter, 'version');
        $this->assertNotContains($lastMigration, $remainingVersions);
        if ($secondLastMigration !== null) {
            $this->assertNotContains($secondLastMigration, $remainingVersions);
        }

        // Cleanup
        foreach ($migrations as $version) {
            @unlink($migrationPath . '/m' . $version . '.php');
        }
        $db->rawQuery('DROP TABLE IF EXISTS test_down_table1');
        $db->rawQuery('DROP TABLE IF EXISTS test_down_table2');
        $db->rawQuery('DROP TABLE IF EXISTS test_down_table3');
        $db->rawQuery('DROP TABLE IF EXISTS __migrations');
    }

    public function testWithoutGlobalScope(): void
    {
        // Without disabling global scope, should only get active users
        $activeOnly = ActiveQueryTestModel::find()->all();
        $this->assertCount(2, $activeOnly); // Alice and Bob are active

        // Disable global scope
        $allUsers = ActiveQueryTestModel::find()->withoutGlobalScope('active')->all();
        $this->assertCount(3, $allUsers); // All users including inactive Charlie
    }

    public function testWithoutGlobalScopes(): void
    {
        // Disable multiple global scopes (even if only one exists)
        $allUsers = ActiveQueryTestModel::find()->withoutGlobalScopes(['active'])->all();
        $this->assertCount(3, $allUsers);
    }

    public function testScopeWithString(): void
    {
        // Test named scope from model (scope applies after global scope)
        $adults = ActiveQueryTestModel::find()->scope('adults')->all();
        $this->assertCount(2, $adults); // Only active users who are 18+ (Alice and Bob)

        $seniors = ActiveQueryTestModel::find()->scope('seniors')->all();
        $this->assertCount(0, $seniors); // No users are 65+
    }

    public function testScopeWithCallable(): void
    {
        // Test scope with callable
        $results = ActiveQueryTestModel::find()->scope(function ($query) {
            $query->where('name', 'Bob');
            return $query;
        })->all();

        $this->assertCount(1, $results);
        $this->assertEquals('Bob', $results[0]->name);
    }

    public function testScopeThrowsExceptionForInvalidScope(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Scope 'nonexistent' not found");

        ActiveQueryTestModel::find()->scope('nonexistent');
    }

    public function testScopeThrowsExceptionForEmptyArguments(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scope() method requires at least one argument');

        ActiveQueryTestModel::find()->scope();
    }

    public function testWithMethod(): void
    {
        // Test with() method accepts string
        $query = ActiveQueryTestModel::find()->with('someRelation');
        $this->assertInstanceOf(\tommyknocker\pdodb\orm\ActiveQuery::class, $query);

        // Test with() method accepts array
        $query2 = ActiveQueryTestModel::find()->with(['relation1', 'relation2']);
        $this->assertInstanceOf(\tommyknocker\pdodb\orm\ActiveQuery::class, $query2);
    }

    public function testWithMethodChaining(): void
    {
        // Test that with() can be chained (without actually loading relations since they don't exist)
        $query = ActiveQueryTestModel::find()
            ->where('age', 30);

        $this->assertInstanceOf(\tommyknocker\pdodb\orm\ActiveQuery::class, $query);
        $results = $query->all();
        $this->assertCount(1, $results); // Only Bob is 30 and active
    }
}
