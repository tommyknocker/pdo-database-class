<?php
/**
 * Example 04: INSERT and UPDATE Operations
 * 
 * Demonstrates various ways to insert and update data
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== INSERT and UPDATE Operations (on $driver) ===\n\n";

// Setup
recreateTable($db, 'counters', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT UNIQUE',
    'value' => 'INTEGER DEFAULT 0',
    'updated_at' => 'DATETIME'
]);

// Example 1: Basic INSERT
echo "1. Basic INSERT...\n";
$id = $db->find()->table('counters')->insert([
    'name' => 'page_views',
    'value' => 100,
    'updated_at' => Db::now()
]);
echo "  ✓ Inserted counter with ID: $id\n\n";

// Example 2: INSERT with auto-increment value
echo "2. INSERT and retrieve auto-increment ID...\n";
$counter = $db->find()->from('counters')->where('id', $id)->getOne();
echo "  ✓ Counter '{$counter['name']}' value: {$counter['value']}\n\n";

// Example 3: Increment operation
echo "3. UPDATE with increment...\n";
$db->find()
    ->table('counters')
    ->where('id', $id)
    ->update(['value' => Db::inc(5)]);

$counter = $db->find()->from('counters')->where('id', $id)->getOne();
echo "  ✓ After increment: {$counter['value']}\n\n";

// Example 4: Decrement operation
echo "4. UPDATE with decrement...\n";
$db->find()
    ->table('counters')
    ->where('id', $id)
    ->update(['value' => Db::dec(2)]);

$counter = $db->find()->from('counters')->where('id', $id)->getOne();
echo "  ✓ After decrement: {$counter['value']}\n\n";

// Example 5: UPDATE with raw SQL expression
echo "5. UPDATE with raw SQL expression...\n";
$db->find()
    ->table('counters')
    ->where('id', $id)
    ->update([
        'value' => Db::raw('value * 2'),
        'updated_at' => Db::now()
    ]);

$counter = $db->find()->from('counters')->where('id', $id)->getOne();
echo "  ✓ After doubling: {$counter['value']}\n\n";

// Example 6: INSERT multiple rows
echo "6. INSERT multiple rows...\n";
$rows = [
    ['name' => 'downloads', 'value' => 50],
    ['name' => 'uploads', 'value' => 30],
    ['name' => 'errors', 'value' => 5],
];

$inserted = $db->find()->table('counters')->insertMulti($rows);
echo "  ✓ Inserted $inserted counters\n\n";

// Example 7: UPDATE multiple rows with condition
echo "7. UPDATE multiple rows...\n";
$updated = $db->find()
    ->table('counters')
    ->where('value', 50, '<')
    ->update(['value' => Db::inc(10)]);

echo "  ✓ Updated $updated counters\n\n";

// Example 8: Conditional UPDATE (only if condition matches)
echo "8. Conditional UPDATE...\n";
$affected = $db->find()
    ->table('counters')
    ->where('name', 'downloads')
    ->andWhere('value', 100, '<')
    ->update(['value' => 100]);

echo "  ✓ Set downloads to 100 (affected: $affected rows)\n\n";

// Example 9: UPDATE with multiple field modifications
echo "9. UPDATE multiple fields...\n";
$db->find()
    ->table('counters')
    ->where('name', 'page_views')
    ->update([
        'value' => Db::inc(50),
        // Use helper-based concatenation; quote literal via raw as a minimal fallback
        'name' => Db::concat('name', Db::raw("'_total'")),
        'updated_at' => Db::now()
    ]);

$counter = $db->find()
    ->from('counters')
    ->where(Db::like('name', '%total'))
    ->getOne();

if ($counter) {
    echo "  ✓ Renamed to '{$counter['name']}', value: {$counter['value']}\n\n";
} else {
    echo "  ✗ Counter not found\n\n";
}

// Example 10: Show final state
echo "10. Final state of all counters:\n";
$all = $db->find()
    ->from('counters')
    ->select(['name', 'value'])
    ->orderBy('value', 'DESC')
    ->get();

foreach ($all as $c) {
    echo "  • {$c['name']}: {$c['value']}\n";
}

echo "\nAll INSERT/UPDATE operations completed!\n";

