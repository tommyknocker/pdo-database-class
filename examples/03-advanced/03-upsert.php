<?php
/**
 * Example: UPSERT Operations
 * 
 * Demonstrates INSERT or UPDATE (UPSERT) across different databases
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== UPSERT Operations Example (on $driver) ===\n\n";

// MSSQL and Oracle don't support INSERT ... ON DUPLICATE KEY UPDATE, so we skip this example
if ($driver === 'sqlsrv' || $driver === 'oci') {
    echo "⚠ Note: {$driver} uses MERGE statement for UPSERT operations, which requires a different syntax.\n";
    echo "   This example demonstrates INSERT ... ON DUPLICATE KEY UPDATE syntax used by MySQL, PostgreSQL, and SQLite.\n";
    echo "   For {$driver}, use the QueryBuilder::merge() method instead.\n\n";
    echo "Example completed (skipped for {$driver} - use merge() method for UPSERT operations)\n";
    exit(0);
}

// Setup
recreateTable($db, 'user_stats', [
    'user_id' => 'INTEGER PRIMARY KEY',  // Not AUTOINCREMENT - user_id is set manually
    'login_count' => 'INTEGER DEFAULT 0',
    'last_login' => 'DATETIME',
    'total_points' => 'INTEGER DEFAULT 0'
]);

echo "✓ Table created\n\n";

// Example 1: First UPSERT (will INSERT)
echo "1. First UPSERT for user 1 (will INSERT)...\n";
if ($driver === 'sqlsrv') {
    // MSSQL uses MERGE instead of INSERT ... ON DUPLICATE KEY UPDATE
    $db->find()->table('user_stats')
        ->merge(
            function ($q) {
                $q->select([Db::raw('1 as user_id'), Db::raw('1 as login_count')]);
            },
            'user_stats.user_id = source.user_id',
            ['login_count' => Db::raw('user_stats.login_count + 1')],
            ['user_id' => Db::raw('source.user_id'), 'login_count' => Db::raw('source.login_count')]
        );
} else {
    $db->find()->table('user_stats')
        ->onDuplicate([
            'login_count' => Db::inc(1)
        ])
        ->insert([
            'user_id' => 1,
            'login_count' => 1
        ]);
}

$stats = $db->find()->from('user_stats')->where('user_id', 1)->getOne();
echo "  ✓ User 1 stats: login_count={$stats['login_count']}, points={$stats['total_points']}\n\n";

// Example 2: Second UPSERT (will UPDATE)
echo "2. Second UPSERT for user 1 (will UPDATE)...\n";
$db->find()->table('user_stats')
    ->onDuplicate([
        'login_count' => Db::inc(1)
    ])
    ->insert([
        'user_id' => 1,
        'login_count' => 1
    ]);

$stats = $db->find()->from('user_stats')->where('user_id', 1)->getOne();
echo "  ✓ User 1 stats: login_count={$stats['login_count']} (incremented!)\n\n";

// Example 3: UPSERT with multiple field updates
echo "3. UPSERT with multiple field updates...\n";
$db->find()->table('user_stats')
    ->onDuplicate([
        'login_count' => Db::inc(1),
        'total_points' => Db::inc(100)
    ])
    ->insert([
        'user_id' => 1,
        'login_count' => 1,
        'total_points' => 50
    ]);

$stats = $db->find()->from('user_stats')->where('user_id', 1)->getOne();
echo "  ✓ User 1 stats: login_count={$stats['login_count']}, points={$stats['total_points']}\n\n";

// Example 4: Multiple users UPSERT
echo "4. UPSERT for multiple users...\n";
for ($userId = 2; $userId <= 5; $userId++) {
    $db->find()->table('user_stats')
        ->onDuplicate([
            'login_count' => Db::inc(1),
            'total_points' => Db::inc(10)
        ])
        ->insert([
            'user_id' => $userId,
            'login_count' => 1,
            'total_points' => 10
        ]);
}

$count = $db->find()->from('user_stats')->select([Db::count()])->getValue();
echo "  ✓ Total users in stats table: $count\n\n";

// Example 5: Simulate daily login tracking
echo "5. Simulating daily logins...\n";
$userIds = [1, 2, 1, 3, 1, 4, 2];

foreach ($userIds as $userId) {
    $db->find()->table('user_stats')
        ->onDuplicate([
            'login_count' => Db::inc(1)
        ])
        ->insert([
            'user_id' => $userId,
            'login_count' => 1
        ]);
}

echo "  ✓ Processed " . count($userIds) . " login events\n\n";

// Show final stats
echo "6. Final statistics:\n";
$allStats = $db->find()
    ->from('user_stats')
    ->select(['user_id', 'login_count', 'total_points'])
    ->orderBy('login_count', 'DESC')
    ->get();

echo "  User login statistics:\n";
foreach ($allStats as $stat) {
    echo "  • User {$stat['user_id']}: {$stat['login_count']} logins, {$stat['total_points']} points\n";
}
echo "\n";

// Example 7: UPSERT with conditional logic
echo "7. UPSERT with bonus points for frequent users...\n";
$db->find()->table('user_stats')
    ->onDuplicate([
        'login_count' => Db::inc(1),
        'total_points' => Db::case([
            'user_stats.login_count >= 3' => 'user_stats.total_points + 50'
        ], 'user_stats.total_points + 10')
    ])
    ->insert([
        'user_id' => 1,
        'login_count' => 1,
        'total_points' => 10
    ]);

$stats = $db->find()->from('user_stats')->where('user_id', 1)->getOne();
echo "  ✓ User 1 (frequent user): {$stats['total_points']} points (bonus applied!)\n";

echo "\nUPSERT operations example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • UPSERT is portable across MySQL, PostgreSQL, and SQLite\n";
echo "  • Use onDuplicate() to specify update behavior\n";
echo "  • Perfect for idempotent operations and stats tracking\n";

