<?php
/**
 * Example: String Helper Functions
 * 
 * Demonstrates string manipulation helpers
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== String Helper Functions Example (on $driver) ===\n\n";

// Setup
recreateTable($db, 'users', ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'first_name' => 'TEXT', 'last_name' => 'TEXT', 'email' => 'TEXT', 'bio' => 'TEXT']);

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
        'full_name' => Db::concat(Db::upper('first_name'), ' ', Db::upper('last_name'))
    ])
    ->get();

foreach ($users as $user) {
    echo "  • {$user['full_name']}\n";
}
echo "\n";

// Example 10: LEFT and RIGHT
echo "10. LEFT and RIGHT - Extract edges of strings...\n";
$sides = $db->find()
    ->from('users')
    ->select([
        'first_name',
        'left2' => Db::left('first_name', 2),
        'right2' => Db::right('first_name', 2)
    ])
    ->limit(3)
    ->get();

foreach ($sides as $row) {
    echo "  • {$row['first_name']} → LEFT(2)={$row['left2']}, RIGHT(2)={$row['right2']}\n";
}
echo "\n";

// Example 11: POSITION - Find substring index (1-based)
echo "11. POSITION - Find '@' in email...\n";
$positions = $db->find()
    ->from('users')
    ->select([
        'email',
        'at_pos' => Db::position('@', 'email')
    ])
    ->limit(3)
    ->get();

foreach ($positions as $row) {
    echo "  • {$row['email']} → '@' at position {$row['at_pos']}\n";
}
echo "\n";

// Example 12: REPEAT and REVERSE (now emulated for SQLite)
echo "12. REPEAT and REVERSE - Banner formatting...\n";
$banners = $db->find()
    ->from('users')
    ->select([
        'first_name',
        'banner' => Db::repeat('-', 3),
        'reversed' => Db::reverse('first_name')
    ])
    ->limit(2)
    ->get();

foreach ($banners as $row) {
    echo "  • {$row['first_name']} → banner={$row['banner']}, reversed={$row['reversed']}\n";
}
echo "\n";

// Example 13: LPAD and RPAD (now emulated for SQLite)
echo "13. LPAD and RPAD - Align strings...\n";
$padded = $db->find()
    ->from('users')
    ->select([
        'first_name',
        'left_padded' => Db::padLeft('first_name', 8, ' '),
        'right_padded' => Db::padRight('first_name', 8, '.')
    ])
    ->limit(3)
    ->get();

foreach ($padded as $row) {
    echo "  • '{$row['left_padded']}' | '{$row['right_padded']}'\n";
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
echo "5. SUBSTRING - Extract first 3 characters...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'first_name',
        'short_name' => Db::substring('first_name', 1, 3)
    ])
    ->get();

foreach ($users as $user) {
    echo "  • {$user['first_name']} → {$user['short_name']}\n";
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

// Example 7: LTRIM and RTRIM operations
echo "7. LTRIM and RTRIM operations...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'bio',
        'left_trimmed' => Db::ltrim('bio'),
        'right_trimmed' => Db::rtrim('bio'),
        'both_trimmed' => Db::trim('bio')
    ])
    ->where(Db::like('bio', '% %'))
    ->get();

foreach ($users as $user) {
    echo "  Original: \"{$user['bio']}\"\n";
    echo "  LTRIM:    \"{$user['left_trimmed']}\"\n";
    echo "  RTRIM:    \"{$user['right_trimmed']}\"\n";
    echo "  TRIM:     \"{$user['both_trimmed']}\"\n";
}
echo "\n";

// Example 8: Combine multiple string functions
echo "8. Combining string functions...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'first_name',
        'last_name',
        // Use helpers without nesting ConcatValue inside other helpers
        'full_name_upper' => Db::concat(Db::upper('first_name'), ' ', Db::upper('last_name')),
        'email_lower' => Db::lower('email'),
        // Length of concatenation via raw as a necessary fallback
        'name_length' => Db::raw('LENGTH(first_name) + 1 + LENGTH(last_name)')
    ])
    ->limit(2)
    ->get();

echo "  Combined operations:\n";
foreach ($users as $user) {
    echo "  • Original: {$user['first_name']} {$user['last_name']}\n";
    echo "    Full name (UPPER): {$user['full_name_upper']}\n";
    echo "    Email (lower): {$user['email_lower']}\n";
    echo "    Name length: {$user['name_length']} characters\n";
}
echo "\n";

// Example 9: Advanced string operations
echo "9. Advanced string operations...\n";
$driver = getCurrentDriver($db);
$substringFunc = $driver === 'pgsql' ? 'SUBSTR' : 'SUBSTRING';
$users = $db->find()
    ->from('users')
    ->select([
        'email',
        'email_domain' => Db::raw("$substringFunc(email, LENGTH(email) - LENGTH(REPLACE(email, '@', '')) + 2)"),
        'email_user' => Db::raw("$substringFunc(email, 1, LENGTH(email) - LENGTH(REPLACE(email, '@', '')) - 1)")
    ])
    ->get();

foreach ($users as $user) {
    echo "  • {$user['email']} → user: {$user['email_user']}, domain: {$user['email_domain']}\n";
}
echo "\n";

// Example 14: REGEXP operations
echo "14. REGEXP operations - Pattern matching and extraction...\n";
$driver = getCurrentDriver($db);

// Check if REGEXP is supported (SQLite requires extension)
if ($driver === 'sqlite') {
    try {
        $db->rawQuery("SELECT 'test' REGEXP 'test'");
    } catch (\PDOException $e) {
        echo "  ⚠ REGEXP extension not available in SQLite, skipping regexp examples\n";
        echo "\nString helper functions example completed!\n";
        exit(0);
    }
}

// Insert test data with various email formats
recreateTable($db, 'contacts', ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'email' => 'TEXT', 'phone' => 'TEXT']);
$db->find()->table('contacts')->insertMulti([
    ['email' => 'user@example.com', 'phone' => '+1-555-123-4567'],
    ['email' => 'admin@test.org', 'phone' => '+44-20-7946-0958'],
    ['email' => 'invalid-email', 'phone' => '12345'],
]);

// REGEXP_MATCH - Find valid email addresses
echo "  a) REGEXP_MATCH - Find valid email addresses:\n";
$validEmails = $db->find()
    ->from('contacts')
    ->select(['email'])
    ->where(Db::regexpMatch('email', '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$'))
    ->get();

foreach ($validEmails as $contact) {
    echo "    • {$contact['email']}\n";
}
echo "\n";

// REGEXP_REPLACE - Format phone numbers
echo "  b) REGEXP_REPLACE - Format phone numbers:\n";
$formattedPhones = $db->find()
    ->from('contacts')
    ->select([
        'phone',
        'formatted' => Db::regexpReplace('phone', '-', ' ')
    ])
    ->where(Db::regexpMatch('phone', '-'))
    ->get();

foreach ($formattedPhones as $contact) {
    echo "    • {$contact['phone']} → {$contact['formatted']}\n";
}
echo "\n";

// REGEXP_EXTRACT - Extract domain from email
echo "  c) REGEXP_EXTRACT - Extract domain from email:\n";
$domains = $db->find()
    ->from('contacts')
    ->select([
        'email',
        'domain' => Db::regexpExtract('email', '@([a-zA-Z0-9.-]+\\.[a-zA-Z]{2,})', 1)
    ])
    ->where(Db::regexpMatch('email', '@'))
    ->get();

foreach ($domains as $contact) {
    if ($contact['domain'] !== null) {
        echo "    • {$contact['email']} → domain: {$contact['domain']}\n";
    }
}
echo "\n";

echo "\nString helper functions example completed!\n";

