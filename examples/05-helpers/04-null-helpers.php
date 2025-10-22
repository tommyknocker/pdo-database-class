<?php
/**
 * Example: NULL Handling Helper Functions
 * 
 * Demonstrates NULL handling and coalescing
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== NULL Handling Helpers Example (on $driver) ===\n\n";

// Setup
recreateTable($db, 'users', ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'name' => 'TEXT', 'email' => 'TEXT', 'phone' => 'TEXT', 'address' => 'TEXT', 'bio' => 'TEXT']);

$db->find()->table('users')->insertMulti([
    ['name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '555-0001', 'address' => '123 Main St', 'bio' => 'Developer'],
    ['name' => 'Bob', 'email' => 'bob@example.com', 'phone' => null, 'address' => null, 'bio' => null],
    ['name' => 'Carol', 'email' => null, 'phone' => '555-0003', 'address' => '789 Oak Ave', 'bio' => null],
    ['name' => 'Dave', 'email' => null, 'phone' => null, 'address' => null, 'bio' => null],
]);

echo "✓ Inserted users with some NULL values\n\n";

// Example 1: IS NULL / IS NOT NULL filters
echo "1. Finding users without email...\n";
$noEmail = $db->find()
    ->from('users')
    ->select(['name', 'email'])
    ->where(Db::isNull('email'))
    ->get();

foreach ($noEmail as $user) {
    echo "  • {$user['name']}: email is NULL\n";
}
echo "\n";

echo "2. Finding users with phone...\n";
$withPhone = $db->find()
    ->from('users')
    ->select(['name', 'phone'])
    ->where(Db::isNotNull('phone'))
    ->get();

foreach ($withPhone as $user) {
    echo "  • {$user['name']}: {$user['phone']}\n";
}
echo "\n";

// Example 3: IFNULL - Replace NULL with default
echo "3. IFNULL - Replace NULL with default value...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'phone' => Db::ifNull('phone', 'N/A'),
        'bio' => Db::ifNull('bio', 'No bio available')
    ])
    ->get();

foreach ($users as $user) {
    echo "  • {$user['name']}\n";
    echo "    Phone: {$user['phone']}\n";
    echo "    Bio: {$user['bio']}\n";
}
echo "\n";

// Example 4: COALESCE - First non-NULL value
echo "4. COALESCE - First available contact method...\n";
$contacts = $db->find()
    ->from('users')
    ->select([
        'name',
        'contact' => Db::coalesce('phone', 'email', "'No contact'")
    ])
    ->get();

foreach ($contacts as $user) {
    echo "  • {$user['name']}: {$user['contact']}\n";
}
echo "\n";

// Example 5: NULLIF - Return NULL if equal
echo "5. NULLIF - Convert empty strings to NULL...\n";
$db->rawQuery("INSERT INTO users (name, email, phone) VALUES ('Eve', '', '555-0005')");

$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'email',
        'clean_email' => Db::nullIf('email', "''")
    ])
    ->where('name', 'Eve')
    ->getOne();

echo "  • {$users['name']}:\n";
echo "    Original email: '{$users['email']}'\n";
echo "    Clean email: " . ($users['clean_email'] ?? 'NULL') . "\n\n";

// Example 6: Combining NULL helpers with updates
echo "6. UPDATE with NULL handling...\n";
$db->find()
    ->table('users')
    ->where(Db::isNull('bio'))
    ->update([
        'bio' => 'Updated by system'
    ]);

$updated = $db->find()
    ->from('users')
    ->where('bio', 'Updated by system')
    ->get();

echo "✓ Updated " . count($updated) . " users with NULL bio\n\n";

// Example 7: Complex COALESCE in ORDER BY
echo "7. Sorting with COALESCE fallback...\n";
$sorted = $db->find()
    ->from('users')
    ->select([
        'name',
        'contact_priority' => Db::coalesce('phone', 'email', "'zzz'")
    ])
    ->orderBy(Db::coalesce('phone', 'email', "'zzz'"))
    ->get();

echo "  Users sorted by contact availability:\n";
foreach ($sorted as $user) {
    echo "  • {$user['name']}\n";
}
echo "\n";

// Example 8: NULL statistics
echo "8. NULL statistics report...\n";
$stats = $db->rawQuery("
    SELECT 
        SUM(CASE WHEN email IS NULL THEN 1 ELSE 0 END) as missing_email,
        SUM(CASE WHEN phone IS NULL THEN 1 ELSE 0 END) as missing_phone,
        SUM(CASE WHEN address IS NULL THEN 1 ELSE 0 END) as missing_address,
        SUM(CASE WHEN bio IS NULL THEN 1 ELSE 0 END) as missing_bio,
        COUNT(*) as total
    FROM users
")[0];

echo "  Data completeness:\n";
echo "  • Total users: {$stats['total']}\n";
echo "  • Missing email: {$stats['missing_email']}\n";
echo "  • Missing phone: {$stats['missing_phone']}\n";
echo "  • Missing address: {$stats['missing_address']}\n";
echo "  • Missing bio: {$stats['missing_bio']}\n";

echo "\nNULL handling helpers example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use isNull/isNotNull for filtering\n";
echo "  • Use ifNull for simple default values\n";
echo "  • Use coalesce for fallback chain (first non-NULL)\n";
echo "  • Use nullIf to convert values to NULL\n";

