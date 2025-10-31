<?php
/**
 * Example: JOIN Operations
 * 
 * Demonstrates INNER, LEFT, RIGHT, and complex JOIN patterns
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== JOIN Operations Example (on $driver) ===\n\n";

// Setup tables
recreateTable($db, 'users', ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'name' => 'TEXT', 'city' => 'TEXT']);
recreateTable($db, 'orders', ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'user_id' => 'INTEGER', 'product' => 'TEXT', 'amount' => 'REAL']);
recreateTable($db, 'reviews', ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'user_id' => 'INTEGER', 'rating' => 'INTEGER', 'comment' => 'TEXT']);

// Insert test data
$db->find()->table('users')->insertMulti([
    ['name' => 'Alice', 'city' => 'NYC'],
    ['name' => 'Bob', 'city' => 'LA'],
    ['name' => 'Carol', 'city' => 'Chicago'],
    ['name' => 'Dave', 'city' => 'NYC'],  // User without orders
]);

$db->find()->table('orders')->insertMulti([
    ['user_id' => 1, 'product' => 'Laptop', 'amount' => 999.99],
    ['user_id' => 1, 'product' => 'Mouse', 'amount' => 29.99],
    ['user_id' => 2, 'product' => 'Keyboard', 'amount' => 79.99],
    ['user_id' => 3, 'product' => 'Monitor', 'amount' => 299.99],
]);

$db->find()->table('reviews')->insertMulti([
    ['user_id' => 1, 'rating' => 5, 'comment' => 'Great products!'],
    ['user_id' => 2, 'rating' => 4, 'comment' => 'Good service'],
]);

echo "✓ Test data inserted\n\n";

// Example 1: INNER JOIN
echo "1. INNER JOIN - Users with their orders...\n";
$results = $db->find()
    ->from('users AS u')
    ->join('orders AS o', 'o.user_id = u.id')
    ->select(['u.name', 'o.product', 'o.amount'])
    ->get();

echo "  Orders (INNER JOIN):\n";
foreach ($results as $row) {
    echo "  • {$row['name']} ordered {$row['product']} (\${$row['amount']})\n";
}
echo "\n";

// Example 2: LEFT JOIN
echo "2. LEFT JOIN - All users (with or without orders)...\n";
$results = $db->find()
    ->from('users AS u')
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->select(['u.name', 'o.product'])
    ->get();

echo "  All users:\n";
$current = null;
foreach ($results as $row) {
    if ($current !== $row['name']) {
        $current = $row['name'];
        echo "  • {$row['name']}:";
    }
    echo $row['product'] ? " {$row['product']}" : " (no orders)";
    echo "\n";
}
echo "\n";

// Example 3: Multiple JOINs
echo "3. Multiple JOINs - Users, orders, and reviews...\n";
$results = $db->find()
    ->from('users AS u')
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->leftJoin('reviews AS r', 'r.user_id = u.id')
    ->select([
        'u.id',
        'u.name',
        'order_count' => Db::count('DISTINCT o.id'),
        'review_count' => Db::count('DISTINCT r.id')
    ])
    ->groupBy(['u.id', 'u.name'])
    ->get();

echo "  User activity summary:\n";
foreach ($results as $row) {
    echo "  • {$row['name']}: {$row['order_count']} orders, {$row['review_count']} reviews\n";
}
echo "\n";

// Example 4: JOIN with aggregation
echo "4. JOIN with aggregation - Total spending per user...\n";
$results = $db->find()
    ->from('users AS u')
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->select([
        'u.name',
        'total_spent' => Db::coalesce(Db::sum('o.amount'), '0')
    ])
    ->groupBy(['u.id', 'u.name'])
    ->orderBy('total_spent', 'DESC')
    ->get();

echo "  User spending:\n";
foreach ($results as $row) {
    echo "  • {$row['name']}: \$" . number_format($row['total_spent'], 2) . "\n";
}
echo "\n";

// Example 5: JOIN with WHERE and HAVING
echo "5. JOIN with WHERE and HAVING...\n";
$results = $db->find()
    ->from('users AS u')
    ->join('orders AS o', 'o.user_id = u.id')
    ->select([
        'u.name',
        'u.city',
        'order_count' => Db::count(),
        'total' => Db::sum('o.amount')
    ])
    ->where('u.city', 'NYC')
    ->groupBy(['u.id', 'u.name', 'u.city'])
    ->having(Db::sum('o.amount'), 500, '>')
    ->get();

echo "  High-value NYC customers:\n";
foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['city']}): {$row['order_count']} orders, \$" . number_format($row['total'], 2) . "\n";
}

echo "\n";

// Example 6: LATERAL JOIN (PostgreSQL/MySQL only)
$dialect = $db->find()->getConnection()->getDialect();
if ($dialect->supportsLateralJoin()) {
    echo "6. LATERAL JOIN - Latest order per user...\n";
    echo "   (LATERAL JOIN allows subqueries to reference columns from preceding tables)\n";
    
    // Get latest order per user using LATERAL JOIN
    $results = $db->find()
        ->from('users AS u')
        ->select([
            'u.name',
            'latest.product',
            'latest.amount'
        ])
        ->lateralJoin(function ($q) {
            $q->from('orders')
              ->select(['product', 'amount'])
              ->where('user_id', 'u.id')
              ->orderBy('id', 'DESC')
              ->limit(1);
        }, null, 'LEFT', 'latest')
        ->get();
    
    echo "  Latest order per user:\n";
    foreach ($results as $row) {
        if ($row['product']) {
            echo "  • {$row['name']}: {$row['product']} (\${$row['amount']})\n";
        } else {
            echo "  • {$row['name']}: (no orders)\n";
        }
    }
    echo "\n";
} else {
    echo "6. LATERAL JOIN - Skipped (not supported by {$driver})\n\n";
}

echo "JOIN operations example completed!\n";

