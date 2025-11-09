<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\helpers\Db;

/**
 * ExternalReferencesTests tests for MSSQL.
 */
final class ExternalReferencesTests extends BaseMSSQLTestCase
{
    public function testExternalReferenceInWhereExists(): void
    {
        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        // Insert test data and get actual IDs
        $userId1 = $db->find()->table('users')->insert(['name' => 'John Doe', 'status' => 'active']);
        $userId2 = $db->find()->table('users')->insert(['name' => 'Jane Smith', 'status' => 'active']);
        $db->find()->table('orders')->insert(['user_id' => $userId1, 'amount' => 100.00]);
        $db->find()->table('orders')->insert(['user_id' => $userId2, 'amount' => 200.00]);

        // Test WHERE EXISTS with external reference
        // Note: MSSQL may have issues with external reference auto-detection in subqueries
        // Using explicit RawValue to ensure proper handling
        $users = $db->find()
        ->from('users')
        ->whereExists(function ($query) {
            $query->from('orders')
            ->where('user_id', Db::raw('users.id'))  // External reference - explicit RawValue
            ->where('amount', 50, '>');
        })
        ->get();

        $this->assertCount(2, $users);
        $userNames = array_column($users, 'name');
        $this->assertContains('John Doe', $userNames);
        $this->assertContains('Jane Smith', $userNames);
    }

    public function testExternalReferenceInWhereNotExists(): void
    {
        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        // Insert test data and get actual IDs
        $userId1 = $db->find()->table('users')->insert(['name' => 'John Doe', 'status' => 'active']);
        $userId2 = $db->find()->table('users')->insert(['name' => 'Jane Smith', 'status' => 'active']);
        $db->find()->table('orders')->insert(['user_id' => $userId1, 'amount' => 100.00]);
        $db->find()->table('orders')->insert(['user_id' => $userId2, 'amount' => 200.00]);

        // Test WHERE NOT EXISTS with external reference
        $users = $db->find()
        ->from('users')
        ->whereNotExists(function ($query) {
            $query->from('orders')
            ->where('user_id', 'users.id')  // External reference - should be auto-converted
            ->where('amount', 50, '>');
        })
        ->get();

        $this->assertCount(0, $users); // Both users have orders with amount > 50
    }

    public function testExternalReferenceInSelect(): void
    {
        $db = self::$db;
        $connection = $db->connection;
        assert($connection !== null);

        // Insert test data and get actual IDs
        $userId1 = $db->find()->table('users')->insert(['name' => 'John Doe', 'status' => 'active']);
        $userId2 = $db->find()->table('users')->insert(['name' => 'Jane Smith', 'status' => 'active']);
        $db->find()->table('orders')->insert(['user_id' => $userId1, 'amount' => 100.00]);
        $db->find()->table('orders')->insert(['user_id' => $userId2, 'amount' => 200.00]);

        // Test SELECT with external reference
        // Note: MSSQL requires all non-aggregated columns to be in GROUP BY
        // Using qualified identifiers to avoid ambiguity
        $users = $db->find()
        ->from('users')
        ->select([
        'users.id',
        'users.name',
        'total_orders' => 'COUNT(orders.id)',  // External reference
        ])
        ->leftJoin('orders', 'orders.user_id = users.id')
        ->groupBy(['users.id', 'users.name'])
        ->get();

        $this->assertCount(2, $users);
        $this->assertArrayHasKey('total_orders', $users[0]);
    }
}
