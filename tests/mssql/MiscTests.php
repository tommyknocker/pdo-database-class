<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use RuntimeException;
use tommyknocker\pdodb\helpers\Db;

/**
 * MiscTests for MSSQL.
 */
final class MiscTests extends BaseMSSQLTestCase
{
    public function testEscape(): void
    {
        // Test that Db::escape() works correctly for MSSQL
        // Note: In MSSQL, prepared statements handle escaping automatically,
        // but Db::escape() should still work for raw SQL contexts
        $escapeValue = Db::escape("O'Reilly");
        $this->assertInstanceOf(\tommyknocker\pdodb\helpers\values\EscapeValue::class, $escapeValue);

        // Test insertion with regular string containing single quote (PDO handles escaping automatically)
        // Note: This test verifies that strings with single quotes are handled correctly
        // The actual escaping is handled by PDO prepared statements, so we test the end result
        $connection = self::$db->connection;
        assert($connection !== null);

        // Ensure we're not in a transaction before insert
        $pdo = $connection->getPdo();
        $wasInTransaction = $connection->inTransaction();
        if ($wasInTransaction) {
            $connection->commit();
        }

        // Insert using query builder
        $insertedId = self::$db->find()
            ->table('users')
            ->insert([
                'name' => "O'Reilly",
                'age' => 30,
            ]);

        // Note: MSSQL getLastInsertId() may return incorrect value in test environment
        // Always get the actual ID from database to ensure we use the correct one
        $findStmt = $pdo->prepare('SELECT id, name FROM users WHERE name = ? ORDER BY id');
        $findStmt->execute(["O'Reilly"]);
        $insertedRow = $findStmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($insertedRow, 'Row should be inserted');
        $id = (int)$insertedRow['id'];

        // Verify row exists and has correct data
        $this->assertEquals("O'Reilly", $insertedRow['name'], 'Inserted row should have correct name');

        // Now use query builder - should work the same way
        $rows = self::$db->find()
            ->from('users')
            ->where('id', $id)
            ->get();

        $this->assertCount(1, $rows, 'Should find exactly one row. ID: ' . $id);
        $row = $rows[0];
        $this->assertEquals("O'Reilly", $row['name']);
        $this->assertEquals(30, (int)$row['age']);

        // Also test getOne() - it should work now
        $rowOne = self::$db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();
        $this->assertNotFalse($rowOne, 'getOne() should return row');
        $this->assertEquals("O'Reilly", $rowOne['name']);
    }

    public function testExistsAndNotExists(): void
    {
        $db = self::$db;

        // Insert one record
        $db->find()->table('users')->insert([
            'name' => 'Existy',
            'company' => 'CheckCorp',
            'age' => 42,
            'status' => 'active',
        ]);

        // Check exists() - should return true
        $exists = $db->find()
            ->from('users')
            ->where(['name' => 'Existy'])
            ->exists();

        $this->assertTrue($exists, 'Expected exists() to return true for matching row');

        // Check notExists() - should return false
        $notExists = $db->find()
            ->from('users')
            ->where(['name' => 'Existy'])
            ->notExists();

        $this->assertFalse($notExists, 'Expected notExists() to return false for matching row');

        // Check exists() - for non-existent value
        $noMatchExists = $db->find()
            ->from('users')
            ->where(['name' => 'Ghosty'])
            ->exists();

        $this->assertFalse($noMatchExists, 'Expected exists() to return false for non-matching row');

        // Check notExists() - for non-existent value
        $noMatchNotExists = $db->find()
            ->from('users')
            ->where(['name' => 'Ghosty'])
            ->notExists();

        $this->assertTrue($noMatchNotExists, 'Expected notExists() to return true for non-matching row');
    }

    public function testFulltextMatchHelper(): void
    {
        $fulltext = Db::match('title, content', 'search term', 'natural');
        $this->assertInstanceOf(\tommyknocker\pdodb\helpers\values\FulltextMatchValue::class, $fulltext);
    }

    // Edge case: DISTINCT ON not supported on MSSQL
    public function testDistinctOnThrowsExceptionOnMSSQL(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DISTINCT ON is not supported');

        self::$db->find()
            ->from('users')
            ->distinctOn('name')
            ->get();
    }

    // Edge case: MATERIALIZED CTE not supported on MSSQL
    public function testMaterializedCteThrowsExceptionOnMSSQL(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Materialized CTE is not supported');

        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        $connection->query('IF OBJECT_ID(\'test_materialized_cte\', \'U\') IS NOT NULL DROP TABLE test_materialized_cte');
        $connection->query('CREATE TABLE test_materialized_cte (id INT PRIMARY KEY, value INT)');

        $db->find()
            ->withMaterialized('high_values', function ($q) {
                $q->from('test_materialized_cte')->where('value', 150, '>');
            })
            ->from('high_values')
            ->get();
    }
}
