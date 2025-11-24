<?php
/**
 * Example: NULL Handling Helper Functions
 * 
 * Demonstrates NULL handling and coalescing
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== NULL Handling Helpers Example (on $driver) ===\n\n";

// Setup
$schema = $db->schema();
recreateTable($db, 'users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->text(),
    'email' => $schema->text(),
    'phone' => $schema->text(),
    'address' => $schema->text(),
    'bio' => $schema->text(),
]);

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
    $user = normalizeRowKeys($user);
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
    $user = normalizeRowKeys($user);
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
    $user = normalizeRowKeys($user);
    $user = normalizeRowKeys($user);
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
    $user = normalizeRowKeys($user);
    echo "  • {$user['name']}: {$user['contact']}\n";
}
echo "\n";

// Example 5: NULLIF - Return NULL if equal
echo "5. NULLIF - Convert empty strings to NULL...\n";
$db->find()->table('users')->insert(['name' => 'Eve', 'email' => '', 'phone' => '555-0005']);

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
    $user = normalizeRowKeys($user);
    echo "  • {$user['name']}\n";
}
echo "\n";

// Example 8: NULL statistics
echo "8. NULL statistics report...\n";
$stats = $db->find()
    ->from('users')
    ->select([
        'missing_email' => Db::sum(Db::case(['email IS NULL' => '1'], '0')),
        'missing_phone' => Db::sum(Db::case(['phone IS NULL' => '1'], '0')),
        'missing_address' => Db::sum(Db::case(['address IS NULL' => '1'], '0')),
        'missing_bio' => Db::sum(Db::case(['bio IS NULL' => '1'], '0')),
        'total' => Db::count()
    ])
    ->getOne();

echo "  Data completeness:\n";
echo "  • Total users: {$stats['total']}\n";
echo "  • Missing email: {$stats['missing_email']}\n";
echo "  • Missing phone: {$stats['missing_phone']}\n";
echo "  • Missing address: {$stats['missing_address']}\n";
echo "  • Missing bio: {$stats['missing_bio']}\n";
echo "\n";

// Example 9: Advanced COALESCE scenarios
echo "9. Advanced COALESCE scenarios...\n";
// Resolve ConcatValue to SQL string before using in coalesce
// ConcatValue cannot be used directly in coalesce() because it throws exception on getValue()
// We need to resolve it through the dialect first to get the SQL string
$resolver = new \tommyknocker\pdodb\query\RawValueResolver($db->connection, new \tommyknocker\pdodb\query\ParameterManager());
$concatRawValue = $db->connection->getDialect()->concat(Db::concat('Name: ', 'name'));
$concatExpr = $concatRawValue->getValue();
$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'display_name' => Db::coalesce('name', "'Anonymous'"),
        'contact_info' => Db::coalesce('phone', 'email', 'address', "'No contact info'"),
        'profile_summary' => Db::coalesce('bio', Db::raw($concatExpr), "'No profile'")
    ])
    ->get();

foreach ($users as $user) {
    $user = normalizeRowKeys($user);
    $user = normalizeRowKeys($user);
    echo "  • {$user['name']}\n";
    echo "    Display: {$user['display_name']}\n";
    echo "    Contact: {$user['contact_info']}\n";
    echo "    Summary: {$user['profile_summary']}\n";
}
echo "\n";

// Example 10: NULLIF with different data types
echo "10. NULLIF with different data types...\n";
$db->find()->table('users')->insertMulti([
    ['name' => 'Frank', 'email' => '0', 'phone' => '0', 'address' => 'N/A'],
    ['name' => 'Grace', 'email' => 'NULL', 'phone' => 'NULL', 'address' => 'NULL']
]);

$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'email',
        'clean_email' => Db::nullIf('email', "'0'"),
        'clean_phone' => Db::nullIf('phone', "'0'"),
        'clean_address' => Db::nullIf('address', "'N/A'")
    ])
    ->where('name', ['Frank', 'Grace'])
    ->get();

foreach ($users as $user) {
    $user = normalizeRowKeys($user);
    $user = normalizeRowKeys($user);
    echo "  • {$user['name']}:\n";
    echo "    Email: '{$user['email']}' → " . ($user['clean_email'] ?? 'NULL') . "\n";
    echo "    Phone: '{$user['phone']}' → " . ($user['clean_phone'] ?? 'NULL') . "\n";
    echo "    Address: '{$user['address']}' → " . ($user['clean_address'] ?? 'NULL') . "\n";
}
echo "\n";

// Example 11: Complex NULL handling in WHERE clauses
echo "11. Complex NULL handling in WHERE clauses...\n";
$users = $db->find()
    ->from('users')
    ->select(['name', 'email', 'phone'])
    ->where('email', 'IS NOT NULL')
    ->orWhere('phone', 'IS NOT NULL')
    ->get();

echo "  Users with valid contact info:\n";
foreach ($users as $user) {
    $user = normalizeRowKeys($user);
    $user = normalizeRowKeys($user);
    $contact = $user['email'] ?? $user['phone'] ?? 'None';
    echo "  • {$user['name']}: {$contact}\n";
}
echo "\n";

// Example 12: NULL handling in aggregations
echo "12. NULL handling in aggregations...\n";
// Use library helpers for all dialects
// Db::length() handles LENGTH() -> LEN() conversion for MSSQL automatically via normalizeRawValue
$stats = $db->find()
    ->from('users')
    ->select([
        'avg_name_length' => Db::avg(Db::length('name')),
        'max_contact_length' => Db::max(Db::length(Db::coalesce('email', 'phone', "'N/A'"))),
        'users_with_complete_info' => Db::count(Db::case([
            'email IS NOT NULL AND phone IS NOT NULL AND address IS NOT NULL' => '1'
        ], null))
    ])
    ->getOne();

echo "  Aggregation statistics:\n";
echo "  • Average name length: " . round($stats['avg_name_length'], 2) . " characters\n";
echo "  • Max contact info length: {$stats['max_contact_length']} characters\n";
echo "  • Users with complete info: {$stats['users_with_complete_info']}\n";

echo "\nNULL handling helpers example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use isNull/isNotNull for filtering\n";
echo "  • Use ifNull for simple default values\n";
echo "  • Use coalesce for fallback chain (first non-NULL)\n";
echo "  • Use nullIf to convert values to NULL\n";
echo "  • Combine NULL functions for complex data cleaning\n";
echo "  • Use NULL functions in aggregations and statistics\n";

