<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;

echo "=== Savepoints and Nested Transactions Examples ===\n\n";

$db = createExampleDb();

// Ensure users table exists
recreateTable($db, 'users', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'VARCHAR(100)',
    'age' => 'INTEGER'
]);

// Example 1: Basic savepoint usage
echo "Example 1: Basic savepoint usage\n";
echo str_repeat('-', 50) . "\n";

$db->startTransaction();

try {
    // Insert first record
    $userId = $db->find()->table('users')->insert([
        'name' => 'Alice',
        'age' => 25
    ]);
    echo "  Inserted user ID: {$userId}\n";

    // Create savepoint
    $db->savepoint('sp1');
    echo "  Created savepoint 'sp1'\n";

    // Insert second record
    $postId = $db->find()->table('users')->insert([
        'name' => 'Bob',
        'age' => 30
    ]);
    echo "  Inserted user ID: {$postId}\n";

    // Verify both records exist
    $count = count($db->find()->from('users')->whereIn('id', [$userId, $postId])->get());
    echo "  Total records before rollback: {$count}\n";

    // Rollback to savepoint (undoes second insert)
    $db->rollbackToSavepoint('sp1');
    echo "  Rolled back to savepoint 'sp1'\n";

    // Verify only first record exists
    $exists1 = $db->find()->from('users')->where('id', $userId)->exists();
    $exists2 = $db->find()->from('users')->where('id', $postId)->exists();
    echo "  First record exists: " . ($exists1 ? 'yes' : 'no') . "\n";
    echo "  Second record exists: " . ($exists2 ? 'yes' : 'no') . "\n";

    $db->rollback();
    echo "  Transaction rolled back\n";
} catch (\Exception $e) {
    $db->rollback();
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Example 2: Savepoint with release
echo "Example 2: Savepoint with release\n";
echo str_repeat('-', 50) . "\n";

$db->startTransaction();

try {
    $userId = $db->find()->table('users')->insert([
        'name' => 'Charlie',
        'age' => 35
    ]);
    echo "  Inserted user ID: {$userId}\n";

    $db->savepoint('sp1');
    echo "  Created savepoint 'sp1'\n";

    $postId = $db->find()->table('users')->insert([
        'name' => 'David',
        'age' => 40
    ]);
    echo "  Inserted user ID: {$postId}\n";

    // Release savepoint (keeps changes, removes savepoint)
    $db->releaseSavepoint('sp1');
    echo "  Released savepoint 'sp1'\n";

    // Both records should still exist
    $exists1 = $db->find()->from('users')->where('id', $userId)->exists();
    $exists2 = $db->find()->from('users')->where('id', $postId)->exists();
    echo "  First record exists: " . ($exists1 ? 'yes' : 'no') . "\n";
    echo "  Second record exists: " . ($exists2 ? 'yes' : 'no') . "\n";

    $db->rollback();
    echo "  Transaction rolled back\n";
} catch (\Exception $e) {
    $db->rollback();
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Example 3: Nested savepoints
echo "Example 3: Nested savepoints\n";
echo str_repeat('-', 50) . "\n";

$db->startTransaction();

try {
    $id1 = $db->find()->table('users')->insert(['name' => 'Eve', 'age' => 28]);
    echo "  Inserted user ID: {$id1}\n";

    $db->savepoint('sp1');
    echo "  Created savepoint 'sp1'\n";

    $id2 = $db->find()->table('users')->insert(['name' => 'Frank', 'age' => 32]);
    echo "  Inserted user ID: {$id2}\n";

    $db->savepoint('sp2');
    echo "  Created savepoint 'sp2'\n";

    $id3 = $db->find()->table('users')->insert(['name' => 'Grace', 'age' => 36]);
    echo "  Inserted user ID: {$id3}\n";

    echo "  Active savepoints: " . implode(', ', $db->getSavepoints()) . "\n";

    // Rollback to first savepoint (removes records 2 and 3)
    $db->rollbackToSavepoint('sp1');
    echo "  Rolled back to savepoint 'sp1'\n";

    $exists1 = $db->find()->from('users')->where('id', $id1)->exists();
    $exists2 = $db->find()->from('users')->where('id', $id2)->exists();
    $exists3 = $db->find()->from('users')->where('id', $id3)->exists();
    echo "  Record 1 exists: " . ($exists1 ? 'yes' : 'no') . "\n";
    echo "  Record 2 exists: " . ($exists2 ? 'yes' : 'no') . "\n";
    echo "  Record 3 exists: " . ($exists3 ? 'yes' : 'no') . "\n";

    $db->rollback();
    echo "  Transaction rolled back\n";
} catch (\Exception $e) {
    $db->rollback();
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Example 4: Error handling with savepoints
echo "Example 4: Error handling with savepoints\n";
echo str_repeat('-', 50) . "\n";

$db->startTransaction();

try {
    $userId = $db->find()->table('users')->insert([
        'name' => 'Henry',
        'age' => 45
    ]);
    echo "  Inserted user ID: {$userId}\n";

    $db->savepoint('sp1');
    echo "  Created savepoint 'sp1'\n";

    try {
        // Simulate an error (e.g., constraint violation)
        // This would fail if there's a unique constraint on name
        $db->find()->table('users')->insert([
            'name' => 'Henry', // Duplicate name might cause error
            'age' => 50
        ]);
        echo "  Second insert succeeded\n";
    } catch (\Exception $e) {
        echo "  Second insert failed: " . $e->getMessage() . "\n";
        // Rollback to savepoint to undo the failed operation
        $db->rollbackToSavepoint('sp1');
        echo "  Rolled back to savepoint 'sp1'\n";
    }

    // First record should still exist
    $exists = $db->find()->from('users')->where('id', $userId)->exists();
    echo "  First record exists: " . ($exists ? 'yes' : 'no') . "\n";

    $db->rollback();
    echo "  Transaction rolled back\n";
} catch (\Exception $e) {
    $db->rollback();
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Example 5: Savepoint stack management
echo "Example 5: Savepoint stack management\n";
echo str_repeat('-', 50) . "\n";

$db->startTransaction();

try {
    echo "  Initial savepoints: " . count($db->getSavepoints()) . "\n";

    $db->savepoint('sp1');
    echo "  After creating sp1: " . implode(', ', $db->getSavepoints()) . "\n";
    echo "  Has sp1: " . ($db->hasSavepoint('sp1') ? 'yes' : 'no') . "\n";

    $db->savepoint('sp2');
    echo "  After creating sp2: " . implode(', ', $db->getSavepoints()) . "\n";
    echo "  Has sp2: " . ($db->hasSavepoint('sp2') ? 'yes' : 'no') . "\n";

    $db->rollbackToSavepoint('sp1');
    echo "  After rollback to sp1: " . implode(', ', $db->getSavepoints()) . "\n";
    echo "  Has sp1: " . ($db->hasSavepoint('sp1') ? 'yes' : 'no') . "\n";
    echo "  Has sp2: " . ($db->hasSavepoint('sp2') ? 'yes' : 'no') . "\n";

    $db->releaseSavepoint('sp1');
    echo "  After release sp1: " . implode(', ', $db->getSavepoints()) . "\n";

    $db->rollback();
    echo "  Transaction rolled back\n";
} catch (\Exception $e) {
    $db->rollback();
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Example 6: Savepoint with commit
echo "Example 6: Savepoint with commit\n";
echo str_repeat('-', 50) . "\n";

$db->startTransaction();

try {
    $id1 = $db->find()->table('users')->insert(['name' => 'Iris', 'age' => 50]);
    echo "  Inserted user ID: {$id1}\n";

    $db->savepoint('sp1');
    echo "  Created savepoint 'sp1'\n";

    $id2 = $db->find()->table('users')->insert(['name' => 'Jack', 'age' => 55]);
    echo "  Inserted user ID: {$id2}\n";

    // Commit transaction (commits everything, including savepoint changes)
    $db->commit();
    echo "  Transaction committed\n";

    // Both records should exist
    $exists1 = $db->find()->from('users')->where('id', $id1)->exists();
    $exists2 = $db->find()->from('users')->where('id', $id2)->exists();
    echo "  Record 1 exists: " . ($exists1 ? 'yes' : 'no') . "\n";
    echo "  Record 2 exists: " . ($exists2 ? 'yes' : 'no') . "\n";

    // Cleanup
    $db->find()->table('users')->whereIn('id', [$id1, $id2])->delete();
    echo "  Cleaned up test data\n";
} catch (\Exception $e) {
    $db->rollback();
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n";
echo "=== Examples completed ===\n";

