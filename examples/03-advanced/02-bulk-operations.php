<?php
/**
 * Example: Bulk Operations
 * 
 * Demonstrates efficient bulk inserts and data loading
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Bulk Operations Example (on $driver) ===\n\n";

// Setup
recreateTable($db, 'users', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT',
    'email' => 'TEXT',
    'age' => 'INTEGER',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

// Example 1: Single inserts (slow)
echo "1. Single inserts (NOT recommended for bulk)...\n";
$start = microtime(true);

for ($i = 1; $i <= 100; $i++) {
    $db->find()->table('users')->insert([
        'name' => "User $i",
        'email' => "user$i@example.com",
        'age' => 20 + ($i % 50)
    ]);
}

$elapsed = round((microtime(true) - $start) * 1000, 2);
echo "✗ Inserted 100 rows in {$elapsed}ms (SLOW)\n\n";

// Clear for comparison
$db->find()->table('users')->truncate();

// Example 2: Bulk insert (fast)
echo "2. Bulk insert with insertMulti (RECOMMENDED)...\n";
$users = [];
for ($i = 1; $i <= 100; $i++) {
    $users[] = [
        'name' => "User $i",
        'email' => "user$i@example.com",
        'age' => 20 + ($i % 50)
    ];
}

$start = microtime(true);
$inserted = $db->find()->table('users')->insertMulti($users);
$elapsed = round((microtime(true) - $start) * 1000, 2);

echo "✓ Inserted $inserted rows in {$elapsed}ms (FAST)\n";
echo "  Performance improvement: ~" . round(100 / $elapsed, 1) . "x faster\n\n";

// Example 3: Bulk insert in batches
echo "3. Bulk insert in batches (for very large datasets)...\n";
$db->find()->table('users')->truncate();

$totalUsers = 1000;
$batchSize = 100;
$batches = ceil($totalUsers / $batchSize);

$start = microtime(true);

for ($batch = 0; $batch < $batches; $batch++) {
    $users = [];
    for ($i = 0; $i < $batchSize; $i++) {
        $id = $batch * $batchSize + $i + 1;
        if ($id > $totalUsers) break;
        
        $users[] = [
            'name' => "User $id",
            'email' => "user$id@example.com",
            'age' => 20 + ($id % 50)
        ];
    }
    
    $db->find()->table('users')->insertMulti($users);
}

$elapsed = round((microtime(true) - $start) * 1000, 2);
$count = $db->find()->from('users')->select([Db::count()])->getValue();

echo "✓ Inserted $count rows in $batches batches ({$elapsed}ms)\n";
echo "  Batch size: $batchSize\n";
echo "  Average per batch: " . round($elapsed / $batches, 2) . "ms\n\n";

// Example 4: Bulk insert with transactions
echo "4. Bulk insert with transaction (even faster)...\n";
$db->find()->table('users')->truncate();

$users = [];
for ($i = 1; $i <= 500; $i++) {
    $users[] = [
        'name' => "User $i",
        'email' => "user$i@example.com",
        'age' => 20 + ($i % 50)
    ];
}

$start = microtime(true);
$db->startTransaction();
try {
    $inserted = $db->find()->table('users')->insertMulti($users);
    $db->commit();
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    
    echo "✓ Inserted $inserted rows in transaction ({$elapsed}ms)\n\n";
} catch (\Throwable $e) {
    $db->rollBack();
    echo "✗ Failed: {$e->getMessage()}\n\n";
}

// Example 5: Bulk update
echo "5. Bulk UPDATE with condition...\n";
$start = microtime(true);

$affected = $db->find()
    ->table('users')
    ->where('age', 50, '>=')
    ->update(['age' => Db::dec(10)]);

$elapsed = round((microtime(true) - $start) * 1000, 2);
echo "✓ Updated $affected rows in {$elapsed}ms\n\n";

// Example 6: Bulk delete
echo "6. Bulk DELETE with condition...\n";
$deleted = $db->find()
    ->table('users')
    ->where('age', 25, '<')
    ->delete();

$remaining = $db->find()->from('users')->select([Db::count()])->getValue();
echo "✓ Deleted $deleted rows, $remaining remaining\n\n";

// Example 7: Truncate (fastest way to clear table)
echo "7. TRUNCATE table...\n";
$start = microtime(true);
$db->find()->table('users')->truncate();
$elapsed = round((microtime(true) - $start) * 1000, 2);

$count = $db->find()->from('users')->select([Db::count()])->getValue();
echo "✓ Table truncated in {$elapsed}ms, $count rows remaining\n";

echo "\nBulk operations example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use insertMulti() for bulk inserts (10-100x faster)\n";
echo "  • Batch large datasets (e.g., 100-1000 rows per batch)\n";
echo "  • Wrap in transactions for atomic operations and speed\n";
echo "  • Use TRUNCATE to quickly clear tables\n";

