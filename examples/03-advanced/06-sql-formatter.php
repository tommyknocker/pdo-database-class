<?php
/**
 * Example: SQL Formatter / Pretty Printer
 *
 * Demonstrates SQL formatting for human-readable output during debugging.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== SQL Formatter Example (on {$driver}) ===\n\n";

// Setup: Create sample tables using fluent API (cross-dialect)
$schema = $db->schema();
recreateTable($db, 'users', [
    'id' => $schema->integer()->notNull(),
    'name' => $schema->string(100),
    'email' => $schema->string(100),
    'status' => $schema->string(20),
    'created_at' => $schema->datetime()->defaultExpression('CURRENT_TIMESTAMP'),
], ['primaryKey' => ['id']]);

recreateTable($db, 'orders', [
    'id' => $schema->integer()->notNull(),
    'user_id' => $schema->integer(),
    'total' => $schema->decimal(10, 2),
    'status' => $schema->string(20),
], ['primaryKey' => ['id']]);

echo "✓ Tables created\n\n";

// 1. Basic query - unformatted (default)
echo "1. Unformatted SQL (default):\n";
echo "   " . str_repeat('-', 70) . "\n";
$sqlData = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->toSQL(false);

echo "   " . $sqlData['sql'] . "\n";
echo "   " . str_repeat('-', 70) . "\n\n";

// 2. Basic query - formatted
echo "2. Formatted SQL:\n";
echo "   " . str_repeat('-', 70) . "\n";
$sqlData = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->orderBy('name')
    ->toSQL(true);

$formattedLines = explode("\n", $sqlData['sql']);
foreach ($formattedLines as $line) {
    echo "   " . $line . "\n";
}
echo "   " . str_repeat('-', 70) . "\n\n";

// 3. Complex query with JOINs
echo "3. Complex query with JOINs (formatted):\n";
echo "   " . str_repeat('-', 70) . "\n";
$sqlData = $db->find()
    ->from('users')
    ->select(['users.name', 'users.email', 'COUNT(orders.id) as order_count'])
    ->join('orders', 'users.id = orders.user_id')
    ->where('users.status', 'active')
    ->andWhere('orders.total', 100, '>')
    ->groupBy('users.id', 'users.name', 'users.email')
    ->having('COUNT(orders.id)', 1, '>')
    ->orderBy('order_count', 'DESC')
    ->limit(10)
    ->toSQL(true);

$formattedLines = explode("\n", $sqlData['sql']);
foreach ($formattedLines as $line) {
    echo "   " . $line . "\n";
}
echo "   " . str_repeat('-', 70) . "\n\n";

// 4. Query with multiple WHERE conditions (AND/OR)
echo "4. Query with multiple WHERE conditions (formatted):\n";
echo "   " . str_repeat('-', 70) . "\n";
$sqlData = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('created_at', date('Y-m-d', strtotime('-30 days')), '>=')
    ->orWhere('email', 'admin@example.com')
    ->orderBy('name')
    ->toSQL(true);

$formattedLines = explode("\n", $sqlData['sql']);
foreach ($formattedLines as $line) {
    echo "   " . $line . "\n";
}
echo "   " . str_repeat('-', 70) . "\n\n";

// 5. Subquery (formatted)
echo "5. Query with subquery (formatted):\n";
echo "   " . str_repeat('-', 70) . "\n";
$sqlData = $db->find()
    ->from('users')
    ->whereIn('id', function ($q) {
        $q->from('orders')
          ->select('user_id')
          ->where('total', 500, '>')
          ->groupBy('user_id');
    })
    ->toSQL(true);

$formattedLines = explode("\n", $sqlData['sql']);
foreach ($formattedLines as $line) {
    echo "   " . $line . "\n";
}
echo "   " . str_repeat('-', 70) . "\n\n";

// 6. CTE (Common Table Expression) query (formatted)
echo "6. Query with CTE (formatted):\n";
echo "   " . str_repeat('-', 70) . "\n";
$sqlData = $db->find()
    ->with('active_users', function ($q) {
        $q->from('users')
          ->where('status', 'active');
    })
    ->from('active_users')
    ->select(['name', 'email'])
    ->orderBy('name')
    ->toSQL(true);

$formattedLines = explode("\n", $sqlData['sql']);
foreach ($formattedLines as $line) {
    echo "   " . $line . "\n";
}
echo "   " . str_repeat('-', 70) . "\n\n";

echo "=== SQL Formatter Example Complete ===\n";

// Clean up
$db->rawQuery('DROP TABLE IF EXISTS users');
$db->rawQuery('DROP TABLE IF EXISTS orders');

