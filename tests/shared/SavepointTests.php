<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\TransactionException;

/**
 * SavepointTests tests for all dialects.
 */
final class SavepointTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create users table for savepoint tests
        self::$db->rawQuery('DROP TABLE IF EXISTS users');
        self::$db->rawQuery('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100),
                age INTEGER
            )
        ');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Clear users table before each test
        self::$db->rawQuery('DELETE FROM users');
        // Reset auto-increment counter (SQLite specific)
        self::$db->rawQuery("DELETE FROM sqlite_sequence WHERE name='users'");
    }

    public function testSavepointBasic(): void
    {
        $db = self::$db;

        $db->startTransaction();

        // Insert first record
        $id1 = $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        $this->assertGreaterThan(0, $id1);

        // Create savepoint
        $db->savepoint('sp1');

        // Insert second record
        $id2 = $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30]);
        $this->assertGreaterThan(0, $id2);

        // Verify both records exist
        $records = $db->find()->from('users')->whereIn('id', [$id1, $id2])->get();
        $this->assertCount(2, $records);

        // Rollback to savepoint
        $db->rollbackToSavepoint('sp1');

        // First record should still exist, second should not
        $exists1 = $db->find()->from('users')->where('id', $id1)->exists();
        $exists2 = $db->find()->from('users')->where('id', $id2)->exists();
        $this->assertTrue($exists1, 'First record should exist after rollback to savepoint');
        $this->assertFalse($exists2, 'Second record should not exist after rollback to savepoint');

        $db->rollback();
    }

    public function testSavepointRelease(): void
    {
        $db = self::$db;

        $db->startTransaction();

        // Insert first record
        $id1 = $db->find()->table('users')->insert(['name' => 'Charlie', 'age' => 35]);
        $this->assertGreaterThan(0, $id1);

        // Create savepoint
        $db->savepoint('sp1');

        // Insert second record
        $id2 = $db->find()->table('users')->insert(['name' => 'David', 'age' => 40]);
        $this->assertGreaterThan(0, $id2);

        // Release savepoint (without rolling back)
        $db->releaseSavepoint('sp1');

        // Both records should still exist
        $exists1 = $db->find()->from('users')->where('id', $id1)->exists();
        $exists2 = $db->find()->from('users')->where('id', $id2)->exists();
        $this->assertTrue($exists1, 'First record should exist after releasing savepoint');
        $this->assertTrue($exists2, 'Second record should exist after releasing savepoint');

        // Now rollback should rollback everything
        $db->rollback();

        // Both records should be gone
        $exists1 = $db->find()->from('users')->where('id', $id1)->exists();
        $exists2 = $db->find()->from('users')->where('id', $id2)->exists();
        $this->assertFalse($exists1, 'First record should not exist after rollback');
        $this->assertFalse($exists2, 'Second record should not exist after rollback');
    }

    public function testSavepointNested(): void
    {
        $db = self::$db;

        $db->startTransaction();

        // Insert first record
        $id1 = $db->find()->table('users')->insert(['name' => 'Eve', 'age' => 28]);
        $this->assertGreaterThan(0, $id1);

        // Create first savepoint
        $db->savepoint('sp1');

        // Insert second record
        $id2 = $db->find()->table('users')->insert(['name' => 'Frank', 'age' => 32]);
        $this->assertGreaterThan(0, $id2);

        // Create second savepoint
        $db->savepoint('sp2');

        // Insert third record
        $id3 = $db->find()->table('users')->insert(['name' => 'Grace', 'age' => 36]);
        $this->assertGreaterThan(0, $id3);

        // Rollback to first savepoint (should remove records 2 and 3)
        $db->rollbackToSavepoint('sp1');

        // Only first record should exist
        $exists1 = $db->find()->from('users')->where('id', $id1)->exists();
        $exists2 = $db->find()->from('users')->where('id', $id2)->exists();
        $exists3 = $db->find()->from('users')->where('id', $id3)->exists();
        $this->assertTrue($exists1, 'First record should exist');
        $this->assertFalse($exists2, 'Second record should not exist');
        $this->assertFalse($exists3, 'Third record should not exist');

        $db->rollback();
    }

    public function testSavepointWithoutTransaction(): void
    {
        $db = self::$db;

        // Ensure we're not in a transaction
        if ($db->inTransaction()) {
            $db->rollback();
        }

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Cannot create savepoint: not in a transaction');

        $db->savepoint('sp1');
    }

    public function testRollbackToSavepointWithoutTransaction(): void
    {
        $db = self::$db;

        // Ensure we're not in a transaction
        if ($db->inTransaction()) {
            $db->rollback();
        }

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Cannot rollback to savepoint: not in a transaction');

        $db->rollbackToSavepoint('sp1');
    }

    public function testReleaseSavepointWithoutTransaction(): void
    {
        $db = self::$db;

        // Ensure we're not in a transaction
        if ($db->inTransaction()) {
            $db->rollback();
        }

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Cannot release savepoint: not in a transaction');

        $db->releaseSavepoint('sp1');
    }

    public function testRollbackToNonExistentSavepoint(): void
    {
        $db = self::$db;

        $db->startTransaction();

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage("Savepoint 'nonexistent' not found");

        $db->rollbackToSavepoint('nonexistent');
    }

    public function testReleaseNonExistentSavepoint(): void
    {
        $db = self::$db;

        $db->startTransaction();

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage("Savepoint 'nonexistent' not found");

        $db->releaseSavepoint('nonexistent');
    }

    public function testInvalidSavepointName(): void
    {
        $db = self::$db;

        $db->startTransaction();

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Invalid savepoint name');

        $db->savepoint('invalid-name');
    }

    public function testGetSavepoints(): void
    {
        $db = self::$db;

        $db->startTransaction();

        $this->assertEquals([], $db->getSavepoints());

        $db->savepoint('sp1');
        $this->assertEquals(['sp1'], $db->getSavepoints());

        $db->savepoint('sp2');
        $this->assertEquals(['sp1', 'sp2'], $db->getSavepoints());

        $db->savepoint('sp3');
        $this->assertEquals(['sp1', 'sp2', 'sp3'], $db->getSavepoints());

        // Rollback to sp1 should remove sp2 and sp3 from stack
        $db->rollbackToSavepoint('sp1');
        $this->assertEquals(['sp1'], $db->getSavepoints());

        // After rollback to savepoint, we can still release it
        // (savepoint still exists in database, just rolled back to it)
        $db->releaseSavepoint('sp1');
        $this->assertEquals([], $db->getSavepoints());

        $db->rollback();
    }

    public function testHasSavepoint(): void
    {
        $db = self::$db;

        $db->startTransaction();

        $this->assertFalse($db->hasSavepoint('sp1'));

        $db->savepoint('sp1');
        $this->assertTrue($db->hasSavepoint('sp1'));
        $this->assertFalse($db->hasSavepoint('sp2'));

        $db->savepoint('sp2');
        $this->assertTrue($db->hasSavepoint('sp1'));
        $this->assertTrue($db->hasSavepoint('sp2'));

        $db->rollbackToSavepoint('sp1');
        $this->assertTrue($db->hasSavepoint('sp1'));
        $this->assertFalse($db->hasSavepoint('sp2'));

        $db->rollback();
    }

    public function testSavepointStackClearedOnCommit(): void
    {
        $db = self::$db;

        $db->startTransaction();

        $db->savepoint('sp1');
        $db->savepoint('sp2');
        $this->assertEquals(['sp1', 'sp2'], $db->getSavepoints());

        $db->commit();

        // Stack should be cleared after commit
        $this->assertEquals([], $db->getSavepoints());
    }

    public function testSavepointStackClearedOnRollback(): void
    {
        $db = self::$db;

        $db->startTransaction();

        $db->savepoint('sp1');
        $db->savepoint('sp2');
        $this->assertEquals(['sp1', 'sp2'], $db->getSavepoints());

        $db->rollback();

        // Stack should be cleared after rollback
        $this->assertEquals([], $db->getSavepoints());
    }

    public function testSavepointStackClearedOnNewTransaction(): void
    {
        $db = self::$db;

        $db->startTransaction();
        $db->savepoint('sp1');
        $db->commit();

        // Start new transaction
        $db->startTransaction();

        // Stack should be empty
        $this->assertEquals([], $db->getSavepoints());

        $db->rollback();
    }

    public function testSavepointWithCommit(): void
    {
        $db = self::$db;

        $db->startTransaction();

        // Insert first record
        $id1 = $db->find()->table('users')->insert(['name' => 'Henry', 'age' => 45]);
        $this->assertGreaterThan(0, $id1);

        // Create savepoint
        $db->savepoint('sp1');

        // Insert second record
        $id2 = $db->find()->table('users')->insert(['name' => 'Iris', 'age' => 50]);
        $this->assertGreaterThan(0, $id2);

        // Commit transaction (should commit everything)
        $db->commit();

        // Both records should exist
        $exists1 = $db->find()->from('users')->where('id', $id1)->exists();
        $exists2 = $db->find()->from('users')->where('id', $id2)->exists();
        $this->assertTrue($exists1, 'First record should exist after commit');
        $this->assertTrue($exists2, 'Second record should exist after commit');

        // Cleanup
        $db->find()->table('users')->whereIn('id', [$id1, $id2])->delete();
    }
}
