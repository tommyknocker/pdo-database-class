<?php
/**
 * Example: Conditional Helper Functions
 * 
 * Demonstrates CASE statements and conditional logic
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Conditional Helper Functions Example (on $driver) ===\n\n";

// Setup
recreateTable($db, 'orders', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'customer_name' => 'TEXT',
    'amount' => 'REAL',
    'status' => 'TEXT',
    'priority' => 'TEXT',
    'order_date' => 'TEXT',
    'discount_percent' => 'REAL'
]);

$db->find()->table('orders')->insertMulti([
    ['customer_name' => 'Alice Johnson', 'amount' => 150.00, 'status' => 'pending', 'priority' => 'high', 'order_date' => '2024-01-15', 'discount_percent' => 0],
    ['customer_name' => 'Bob Smith', 'amount' => 75.50, 'status' => 'completed', 'priority' => 'medium', 'order_date' => '2024-01-14', 'discount_percent' => 5],
    ['customer_name' => 'Carol Davis', 'amount' => 300.00, 'status' => 'pending', 'priority' => 'low', 'order_date' => '2024-01-16', 'discount_percent' => 10],
    ['customer_name' => 'Dave Wilson', 'amount' => 25.00, 'status' => 'cancelled', 'priority' => 'high', 'order_date' => '2024-01-13', 'discount_percent' => 0],
    ['customer_name' => 'Eve Brown', 'amount' => 500.00, 'status' => 'shipped', 'priority' => 'medium', 'order_date' => '2024-01-12', 'discount_percent' => 15],
]);

echo "✓ Test data inserted\n\n";

// Example 1: Basic CASE statements
echo "1. Basic CASE statements...\n";
$results = $db->find()
    ->from('orders')
    ->select([
        'customer_name',
        'amount',
        'status',
        'priority_level' => Db::case([
            'priority = \'high\'' => '1',
            'priority = \'medium\'' => '2',
            'priority = \'low\'' => '3'
        ], '0')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['customer_name']}: \${$row['amount']} ({$row['status']}, priority: {$row['priority_level']})\n";
}
echo "\n";

// Example 2: Complex CASE with calculations
echo "2. Complex CASE with calculations...\n";
$results = $db->find()
    ->from('orders')
    ->select([
        'customer_name',
        'amount',
        'discount_percent',
        'discount_amount' => Db::case([
            'amount >= 200' => 'amount * 0.1',
            'amount >= 100' => 'amount * 0.05',
            'amount >= 50' => 'amount * 0.02'
        ], '0')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['customer_name']}: \${$row['amount']} (discount: \${$row['discount_amount']})\n";
}
echo "\n";

// Example 3: CASE with string operations
echo "3. CASE with string operations...\n";
$results = $db->find()
    ->from('orders')
    ->select([
        'customer_name',
        'status',
        'status_description' => Db::case([
            'status = \'pending\'' => '\'Order is being processed\'',
            'status = \'completed\'' => '\'Order has been delivered\'',
            'status = \'shipped\'' => '\'Order is on the way\'',
            'status = \'cancelled\'' => '\'Order was cancelled\''
        ], '\'Unknown status\'')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['customer_name']}: {$row['status_description']}\n";
}
echo "\n";

// Example 4: CASE with multiple conditions
echo "4. CASE with multiple conditions...\n";
$results = $db->find()
    ->from('orders')
    ->select([
        'customer_name',
        'amount',
        'priority',
        'processing_time' => Db::case([
            'priority = \'high\' AND amount > 100' => '\'Express (1 day)\'',
            'priority = \'high\'' => '\'Fast (2 days)\'',
            'amount > 200' => '\'Standard (3 days)\'',
            'amount > 50' => '\'Regular (5 days)\''
        ], '\'Slow (7 days)\'')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['customer_name']}: \${$row['amount']} ({$row['priority']}) → {$row['processing_time']}\n";
}
echo "\n";

// Example 5: CASE with date operations
echo "5. CASE with date operations...\n";
$driver = getCurrentDriver($db);
$dayOfWeekFunc = $driver === 'sqlite' ? 'strftime("%w", order_date)' : 
                 ($driver === 'mysql' ? 'WEEKDAY(order_date)' : 
                 'EXTRACT(DOW FROM order_date::DATE)');

$results = $db->find()
    ->from('orders')
    ->select([
        'customer_name',
        'order_date',
        'day_of_week' => Db::case([
            $dayOfWeekFunc . ' = 0' => '\'Sunday\'',
            $dayOfWeekFunc . ' = 1' => '\'Monday\'',
            $dayOfWeekFunc . ' = 2' => '\'Tuesday\'',
            $dayOfWeekFunc . ' = 3' => '\'Wednesday\'',
            $dayOfWeekFunc . ' = 4' => '\'Thursday\'',
            $dayOfWeekFunc . ' = 5' => '\'Friday\'',
            $dayOfWeekFunc . ' = 6' => '\'Saturday\''
        ], '\'Unknown\'')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['customer_name']}: {$row['order_date']} ({$row['day_of_week']})\n";
}
echo "\n";

// Example 6: CASE in WHERE clause
echo "6. CASE in WHERE clause...\n";
$results = $db->find()
    ->from('orders')
    ->select(['customer_name', 'amount', 'status'])
    ->where(Db::case([
        'status = \'pending\'' => 'amount',
        'status = \'completed\'' => 'amount * 0.9',
        'status = \'shipped\'' => 'amount * 0.95'
    ], 'amount'), 100, '>')
    ->get();

echo "  Orders with adjusted amount > \$100:\n";
foreach ($results as $row) {
    echo "  • {$row['customer_name']}: \${$row['amount']} ({$row['status']})\n";
}
echo "\n";

// Example 7: CASE with aggregation
echo "7. CASE with aggregation...\n";
$results = $db->find()
    ->from('orders')
    ->select([
        'status',
        'count' => Db::count(),
        'total_amount' => Db::sum('amount'),
        'avg_amount' => Db::avg('amount'),
        'status_category' => Db::case([
            'status IN (\'pending\', \'shipped\')' => '\'Active\'',
            'status = \'completed\'' => '\'Finished\'',
            'status = \'cancelled\'' => '\'Cancelled\''
        ], '\'Unknown\'')
    ])
    ->groupBy('status')
    ->get();

foreach ($results as $row) {
    echo "  • {$row['status']} ({$row['status_category']}): {$row['count']} orders, \${$row['total_amount']} total, \${$row['avg_amount']} avg\n";
}
echo "\n";

// Example 8: Complex CASE statements
echo "8. Complex CASE statements...\n";
$results = $db->find()
    ->from('orders')
    ->select([
        'customer_name',
        'amount',
        'priority',
        'status',
        'urgency_score' => Db::case([
            'priority = \'high\' AND status = \'pending\'' => '10',
            'priority = \'high\' AND status = \'shipped\'' => '8',
            'priority = \'high\' AND status = \'completed\'' => '5',
            'priority = \'high\' AND status = \'cancelled\'' => '1',
            'priority = \'medium\' AND status = \'pending\'' => '7',
            'priority = \'medium\' AND status = \'shipped\'' => '6',
            'priority = \'medium\' AND status = \'completed\'' => '4',
            'priority = \'medium\' AND status = \'cancelled\'' => '1',
            'priority = \'low\' AND status = \'pending\'' => '4',
            'priority = \'low\' AND status = \'shipped\'' => '3',
            'priority = \'low\' AND status = \'completed\'' => '2',
            'priority = \'low\' AND status = \'cancelled\'' => '1'
        ], '0')
    ])
    ->orderBy('urgency_score', 'DESC')
    ->get();

echo "  Orders by urgency score:\n";
foreach ($results as $row) {
    echo "  • {$row['customer_name']}: \${$row['amount']} ({$row['priority']}, {$row['status']}) → score: {$row['urgency_score']}\n";
}
echo "\n";

// Example 9: CASE with mathematical operations
echo "9. CASE with mathematical operations...\n";
$results = $db->find()
    ->from('orders')
    ->select([
        'customer_name',
        'amount',
        'discount_percent',
        'final_amount' => Db::case([
            'discount_percent > 0' => 'amount - (amount * discount_percent / 100)',
            'amount >= 200' => 'amount * 0.9',
            'amount >= 100' => 'amount * 0.95'
        ], 'amount')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['customer_name']}: \${$row['amount']} → \${$row['final_amount']} (discount: {$row['discount_percent']}%)\n";
}
echo "\n";

// Example 10: CASE for data categorization
echo "10. CASE for data categorization...\n";
$results = $db->find()
    ->from('orders')
    ->select([
        'customer_name',
        'amount',
        'customer_tier' => Db::case([
            'amount >= 300' => '\'Premium\'',
            'amount >= 100' => '\'Gold\'',
            'amount >= 50' => '\'Silver\''
        ], '\'Bronze\''),
        'tier_benefits' => Db::case([
            'amount >= 300' => '\'Free shipping + Priority support\'',
            'amount >= 100' => '\'Free shipping\'',
            'amount >= 50' => '\'Standard shipping\''
        ], '\'Basic service\'')
    ])
    ->orderBy('amount', 'DESC')
    ->get();

echo "  Customer tiers based on order amount:\n";
foreach ($results as $row) {
    echo "  • {$row['customer_name']}: \${$row['amount']} → {$row['customer_tier']} ({$row['tier_benefits']})\n";
}

echo "\nConditional helper functions example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use CASE for conditional logic in SELECT, WHERE, ORDER BY\n";
echo "  • CASE supports multiple WHEN conditions with THEN results\n";
echo "  • Use ELSE for default values when no conditions match\n";
echo "  • CASE can contain complex expressions and calculations\n";
echo "  • Nested CASE statements allow for sophisticated logic\n";
echo "  • CASE is useful for data categorization and conditional calculations\n";
