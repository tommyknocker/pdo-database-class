<?php
/**
 * Example: Boolean Helper Functions
 * 
 * Demonstrates boolean operations and conditions
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Boolean Helper Functions Example (on $driver) ===\n\n";

// Setup
recreateTable($db, 'settings', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'user_id' => 'INTEGER',
    'setting_name' => 'TEXT',
    'setting_value' => 'TEXT',
    'is_active' => 'BOOLEAN',
    'is_default' => 'BOOLEAN',
    'is_public' => 'BOOLEAN',
    'is_required' => 'BOOLEAN'
]);

$db->find()->table('settings')->insertMulti([
    ['user_id' => 1, 'setting_name' => 'notifications', 'setting_value' => 'true', 'is_active' => 1, 'is_default' => 0, 'is_public' => 1, 'is_required' => 0],
    ['user_id' => 1, 'setting_name' => 'theme', 'setting_value' => 'dark', 'is_active' => 1, 'is_default' => 1, 'is_public' => 0, 'is_required' => 1],
    ['user_id' => 2, 'setting_name' => 'notifications', 'setting_value' => 'false', 'is_active' => 0, 'is_default' => 1, 'is_public' => 1, 'is_required' => 0],
    ['user_id' => 2, 'setting_name' => 'theme', 'setting_value' => 'light', 'is_active' => 1, 'is_default' => 0, 'is_public' => 0, 'is_required' => 1],
    ['user_id' => 3, 'setting_name' => 'privacy', 'setting_value' => 'private', 'is_active' => 1, 'is_default' => 1, 'is_public' => 0, 'is_required' => 1],
]);

echo "✓ Test data inserted\n\n";

// Example 1: TRUE/FALSE constants
echo "1. TRUE/FALSE constants...\n";
$results = $db->find()
    ->from('settings')
    ->where('is_active', Db::true())
    ->select(['user_id', 'setting_name', 'setting_value'])
    ->get();

echo "  Active settings:\n";
foreach ($results as $row) {
    echo "  • User {$row['user_id']}: {$row['setting_name']} = {$row['setting_value']}\n";
}
echo "\n";

// Example 2: DEFAULT values
echo "2. DEFAULT values...\n";
$results = $db->find()
    ->from('settings')
    ->where('is_default', Db::true())
    ->select(['user_id', 'setting_name', 'setting_value'])
    ->get();

echo "  Default settings:\n";
foreach ($results as $row) {
    echo "  • User {$row['user_id']}: {$row['setting_name']} = {$row['setting_value']} (default)\n";
}
echo "\n";

// Example 3: Boolean conditions
echo "3. Boolean conditions...\n";
$results = $db->find()
    ->from('settings')
    ->where('is_active', Db::true())
    ->andWhere('is_default', Db::false())
    ->select(['user_id', 'setting_name', 'setting_value'])
    ->get();

echo "  Active, non-default settings:\n";
foreach ($results as $row) {
    echo "  • User {$row['user_id']}: {$row['setting_name']} = {$row['setting_value']} (active, not default)\n";
}
echo "\n";

// Example 4: Complex boolean logic
echo "4. Complex boolean logic...\n";
$results = $db->find()
    ->from('settings')
    ->where('is_public', Db::true())
    ->andWhere('is_required', Db::false())
    ->orWhere('is_active', Db::false())
    ->select(['user_id', 'setting_name', 'is_active', 'is_public', 'is_required'])
    ->get();

echo "  Public non-required OR inactive settings:\n";
foreach ($results as $row) {
    $active = $row['is_active'] ? 'Yes' : 'No';
    $public = $row['is_public'] ? 'Yes' : 'No';
    $required = $row['is_required'] ? 'Yes' : 'No';
    echo "  • User {$row['user_id']}: {$row['setting_name']} (active: {$active}, public: {$public}, required: {$required})\n";
}
echo "\n";

// Example 5: Boolean in SELECT with CASE
echo "5. Boolean in SELECT with CASE...\n";
$results = $db->find()
    ->from('settings')
    ->select([
        'user_id',
        'setting_name',
        'setting_value',
        'status' => Db::case([
            'is_active = true AND is_default = true' => '\'Active Default\'',
            'is_active = true AND is_default = false' => '\'Active Custom\'',
            'is_active = false AND is_default = true' => '\'Inactive Default\'',
            'is_active = false AND is_default = false' => '\'Inactive Custom\''
        ], '\'Unknown\''),
        'is_editable' => Db::case([
            'is_default = false' => '1',
            'is_default = true' => '0'
        ], '0')
    ])
    ->get();

foreach ($results as $row) {
    $editable = $row['is_editable'] ? 'Yes' : 'No';
    echo "  • User {$row['user_id']}: {$row['setting_name']} = {$row['setting_value']} ({$row['status']}, editable: {$editable})\n";
}
echo "\n";

// Example 6: Boolean aggregation
echo "6. Boolean aggregation...\n";
$results = $db->find()
    ->from('settings')
    ->select([
        'user_id',
        'total_settings' => Db::count(),
        'active_settings' => Db::sum(Db::case([($driver === 'sqlsrv' ? 'is_active = 1' : 'is_active = true') => '1'], '0')),
        'default_settings' => Db::sum(Db::case([($driver === 'sqlsrv' ? 'is_default = 1' : 'is_default = true') => '1'], '0')),
        'public_settings' => Db::sum(Db::case([($driver === 'sqlsrv' ? 'is_public = 1' : 'is_public = true') => '1'], '0')),
        'required_settings' => Db::sum(Db::case([($driver === 'sqlsrv' ? 'is_required = 1' : 'is_required = true') => '1'], '0'))
    ])
    ->groupBy('user_id')
    ->orderBy('user_id')
    ->get();

echo "  User settings summary:\n";
foreach ($results as $row) {
    echo "  • User {$row['user_id']}: {$row['total_settings']} total, {$row['active_settings']} active, {$row['default_settings']} default\n";
    echo "    Public: {$row['public_settings']}, Required: {$row['required_settings']}\n";
}
echo "\n";

// Example 7: Boolean filtering with multiple conditions
echo "7. Boolean filtering with multiple conditions...\n";
$results = $db->find()
    ->from('settings')
    ->where('is_active', Db::true())
    ->andWhere('is_public', Db::true())
    ->andWhere('is_required', Db::false())
    ->select(['user_id', 'setting_name', 'setting_value'])
    ->get();

echo "  Active, public, non-required settings:\n";
foreach ($results as $row) {
    echo "  • User {$row['user_id']}: {$row['setting_name']} = {$row['setting_value']}\n";
}
echo "\n";

// Example 8: Boolean with DEFAULT in INSERT
echo "8. Boolean with DEFAULT in INSERT...\n";
$db->find()->table('settings')->insert([
    'user_id' => 4,
    'setting_name' => 'language',
    'setting_value' => 'en',
    'is_active' => null,
    'is_default' => null,
    'is_public' => null,
    'is_required' => null
]);

$newSetting = $db->find()
    ->from('settings')
    ->where('user_id', 4)
    ->andWhere('setting_name', 'language')
    ->getOne();

echo "  New setting with DEFAULT values:\n";
echo "  • User {$newSetting['user_id']}: {$newSetting['setting_name']} = {$newSetting['setting_value']}\n";
echo "    Active: {$newSetting['is_active']}, Default: {$newSetting['is_default']}\n";
echo "    Public: {$newSetting['is_public']}, Required: {$newSetting['is_required']}\n";
echo "\n";

// Example 9: Boolean in ORDER BY
echo "9. Boolean in ORDER BY...\n";
$results = $db->find()
    ->from('settings')
    ->select(['user_id', 'setting_name', 'is_active', 'is_default'])
    ->orderBy('is_active', 'DESC')
    ->orderBy('is_default', 'DESC')
    ->orderBy('setting_name')
    ->get();

echo "  Settings ordered by boolean flags:\n";
foreach ($results as $row) {
    $active = $row['is_active'] ? 'Active' : 'Inactive';
    $default = $row['is_default'] ? 'Default' : 'Custom';
    echo "  • User {$row['user_id']}: {$row['setting_name']} ({$active}, {$default})\n";
}
echo "\n";

// Example 10: Boolean statistics
echo "10. Boolean statistics...\n";
$stats = $db->find()
    ->from('settings')
    ->select([
        'total_settings' => Db::count(),
        'active_count' => Db::sum(Db::case([($driver === 'sqlsrv' ? 'is_active = 1' : 'is_active = true') => '1'], '0')),
        'default_count' => Db::sum(Db::case([($driver === 'sqlsrv' ? 'is_default = 1' : 'is_default = true') => '1'], '0')),
        'public_count' => Db::sum(Db::case([($driver === 'sqlsrv' ? 'is_public = 1' : 'is_public = true') => '1'], '0')),
        'required_count' => Db::sum(Db::case([($driver === 'sqlsrv' ? 'is_required = 1' : 'is_required = true') => '1'], '0')),
        'active_percentage' => Db::round(Db::raw($driver === 'sqlsrv' ? '(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*))' : '(SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) * 100.0 / COUNT(*))'), 1)
    ])
    ->getOne();

echo "  Settings statistics:\n";
echo "  • Total settings: {$stats['total_settings']}\n";
echo "  • Active: {$stats['active_count']} ({$stats['active_percentage']}%)\n";
echo "  • Default: {$stats['default_count']}\n";
echo "  • Public: {$stats['public_count']}\n";
echo "  • Required: {$stats['required_count']}\n";

echo "\nBoolean helper functions example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use TRUE/FALSE for explicit boolean values\n";
echo "  • Use DEFAULT for column default values\n";
echo "  • Boolean values work in WHERE, SELECT, ORDER BY clauses\n";
echo "  • Combine boolean conditions with AND/OR for complex logic\n";
echo "  • Use boolean functions in aggregations and statistics\n";
echo "  • Boolean values are useful for flags and status indicators\n";
