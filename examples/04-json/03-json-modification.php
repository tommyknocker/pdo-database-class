<?php
/**
 * Example: JSON Modification Helpers
 *
 * Demonstrates the use of Db::jsonSet(), Db::jsonRemove(), and Db::jsonReplace()
 * for modifying JSON data in database columns.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== JSON Modification Helpers Example (on $driver) ===\n\n";

// Setup
recreateTable($db, 'users', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT',
    'meta' => $driver === 'pgsql' ? 'JSONB' : 'TEXT',
    'preferences' => $driver === 'pgsql' ? 'JSONB' : 'TEXT'
]);

echo "1. Inserting users with JSON metadata...\n";
$db->find()->table('users')->insertMulti([
    [
        'name' => 'John Doe',
        'meta' => Db::jsonObject([
            'status' => 'active',
            'score' => 10,
            'tags' => ['user', 'premium']
        ]),
        'preferences' => Db::jsonObject([
            'theme' => 'light',
            'notifications' => true
        ])
    ],
    [
        'name' => 'Jane Smith',
        'meta' => Db::jsonObject([
            'status' => 'inactive',
            'score' => 5,
            'tags' => ['user']
        ]),
        'preferences' => Db::jsonObject([
            'theme' => 'dark',
            'notifications' => false
        ])
    ]
]);

echo "2. Using Db::jsonSet() to update JSON values...\n";
// Update status using jsonSet (creates path if missing)
$db->find()
    ->table('users')
    ->where('name', 'John Doe')
    ->update(['meta' => Db::jsonSet('meta', ['status'], 'premium')]);

// Create nested path
$db->find()
    ->table('users')
    ->where('name', 'John Doe')
    ->update(['meta' => Db::jsonSet('meta', ['profile', 'city'], 'New York')]);

// Update array element
$db->find()
    ->table('users')
    ->where('name', 'John Doe')
    ->update(['meta' => Db::jsonSet('meta', ['tags', 0], 'vip')]);

$result = $db->find()
    ->table('users')
    ->selectJson('meta', ['status'], 'status')
    ->selectJson('meta', ['profile', 'city'], 'city')
    ->selectJson('meta', ['tags', 0], 'first_tag')
    ->where('name', 'John Doe')
    ->getOne();

echo "   Status: {$result['status']}\n";
echo "   City: {$result['city']}\n";
echo "   First tag: {$result['first_tag']}\n\n";

echo "3. Using Db::jsonRemove() to remove JSON paths...\n";
// Remove a field
$db->find()
    ->table('users')
    ->where('name', 'Jane Smith')
    ->update(['meta' => Db::jsonRemove('meta', ['score'])]);

// Remove nested path
$db->find()
    ->table('users')
    ->where('name', 'John Doe')
    ->update(['meta' => Db::jsonRemove('meta', ['profile', 'city'])]);

$result = $db->find()
    ->table('users')
    ->selectJson('meta', ['score'], 'score')
    ->selectJson('meta', ['profile', 'city'], 'city')
    ->where('name', 'Jane Smith')
    ->getOne();

echo "   Score after removal: " . ($result['score'] ?? 'null') . "\n";
echo "   City after removal: " . ($result['city'] ?? 'null') . "\n\n";

echo "4. Using Db::jsonReplace() to replace existing values only...\n";
// Replace existing value
$db->find()
    ->table('users')
    ->where('name', 'John Doe')
    ->update(['preferences' => Db::jsonReplace('preferences', ['theme'], 'dark')]);

// Try to replace non-existent path (won't create it)
$db->find()
    ->table('users')
    ->where('name', 'Jane Smith')
    ->update(['preferences' => Db::jsonReplace('preferences', ['language'], 'en')]);

$result1 = $db->find()
    ->table('users')
    ->selectJson('preferences', ['theme'], 'theme')
    ->where('name', 'John Doe')
    ->getOne();

$result2 = $db->find()
    ->table('users')
    ->selectJson('preferences', ['language'], 'language')
    ->where('name', 'Jane Smith')
    ->getOne();

echo "   Theme replaced: {$result1['theme']}\n";
echo "   Language (non-existent): " . ($result2['language'] ?? 'null (not created)') . "\n\n";

echo "5. Comparison: jsonSet vs jsonReplace...\n";
// jsonSet creates path if missing
$db->find()
    ->table('users')
    ->where('name', 'Jane Smith')
    ->update(['preferences' => Db::jsonSet('preferences', ['language'], 'en')]);

$result = $db->find()
    ->table('users')
    ->selectJson('preferences', ['language'], 'language')
    ->where('name', 'Jane Smith')
    ->getOne();

echo "   Language after jsonSet: {$result['language']} (path created)\n\n";

echo "6. Complex JSON operations...\n";
// Set complex nested structure
$complexData = [
    'settings' => [
        'privacy' => ['public' => false, 'show_email' => false],
        'display' => ['compact' => true]
    ]
];

$db->find()
    ->table('users')
    ->where('name', 'John Doe')
    ->update(['preferences' => Db::jsonSet('preferences', ['settings'], $complexData)]);

$result = $db->find()
    ->table('users')
    ->selectJson('preferences', ['settings'], 'settings')
    ->where('name', 'John Doe')
    ->getOne();

$settings = json_decode($result['settings'], true);
echo "   Settings: " . json_encode($settings, JSON_PRETTY_PRINT) . "\n\n";

echo "=== Example completed successfully! ===\n";

