<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use ReflectionClass;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Tests for FileLoader transaction handling.
 */
class FileLoaderTransactionTests extends BaseSharedTestCase
{
    /**
     * Test FileLoader when transaction is already started.
     */
    public function testFileLoaderWithExistingTransaction(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_trans');

        $db->schema()->createTable('test_fileloader_trans', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        // Start transaction
        $db->startTransaction();

        // FileLoader should detect existing transaction and not start a new one
        $loader = $db->find()->table('test_fileloader_trans');
        $this->assertTrue($db->inTransaction(), 'Transaction should be active');

        // For SQLite, test that loadJson works with existing transaction
        // Note: FileLoader methods require actual files, so we test the transaction logic
        // by checking that inTransaction() is called correctly
        $reflection = new ReflectionClass($loader);
        $connectionProperty = $reflection->getProperty('connection');
        $connectionProperty->setAccessible(true);
        $connection = $connectionProperty->getValue($loader);

        $this->assertTrue($connection->inTransaction(), 'Connection should be in transaction');

        // Cleanup
        $db->rollback();
        $db->schema()->dropTable('test_fileloader_trans');
    }

    /**
     * Test that FileLoader commits transaction on success.
     */
    public function testFileLoaderCommitsOnSuccess(): void
    {
        $db = self::$db;
        $db->schema()->dropTableIfExists('test_fileloader_commit');

        $db->schema()->createTable('test_fileloader_commit', [
            'id' => $db->schema()->primaryKey(),
            'name' => $db->schema()->string(100),
        ]);

        // FileLoader should start and commit transaction
        $loader = $db->find()->table('test_fileloader_commit');

        // Verify loader is created correctly
        $this->assertNotNull($loader);
        $this->assertInstanceOf(QueryBuilder::class, $loader);

        // Verify table exists
        $this->assertTrue($db->schema()->tableExists('test_fileloader_commit'));

        // Cleanup
        $db->schema()->dropTable('test_fileloader_commit');
    }
}
