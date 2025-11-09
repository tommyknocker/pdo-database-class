<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

/**
 * AnalysisTests tests for MSSQL.
 */
final class AnalysisTests extends BaseMSSQLTestCase
{
    public function testQueryAnalysisMethods(): void
    {
        $connection = self::$db->connection;
        assert($connection !== null);

        // Clean up first
        $connection->query('DELETE FROM orders');
        $connection->query('DELETE FROM users');

        // Insert test data and get actual IDs
        $userId1 = self::$db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        $userId2 = self::$db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30]);

        self::$db->find()->table('orders')->insertMulti([
        ['user_id' => $userId1, 'amount' => 99.99],
        ['user_id' => $userId2, 'amount' => 199.99],
        ]);

        // Test explain()
        $explainResult = self::$db->find()
        ->table('users')
        ->where('age', 25, '>')
        ->explain();

        $this->assertIsArray($explainResult);
        $this->assertNotEmpty($explainResult);

        // Test explainAnalyze() - MSSQL uses SET STATISTICS XML ON
        $explainAnalyzeResult = self::$db->find()
        ->table('users')
        ->where('age', 25, '>')
        ->explainAnalyze();

        $this->assertIsArray($explainAnalyzeResult);
        $this->assertNotEmpty($explainAnalyzeResult);

        // Test describe()
        $describeResult = self::$db->find()
        ->table('users')
        ->describe();

        $this->assertIsArray($describeResult);
        $this->assertNotEmpty($describeResult);
        // MSSQL returns different column names
        $this->assertArrayHasKey('COLUMN_NAME', $describeResult[0]);
        $this->assertArrayHasKey('DATA_TYPE', $describeResult[0]);

        // Verify we have expected columns
        $fieldNames = array_column($describeResult, 'COLUMN_NAME');
        $this->assertContains('id', $fieldNames);
        $this->assertContains('name', $fieldNames);
        $this->assertContains('age', $fieldNames);
    }

    public function testExplainQuery(): void
    {
        $db = self::$db;

        // Insert test data
        $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
        $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30]);

        // Test EXPLAIN
        $explainResult = $db->explain('SELECT * FROM [users] WHERE [age] > ?', [20]);
        $this->assertIsArray($explainResult);
        $this->assertNotEmpty($explainResult);
    }
}
