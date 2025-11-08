<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$currentDriver = getCurrentDriver($db);

echo "=== UPDATE/DELETE with JOIN Examples ===\n\n";

// Create tables
echo "Creating tables...\n";

$driverName = $db->find()->getConnection()->getDialect()->getDriverName();

if ($driverName === 'pgsql') {
    $db->rawQuery('DROP TABLE IF EXISTS update_delete_join_orders');
    $db->rawQuery('DROP TABLE IF EXISTS update_delete_join_users');

    $db->rawQuery('
        CREATE TABLE update_delete_join_users (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100),
            status VARCHAR(50),
            balance DECIMAL(10,2) DEFAULT 0
        )
    ');

    $db->rawQuery('
        CREATE TABLE update_delete_join_orders (
            id SERIAL PRIMARY KEY,
            user_id INTEGER,
            amount DECIMAL(10,2),
            status VARCHAR(50)
        )
    ');
} elseif ($driverName === 'mysql' || $driverName === 'mariadb') {
    $db->rawQuery('DROP TABLE IF EXISTS update_delete_join_orders');
    $db->rawQuery('DROP TABLE IF EXISTS update_delete_join_users');

    $db->rawQuery('
        CREATE TABLE update_delete_join_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            status VARCHAR(50),
            balance DECIMAL(10,2) DEFAULT 0
        )
    ');

    $db->rawQuery('
        CREATE TABLE update_delete_join_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            amount DECIMAL(10,2),
            status VARCHAR(50)
        )
    ');
} else {
    // SQLite
    $db->rawQuery('DROP TABLE IF EXISTS update_delete_join_orders');
    $db->rawQuery('DROP TABLE IF EXISTS update_delete_join_users');

    $db->rawQuery('
        CREATE TABLE update_delete_join_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            status TEXT,
            balance NUMERIC(10,2) DEFAULT 0
        )
    ');

    $db->rawQuery('
        CREATE TABLE update_delete_join_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            amount NUMERIC(10,2),
            status TEXT
        )
    ');
}

echo "Tables created.\n\n";

// Example 1: UPDATE with JOIN
echo "Example 1: UPDATE with JOIN\n";
echo "Update user balance based on order amount\n";

$userId1 = $db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active', 'balance' => 100]);
$userId2 = $db->find()->table('update_delete_join_users')->insert(['name' => 'Bob', 'status' => 'active', 'balance' => 200]);

$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'completed']);
$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId2, 'amount' => 75, 'status' => 'completed']);

try {
    $affected = $db->find()
        ->table('update_delete_join_users')
        ->join('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
        ->where('update_delete_join_orders.status', 'completed')
        ->update(['balance' => Db::raw('update_delete_join_users.balance + update_delete_join_orders.amount')]);

    echo "Updated {$affected} row(s)\n";

    $user1 = $db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
    echo "User 1 balance: {$user1['balance']}\n";

    $user2 = $db->find()->from('update_delete_join_users')->where('id', $userId2)->getOne();
    echo "User 2 balance: {$user2['balance']}\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if ($driverName === 'sqlite') {
        echo "Note: SQLite doesn't support JOIN in UPDATE statements.\n";
    }
}

echo "\n";

// Example 2: DELETE with JOIN
echo "Example 2: DELETE with JOIN\n";
echo "Delete users who have cancelled orders\n";

// Reset data
$db->rawQuery('DELETE FROM update_delete_join_orders');
$db->rawQuery('DELETE FROM update_delete_join_users');

$userId1 = $db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active']);
$userId2 = $db->find()->table('update_delete_join_users')->insert(['name' => 'Bob', 'status' => 'active']);

$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'cancelled']);
$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId2, 'amount' => 75, 'status' => 'completed']);

try {
    $affected = $db->find()
        ->table('update_delete_join_users')
        ->join('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
        ->where('update_delete_join_orders.status', 'cancelled')
        ->delete();

    echo "Deleted {$affected} row(s)\n";

    $user1 = $db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
    echo "User 1 exists: " . ($user1 ? 'yes' : 'no') . "\n";

    $user2 = $db->find()->from('update_delete_join_users')->where('id', $userId2)->getOne();
    echo "User 2 exists: " . ($user2 ? 'yes' : 'no') . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if ($driverName === 'sqlite') {
        echo "Note: SQLite doesn't support JOIN in DELETE statements.\n";
    }
}

echo "\n";

// Example 3: UPDATE with LEFT JOIN
echo "Example 3: UPDATE with LEFT JOIN\n";
echo "Update users who have orders\n";

// Reset data
$db->rawQuery('DELETE FROM update_delete_join_orders');
$db->rawQuery('DELETE FROM update_delete_join_users');

$userId1 = $db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active', 'balance' => 100]);
$userId2 = $db->find()->table('update_delete_join_users')->insert(['name' => 'Bob', 'status' => 'active', 'balance' => 200]);

$db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'completed']);

try {
    // For MySQL/MariaDB, use qualified column name to avoid ambiguity
    // For PostgreSQL, column name doesn't need table prefix
    $updateData = ($driverName === 'pgsql')
        ? ['status' => 'has_orders']
        : ['update_delete_join_users.status' => 'has_orders'];

    $affected = $db->find()
        ->table('update_delete_join_users')
        ->leftJoin('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
        ->where('update_delete_join_orders.id', null, 'IS NOT')
        ->update($updateData);

    echo "Updated {$affected} row(s)\n";

    $user1 = $db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
    echo "User 1 status: {$user1['status']}\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if ($driverName === 'sqlite') {
        echo "Note: SQLite doesn't support JOIN in UPDATE statements.\n";
    }
}

echo "\n";

// Example 4: UPDATE with multiple JOINs
if ($driverName !== 'sqlite') {
    echo "Example 4: UPDATE with multiple JOINs\n";
    echo "Update users based on multiple joined tables\n";

    // Reset data
    $db->rawQuery('DELETE FROM update_delete_join_orders');
    $db->rawQuery('DELETE FROM update_delete_join_users');

    $userId1 = $db->find()->table('update_delete_join_users')->insert(['name' => 'Alice', 'status' => 'active', 'balance' => 100]);
    $userId2 = $db->find()->table('update_delete_join_users')->insert(['name' => 'Bob', 'status' => 'active', 'balance' => 200]);

    $db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 50, 'status' => 'completed']);
    $db->find()->table('update_delete_join_orders')->insert(['user_id' => $userId1, 'amount' => 25, 'status' => 'completed']);

    try {
        $updateData = ($driverName === 'pgsql')
            ? ['status' => 'has_multiple_orders']
            : ['update_delete_join_users.status' => 'has_multiple_orders'];

        $affected = $db->find()
            ->table('update_delete_join_users')
            ->join('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
            ->where('update_delete_join_users.id', $userId1)
            ->where('update_delete_join_orders.status', 'completed')
            ->update($updateData);

        echo "Updated {$affected} row(s)\n";

        $user1 = $db->find()->from('update_delete_join_users')->where('id', $userId1)->getOne();
        echo "User 1 status: {$user1['status']}\n";
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

echo "=== Examples completed ===\n";

