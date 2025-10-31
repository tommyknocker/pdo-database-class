<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDOException;
use PHPUnit\Framework\TestCase;
use StdClass;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * SchemaIntrospectionTests tests for mysql.
 */
final class SchemaIntrospectionTests extends BaseMySQLTestCase
{
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
    
    // MySQL EXPLAIN FORMAT=JSON returns array with 'EXPLAIN' key containing JSON string
    $this->assertArrayHasKey('EXPLAIN', $plan[0]);
    $this->assertIsString($plan[0]['EXPLAIN']);
    $jsonData = json_decode($plan[0]['EXPLAIN'], true);
    $this->assertIsArray($jsonData);
    $this->assertArrayHasKey('query_block', $jsonData);
    }

    public function testDescribeUsers(): void
    {
    $db = self::$db;
    $columns = $db->describe('users');
    $this->assertNotEmpty($columns);
    
    $columnNames = array_column($columns, 'Field');
    $this->assertContains('id', $columnNames);
    $this->assertContains('name', $columnNames);
    $this->assertContains('company', $columnNames);
    $this->assertContains('age', $columnNames);
    $this->assertContains('status', $columnNames);
    $this->assertContains('created_at', $columnNames);
    $this->assertContains('updated_at', $columnNames);
    
    $idColumn = $columns[0];
    $this->assertEquals('id', $idColumn['Field']);
    $this->assertEquals('int', strtolower($idColumn['Type']));
    $this->assertEquals('NO', $idColumn['Null']);
    $this->assertEquals('PRI', $idColumn['Key']);
    $this->assertEquals('auto_increment', $idColumn['Extra']);
    
    foreach ($columns as $column) {
    $this->assertArrayHasKey('Field', $column);
    $this->assertArrayHasKey('Type', $column);
    $this->assertArrayHasKey('Null', $column);
    $this->assertArrayHasKey('Key', $column);
    $this->assertArrayHasKey('Default', $column);
    $this->assertArrayHasKey('Extra', $column);
    }
    }

    public function testDescribeOrders(): void
    {
    $db = self::$db;
    $columns = $db->describe('orders');
    $this->assertNotEmpty($columns);
    
    $columnNames = array_column($columns, 'Field');
    $this->assertContains('id', $columnNames);
    $this->assertContains('user_id', $columnNames);
    $this->assertContains('amount', $columnNames);
    
    $userIdColumn = null;
    foreach ($columns as $column) {
    if ($column['Field'] === 'user_id') {
    $userIdColumn = $column;
    break;
    }
    }
    $this->assertNotNull($userIdColumn);
    $this->assertEquals('int', strtolower($userIdColumn['Type']));
    $this->assertEquals('NO', $userIdColumn['Null']);
    $this->assertEquals('MUL', $userIdColumn['Key']); // Foreign key
    }

    public function testPrefixMethod(): void
    {
    $db = self::$db;
    
    $queryBuilder = $db->find()->prefix('test_')->from('users');
    
    $db->rawQuery('CREATE TABLE IF NOT EXISTS test_prefixed_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100)
    )');
    
    $id = $queryBuilder->table('prefixed_table')->insert(['name' => 'Test User']);
    $this->assertIsInt($id);
    
    $user = $queryBuilder->table('prefixed_table')->where('id', $id)->getOne();
    $this->assertEquals('Test User', $user['name']);
    
    $lastQuery = $db->lastQuery ?? '';
    $this->assertStringContainsString('`test_prefixed_table`', $lastQuery);
    
    $db->rawQuery('DROP TABLE IF EXISTS test_prefixed_table');
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
