<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;


use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

/**
 * SchemaIntrospectionTests tests for postgresql.
 */
final class SchemaIntrospectionTests extends BasePostgreSQLTestCase
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
    $plan = $db->explainAnalyze('SELECT * FROM users WHERE status = :status', ['status' => 'active']);
    $this->assertNotEmpty($plan);
    $this->assertArrayHasKey('QUERY PLAN', $plan[0]);
    }

    public function testDescribeUsers(): void
    {
    $db = self::$db;
    $columns = $db->describe('users');
    $this->assertNotEmpty($columns);
    
    $columnNames = array_column($columns, 'column_name');
    $this->assertContains('id', $columnNames);
    $this->assertContains('name', $columnNames);
    $this->assertContains('company', $columnNames);
    $this->assertContains('age', $columnNames);
    $this->assertContains('status', $columnNames);
    $this->assertContains('created_at', $columnNames);
    $this->assertContains('updated_at', $columnNames);
    
    $idColumn = null;
    foreach ($columns as $column) {
    if ($column['column_name'] === 'id') {
    $idColumn = $column;
    break;
    }
    }
    $this->assertNotNull($idColumn);
    $this->assertEquals('integer', $idColumn['data_type']);
    $this->assertEquals('NO', $idColumn['is_nullable']);
    
    $nameColumn = null;
    foreach ($columns as $column) {
    if ($column['column_name'] === 'name') {
    $nameColumn = $column;
    break;
    }
    }
    $this->assertNotNull($nameColumn);
    $this->assertEquals('character varying', $nameColumn['data_type']);
    $this->assertContains($nameColumn['is_nullable'], ['YES', 'NO']);
    
    foreach ($columns as $column) {
    $this->assertArrayHasKey('column_name', $column);
    $this->assertArrayHasKey('data_type', $column);
    $this->assertArrayHasKey('is_nullable', $column);
    $this->assertArrayHasKey('column_default', $column);
    }
    }

    public function testDescribeOrders(): void
    {
    $db = self::$db;
    $columns = $db->describe('orders');
    $this->assertNotEmpty($columns);
    
    $columnNames = array_column($columns, 'column_name');
    $this->assertContains('id', $columnNames);
    $this->assertContains('user_id', $columnNames);
    $this->assertContains('amount', $columnNames);
    
    $userIdColumn = null;
    foreach ($columns as $column) {
    if ($column['column_name'] === 'user_id') {
    $userIdColumn = $column;
    break;
    }
    }
    $this->assertNotNull($userIdColumn);
    $this->assertEquals('integer', $userIdColumn['data_type']);
    $this->assertEquals('NO', $userIdColumn['is_nullable']);
    
    foreach ($columns as $column) {
    if ($column['column_name'] === 'amount') {
    $amountColumn = $column;
    break;
    }
    }
    $this->assertNotNull($amountColumn);
    $this->assertEquals('numeric', $amountColumn['data_type']);
    $this->assertEquals('NO', $amountColumn['is_nullable']);
    }

    public function testPrefixMethod(): void
    {
    $db = self::$db;
    
    $queryBuilder = $db->find()->prefix('test_')->from('users');
    
    $db->rawQuery('CREATE TABLE IF NOT EXISTS test_prefixed_table (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100)
    )');
    
    $id = $queryBuilder->table('prefixed_table')->insert(['name' => 'Test User']);
    $this->assertIsInt($id);
    
    $user = $queryBuilder->table('prefixed_table')->where('id', $id)->getOne();
    $this->assertEquals('Test User', $user['name']);
    
    $this->assertStringContainsString('"test_prefixed_table"', $db->lastQuery);
    
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
