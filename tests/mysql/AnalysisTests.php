<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

/**
 * AnalysisTests tests for mysql.
 */
final class AnalysisTests extends BaseMySQLTestCase
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
    $this->assertArrayHasKey('id', $explainResult[0]);
    $this->assertArrayHasKey('select_type', $explainResult[0]);
    $this->assertArrayHasKey('table', $explainResult[0]);
    
    // Test explainAnalyze() - MySQL uses FORMAT=JSON
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
    $this->assertArrayHasKey('Field', $describeResult[0]);
    $this->assertArrayHasKey('Type', $describeResult[0]);
    $this->assertArrayHasKey('Null', $describeResult[0]);
    $this->assertArrayHasKey('Key', $describeResult[0]);
    $this->assertArrayHasKey('Default', $describeResult[0]);
    $this->assertArrayHasKey('Extra', $describeResult[0]);
    
    // Verify we have expected columns
    $fieldNames = array_column($describeResult, 'Field');
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
    $explainResult = $db->explain('SELECT * FROM users WHERE age > ?', [20]);
    
    $this->assertIsArray($explainResult);
    $this->assertNotEmpty($explainResult);
    $this->assertArrayHasKey('id', $explainResult[0]);
    $this->assertArrayHasKey('select_type', $explainResult[0]);
    $this->assertArrayHasKey('table', $explainResult[0]);
    $this->assertArrayHasKey('type', $explainResult[0]);
    $this->assertArrayHasKey('possible_keys', $explainResult[0]);
    $this->assertArrayHasKey('key', $explainResult[0]);
    $this->assertArrayHasKey('key_len', $explainResult[0]);
    $this->assertArrayHasKey('ref', $explainResult[0]);
    $this->assertArrayHasKey('rows', $explainResult[0]);
    $this->assertArrayHasKey('Extra', $explainResult[0]);
    }

    public function testExplainAnalyzeQuery(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25]);
    $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30]);
    
    // Test EXPLAIN ANALYZE (MySQL 8.0+)
    $explainAnalyzeResult = $db->explainAnalyze('SELECT * FROM users WHERE age > ?', [20]);
    
    $this->assertIsArray($explainAnalyzeResult);
    $this->assertNotEmpty($explainAnalyzeResult);
    
    // MySQL EXPLAIN FORMAT=JSON returns array with 'EXPLAIN' key containing JSON string
    $this->assertArrayHasKey('EXPLAIN', $explainAnalyzeResult[0]);
    $this->assertIsString($explainAnalyzeResult[0]['EXPLAIN']);
    $jsonData = json_decode($explainAnalyzeResult[0]['EXPLAIN'], true);
    $this->assertIsArray($jsonData);
    $this->assertArrayHasKey('query_block', $jsonData);
    }
}
