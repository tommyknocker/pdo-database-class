<?php
/**
 * Real-World Example: Advanced Search & Filters
 * 
 * Demonstrates building complex search functionality with multiple filters,
 * facets, sorting, and pagination - like e-commerce product search
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Advanced Search & Filters Example (on $driver) ===\n\n";

// Create schema
echo "Setting up product catalog database...\n";

recreateTable($db, 'products', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT NOT NULL',
    'description' => 'TEXT',
    'category' => 'TEXT NOT NULL',
    'brand' => 'TEXT',
    'price' => 'REAL NOT NULL',
    'stock' => 'INTEGER DEFAULT 0',
    'rating' => 'REAL DEFAULT 0',
    'review_count' => 'INTEGER DEFAULT 0',
    'tags' => 'TEXT',
    'specs' => 'TEXT',
    'is_featured' => 'INTEGER DEFAULT 0',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

recreateTable($db, 'reviews', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'product_id' => 'INTEGER NOT NULL',
    'user_name' => 'TEXT NOT NULL',
    'rating' => 'INTEGER NOT NULL',
    'comment' => 'TEXT',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

echo "✓ Schema created\n\n";

// Scenario 1: Populate sample data
echo "1. Populating product catalog...\n";

$products = [
    ['name' => 'iPhone 15 Pro', 'category' => 'Electronics', 'brand' => 'Apple', 'price' => 999.99, 'stock' => 50, 'rating' => 4.8, 'review_count' => 230, 'tags' => 'smartphone,5g,premium', 'specs' => '{"storage":"256GB","color":"titanium"}', 'is_featured' => 1],
    ['name' => 'Samsung Galaxy S24', 'category' => 'Electronics', 'brand' => 'Samsung', 'price' => 899.99, 'stock' => 75, 'rating' => 4.7, 'review_count' => 180, 'tags' => 'smartphone,5g,android', 'specs' => '{"storage":"128GB","color":"black"}', 'is_featured' => 1],
    ['name' => 'MacBook Air M3', 'category' => 'Electronics', 'brand' => 'Apple', 'price' => 1299.99, 'stock' => 30, 'rating' => 4.9, 'review_count' => 150, 'tags' => 'laptop,ultrabook,macos', 'specs' => '{"ram":"16GB","storage":"512GB"}', 'is_featured' => 1],
    ['name' => 'Dell XPS 13', 'category' => 'Electronics', 'brand' => 'Dell', 'price' => 1199.99, 'stock' => 25, 'rating' => 4.6, 'review_count' => 95, 'tags' => 'laptop,ultrabook,windows', 'specs' => '{"ram":"16GB","storage":"512GB"}', 'is_featured' => 0],
    ['name' => 'Sony WH-1000XM5', 'category' => 'Audio', 'brand' => 'Sony', 'price' => 399.99, 'stock' => 100, 'rating' => 4.8, 'review_count' => 320, 'tags' => 'headphones,noise-cancelling,wireless', 'specs' => '{"type":"over-ear","battery":"30h"}', 'is_featured' => 1],
    ['name' => 'AirPods Pro 2', 'category' => 'Audio', 'brand' => 'Apple', 'price' => 249.99, 'stock' => 150, 'rating' => 4.7, 'review_count' => 410, 'tags' => 'earbuds,noise-cancelling,wireless', 'specs' => '{"type":"in-ear","battery":"6h"}', 'is_featured' => 0],
    ['name' => 'Bose QuietComfort Ultra', 'category' => 'Audio', 'brand' => 'Bose', 'price' => 429.99, 'stock' => 60, 'rating' => 4.6, 'review_count' => 145, 'tags' => 'headphones,noise-cancelling,premium', 'specs' => '{"type":"over-ear","battery":"24h"}', 'is_featured' => 0],
    ['name' => 'Canon EOS R6', 'category' => 'Cameras', 'brand' => 'Canon', 'price' => 2499.99, 'stock' => 15, 'rating' => 4.9, 'review_count' => 78, 'tags' => 'camera,mirrorless,professional', 'specs' => '{"sensor":"full-frame","megapixels":"20MP"}', 'is_featured' => 1],
    ['name' => 'Sony A7 IV', 'category' => 'Cameras', 'brand' => 'Sony', 'price' => 2499.99, 'stock' => 20, 'rating' => 4.9, 'review_count' => 92, 'tags' => 'camera,mirrorless,video', 'specs' => '{"sensor":"full-frame","megapixels":"33MP"}', 'is_featured' => 1],
    ['name' => 'GoPro Hero 12', 'category' => 'Cameras', 'brand' => 'GoPro', 'price' => 399.99, 'stock' => 80, 'rating' => 4.5, 'review_count' => 210, 'tags' => 'camera,action,waterproof', 'specs' => '{"resolution":"5.3K","waterproof":"10m"}', 'is_featured' => 0],
    ['name' => 'Logitech MX Master 3S', 'category' => 'Accessories', 'brand' => 'Logitech', 'price' => 99.99, 'stock' => 200, 'rating' => 4.8, 'review_count' => 560, 'tags' => 'mouse,wireless,productivity', 'specs' => '{"dpi":"8000","battery":"70days"}', 'is_featured' => 0],
    ['name' => 'iPad Pro 12.9"', 'category' => 'Electronics', 'brand' => 'Apple', 'price' => 1099.99, 'stock' => 40, 'rating' => 4.8, 'review_count' => 175, 'tags' => 'tablet,ios,stylus', 'specs' => '{"storage":"256GB","display":"liquid-retina"}', 'is_featured' => 1],
];

$db->find()->table('products')->insertMulti($products);

echo "✓ Inserted " . count($products) . " products\n\n";

// Scenario 2: Simple text search
echo "2. Text search: 'Pro'\n";

$searchTerm = '%Pro%';
$searchResults = $db->rawQuery(
    "SELECT name, brand, price, rating FROM products 
     WHERE name LIKE :search1 OR description LIKE :search2 
     ORDER BY rating DESC",
    ['search1' => $searchTerm, 'search2' => $searchTerm]
);

echo "  Found " . count($searchResults) . " products:\n";
foreach ($searchResults as $product) {
    echo "  • {$product['name']} ({$product['brand']}) - \${$product['price']} ⭐{$product['rating']}\n";
}
echo "\n";

// Scenario 3: Multi-filter search with builder pattern
echo "3. Advanced filters: Electronics, Apple, \$500-1500, rating > 4.5\n";

function buildSearchQuery($db, $filters) {
    $query = $db->find()->from('products');
    
    // Category filter
    if (!empty($filters['category'])) {
        $query->where('category', $filters['category']);
    }
    
    // Brand filter
    if (!empty($filters['brand'])) {
        $query->where('brand', $filters['brand']);
    }
    
    // Price range
    if (!empty($filters['price_min'])) {
        $query->where('price', $filters['price_min'], '>=');
    }
    if (!empty($filters['price_max'])) {
        $query->where('price', $filters['price_max'], '<=');
    }
    
    // Rating filter
    if (!empty($filters['min_rating'])) {
        $query->where('rating', $filters['min_rating'], '>=');
    }
    
    // In stock only
    if (!empty($filters['in_stock'])) {
        $query->where('stock', 0, '>');
    }
    
    // Featured products
    if (!empty($filters['featured'])) {
        $query->where('is_featured', 1);
    }
    
    // Tag search
    if (!empty($filters['tags'])) {
        foreach ($filters['tags'] as $tag) {
            $query->where('tags', "%$tag%", 'LIKE');
        }
    }
    
    // Sort
    $sortField = $filters['sort'] ?? 'rating';
    $sortDir = $filters['sort_dir'] ?? 'DESC';
    $query->orderBy($sortField, $sortDir);
    
    // Pagination
    $page = $filters['page'] ?? 1;
    $perPage = $filters['per_page'] ?? 10;
    $query->limit($perPage)->offset(($page - 1) * $perPage);
    
    return $query;
}

$filters = [
    'category' => 'Electronics',
    'brand' => 'Apple',
    'price_min' => 500,
    'price_max' => 1500,
    'min_rating' => 4.5,
    'in_stock' => true,
    'sort' => 'price',
    'sort_dir' => 'ASC',
    'page' => 1,
    'per_page' => 5
];

$filtered = buildSearchQuery($db, $filters)->get();

echo "  Results: " . count($filtered) . " products\n";
foreach ($filtered as $product) {
    echo "  • {$product['name']} - \${$product['price']} ⭐{$product['rating']} ({$product['stock']} in stock)\n";
}
echo "\n";

// Scenario 4: Faceted search - count by category
echo "4. Faceted search: Product count by category\n";

$facets = $db->find()
    ->from('products')
    ->select([
        'category',
        'count' => Db::count(),
        'avg_price' => Db::avg('price'),
        'min_price' => Db::min('price'),
        'max_price' => Db::max('price')
    ])
    ->groupBy('category')
    ->orderBy(Db::count(), 'DESC')
    ->get();

echo "  Category facets:\n";
foreach ($facets as $facet) {
    $avgPrice = number_format($facet['avg_price'], 2);
    $minPrice = number_format($facet['min_price'], 2);
    $maxPrice = number_format($facet['max_price'], 2);
    echo "  • {$facet['category']}: {$facet['count']} products, avg \${$avgPrice} (range: \${$minPrice}-\${$maxPrice})\n";
}
echo "\n";

// Scenario 5: Brand facets with stock availability
echo "5. Brand availability:\n";

$brandStats = $db->find()
    ->from('products')
    ->select([
        'brand',
        'total_products' => Db::count(),
        'in_stock_count' => Db::sum(Db::case(['stock > 0' => '1'], '0')),
        'total_stock' => Db::sum('stock'),
        'avg_rating' => Db::avg('rating')
    ])
    ->groupBy('brand')
    ->orderBy('total_products', 'DESC')
    ->get();

echo "  Brand statistics:\n";
foreach ($brandStats as $brand) {
    $avgRating = number_format($brand['avg_rating'], 2);
    echo "  • {$brand['brand']}: {$brand['total_products']} products, {$brand['in_stock_count']} in stock ({$brand['total_stock']} units), ⭐{$avgRating}\n";
}
echo "\n";

// Scenario 6: Price range facets
echo "6. Price range distribution:\n";

$priceRanges = $db->find()
    ->from('products')
    ->select([
        'range' => Db::raw("CASE 
            WHEN price < 100 THEN 'Under $100'
            WHEN price < 500 THEN '$100-$500'
            WHEN price < 1000 THEN '$500-$1000'
            WHEN price < 2000 THEN '$1000-$2000'
            ELSE 'Over $2000'
        END"),
        'count' => Db::count(),
        'avg_rating' => Db::avg('rating')
    ])
    ->groupBy(Db::raw("CASE 
        WHEN price < 100 THEN 'Under $100'
        WHEN price < 500 THEN '$100-$500'
        WHEN price < 1000 THEN '$500-$1000'
        WHEN price < 2000 THEN '$1000-$2000'
        ELSE 'Over $2000'
    END"))
    ->orderBy('count', 'DESC')
    ->get();

echo "  Price ranges:\n";
foreach ($priceRanges as $range) {
    $avgRating = number_format($range['avg_rating'], 2);
    echo "  • {$range['range']}: {$range['count']} products, ⭐{$avgRating}\n";
}
echo "\n";

// Scenario 7: Top rated products (with minimum reviews)
echo "7. Top rated products (minimum 100 reviews):\n";

$topRated = $db->find()
    ->from('products')
    ->where('review_count', 100, '>=')
    ->orderBy('rating', 'DESC')
    ->limit(5)
    ->get();

echo "  Top " . count($topRated) . " products:\n";
foreach ($topRated as $i => $product) {
    $rank = $i + 1;
    echo "  {$rank}. {$product['name']} - ⭐{$product['rating']} ({$product['review_count']} reviews)\n";
}
echo "\n";

// Scenario 8: Search with sorting options
echo "8. Available sorting options:\n";

$sortOptions = [
    ['field' => 'rating', 'dir' => 'DESC', 'label' => 'Highest Rated'],
    ['field' => 'price', 'dir' => 'ASC', 'label' => 'Price: Low to High'],
    ['field' => 'price', 'dir' => 'DESC', 'label' => 'Price: High to Low'],
    ['field' => 'review_count', 'dir' => 'DESC', 'label' => 'Most Reviewed'],
    ['field' => 'created_at', 'dir' => 'DESC', 'label' => 'Newest First'],
];

foreach ($sortOptions as $i => $option) {
    $results = $db->find()
        ->from('products')
        ->orderBy($option['field'], $option['dir'])
        ->limit(1)
        ->getOne();
    
    echo "  • {$option['label']}: {$results['name']} (\${$results['price']})\n";
}
echo "\n";

// Scenario 9: JSON specs filtering
echo "9. JSON specs filtering: Products with 16GB RAM\n";

$withRam = $db->find()
    ->from('products')
    ->where(Db::raw("specs LIKE '%\"ram\":\"16GB\"%'"))
    ->select(['name', 'brand', 'specs'])
    ->get();

echo "  Found " . count($withRam) . " products with 16GB RAM:\n";
foreach ($withRam as $product) {
    echo "  • {$product['name']} ({$product['brand']})\n";
}
echo "\n";

// Scenario 10: Autocomplete suggestions
echo "10. Autocomplete suggestions for 'so':\n";

$term = '%so%';
$suggestions = $db->rawQuery(
    "SELECT name AS suggestion, 'product' AS type, category 
     FROM products 
     WHERE LOWER(name) LIKE :term OR LOWER(brand) LIKE :term 
     LIMIT 5",
    ['term' => $term]
);

echo "  Suggestions:\n";
foreach ($suggestions as $sug) {
    echo "  • {$sug['suggestion']} (in {$sug['category']})\n";
}

echo "\n";

echo "Advanced search & filters example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Dynamic query building based on user filters\n";
echo "  • Faceted search with aggregations\n";
echo "  • Price range bucketing with CASE statements\n";
echo "  • Multiple sorting strategies\n";
echo "  • JSON field filtering\n";
echo "  • Autocomplete implementation\n";
echo "  • Pagination support\n";

