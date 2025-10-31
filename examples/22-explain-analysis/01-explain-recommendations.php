<?php

declare(strict_types=1);

/**
 * EXPLAIN Analysis with Recommendations Examples.
 *
 * This example demonstrates how to use EXPLAIN analysis with optimization
 * recommendations to identify performance issues and get suggestions.
 *
 * Usage:
 *   php examples/22-explain-analysis/01-explain-recommendations.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

// Get database from environment or default to SQLite
$db = createExampleDb();
$driver = getCurrentDriver($db);

// Setup test table
$driverName = $db->connection->getDriverName();
echo "=== EXPLAIN Analysis with Recommendations Examples ===\n\n";
echo "Driver: $driverName\n\n";

// Create test table without indexes on some columns
if ($driverName === 'pgsql') {
    $db->rawQuery('DROP TABLE IF EXISTS explain_demo CASCADE');
    $db->rawQuery('
        CREATE TABLE explain_demo (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255),
            status VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
} elseif ($driverName === 'mysql') {
    $db->rawQuery('DROP TABLE IF EXISTS explain_demo');
    $db->rawQuery('
        CREATE TABLE explain_demo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255),
            status VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ');
} else { // sqlite
    $db->rawQuery('DROP TABLE IF EXISTS explain_demo');
    $db->rawQuery('
        CREATE TABLE explain_demo (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT,
            status TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
}

// Insert test data
echo "1. Inserting Test Data\n";
echo "   --------------------\n";
for ($i = 1; $i <= 100; $i++) {
    $db->find()
        ->table('explain_demo')
        ->insert([
            'name' => "User $i",
            'email' => "user$i@example.com",
            'status' => $i % 2 === 0 ? 'active' : 'inactive',
        ]);
}
echo "   ✓ Inserted 100 rows\n\n";

// Example 1: Query with index (should not show warnings)
echo "2. Analyzing Query with Index (Primary Key)\n";
echo "   -----------------------------------------\n";
$analysis = $db->find()
    ->from('explain_demo')
    ->where('id', 1)
            ->explainAdvice('explain_demo');

echo '   Access Type: ' . ($analysis->plan->accessType ?? 'N/A') . "\n";
echo '   Used Index: ' . ($analysis->plan->usedIndex ?? 'N/A') . "\n";
echo '   Estimated Rows: ' . $analysis->plan->estimatedRows . "\n";
echo '   Issues: ' . count($analysis->issues) . "\n";
echo '   Recommendations: ' . count($analysis->recommendations) . "\n\n";

// Example 2: Query without index (should show warnings)
echo "3. Analyzing Query without Index (Full Table Scan)\n";
echo "   ------------------------------------------------\n";
$analysis = $db->find()
    ->from('explain_demo')
    ->where('status', 'active')
            ->explainAdvice('explain_demo');

echo '   Access Type: ' . ($analysis->plan->accessType ?? 'N/A') . "\n";
echo '   Table Scans: ' . implode(', ', $analysis->plan->tableScans) . "\n";
echo '   Estimated Rows: ' . $analysis->plan->estimatedRows . "\n";
echo '   Issues: ' . count($analysis->issues) . "\n";
echo '   Recommendations: ' . count($analysis->recommendations) . "\n";

if (!empty($analysis->recommendations)) {
    echo "\n   Recommendations:\n";
    foreach ($analysis->recommendations as $i => $rec) {
        echo '   ' . ($i + 1) . ". [{$rec->severity}] {$rec->type}: {$rec->message}\n";
        if ($rec->suggestion !== null) {
            echo "      Suggestion: {$rec->suggestion}\n";
        }
    }
}
echo "\n";

// Example 3: Create index and analyze again
echo "4. Creating Index and Re-analyzing\n";
echo "   --------------------------------\n";
if ($driverName === 'pgsql') {
    $db->rawQuery('CREATE INDEX IF NOT EXISTS idx_explain_demo_status ON explain_demo(status)');
} elseif ($driverName === 'mysql') {
    $db->rawQuery('CREATE INDEX idx_explain_demo_status ON explain_demo(status)');
} else { // sqlite
    $db->rawQuery('CREATE INDEX IF NOT EXISTS idx_explain_demo_status ON explain_demo(status)');
}
echo "   ✓ Created index on status column\n";

$analysis = $db->find()
    ->from('explain_demo')
    ->where('status', 'active')
            ->explainAdvice('explain_demo');

echo '   Access Type: ' . ($analysis->plan->accessType ?? 'N/A') . "\n";
echo '   Used Index: ' . ($analysis->plan->usedIndex ?? 'N/A') . "\n";
echo '   Table Scans: ' . (empty($analysis->plan->tableScans) ? 'None' : implode(', ', $analysis->plan->tableScans)) . "\n";
echo '   Recommendations: ' . count($analysis->recommendations) . "\n";
echo "   ✓ Query now uses index!\n\n";

// Example 4: Query with ORDER BY (may require filesort)
echo "5. Analyzing Query with ORDER BY\n";
echo "   -----------------------------\n";
$analysis = $db->find()
    ->from('explain_demo')
    ->where('status', 'active')
    ->orderBy('name', 'ASC')
            ->explainAdvice('explain_demo');

echo '   Access Type: ' . ($analysis->plan->accessType ?? 'N/A') . "\n";
echo '   Warnings: ' . count($analysis->plan->warnings) . "\n";
if (!empty($analysis->plan->warnings)) {
    echo "   Warnings:\n";
    foreach ($analysis->plan->warnings as $warning) {
        echo "     - $warning\n";
    }
}
echo '   Recommendations: ' . count($analysis->recommendations) . "\n";
if (!empty($analysis->recommendations)) {
    foreach ($analysis->recommendations as $rec) {
        if ($rec->type === 'filesort') {
            echo "   {$rec->message}\n";
            if ($rec->suggestion !== null) {
                echo "   {$rec->suggestion}\n";
            }
        }
    }
}
echo "\n";

// Example 5: Check for critical issues
echo "6. Checking for Critical Issues\n";
echo "   -----------------------------\n";
$analysis = $db->find()
    ->from('explain_demo')
    ->where('email', 'user50@example.com')
            ->explainAdvice('explain_demo');

if ($analysis->hasCriticalIssues()) {
    echo "   ⚠ Critical issues detected!\n";
} else {
    echo "   ✓ No critical issues\n";
}

if ($analysis->hasRecommendations()) {
    echo "   ℹ Recommendations available\n";
} else {
    echo "   ✓ No recommendations needed\n";
}
echo "\n";

// Cleanup
echo "7. Cleanup\n";
echo "   -------\n";
$db->rawQuery('DROP TABLE IF EXISTS explain_demo');
echo "   ✓ Cleaned up test table\n\n";

echo "=== Examples Complete ===\n";
