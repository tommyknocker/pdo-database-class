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

// Create test table without indexes on some columns using fluent API
$schema = $db->schema();
$schema->dropTableIfExists('explain_demo');
$schema->createTable('explain_demo', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(100)->notNull(),
    'email' => $schema->string(255),
    'status' => $schema->string(50),
    'created_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
]);

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

// MySQL/MariaDB specific: Filter ratio
if ($analysis->plan->filtered < 100.0) {
    echo '   Filter Ratio: ' . $analysis->plan->filtered . "%\n";
}

// PostgreSQL specific: Query cost
if ($analysis->plan->totalCost !== null) {
    echo '   Query Cost: ' . $analysis->plan->totalCost . "\n";
}

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
// Use schema API for cross-dialect index creation
// Try to create index (will fail if already exists, which is OK for this example)
try {
    $schema->createIndex('idx_explain_demo_status', 'explain_demo', 'status');
} catch (\Exception $e) {
    // Index might already exist, continue
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
$schema->dropTableIfExists('explain_demo');
echo "   ✓ Cleaned up test table\n\n";

echo "=== Examples Complete ===\n";
