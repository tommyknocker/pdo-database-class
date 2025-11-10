<?php
/**
 * Example 10: Batch Processing
 * 
 * Demonstrates batch(), each(), and stream() methods for efficient processing
 * of large datasets without loading everything into memory.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Batch Processing Examples (on $driver) ===\n\n";

// Setup using fluent API (cross-dialect)
$schema = $db->schema();
recreateTable($db, 'users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(255),
    'email' => $schema->string(255),
    'age' => $schema->integer(),
    'active' => $schema->integer()->defaultValue(1),
    'created_at' => $schema->datetime()->defaultExpression('CURRENT_TIMESTAMP'),
]);

// Insert test data
echo "1. Inserting test data...\n";
$users = [];
for ($i = 1; $i <= 50; $i++) {
    $users[] = [
        'name' => "User {$i}",
        'email' => "user{$i}@example.com",
        'age' => 20 + ($i % 50),
        'active' => $i % 3 === 0 ? 0 : 1
    ];
}
$db->find()->table('users')->insertMulti($users);
echo "✓ Inserted " . count($users) . " users\n\n";

// Example 1: Batch processing
echo "2. Batch processing (processing in groups of 10)...\n";
$batchCount = 0;
$totalProcessed = 0;

foreach ($db->find()->from('users')->orderBy('id')->batch(10) as $batch) {
    $batchCount++;
    $totalProcessed += count($batch);
    echo "  Batch {$batchCount}: Processing " . count($batch) . " users\n";
    
    // Simulate processing each batch
    foreach ($batch as $user) {
        // Process user (e.g., send email, update stats, etc.)
        // echo "    Processing: {$user['name']}\n";
    }
}

echo "✓ Processed {$totalProcessed} users in {$batchCount} batches\n\n";

// Example 2: Each processing
echo "3. Each processing (one record at a time)...\n";
$processedCount = 0;
$activeUsers = 0;

foreach ($db->find()
    ->from('users')
    ->where('active', 1)
    ->orderBy('id')
    ->each(15) as $user) {
    
    $processedCount++;
    if ($user['age'] > 40) {
        $activeUsers++;
    }
    
    // Process individual user
    // echo "  Processing: {$user['name']} (age: {$user['age']})\n";
}

echo "✓ Processed {$processedCount} active users\n";
echo "✓ Found {$activeUsers} users over 40\n\n";

// Example 3: Stream processing (most memory efficient)
echo "4. Stream processing (streaming results)...\n";
$streamCount = 0;
$totalAge = 0;

foreach ($db->find()
    ->from('users')
    ->where('age', 30, '>=')
    ->orderBy('age', 'DESC')
    ->stream() as $user) {
    
    $streamCount++;
    $totalAge += $user['age'];
    
    // Process user with minimal memory usage
    // echo "  User: {$user['name']}, Age: {$user['age']}\n";
}

$averageAge = $streamCount > 0 ? round($totalAge / $streamCount, 2) : 0;
echo "✓ Processed {$streamCount} users aged 30+\n";
echo "✓ Average age: {$averageAge}\n\n";

// Example 4: Complex batch processing with conditions
echo "5. Complex batch processing with conditions...\n";
$youngUsers = 0;
$oldUsers = 0;

foreach ($db->find()
    ->from('users')
    ->where('active', 1)
    ->andWhere('age', 25, '>=')
    ->orderBy('age')
    ->batch(8) as $batch) {
    
    echo "  Processing batch of " . count($batch) . " users\n";
    
    foreach ($batch as $user) {
        if ($user['age'] < 35) {
            $youngUsers++;
        } else {
            $oldUsers++;
        }
    }
}

echo "✓ Young users (25-34): {$youngUsers}\n";
echo "✓ Older users (35+): {$oldUsers}\n\n";

// Example 5: Performance comparison
echo "6. Performance comparison...\n";

// Traditional get() method (loads all data)
$start = microtime(true);
$allUsers = $db->find()->from('users')->get();
$traditionalTime = microtime(true) - $start;
$traditionalMemory = memory_get_peak_usage(true);

echo "  Traditional get(): " . count($allUsers) . " users\n";
echo "  Time: " . round($traditionalTime * 1000, 2) . "ms\n";
echo "  Memory: " . round($traditionalMemory / 1024 / 1024, 2) . "MB\n\n";

// Batch processing
$start = microtime(true);
$batchCount = 0;
foreach ($db->find()->from('users')->batch(10) as $batch) {
    $batchCount += count($batch);
}
$batchTime = microtime(true) - $start;
$batchMemory = memory_get_peak_usage(true);

echo "  Batch processing: {$batchCount} users\n";
echo "  Time: " . round($batchTime * 1000, 2) . "ms\n";
echo "  Memory: " . round($batchMemory / 1024 / 1024, 2) . "MB\n\n";

// Stream processing
$start = microtime(true);
$streamCount = 0;
foreach ($db->find()->from('users')->stream() as $user) {
    $streamCount++;
}
$streamTime = microtime(true) - $start;
$streamMemory = memory_get_peak_usage(true);

echo "  Stream processing: {$streamCount} users\n";
echo "  Time: " . round($streamTime * 1000, 2) . "ms\n";
echo "  Memory: " . round($streamMemory / 1024 / 1024, 2) . "MB\n\n";

// Example 6: Real-world use case - data export
echo "7. Real-world use case: Data export...\n";

function exportUsersToCsv($db, $filename) {
    $file = fopen($filename, 'w');
    if (!$file) {
        throw new RuntimeException("Cannot create file: {$filename}");
    }
    
    // Write CSV header
    fputcsv($file, ['ID', 'Name', 'Email', 'Age', 'Active', 'Created At']);
    
    $exportedCount = 0;
    foreach ($db->find()
        ->from('users')
        ->orderBy('id')
        ->stream() as $user) {
        
        fputcsv($file, [
            $user['id'],
            $user['name'],
            $user['email'],
            $user['age'],
            $user['active'] ? 'Yes' : 'No',
            $user['created_at']
        ]);
        
        $exportedCount++;
    }
    
    fclose($file);
    return $exportedCount;
}

$exportedCount = exportUsersToCsv($db, '/tmp/users_export.csv');
echo "✓ Exported {$exportedCount} users to CSV\n";
echo "✓ File size: " . round(filesize('/tmp/users_export.csv') / 1024, 2) . "KB\n\n";

// Cleanup
unlink('/tmp/users_export.csv');

echo "Batch processing examples completed!\n";
echo "\nKey Takeaways:\n";
echo "  • batch() - Process data in chunks, good for bulk operations\n";
echo "  • each() - Process one record at a time, good for individual processing\n";
echo "  • stream() - Most memory efficient, good for large datasets\n";
echo "  • All methods work with WHERE conditions and ORDER BY\n";
echo "  • Generators provide lazy evaluation and memory efficiency\n";
