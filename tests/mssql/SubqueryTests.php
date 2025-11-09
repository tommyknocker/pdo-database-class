<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * SubqueryTests tests for MSSQL.
 */
final class SubqueryTests extends BaseMSSQLTestCase
{
    public function testSubQueryInWhere(): void
    {
        $db = self::$db;

        $rowCount = self::$db->find()
        ->table('users')
        ->insertMulti([
        ['name' => 'UserA', 'age' => 20],
        ['name' => 'UserB', 'age' => 20],
        ['name' => 'UserC', 'age' => 30],
        ['name' => 'UserD', 'age' => 40],
        ['name' => 'UserE', 'age' => 50],
        ]);
        $this->assertEquals(5, $rowCount);

        // prepare subquery that selects ids of users older than 30 and aliases it as u
        $sub = $db->find()->from('users u')
        ->select(['u.id'])
        ->where('age', 30, '>');

        // main query joins the subquery alias u to the main users table and returns up to 5 rows
        $rows = $db->find()
        ->from('users main')
        ->join('users u', 'u.[id] = main.[id]')
        ->select(['main.name', 'u.age'])
        ->where('u.id', $sub, 'IN')
        ->get();

        $lastQuery = $db->lastQuery ?? '';
        $this->assertStringContainsString('IN (SELECT', $lastQuery);
        $this->assertStringContainsString(')', $lastQuery);

        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
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

    public function testTableExists(): void
    {
        $this->assertTrue(self::$db->find()->table('users')->tableExists());
        $this->assertFalse(self::$db->find()->table('nonexistent')->tableExists());
    }

    public function testCallbackInWhere(): void
    {
        $db = self::$db;

        // Insert test data
        $db->find()->table('users')->insertMulti([
        ['name' => 'Alice', 'age' => 25, 'status' => 'active'],
        ['name' => 'Bob', 'age' => 30, 'status' => 'inactive'],
        ['name' => 'Charlie', 'age' => 35, 'status' => 'active'],
        ]);

        $db->find()->table('orders')->insertMulti([
        ['user_id' => 1, 'amount' => 999.99],
        ['user_id' => 2, 'amount' => 29.99],
        ['user_id' => 3, 'amount' => 79.99],
        ]);

        // Test callback in where clause
        $results = $db->find()
        ->from('users')
        ->where('id', function ($q) {
            $q->from('orders')
            ->select('user_id')
            ->where('amount', 50, '>');
        }, 'IN')
        ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('Alice', $results[0]['name']);
        $this->assertEquals('Charlie', $results[1]['name']);
    }

    public function testCallbackInOrWhere(): void
    {
        $db = self::$db;

        // Insert test data and get IDs
        $aliceId = $db->find()->table('users')->insert(['name' => 'Alice', 'age' => 25, 'status' => 'active']);
        $bobId = $db->find()->table('users')->insert(['name' => 'Bob', 'age' => 30, 'status' => 'inactive']);
        $charlieId = $db->find()->table('users')->insert(['name' => 'Charlie', 'age' => 35, 'status' => 'active']);

        $db->find()->table('orders')->insertMulti([
        ['user_id' => $aliceId, 'amount' => 999.99],
        ['user_id' => $bobId, 'amount' => 29.99],
        ['user_id' => $charlieId, 'amount' => 79.99],
        ]);

        // Test callback in orWhere clause
        $results = $db->find()
        ->from('users')
        ->where('age', 30, '>=')
        ->orWhere('id', function ($q) {
            $q->from('orders')
            ->select('user_id')
            ->where('amount', 100, '>');
        }, 'IN')
        ->get();

        $this->assertGreaterThanOrEqual(2, count($results));
    }

    public function testCallbackInHaving(): void
    {
        $db = self::$db;

        // Test callback in having clause
        $results = $db->find()
        ->from('users')
        ->select(['name'])
        ->groupBy('name')
        ->having('COUNT(*)', function ($q) {
            $q->from('orders')
            ->select('COUNT(*)')
            ->where('user_id', 1);
        }, '>')
        ->get();

        $this->assertIsArray($results);
    }

    public function testCallbackInOrHaving(): void
    {
        $db = self::$db;

        // Test callback in orHaving clause
        $results = $db->find()
        ->from('users')
        ->select(['name'])
        ->groupBy('name')
        ->having('COUNT(*)', 1, '>')
        ->orHaving('COUNT(*)', function ($q) {
            $q->from('orders')
            ->select('COUNT(*)')
            ->where('user_id', 1);
        }, '>')
        ->get();

        $this->assertIsArray($results);
    }

    public function testComplexCallbackQuery(): void
    {
        $db = self::$db;

        // Test complex query with multiple callbacks
        $results = $db->find()
        ->from('users')
        ->where('status', 'active')
        ->where('id', function ($q) {
            $q->from('orders')
            ->select('user_id')
            ->where('amount', 50, '>');
        }, 'IN')
        ->orWhere('age', function ($q) {
            $q->from('orders')
            ->select('AVG([amount])')
            ->where('user_id', 1);
        }, '>')
        ->get();

        $this->assertIsArray($results);
    }

    public function testSelectWithCallbacks(): void
    {
        $db = self::$db;

        // Insert test data
        $db->find()->table('users')->insertMulti([
        ['name' => 'Alice', 'age' => 25, 'status' => 'active'],
        ['name' => 'Bob', 'age' => 30, 'status' => 'inactive'],
        ]);

        $db->find()->table('orders')->insertMulti([
        ['user_id' => 1, 'amount' => 150.50],
        ['user_id' => 1, 'amount' => 200.75],
        ['user_id' => 2, 'amount' => 99.99],
        ]);

        // Test select with callback subquery
        $results = $db->find()
        ->from('users')
        ->select([
        'id',
        'name',
        'order_count' => function ($q) {
            $q->from('orders')
            ->select(Db::raw('COUNT(*)'))
            ->where('user_id', Db::raw('[users].[id]'), '=');
        },
        'total_amount' => function ($q) {
            $q->from('orders')
            ->select(Db::raw('SUM([amount])'))
            ->where('user_id', Db::raw('[users].[id]'), '=');
        },
        ])
        ->get();

        $this->assertCount(2, $results);
        $this->assertEquals('Alice', $results[0]['name']);
        $this->assertEquals('Bob', $results[1]['name']);
        $this->assertEquals(2, $results[0]['order_count']);
        $this->assertEquals(1, $results[1]['order_count']);
    }
}
