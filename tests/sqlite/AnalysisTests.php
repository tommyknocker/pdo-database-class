<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * AnalysisTests tests for sqlite.
 */
final class AnalysisTests extends BaseSqliteTestCase
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
    $this->assertArrayHasKey('addr', $explainResult[0]);
    $this->assertArrayHasKey('opcode', $explainResult[0]);
    $this->assertArrayHasKey('p1', $explainResult[0]);
    $this->assertArrayHasKey('p2', $explainResult[0]);
    $this->assertArrayHasKey('p3', $explainResult[0]);
    $this->assertArrayHasKey('p4', $explainResult[0]);
    $this->assertArrayHasKey('p5', $explainResult[0]);
    $this->assertArrayHasKey('comment', $explainResult[0]);
    
    // Test explainAnalyze() - SQLite uses EXPLAIN QUERY PLAN
    $explainAnalyzeResult = self::$db->find()
    ->table('users')
    ->where('age', 25, '>')
    ->explainAnalyze();
    
    $this->assertIsArray($explainAnalyzeResult);
    $this->assertNotEmpty($explainAnalyzeResult);
    // SQLite EXPLAIN QUERY PLAN structure may vary, just check it's not empty
    $this->assertGreaterThan(0, count($explainAnalyzeResult));
    
    // Test describe()
    $describeResult = self::$db->find()
    ->table('users')
    ->describe();
    
    $this->assertIsArray($describeResult);
    $this->assertNotEmpty($describeResult);
    $this->assertArrayHasKey('cid', $describeResult[0]);
    $this->assertArrayHasKey('name', $describeResult[0]);
    $this->assertArrayHasKey('type', $describeResult[0]);
    $this->assertArrayHasKey('notnull', $describeResult[0]);
    $this->assertArrayHasKey('dflt_value', $describeResult[0]);
    $this->assertArrayHasKey('pk', $describeResult[0]);
    
    // Verify we have expected columns
    $fieldNames = array_column($describeResult, 'name');
    $this->assertContains('id', $fieldNames);
    $this->assertContains('name', $fieldNames);
    $this->assertContains('age', $fieldNames);
    }
}
