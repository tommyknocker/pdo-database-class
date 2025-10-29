<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

$driver = getenv('PDODB_DRIVER') ?: 'mysql';
$config = getExampleConfig();

echo "=== Window Functions Examples ===\n\n";
echo "Database: $driver\n\n";

$db = createExampleDb($config);

// Create test table with sample sales data
if ($driver === 'sqlite') {
    $db->rawQuery('DROP TABLE IF EXISTS sales');
    $db->rawQuery('CREATE TABLE sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product TEXT NOT NULL,
        region TEXT NOT NULL,
        amount INTEGER NOT NULL,
        sale_date TEXT NOT NULL
    )');
} elseif ($driver === 'pgsql') {
    $db->rawQuery('DROP TABLE IF EXISTS sales CASCADE');
    $db->rawQuery('CREATE TABLE sales (
        id SERIAL PRIMARY KEY,
        product VARCHAR(100) NOT NULL,
        region VARCHAR(50) NOT NULL,
        amount INTEGER NOT NULL,
        sale_date DATE NOT NULL
    )');
} else {
    $db->rawQuery('DROP TABLE IF EXISTS sales');
    $db->rawQuery('CREATE TABLE sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product VARCHAR(100) NOT NULL,
        region VARCHAR(50) NOT NULL,
        amount INT NOT NULL,
        sale_date DATE NOT NULL
    ) ENGINE=InnoDB');
}

// Insert sample data
$db->find()->table('sales')->insertMulti([
    ['product' => 'Laptop', 'region' => 'North', 'amount' => 1200, 'sale_date' => '2025-01-15'],
    ['product' => 'Mouse', 'region' => 'North', 'amount' => 25, 'sale_date' => '2025-01-16'],
    ['product' => 'Keyboard', 'region' => 'North', 'amount' => 75, 'sale_date' => '2025-01-17'],
    ['product' => 'Monitor', 'region' => 'North', 'amount' => 400, 'sale_date' => '2025-01-18'],
    ['product' => 'Laptop', 'region' => 'South', 'amount' => 1300, 'sale_date' => '2025-01-15'],
    ['product' => 'Mouse', 'region' => 'South', 'amount' => 30, 'sale_date' => '2025-01-16'],
    ['product' => 'Monitor', 'region' => 'South', 'amount' => 450, 'sale_date' => '2025-01-17'],
    ['product' => 'Keyboard', 'region' => 'East', 'amount' => 80, 'sale_date' => '2025-01-15'],
    ['product' => 'Laptop', 'region' => 'East', 'amount' => 1250, 'sale_date' => '2025-01-16'],
    ['product' => 'Mouse', 'region' => 'East', 'amount' => 28, 'sale_date' => '2025-01-17'],
]);

echo "Sample sales data inserted.\n\n";

// Example 1: ROW_NUMBER - Number rows within each region
echo "=== Example 1: ROW_NUMBER() ===\n";
echo "Numbering sales within each region by amount (highest first)\n\n";

$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'region',
        'amount',
        'row_num' => Db::rowNumber()->partitionBy('region')->orderBy('amount', 'DESC'),
    ])
    ->orderBy('region')
    ->orderBy('amount', 'DESC')
    ->get();

foreach ($results as $row) {
    printf(
        "%-15s %-10s $%-6d Row: %d\n",
        $row['product'],
        $row['region'],
        $row['amount'],
        $row['row_num']
    );
}
echo "\n";

// Example 2: RANK - Rank products by amount within region (with gaps for ties)
echo "=== Example 2: RANK() ===\n";
echo "Ranking products by amount within each region\n\n";

$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'region',
        'amount',
        'item_rank' => Db::rank()->partitionBy('region')->orderBy('amount', 'DESC'),
    ])
    ->orderBy('region')
    ->orderBy('amount', 'DESC')
    ->get();

foreach ($results as $row) {
    printf(
        "%-15s %-10s $%-6d Rank: %d\n",
        $row['product'],
        $row['region'],
        $row['amount'],
        $row['item_rank']
    );
}
echo "\n";

// Example 3: DENSE_RANK - Rank without gaps
echo "=== Example 3: DENSE_RANK() ===\n";
echo "Ranking without gaps (useful when ties exist)\n\n";

$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'region',
        'amount',
        'item_dense_rank' => Db::denseRank()->partitionBy('region')->orderBy('amount', 'DESC'),
    ])
    ->orderBy('region')
    ->orderBy('amount', 'DESC')
    ->limit(6)
    ->get();

foreach ($results as $row) {
    printf(
        "%-15s %-10s $%-6d Dense Rank: %d\n",
        $row['product'],
        $row['region'],
        $row['amount'],
        $row['item_dense_rank']
    );
}
echo "\n";

// Example 4: LAG - Compare with previous sale
echo "=== Example 4: LAG() ===\n";
echo "Compare each sale with the previous sale in the same region\n\n";

$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'region',
        'amount',
        'prev_amount' => Db::lag('amount', 1, 0)->partitionBy('region')->orderBy('sale_date'),
    ])
    ->orderBy('region')
    ->orderBy('sale_date')
    ->limit(8)
    ->get();

foreach ($results as $row) {
    $diff = $row['amount'] - $row['prev_amount'];
    printf(
        "%-15s %-10s $%-6d Previous: $%-6d Difference: %+d\n",
        $row['product'],
        $row['region'],
        $row['amount'],
        $row['prev_amount'],
        $diff
    );
}
echo "\n";

// Example 5: LEAD - Look ahead to next sale
echo "=== Example 5: LEAD() ===\n";
echo "Look ahead to the next sale in each region\n\n";

$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'region',
        'amount',
        'next_amount' => Db::lead('amount', 1, 0)->partitionBy('region')->orderBy('sale_date'),
    ])
    ->orderBy('region')
    ->orderBy('sale_date')
    ->limit(6)
    ->get();

foreach ($results as $row) {
    $next = $row['next_amount'] ? '$' . $row['next_amount'] : 'None';
    printf(
        "%-15s %-10s $%-6d Next: %s\n",
        $row['product'],
        $row['region'],
        $row['amount'],
        $next
    );
}
echo "\n";

// Example 6: Running total using SUM() as window function
echo "=== Example 6: Running Total ===\n";
echo "Calculate running total of sales for each region\n\n";

$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'region',
        'amount',
        'running_total' => Db::windowAggregate('SUM', 'amount')
            ->partitionBy('region')
            ->orderBy('sale_date')
            ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW'),
    ])
    ->orderBy('region')
    ->orderBy('sale_date')
    ->limit(8)
    ->get();

foreach ($results as $row) {
    printf(
        "%-15s %-10s $%-6d Running Total: $%-6d\n",
        $row['product'],
        $row['region'],
        $row['amount'],
        $row['running_total']
    );
}
echo "\n";

// Example 7: Moving average (last 3 sales)
echo "=== Example 7: Moving Average ===\n";
echo "Calculate moving average of last 3 sales per region\n\n";

$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'region',
        'amount',
        'moving_avg' => Db::windowAggregate('AVG', 'amount')
            ->partitionBy('region')
            ->orderBy('sale_date')
            ->rows('ROWS BETWEEN 2 PRECEDING AND CURRENT ROW'),
    ])
    ->orderBy('region')
    ->orderBy('sale_date')
    ->limit(8)
    ->get();

foreach ($results as $row) {
    printf(
        "%-15s %-10s $%-6d Moving Avg: $%.0f\n",
        $row['product'],
        $row['region'],
        $row['amount'],
        $row['moving_avg']
    );
}
echo "\n";

// Example 8: FIRST_VALUE and LAST_VALUE
echo "=== Example 8: FIRST_VALUE() and LAST_VALUE() ===\n";
echo "Get first and last sale amount in each region\n\n";

$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'region',
        'amount',
        'first_sale' => Db::firstValue('amount')->partitionBy('region')->orderBy('sale_date'),
        'last_sale' => Db::lastValue('amount')
            ->partitionBy('region')
            ->orderBy('sale_date')
            ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING'),
    ])
    ->orderBy('region')
    ->orderBy('sale_date')
    ->limit(6)
    ->get();

foreach ($results as $row) {
    printf(
        "%-15s %-10s $%-6d First: $%-6d Last: $%-6d\n",
        $row['product'],
        $row['region'],
        $row['amount'],
        $row['first_sale'],
        $row['last_sale']
    );
}
echo "\n";

// Example 9: NTILE - Divide into quartiles
echo "=== Example 9: NTILE() ===\n";
echo "Divide sales into 4 quartiles by amount\n\n";

$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'amount',
        'quartile' => Db::ntile(4)->orderBy('amount'),
    ])
    ->orderBy('amount')
    ->get();

foreach ($results as $row) {
    printf(
        "%-15s $%-6d Quartile: %d\n",
        $row['product'],
        $row['amount'],
        $row['quartile']
    );
}
echo "\n";

// Example 10: Multiple window functions in one query
echo "=== Example 10: Multiple Window Functions ===\n";
echo "Using multiple window functions together\n\n";

$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'region',
        'amount',
        'item_rank' => Db::rank()->partitionBy('region')->orderBy('amount', 'DESC'),
        'running_sum' => Db::windowAggregate('SUM', 'amount')
            ->partitionBy('region')
            ->orderBy('amount', 'DESC')
            ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW'),
        'region_total' => Db::windowAggregate('SUM', 'amount')->partitionBy('region'),
    ])
    ->orderBy('region')
    ->orderBy('amount', 'DESC')
    ->limit(8)
    ->get();

foreach ($results as $row) {
    $percentage = round(($row['running_sum'] / $row['region_total']) * 100);
    printf(
        "%-15s %-10s $%-6d Rank: %d  Cumulative: $%-6d (%.0f%% of region)\n",
        $row['product'],
        $row['region'],
        $row['amount'],
        $row['item_rank'],
        $row['running_sum'],
        $percentage
    );
}
echo "\n";

echo "=== Window Functions Examples Complete ===\n";

