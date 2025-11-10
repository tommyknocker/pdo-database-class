<?php
/**
 * Example: Aggregations and GROUP BY
 * 
 * Demonstrates GROUP BY, HAVING, and aggregate functions
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Aggregations Example (on $driver) ===\n\n";

// Setup
$schema = $db->schema();
recreateTable($db, 'sales', [
    'id' => $schema->primaryKey(),
    'product' => $schema->text(),
    'category' => $schema->text(),
    'amount' => $schema->decimal(10, 2),
    'quantity' => $schema->integer(),
    'region' => $schema->text(),
    'sale_date' => $schema->date(),
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
echo "\n";

// Example 7b: GROUP_CONCAT / STRING_AGG
echo "7b. GROUP_CONCAT / STRING_AGG - Products by category...\n";
$concat = $db->find()
    ->from('sales')
    ->select([
        'category',
        // SQLite DISTINCT in GROUP_CONCAT may not be available in older versions
        // MSSQL STRING_AGG DISTINCT requires SQL Server 2022+, so we skip DISTINCT for compatibility
        'products' => ($driver === 'sqlite' || $driver === 'sqlsrv')
            ? Db::groupConcat('product', ', ', false)
            : Db::groupConcat('product', ', ', true)
    ])
    ->groupBy('category')
    ->orderBy('category')
    ->get();

foreach ($concat as $row) {
    echo "  • {$row['category']}: {$row['products']}\n";
}
echo "\n";

// Example 8: FILTER clause - Conditional aggregates
echo "8. FILTER clause - Separate aggregates for North and South...\n";
$filtered = $db->find()
    ->from('sales')
    ->select([
        'category',
        'total_sales' => Db::count('*'),
        'north_sales' => Db::count('*')->filter('region', 'North'),
        'south_sales' => Db::count('*')->filter('region', 'South'),
        'total_revenue' => Db::sum('amount'),
        'north_revenue' => Db::sum('amount')->filter('region', 'North'),
        'south_revenue' => Db::sum('amount')->filter('region', 'South'),
    ])
    ->groupBy('category')
    ->orderBy('category')
    ->get();

echo "  Category breakdown by region:\n";
foreach ($filtered as $row) {
    echo "  • {$row['category']}:\n";
    echo "    Total: {$row['total_sales']} sales, \$" . number_format($row['total_revenue'], 2) . "\n";
    echo "    North: {$row['north_sales']} sales, \$" . number_format($row['north_revenue'], 2) . "\n";
    echo "    South: {$row['south_sales']} sales, \$" . number_format($row['south_revenue'], 2) . "\n";
}
echo "\n";

// Example 9: FILTER with multiple conditions
echo "9. FILTER - High-value sales (> $200)...\n";
$highValue = $db->find()
    ->from('sales')
    ->select([
        'region',
        'all_sales' => Db::count('*'),
        'high_value_sales' => Db::count('*')->filter('amount', 200, '>'),
        'high_value_total' => Db::sum('amount')->filter('amount', 200, '>'),
    ])
    ->groupBy('region')
    ->orderBy('region')
    ->get();

foreach ($highValue as $row) {
    $highValueTotal = $row['high_value_total'] ?? 0;
    echo "  • {$row['region']}: {$row['high_value_sales']}/{$row['all_sales']} high-value sales, \$" . number_format($highValueTotal, 2) . "\n";
}

echo "\nAggregations example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use aggregate functions: COUNT, SUM, AVG, MIN, MAX\n";
echo "  • GROUP BY groups rows by column values\n";
echo "  • HAVING filters grouped results (like WHERE for groups)\n";
echo "  • Can group by multiple columns\n";
echo "  • FILTER clause allows conditional aggregation without subqueries\n";
echo "  • FILTER works with all aggregate functions (COUNT, SUM, AVG, etc.)\n";

