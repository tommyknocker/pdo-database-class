<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

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

        // Ensure clean state and reset IDENTITY
        $connection->query('DELETE FROM orders');
        $connection->query('DELETE FROM users');
        $connection->query('DBCC CHECKIDENT(\'users\', RESEED, 0)');
        $connection->query('DBCC CHECKIDENT(\'orders\', RESEED, 0)');

        // Insert test data and get actual IDs
        $db->find()->table('users')->insert(['name' => 'John Doe', 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'Jane Smith', 'status' => 'active']);

        // Get actual IDs from database
        $userId1 = (int)$db->find()->table('users')->select('id')->where('name', 'John Doe')->getValue();
        $userId2 = (int)$db->find()->table('users')->select('id')->where('name', 'Jane Smith')->getValue();

        $db->find()->table('orders')->insert(['user_id' => $userId1, 'amount' => 100.00]);
        $db->find()->table('orders')->insert(['user_id' => $userId2, 'amount' => 200.00]);

        // Verify data exists - both users and orders should be present
        $allUsers = $connection->query('SELECT * FROM users ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $allOrders = $connection->query('SELECT * FROM orders ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $allUsers, 'Should have 2 users before query');
        $this->assertCount(2, $allOrders, 'Should have 2 orders before query');

        // Verify that orders reference the correct users
        $orderUserIds = array_map(fn ($o) => (int)$o['user_id'], $allOrders);
        $this->assertContains($userId1, $orderUserIds, 'Should have order for user 1');
        $this->assertContains($userId2, $orderUserIds, 'Should have order for user 2');

        // Test WHERE EXISTS with external reference
        // External reference should be auto-detected and converted to RawValue
        $query = $db->find()
        ->from('users')
        ->whereExists(function ($query) {
            $query->from('orders')
            ->where('user_id', 'users.id')  // External reference - should be auto-converted
            ->where('amount', 50, '>');
        });

        $sql = $query->toSQL();

        // Execute SQL directly with same parameters to verify it works
        $pdo = $connection->getPdo();
        $stmt = $pdo->prepare($sql['sql']);
        $stmt->execute($sql['params']);
        $directResult = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $directResult, 'Direct SQL execution should return 2 users');

        $users = $query->get();

        // Debug: if test fails, the SQL will be shown
        $this->assertCount(2, $users, 'Expected 2 users but got ' . count($users) . '. SQL: ' . $sql['sql']);
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
        $db->find()->table('users')->insert(['name' => 'John Doe', 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'Jane Smith', 'status' => 'active']);

        // Get actual IDs from database
        $userId1 = (int)$db->find()->table('users')->select('id')->where('name', 'John Doe')->getValue();
        $userId2 = (int)$db->find()->table('users')->select('id')->where('name', 'Jane Smith')->getValue();

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
        $db->find()->table('users')->insert(['name' => 'John Doe', 'status' => 'active']);
        $db->find()->table('users')->insert(['name' => 'Jane Smith', 'status' => 'active']);

        // Get actual IDs from database
        $userId1 = (int)$db->find()->table('users')->select('id')->where('name', 'John Doe')->getValue();
        $userId2 = (int)$db->find()->table('users')->select('id')->where('name', 'Jane Smith')->getValue();

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
