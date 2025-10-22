<?php
/**
 * Example: Pagination
 * 
 * Demonstrates LIMIT and OFFSET for paginated results
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Pagination Example (on $driver) ===\n\n";

// Setup with 50 users
recreateTable($db, 'users', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT',
    'email' => 'TEXT',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

echo "1. Inserting 50 users...\n";
$users = [];
for ($i = 1; $i <= 50; $i++) {
    $users[] = [
        'name' => "User $i",
        'email' => "user$i@example.com"
    ];
}
$db->find()->table('users')->insertMulti($users);
echo "✓ Inserted 50 users\n\n";

// Example 2: Simple pagination
echo "2. Paginating results (10 per page)...\n";
$perPage = 10;
$page = 1;

for ($page = 1; $page <= 3; $page++) {
    $offset = ($page - 1) * $perPage;
    
    $results = $db->find()
        ->from('users')
        ->select(['id', 'name', 'email'])
        ->orderBy('id')
        ->limit($perPage)
        ->offset($offset)
        ->get();
    
    echo "  Page $page:\n";
    foreach ($results as $user) {
        echo "    • #{$user['id']}: {$user['name']}\n";
    }
    echo "\n";
}

// Example 3: Calculate total pages
echo "3. Pagination metadata...\n";
$totalUsers = $db->find()->from('users')->select([Db::count()])->getValue();
$totalPages = ceil($totalUsers / $perPage);

echo "  Total users: $totalUsers\n";
echo "  Per page: $perPage\n";
echo "  Total pages: $totalPages\n\n";

// Example 4: Pagination function
function getPaginatedResults($db, $page, $perPage = 10) {
    $offset = ($page - 1) * $perPage;
    
    $results = $db->find()
        ->from('users')
        ->select(['id', 'name', 'email'])
        ->orderBy('id')
        ->limit($perPage)
        ->offset($offset)
        ->get();
    
    $total = $db->find()->from('users')->select([Db::count()])->getValue();
    
    return [
        'data' => $results,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => ceil($total / $perPage),
            'has_next' => $page < ceil($total / $perPage),
            'has_prev' => $page > 1
        ]
    ];
}

echo "4. Using pagination function...\n";
$result = getPaginatedResults($db, 3, 10);

echo "  Page {$result['pagination']['current_page']} of {$result['pagination']['total_pages']}\n";
echo "  Showing: " . count($result['data']) . " users\n";
echo "  Has next: " . ($result['pagination']['has_next'] ? 'Yes' : 'No') . "\n";
echo "  Has previous: " . ($result['pagination']['has_prev'] ? 'Yes' : 'No') . "\n";
echo "  Users on this page: " . implode(', ', array_column($result['data'], 'name')) . "\n\n";

// Example 5: Last page
echo "5. Jumping to last page...\n";
$lastPage = $totalPages;
$result = getPaginatedResults($db, $lastPage, 10);

echo "  Last page ({$result['pagination']['current_page']}):\n";
foreach ($result['data'] as $user) {
    echo "  • {$user['name']}\n";
}
echo "  Total on last page: " . count($result['data']) . "\n";

echo "\nPagination example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Always use LIMIT and OFFSET together\n";
echo "  • Calculate total pages: ceil(total / perPage)\n";
echo "  • Offset = (page - 1) * perPage\n";
echo "  • Include ORDER BY for consistent results\n";

