<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\migrations\Migration;

/**
 * Test concrete migration class.
 */
class TestMigration extends Migration
{
    public function up(): void
    {
        // Test safeUp call
        $this->safeUp();
    }

    public function down(): void
    {
        // Test safeDown call
        $this->safeDown();
    }

    protected function safeUp(): void
    {
        // Test schema() method
        $this->schema()->createTable('test_migration_base', [
            'id' => $this->schema()->primaryKey(),
        ]);
    }

    protected function safeDown(): void
    {
        $this->schema()->dropTable('test_migration_base');
    }
}

/**
 * Tests for Migration base class methods.
 */
class MigrationClassTests extends BaseSharedTestCase
{
    /**
     * Test Migration constructor and schema() method.
     */
    public function testMigrationConstructorAndSchema(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_migration_base');

        $migration = new TestMigration($db);
        $this->assertInstanceOf(Migration::class, $migration);

        $reflection = new \ReflectionClass($migration);
        $schemaMethod = $reflection->getMethod('schema');
        $schemaMethod->setAccessible(true);

        $schema = $schemaMethod->invoke($migration);
        $this->assertInstanceOf(\tommyknocker\pdodb\query\DdlQueryBuilder::class, $schema);

        // Cleanup
        $db->schema()->dropTableIfExists('test_migration_base');
    }

    /**
     * Test find() method.
     */
    public function testMigrationFind(): void
    {
        $db = self::$db;
        $migration = new TestMigration($db);

        $reflection = new \ReflectionClass($migration);
        $findMethod = $reflection->getMethod('find');
        $findMethod->setAccessible(true);

        $queryBuilder = $findMethod->invoke($migration);
        $this->assertInstanceOf(\tommyknocker\pdodb\query\QueryBuilder::class, $queryBuilder);
    }

    /**
     * Test execute() method.
     */
    public function testMigrationExecute(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_execute_table');

        $migration = new TestMigration($db);
        $reflection = new \ReflectionClass($migration);
        $executeMethod = $reflection->getMethod('execute');
        $executeMethod->setAccessible(true);

        $executeMethod->invoke($migration, 'CREATE TABLE test_execute_table (id INT PRIMARY KEY)');

        $exists = $db->schema()->tableExists('test_execute_table');
        $this->assertTrue($exists);

        // Cleanup
        $db->schema()->dropTable('test_execute_table');
    }

    /**
     * Test insert() method.
     */
    public function testMigrationInsert(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_insert_table');
        $db->schema()->createTable('test_insert_table', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        $migration = new TestMigration($db);
        $reflection = new \ReflectionClass($migration);
        $insertMethod = $reflection->getMethod('insert');
        $insertMethod->setAccessible(true);

        $id = $insertMethod->invoke($migration, 'test_insert_table', ['name' => 'Test User']);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $result = $db->find()
            ->from('test_insert_table')
            ->where('id', $id)
            ->getOne();

        $this->assertNotNull($result);
        $this->assertEquals('Test User', $result['name']);

        // Cleanup
        $db->schema()->dropTable('test_insert_table');
    }

    /**
     * Test batchInsert() method.
     */
    public function testMigrationBatchInsert(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_batch_table');
        $db->schema()->createTable('test_batch_table', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
            'value' => $db->schema()->integer(),
        ]);

        $migration = new TestMigration($db);
        $reflection = new \ReflectionClass($migration);
        $batchInsertMethod = $reflection->getMethod('batchInsert');
        $batchInsertMethod->setAccessible(true);

        $batchInsertMethod->invoke($migration, 'test_batch_table', ['name', 'value'], [
            ['name' => 'Item 1', 'value' => 10],
            ['name' => 'Item 2', 'value' => 20],
            ['name' => 'Item 3', 'value' => 30],
        ]);

        $count = $db->find()
            ->from('test_batch_table')
            ->select(['count' => \tommyknocker\pdodb\helpers\Db::count()])
            ->getValue('count');

        $this->assertEquals(3, (int)$count);

        // Cleanup
        $db->schema()->dropTable('test_batch_table');
    }

    /**
     * Test safeUp() and safeDown() methods.
     */
    public function testMigrationSafeUpDown(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_migration_base');

        $migration = new TestMigration($db);

        // Test safeUp is called in up()
        $migration->up();
        $exists = $db->schema()->tableExists('test_migration_base');
        $this->assertTrue($exists);

        // Test safeDown is called in down()
        $migration->down();
        $exists = $db->schema()->tableExists('test_migration_base');
        $this->assertFalse($exists);
    }
}
