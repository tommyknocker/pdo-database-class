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
 * SchemaIntrospectionTests tests for sqlite.
 */
final class SchemaIntrospectionTests extends BaseSqliteTestCase
{
    public function testDescribeUsers(): void
    {
    $db = self::$db;
    $columns = $db->describe('users');
    $this->assertNotEmpty($columns);
    
    $columnNames = array_column($columns, 'name');
    $this->assertContains('id', $columnNames);
    $this->assertContains('name', $columnNames);
    $this->assertContains('company', $columnNames);
    $this->assertContains('age', $columnNames);
    $this->assertContains('status', $columnNames);
    $this->assertContains('created_at', $columnNames);
    $this->assertContains('updated_at', $columnNames);
    
    $idColumn = $columns[0];
    $this->assertEquals('id', $idColumn['name']);
    $this->assertEquals('INTEGER', $idColumn['type']);
    $this->assertEquals(0, (int)$idColumn['notnull']); // 0 = false (nullable)
    $this->assertEquals(1, (int)$idColumn['pk']); // Primary key
    if (isset($idColumn['auto_increment'])) {
    $this->assertEquals(1, (int)$idColumn['auto_increment']); // Auto increment
    }
    
    $nameColumn = null;
    foreach ($columns as $column) {
    if ($column['name'] === 'name') {
    $nameColumn = $column;
    break;
    }
    }
    $this->assertNotNull($nameColumn);
    $this->assertEquals('TEXT', $nameColumn['type']);
    $this->assertEquals(0, (int)$nameColumn['notnull']);
    $this->assertEquals(0, (int)$nameColumn['pk']);
    
    foreach ($columns as $column) {
    $this->assertArrayHasKey('name', $column);
    $this->assertArrayHasKey('type', $column);
    $this->assertArrayHasKey('notnull', $column);
    $this->assertArrayHasKey('pk', $column);
    }
    }

    public function testDescribeOrders(): void
    {
    $db = self::$db;
    $columns = $db->describe('orders');
    $this->assertNotEmpty($columns);
    
    $columnNames = array_column($columns, 'name');
    $this->assertContains('id', $columnNames);
    $this->assertContains('user_id', $columnNames);
    $this->assertContains('amount', $columnNames);
    
    $userIdColumn = null;
    foreach ($columns as $column) {
    if ($column['name'] === 'user_id') {
    $userIdColumn = $column;
    break;
    }
    }
    $this->assertNotNull($userIdColumn);
    $this->assertEquals('INTEGER', $userIdColumn['type']);
    $this->assertEquals(1, (int)$userIdColumn['notnull']); // NOT NULL
    
    $amountColumn = null;
    foreach ($columns as $column) {
    if ($column['name'] === 'amount') {
    $amountColumn = $column;
    break;
    }
    }
    $this->assertNotNull($amountColumn);
    $this->assertEquals('NUMERIC(10,2)', $amountColumn['type']);
    $this->assertEquals(1, (int)$amountColumn['notnull']); // NOT NULL
    }

    public function testPrefixMethod(): void
    {
    $db = self::$db;
    
    $queryBuilder = $db->find()->prefix('test_')->from('users');
    
    $db->rawQuery('CREATE TABLE IF NOT EXISTS test_prefixed_table (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT
    )');
    
    $id = $queryBuilder->table('prefixed_table')->insert(['name' => 'Test User']);
    $this->assertIsInt($id);
    
    $user = $queryBuilder->table('prefixed_table')->where('id', $id)->getOne();
    $this->assertEquals('Test User', $user['name']);
    
    $this->assertStringContainsString('"test_prefixed_table"', $db->lastQuery);
    
    $db->rawQuery('DROP TABLE IF EXISTS test_prefixed_table');
    }

    public function testExplainSelectUsers(): void
    {
    $db = self::$db;
    $plan = $db->explain("SELECT * FROM users WHERE status = 'active'");
    $this->assertNotEmpty($plan);
    $this->assertIsArray($plan[0]);
    }

    public function testExplainAnalyzeSelectUsers(): void
    {
    $db = self::$db;
    $plan = $db->explainAnalyze('SELECT * FROM users WHERE status = "active"');
    $this->assertNotEmpty($plan);
    $this->assertArrayHasKey('detail', $plan[0]);
    }

    public function testIndexesViaPdoDb(): void
    {
    $indexes = self::$db->indexes('users');
    $this->assertIsArray($indexes);
    }

    public function testKeysViaPdoDb(): void
    {
    $foreignKeys = self::$db->keys('orders');
    $this->assertIsArray($foreignKeys);
    }

    public function testConstraintsViaPdoDb(): void
    {
    $constraints = self::$db->constraints('users');
    $this->assertIsArray($constraints);
    }

    public function testIndexesViaQueryBuilder(): void
    {
    $indexes = self::$db->find()->from('users')->indexes();
    $this->assertIsArray($indexes);
    }

    public function testKeysViaQueryBuilder(): void
    {
    $foreignKeys = self::$db->find()->from('orders')->keys();
    $this->assertIsArray($foreignKeys);
    }

    public function testConstraintsViaQueryBuilder(): void
    {
    $constraints = self::$db->find()->from('users')->constraints();
    $this->assertIsArray($constraints);
    }
}
