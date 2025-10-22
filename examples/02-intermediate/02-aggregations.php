<?php
/**
 * Example: Aggregations and GROUP BY
 * 
 * Demonstrates GROUP BY, HAVING, and aggregate functions
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Aggregations Example (on $driver) ===\n\n";

// Setup
recreateTable($db, 'sales', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'product' => 'TEXT',
    'category' => 'TEXT',
    'amount' => 'REAL',
    'quantity' => 'INTEGER',
    'region' => 'TEXT',
    'sale_date' => 'DATE'
]);

$db->find()->table('sales')->insertMulti([
    ['product' => 'Laptop', 'category' => 'Electronics', 'amount' => 999.99, 'quantity' => 2, 'region' => 'East', 'sale_date' => '2025-10-01'],
    ['product' => 'Mouse', 'category' => 'Electronics', 'amount' => 29.99, 'quantity' => 5, 'region' => 'East', 'sale_date' => '2025-10-01'],
    ['product' => 'Desk', 'category' => 'Furniture', 'amount' => 299.99, 'quantity' => 1, 'region' => 'West', 'sale_date' => '2025-10-02'],
    ['product' => 'Chair', 'category' => 'Furniture', 'amount' => 199.99, 'quantity' => 3, 'region' => 'West', 'sale_date' => '2025-10-02'],
    ['product' => 'Keyboard', 'category' => 'Electronics', 'amount' => 79.99, 'quantity' => 4, 'region' => 'East', 'sale_date' => '2025-10-03'],
    ['product' => 'Monitor', 'category' => 'Electronics', 'amount' => 299.99, 'quantity' => 2, 'region' => 'West', 'sale_date' => '2025-10-03'],
]);

echo "✓ Inserted 6 sales records\n\n";

// Example 1: COUNT
echo "1. COUNT - Sales by category...\n";
$byCategory = $db->find()
    ->from('sales')
    ->select([
        'category',
        'sale_count' => Db::count()
    ])
    ->groupBy('category')
    ->get();

foreach ($byCategory as $row) {
    echo "  • {$row['category']}: {$row['sale_count']} sales\n";
}
echo "\n";

// Example 2: SUM
echo "2. SUM - Total revenue by category...\n";
$revenue = $db->find()
    ->from('sales')
    ->select([
        'category',
        'total_revenue' => Db::sum('amount')
    ])
    ->groupBy('category')
    ->orderBy(Db::sum('amount'), 'DESC')
    ->get();

foreach ($revenue as $row) {
    echo "  • {$row['category']}: \$" . number_format($row['total_revenue'], 2) . "\n";
}
echo "\n";

// Example 3: AVG
echo "3. AVG - Average sale amount by region...\n";
$avgByRegion = $db->find()
    ->from('sales')
    ->select([
        'region',
        'avg_amount' => Db::avg('amount')
    ])
    ->groupBy('region')
    ->get();

foreach ($avgByRegion as $row) {
    echo "  • {$row['region']}: \$" . number_format($row['avg_amount'], 2) . " average\n";
}
echo "\n";

// Example 4: MIN and MAX
echo "4. MIN/MAX - Price range by category...\n";
$priceRange = $db->find()
    ->from('sales')
    ->select([
        'category',
        'min_price' => Db::min('amount'),
        'max_price' => Db::max('amount')
    ])
    ->groupBy('category')
    ->get();

foreach ($priceRange as $row) {
    echo "  • {$row['category']}: \$" . number_format($row['min_price'], 2) . " - \$" . number_format($row['max_price'], 2) . "\n";
}
echo "\n";

// Example 5: Multiple aggregates
echo "5. Complete statistics by category...\n";
$stats = $db->find()
    ->from('sales')
    ->select([
        'category',
        'total_sales' => Db::count(),
        'total_quantity' => Db::sum('quantity'),
        'total_revenue' => Db::sum('amount'),
        'avg_sale' => Db::avg('amount'),
        'min_sale' => Db::min('amount'),
        'max_sale' => Db::max('amount')
    ])
    ->groupBy('category')
    ->get();

foreach ($stats as $row) {
    echo "  {$row['category']}:\n";
    echo "    Sales: {$row['total_sales']}\n";
    echo "    Units: {$row['total_quantity']}\n";
    echo "    Revenue: \$" . number_format($row['total_revenue'], 2) . "\n";
    echo "    Average: \$" . number_format($row['avg_sale'], 2) . "\n";
    echo "    Range: \$" . number_format($row['min_sale'], 2) . " - \$" . number_format($row['max_sale'], 2) . "\n\n";
}

// Example 6: HAVING clause
echo "6. HAVING - Categories with total revenue > $1000...\n";
$highRevenue = $db->find()
    ->from('sales')
    ->select([
        'category',
        'total_revenue' => Db::sum('amount')
    ])
    ->groupBy('category')
    ->having(Db::sum('amount'), 1000, '>')
    ->get();

foreach ($highRevenue as $row) {
    echo "  • {$row['category']}: \$" . number_format($row['total_revenue'], 2) . "\n";
}
echo "\n";

// Example 7: Multiple GROUP BY columns
echo "7. GROUP BY multiple columns (category + region)...\n";
$detailed = $db->find()
    ->from('sales')
    ->select([
        'category',
        'region',
        'sales' => Db::count(),
        'revenue' => Db::sum('amount')
    ])
    ->groupBy(['category', 'region'])
    ->orderBy('category')
    ->orderBy('region')
    ->get();

foreach ($detailed as $row) {
    echo "  • {$row['category']} ({$row['region']}): {$row['sales']} sales, \$" . number_format($row['revenue'], 2) . "\n";
}

echo "\nAggregations example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use aggregate functions: COUNT, SUM, AVG, MIN, MAX\n";
echo "  • GROUP BY groups rows by column values\n";
echo "  • HAVING filters grouped results (like WHERE for groups)\n";
echo "  • Can group by multiple columns\n";

