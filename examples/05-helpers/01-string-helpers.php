<?php
/**
 * Example: String Helper Functions
 * 
 * Demonstrates string manipulation helpers
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = new PdoDb('sqlite', ['path' => ':memory:']);

echo "=== String Helper Functions Example ===\n\n";

// Setup
$db->rawQuery("CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, bio TEXT)");

$db->find()->table('users')->insertMulti([
    ['first_name' => 'john', 'last_name' => 'doe', 'email' => 'JOHN@EXAMPLE.COM', 'bio' => '  Software Developer  '],
    ['first_name' => 'jane', 'last_name' => 'smith', 'email' => 'JANE@EXAMPLE.COM', 'bio' => 'Product Manager'],
    ['first_name' => 'bob', 'last_name' => 'johnson', 'email' => 'BOB@TEST.COM', 'bio' => '  Designer  '],
]);

echo "✓ Test data inserted\n\n";

// Example 1: CONCAT - Combine strings
echo "1. CONCAT - Full names...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'full_name' => Db::concat(Db::upper('first_name'), Db::raw("' '"), Db::upper('last_name'))
    ])
    ->get();

foreach ($users as $user) {
    echo "  • {$user['full_name']}\n";
}
echo "\n";

// Example 2: UPPER and LOWER
echo "2. UPPER and LOWER - Case conversion...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'first_name',
        'upper_name' => Db::upper('first_name'),
        'lower_email' => Db::lower('email')
    ])
    ->limit(1)
    ->getOne();

echo "  Original: {$users['first_name']}\n";
echo "  UPPER: {$users['upper_name']}\n";
echo "  Email (lower): {$users['lower_email']}\n\n";

// Example 3: TRIM - Remove whitespace
echo "3. TRIM - Remove whitespace...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'bio',
        'trimmed' => Db::trim('bio'),
        'length_before' => Db::length('bio'),
        'length_after' => Db::length(Db::trim('bio'))
    ])
    ->where(Db::like('bio', '% %'))
    ->get();

foreach ($users as $user) {
    echo "  Before: \"{$user['bio']}\" (length: {$user['length_before']})\n";
    echo "  After:  \"{$user['trimmed']}\" (length: {$user['length_after']})\n";
}
echo "\n";

// Example 4: LENGTH - String length
echo "4. LENGTH - Find users with long bios...\n";
$users = $db->find()
    ->from('users')
    ->select(['first_name', 'bio_length' => Db::length('bio')])
    ->where(Db::length('bio'), 15, '>')
    ->get();

echo "  Users with long bios:\n";
foreach ($users as $user) {
    echo "  • {$user['first_name']} (bio length: {$user['bio_length']})\n";
}
echo "\n";

// Example 5: SUBSTRING - Extract part of string
echo "5. SUBSTRING - Extract email domain...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'email',
        'domain' => Db::substring('email', Db::raw('INSTR(email, "@") + 1'))
    ])
    ->get();

foreach ($users as $user) {
    echo "  • {$user['email']} → domain: {$user['domain']}\n";
}
echo "\n";

// Example 6: REPLACE - String replacement
echo "6. REPLACE - Mask email addresses...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'first_name',
        'masked_email' => Db::replace(Db::lower('email'), '@', '(at)')
    ])
    ->get();

foreach ($users as $user) {
    echo "  • {$user['first_name']}: {$user['masked_email']}\n";
}
echo "\n";

// Example 7: Combine multiple string functions
echo "7. Complex string operations...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'display_name' => Db::concat(
            Db::upper(Db::substring('first_name', 1, 1)),
            Db::lower(Db::substring('first_name', 2)),
            Db::raw("' '"),
            Db::upper(Db::substring('last_name', 1, 1)),
            Db::lower(Db::substring('last_name', 2))
        )
    ])
    ->get();

echo "  Properly capitalized names:\n";
foreach ($users as $user) {
    echo "  • {$user['display_name']}\n";
}

echo "\nString helper functions example completed!\n";

