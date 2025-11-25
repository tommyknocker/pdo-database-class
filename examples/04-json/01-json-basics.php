<?php
/**
 * Example: JSON Basics
 * 
 * Demonstrates creating, storing, and basic querying of JSON data
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== JSON Basics Example (on $driver) ===\n\n";

// Create table using fluent API (cross-dialect)
$schema = $db->schema();
$driver = getCurrentDriver($db);
recreateTable($db, 'users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(255),
    'settings' => $driver === 'pgsql' ? $schema->json() : $schema->text(),
    'tags' => $driver === 'pgsql' ? $schema->json() : $schema->text(),
]);

echo "✓ Table created\n\n";

echo "1. Inserting users with JSON data...\n";

// Insert with JSON helpers
$id1 = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'settings' => Db::jsonObject([
        'theme' => 'dark',
        'language' => 'en',
        'notifications' => true,
        'fontSize' => 14
    ]),
    'tags' => Db::jsonArray('admin', 'developer', 'team-lead')
]);

$id2 = $db->find()->table('users')->insert([
    'name' => 'Bob',
    'settings' => Db::jsonObject([
        'theme' => 'light',
        'language' => 'es',
        'notifications' => false,
        'fontSize' => 16
    ]),
    'tags' => Db::jsonArray('developer', 'frontend')
]);

$id3 = $db->find()->table('users')->insert([
    'name' => 'Carol',
    'settings' => Db::jsonObject([
        'theme' => 'dark',
        'language' => 'fr',
        'notifications' => true,
        'fontSize' => 12
    ]),
    'tags' => Db::jsonArray('designer', 'ux')
]);

echo "✓ Inserted 3 users with JSON data\n\n";

// Query with JSON path
echo "2. Finding users with dark theme...\n";
$darkThemeUsers = $db->find()
    ->from('users')
    ->where(Db::jsonPath('settings', ['theme'], '=', 'dark'))
    ->get();

echo "  Found " . count($darkThemeUsers) . " users:\n";
foreach ($darkThemeUsers as $user) {
    // Oracle returns CLOB as resource, need to read it
    $settingsJson = is_resource($user['settings']) ? stream_get_contents($user['settings']) : $user['settings'];
    $settings = json_decode($settingsJson, true);
    echo "  • {$user['name']} (theme: {$settings['theme']}, lang: {$settings['language']})\n";
}
echo "\n";

// Query with JSON contains
echo "3. Finding users with 'developer' tag...\n";
$developers = $db->find()
    ->from('users')
    ->where(Db::jsonContains('tags', 'developer'))
    ->get();

echo "  Found " . count($developers) . " developers:\n";
foreach ($developers as $user) {
    // Oracle returns CLOB as resource, need to read it
    $tagsJson = is_resource($user['tags']) ? stream_get_contents($user['tags']) : $user['tags'];
    $tags = json_decode($tagsJson, true);
    echo "  • {$user['name']}: " . implode(', ', $tags) . "\n";
}
echo "\n";

// Extract JSON values
echo "4. Extracting JSON values in SELECT...\n";
$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'theme' => Db::jsonGet('settings', ['theme']),
        'language' => Db::jsonGet('settings', ['language']),
        'fontSize' => Db::jsonGet('settings', ['fontSize'])
    ])
    ->get();

echo "  User settings:\n";
foreach ($users as $user) {
    echo "  • {$user['name']}: {$user['theme']} theme, {$user['language']}, font size {$user['fontSize']}\n";
}
echo "\n";

// Check if JSON path exists
echo "5. Finding users with notifications enabled...\n";
$withNotifications = $db->find()
    ->from('users')
    ->where(Db::jsonPath('settings', ['notifications'], '=', true))
    ->select(['name'])
    ->get();

echo "  Users with notifications:\n";
foreach ($withNotifications as $user) {
    echo "  • {$user['name']}\n";
}
echo "\n";

// Order by JSON value
echo "6. Ordering users by font size...\n";
$sorted = $db->find()
    ->from('users')
    ->select([
        'name',
        'fontSize' => Db::jsonGet('settings', ['fontSize'])
    ])
    ->orderBy(Db::jsonGet('settings', ['fontSize']), 'DESC')
    ->get();

echo "  Users sorted by font size (largest first):\n";
foreach ($sorted as $user) {
    echo "  • {$user['name']}: {$user['fontSize']}px\n";
}

echo "\nJSON basics example completed!\n";

