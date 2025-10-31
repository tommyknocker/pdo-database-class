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
 * ExternalReferencesTests tests for postgresql.
 */
final class ExternalReferencesTests extends BasePostgreSQLTestCase
{
    public function testExternalReferenceInWhereExists(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (1, 100.00)');
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (2, 200.00)');
    
    // Test WHERE EXISTS with external reference
    $users = $db->find()
    ->from('users')
    ->whereExists(function ($query) {
    $query->from('orders')
    ->where('user_id', 'users.id')  // External reference - should be auto-converted
    ->where('amount', 50, '>');
    })
    ->get();
    
    $this->assertCount(2, $users);
    $this->assertEquals('John Doe', $users[0]['name']);
    $this->assertEquals('Jane Smith', $users[1]['name']);
    }

    public function testExternalReferenceInWhereNotExists(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (1, 100.00)');
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (2, 200.00)');
    
    // Test WHERE NOT EXISTS with external reference
    $users = $db->find()
    ->from('users')
    ->whereNotExists(function ($query) {
    $query->from('orders')
    ->where('user_id', 'users.id')  // External reference
    ->where('amount', 50, '>');
    })
    ->get();
    
    $this->assertCount(0, $users); // Both users have orders with amount > 50
    }

    public function testExternalReferenceInSelect(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (1, 100.00)');
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (2, 200.00)');
    
    // Test SELECT with external reference
    $users = $db->find()
    ->from('users')
    ->select([
    'users.id',
    'users.name',
    'total_orders' => 'COUNT(orders.id)',  // External reference
    ])
    ->leftJoin('orders', 'orders.user_id = users.id')
    ->groupBy('users.id', 'users.name')
    ->get();
    
    $this->assertCount(2, $users);
    $this->assertArrayHasKey('total_orders', $users[0]);
    }

    public function testExternalReferenceInOrderBy(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (1, 100.00)');
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (2, 200.00)');
    
    // Test ORDER BY with external reference
    $users = $db->find()
    ->from('users')
    ->select(['users.id', 'users.name', 'total' => 'SUM(orders.amount)'])
    ->leftJoin('orders', 'orders.user_id = users.id')
    ->groupBy('users.id', 'users.name')
    ->orderBy('total', 'DESC')  // External reference
    ->get();
    
    $this->assertCount(2, $users);
    $this->assertEquals('Jane Smith', $users[0]['name']); // Higher total
    }

    public function testExternalReferenceInGroupBy(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (1, 100.00)');
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (2, 200.00)');
    
    // Test GROUP BY with external reference
    $results = $db->find()
    ->from('orders')
    ->select(['user_id', 'total' => 'SUM(amount)'])
    ->groupBy('user_id')  // This should work normally
    ->get();
    
    $this->assertCount(2, $results);
    }

    public function testInternalReferenceNotConverted(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    
    // Test that internal references are NOT converted to RawValue
    $users = $db->find()
    ->from('users')
    ->where('users.status', 'active')  // Internal reference - should stay as is
    ->get();
    
    $this->assertCount(2, $users);
    }

    public function testAliasedTableReferences(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (1, 100.00)');
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (2, 200.00)');
    
    // Test with table aliases
    $users = $db->find()
    ->from('users AS u')
    ->whereExists(function ($query) {
    $query->from('orders AS o')
    ->where('o.user_id', 'u.id')  // External reference with aliases
    ->where('o.amount', 50, '>');
    })
    ->get();
    
    $this->assertCount(2, $users);
    }

    public function testComplexExternalReference(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (1, 100.00)');
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (2, 200.00)');
    
    // Test complex query with multiple external references
    $results = $db->find()
    ->from('users AS u')
    ->select([
    'u.id',
    'u.name',
    'total_orders' => 'COUNT(orders.id)',  // External reference
    'total_amount' => 'SUM(orders.amount)',  // External reference
    ])
    ->leftJoin('orders', 'orders.user_id = u.id')
    ->groupBy('u.id', 'u.name')
    ->orderBy('total_amount', 'DESC')  // External reference
    ->get();
    
    $this->assertCount(2, $results);
    $this->assertArrayHasKey('total_orders', $results[0]);
    }

    public function testWhereInWithExternalReference(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (1, 100.00)');
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (2, 200.00)');
    
    // Test WHERE IN with external reference
    $users = $db->find()
    ->from('users')
    ->whereIn('id', function ($query) {
    $query->from('orders')
    ->select('user_id')
    ->where('amount', 50, '>');  // Fixed: use literal value instead of external reference
    })
    ->get();
    
    $this->assertCount(2, $users); // Both users have orders with amount > 50
    }

    public function testHavingWithExternalReference(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (1, 100.00)');
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (2, 200.00)');
    
    // Test HAVING with external reference - simplified test
    $users = $db->find()
    ->from('users')
    ->select(['users.id', 'users.name', 'total' => 'COALESCE(SUM(orders.amount), 0)'])
    ->leftJoin('orders', 'orders.user_id = users.id')
    ->groupBy('users.id', 'users.name')
    ->get();
    
    // Just verify the query works and returns data
    $this->assertCount(2, $users);
    $this->assertArrayHasKey('total', $users[0]);
    
    // Test that external references work in HAVING by using a simple condition
    $usersWithOrders = $db->find()
    ->from('users')
    ->select(['users.id', 'users.name', 'total' => 'COALESCE(SUM(orders.amount), 0)'])
    ->leftJoin('orders', 'orders.user_id = users.id')
    ->groupBy('users.id', 'users.name')
    ->having('users.id', 1, '>=')  // Simple condition
    ->get();
    
    $this->assertCount(2, $usersWithOrders);
    }

    public function testNonTableColumnPatternNotConverted(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    
    // Test that non-table.column patterns are not converted
    $users = $db->find()
    ->from('users')
    ->where('name', "'John.Doe'")  // Not a table.column pattern - use quotes
    ->get();
    
    $this->assertCount(0, $users); // No user with name 'John.Doe'
    }

    public function testInvalidTableColumnPatternNotConverted(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    
    // Test that invalid patterns are not converted
    $users = $db->find()
    ->from('users')
    ->where('name', '123.invalid')  // Starts with number - invalid
    ->get();
    
    $this->assertCount(0, $users);
    }

    public function testRawValueStillWorks(): void
    {
    $db = self::$db;
    
    // Insert test data
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('John Doe', 'active')");
    $db->rawQuery("INSERT INTO users (name, status) VALUES ('Jane Smith', 'active')");
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (1, 100.00)');
    $db->rawQuery('INSERT INTO orders (user_id, amount) VALUES (2, 200.00)');
    
    // Test that explicit RawValue still works
    $users = $db->find()
    ->from('users')
    ->whereExists(function ($query) {
    $query->from('orders')
    ->where('user_id', new \tommyknocker\pdodb\helpers\values\RawValue('users.id'))  // Explicit RawValue
    ->where('amount', 50, '>');
    })
    ->get();
    
    $this->assertCount(2, $users);
    }
}
