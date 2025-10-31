<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

/**
 * AnalysisTests tests for postgresql.
 */
final class AnalysisTests extends BasePostgreSQLTestCase
{
    public function testQueryAnalysisMethods(): void
    {
        // Clean up first
        self::$db->find()->table('orders')->delete();
        self::$db->find()->table('users')->delete();

        // Insert test data
        self::$db->find()->table('users')->insertMulti([
        ['name' => 'Alice', 'age' => 25],
        ['name' => 'Bob', 'age' => 30],
        ]);

        self::$db->find()->table('orders')->insertMulti([
        ['user_id' => 1, 'amount' => 99.99],
        ['user_id' => 2, 'amount' => 199.99],
        ]);

        // Test explain()
        $explainResult = self::$db->find()
        ->table('users')
        ->where('age', 25, '>')
        ->explain();

        $this->assertIsArray($explainResult);
        $this->assertNotEmpty($explainResult);
        $this->assertArrayHasKey('QUERY PLAN', $explainResult[0]);

        // Test explainAnalyze() - PostgreSQL uses EXPLAIN ANALYZE
        $explainAnalyzeResult = self::$db->find()
        ->table('users')
        ->where('age', 25, '>')
        ->explainAnalyze();

        $this->assertIsArray($explainAnalyzeResult);
        $this->assertNotEmpty($explainAnalyzeResult);
        $this->assertArrayHasKey('QUERY PLAN', $explainAnalyzeResult[0]);

        // Test describe()
        $describeResult = self::$db->find()
        ->table('users')
        ->describe();

        $this->assertIsArray($describeResult);
        $this->assertNotEmpty($describeResult);
        $this->assertArrayHasKey('column_name', $describeResult[0]);
        $this->assertArrayHasKey('data_type', $describeResult[0]);
        $this->assertArrayHasKey('is_nullable', $describeResult[0]);
        $this->assertArrayHasKey('column_default', $describeResult[0]);

        // Verify we have expected columns
        $fieldNames = array_column($describeResult, 'column_name');
        $this->assertContains('id', $fieldNames);
        $this->assertContains('name', $fieldNames);
        $this->assertContains('age', $fieldNames);
    }
}
