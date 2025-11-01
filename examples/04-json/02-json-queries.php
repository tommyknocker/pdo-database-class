<?php
/**
 * Example: JSON Queries
 * 
 * Advanced JSON querying patterns and techniques
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== JSON Queries Example (on $driver) ===\n\n";

// Setup
$driver = getCurrentDriver($db);
recreateTable($db, 'products', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT',
    'specs' => $driver === 'pgsql' ? 'JSONB' : 'TEXT',
    'tags' => $driver === 'pgsql' ? 'JSONB' : 'TEXT'
]);

echo "1. Inserting products with nested JSON...\n";
$db->find()->table('products')->insertMulti([
    [
        'name' => 'Gaming Laptop',
        'specs' => Db::jsonObject([
            'cpu' => 'Intel i7',
            'ram' => 16,
            'storage' => ['type' => 'SSD', 'size' => 512],
            'gpu' => 'RTX 3060'
        ]),
        'tags' => Db::jsonArray('laptop', 'gaming', 'portable')
    ],
    [
        'name' => 'Workstation',
        'specs' => Db::jsonObject([
            'cpu' => 'AMD Ryzen 9',
            'ram' => 32,
            'storage' => ['type' => 'SSD', 'size' => 1024],
            'gpu' => 'RTX 4080'
        ]),
        'tags' => Db::jsonArray('desktop', 'workstation', 'powerful')
    ],
    [
        'name' => 'Ultrabook',
        'specs' => Db::jsonObject([
            'cpu' => 'Intel i5',
            'ram' => 8,
            'storage' => ['type' => 'SSD', 'size' => 256],
            'gpu' => 'Integrated'
        ]),
        'tags' => Db::jsonArray('laptop', 'portable', 'lightweight')
    ],
]);

echo "✓ Inserted 3 products\n\n";

// Example 2: Query by nested JSON value
echo "2. Finding products with 16GB+ RAM...\n";
$highRam = $db->find()
    ->from('products')
    ->select(['name', 'ram' => Db::jsonGet('specs', ['ram'])])
    ->where(Db::jsonPath('specs', ['ram'], '>=', 16))
    ->get();

foreach ($highRam as $p) {
    echo "  • {$p['name']}: {$p['ram']}GB RAM\n";
}
echo "\n";

// Example 3: JSON array contains single value
echo "3. Finding laptops (contains 'laptop' tag)...\n";
$laptops = $db->find()
    ->from('products')
    ->select(['name'])
    ->where(Db::jsonContains('tags', 'laptop'))
    ->get();

foreach ($laptops as $p) {
    echo "  • {$p['name']}\n";
}
echo "\n";

// Example 4: JSON array contains multiple values (subset)
echo "4. Finding portable laptops (must have both tags)...\n";
$portable = $db->find()
    ->from('products')
    ->select(['name'])
    ->where(Db::jsonContains('tags', ['laptop', 'portable']))
    ->get();

foreach ($portable as $p) {
    echo "  • {$p['name']}\n";
}
echo "\n";

// Example 5: Check if JSON path exists
echo "5. Finding products with GPU specified...\n";
$withGpu = $db->find()
    ->from('products')
    ->select(['name', 'gpu' => Db::jsonGet('specs', ['gpu'])])
    ->where(Db::jsonExists('specs', ['gpu']))
    ->get();

foreach ($withGpu as $p) {
    echo "  • {$p['name']}: {$p['gpu']}\n";
}
echo "\n";

// Example 6: JSON length queries
echo "6. Finding products with many tags...\n";
$manyTags = $db->find()
    ->from('products')
    ->select([
        'name',
        'tag_count' => Db::jsonLength('tags')
    ])
    ->where(Db::jsonLength('tags'), 2, '>')
    ->get();

foreach ($manyTags as $p) {
    echo "  • {$p['name']}: {$p['tag_count']} tags\n";
}
echo "\n";

// Example 7: Order by JSON value
echo "7. Products ordered by RAM (descending)...\n";
$sorted = $db->find()
    ->from('products')
    ->select([
        'name',
        'ram' => Db::jsonGet('specs', ['ram'])
    ])
    ->orderBy(Db::jsonGet('specs', ['ram']), 'DESC')
    ->get();

foreach ($sorted as $p) {
    echo "  • {$p['name']}: {$p['ram']}GB\n";
}
echo "\n";

// Example 8: Complex JSON queries
echo "8. Complex query: Gaming products with SSD and 16GB+ RAM...\n";
$gaming = $db->find()
    ->from('products')
    ->select([
        'name',
        'cpu' => Db::jsonGet('specs', ['cpu']),
        'ram' => Db::jsonGet('specs', ['ram'])
    ])
    ->where(Db::jsonContains('tags', 'gaming'))
    ->andWhere(Db::jsonPath('specs', ['ram'], '>=', 16))
    ->get();

if ($gaming) {
    foreach ($gaming as $p) {
        echo "  • {$p['name']}: {$p['cpu']}, {$p['ram']}GB RAM\n";
    }
} else {
    echo "  No matching products found\n";
}
echo "\n";

// Example 9: Get JSON type
echo "9. Checking JSON value types...\n";
$products = $db->find()
    ->from('products')
    ->select([
        'name',
        'tags_type' => Db::jsonType('tags'),
        'specs_type' => Db::jsonType('specs')
    ])
    ->limit(1)
    ->getOne();

echo "  • {$products['name']}:\n";
echo "    tags type: {$products['tags_type']}\n";
echo "    specs type: {$products['specs_type']}\n";

echo "\nJSON queries example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • JSON queries work identically across MySQL, MariaDB, PostgreSQL, SQLite\n";
echo "  • Use jsonPath() for comparisons\n";
echo "  • Use jsonContains() for array membership\n";
echo "  • Combine JSON queries with regular SQL conditions\n";

