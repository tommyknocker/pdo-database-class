<?php
/**
 * Example: UPSERT Operations
 * 
 * Demonstrates INSERT or UPDATE (UPSERT) across different databases
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = new PdoDb('sqlite', ['path' => ':memory:']);

echo "=== UPSERT Operations Example ===\n\n";

// Setup
$db->rawQuery("
    CREATE TABLE user_stats (
        user_id INTEGER PRIMARY KEY,
        login_count INTEGER DEFAULT 0,
        last_login DATETIME,
        total_points INTEGER DEFAULT 0
    )
");

echo "✓ Table created\n\n";

// Example 1: First UPSERT (will INSERT)
echo "1. First UPSERT for user 1 (will INSERT)...\n";
$db->find()->table('user_stats')
    ->onDuplicate([
        'login_count' => Db::inc(),
        'last_login' => Db::now()
    ])
    ->insert([
        'user_id' => 1,
        'login_count' => 1,
        'last_login' => Db::now()
    ]);

$stats = $db->find()->from('user_stats')->where('user_id', 1)->getOne();
echo "  ✓ User 1 stats: login_count={$stats['login_count']}, points={$stats['total_points']}\n\n";

// Example 2: Second UPSERT (will UPDATE)
echo "2. Second UPSERT for user 1 (will UPDATE)...\n";
$db->find()->table('user_stats')
    ->onDuplicate([
        'login_count' => Db::inc(),
        'last_login' => Db::now()
    ])
    ->insert([
        'user_id' => 1,
        'login_count' => 1,
        'last_login' => Db::now()
    ]);

$stats = $db->find()->from('user_stats')->where('user_id', 1)->getOne();
echo "  ✓ User 1 stats: login_count={$stats['login_count']} (incremented!)\n\n";

// Example 3: UPSERT with complex update
echo "3. UPSERT with multiple field updates...\n";
$db->find()->table('user_stats')
    ->onDuplicate([
        'login_count' => Db::inc(),
        'total_points' => Db::raw('total_points + :points', ['points' => 100]),
        'last_login' => Db::now()
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
            'login_count' => Db::inc(),
            'total_points' => Db::raw('total_points + 10'),
            'last_login' => Db::now()
        ])
        ->insert([
            'user_id' => $userId,
            'login_count' => 1,
            'total_points' => 10
        ]);
}

$count = $db->rawQueryValue('SELECT COUNT(*) FROM user_stats');
echo "  ✓ Total users in stats table: $count\n\n";

// Example 5: Simulate daily login tracking
echo "5. Simulating daily logins...\n";
$userIds = [1, 2, 1, 3, 1, 4, 2];

foreach ($userIds as $userId) {
    $db->find()->table('user_stats')
        ->onDuplicate([
            'login_count' => Db::inc(),
            'last_login' => Db::now()
        ])
        ->insert([
            'user_id' => $userId,
            'login_count' => 1,
            'last_login' => Db::now()
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
        'login_count' => Db::inc(),
        'total_points' => Db::raw('CASE WHEN login_count >= 3 THEN total_points + 50 ELSE total_points + 10 END'),
        'last_login' => Db::now()
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

