<?php
/**
 * Example: INSERT ... SELECT Operations
 *
 * Demonstrates INSERT ... SELECT functionality for copying data between tables
 * Supports QueryBuilder, subqueries, CTE, and automatic data type handling
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== INSERT ... SELECT Operations Example (on $driver) ===\n\n";

// Setup source and target tables
$schema = $db->schema();
recreateTable($db, 'source_users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(100),
    'email' => $schema->string(100),
    'age' => $schema->integer(),
    'status' => $schema->string(50),
    'created_at' => $schema->datetime()->defaultExpression('CURRENT_TIMESTAMP'),
]);

recreateTable($db, 'target_users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(100),
    'email' => $schema->string(100),
    'age' => $schema->integer(),
    'status' => $schema->string(50),
    'created_at' => $schema->datetime()->defaultExpression('CURRENT_TIMESTAMP'),
]);

echo "✓ Tables created\n\n";

// Insert source data
echo "1. Inserting source data...\n";
$db->find()->table('source_users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 25, 'status' => 'active']);
$db->find()->table('source_users')->insert(['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 30, 'status' => 'active']);
$db->find()->table('source_users')->insert(['name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 35, 'status' => 'inactive']);
$db->find()->table('source_users')->insert(['name' => 'David', 'email' => 'david@example.com', 'age' => 28, 'status' => 'active']);
echo "  ✓ 4 users inserted into source_users\n\n";

// Example 1: Copy all data from table
echo "2. Example 1: Copy all data from source table...\n";
// Library handles IDENTITY columns automatically (excludes them for MSSQL)
try {
    $affected = $db->find()
        ->table('target_users')
        ->insertFrom('source_users');

    echo "  ✓ Copied {$affected} rows from source_users to target_users\n";
    $count = $db->find()->from('target_users')->select([Db::count()])->getValue();
    echo "  ✓ Total rows in target_users: {$count}\n\n";
} catch (\Exception $e) {
    // If automatic column detection fails (e.g., IDENTITY columns), specify columns explicitly
    $affected = $db->find()
        ->table('target_users')
        ->insertFrom(function ($query) {
            $query->from('source_users')
                ->select(['name', 'email', 'age', 'status', 'created_at']);
        }, ['name', 'email', 'age', 'status', 'created_at']);
    echo "  ✓ Copied {$affected} rows from source_users to target_users (with explicit columns)\n";
    $count = $db->find()->from('target_users')->select([Db::count()])->getValue();
    echo "  ✓ Total rows in target_users: {$count}\n\n";
}

// Clear target for next example
$db->find()->table('target_users')->truncate();

// Example 2: Copy with specific columns
echo "3. Example 2: Copy specific columns only...\n";
$affected = $db->find()
    ->table('target_users')
    ->insertFrom(function ($query) {
        $query->from('source_users')
            ->select(['name', 'age', 'status']);
    }, ['name', 'age', 'status']);

echo "  ✓ Copied {$affected} rows with selected columns\n";
$sample = $db->find()->from('target_users')->where('name', 'Alice')->getOne();
echo "  ✓ Sample row: name='{$sample['name']}', age={$sample['age']}, email=" . ($sample['email'] ?? 'NULL') . "\n\n";

// Clear target for next example
$db->find()->table('target_users')->truncate();

// Example 3: Copy with QueryBuilder filter
echo "4. Example 3: Copy filtered data using QueryBuilder...\n";
// Library handles IDENTITY columns automatically
try {
    $affected = $db->find()
        ->table('target_users')
        ->insertFrom(function ($query) {
            $query->from('source_users')
                ->where('status', 'active');
        });

    echo "  ✓ Copied {$affected} active users to target_users\n";
    $count = $db->find()->from('target_users')->select([Db::count()])->getValue();
    echo "  ✓ Total active users copied: {$count}\n\n";
} catch (\Exception $e) {
    // If automatic column detection fails (e.g., IDENTITY columns), specify columns explicitly
    $affected = $db->find()
        ->table('target_users')
        ->insertFrom(function ($query) {
            $query->from('source_users')
                ->where('status', 'active')
                ->select(['name', 'email', 'age', 'status', 'created_at']);
        }, ['name', 'email', 'age', 'status', 'created_at']);
    echo "  ✓ Copied {$affected} active users to target_users (with explicit columns)\n";
    $count = $db->find()->from('target_users')->select([Db::count()])->getValue();
    echo "  ✓ Total active users copied: {$count}\n\n";
}

// Clear target for next example
$db->find()->table('target_users')->truncate();

// Example 4: Copy with aggregation
echo "5. Example 4: Copy aggregated data...\n";
try {
    // Library handles CAST syntax cross-dialectally
    $affected = $db->find()
        ->table('target_users')
        ->insertFrom(function ($query) {
            $query->from('source_users')
                ->select([
                    'name' => Db::raw("CONCAT('User-', name)"),
                    'email' => Db::raw("CONCAT(LOWER(name), '@company.com')"),
                    'age' => Db::cast(Db::raw('AVG(age)'), 'INTEGER'),
                    'status' => Db::raw("'aggregated'")
                ])
                ->groupBy('name');
        }, ['name', 'email', 'age', 'status']);

    echo "  ✓ Copied {$affected} aggregated rows\n";
    $sample = $db->find()->from('target_users')->first();
    if ($sample) {
        echo "  ✓ Sample aggregated row: name='{$sample['name']}', email='{$sample['email']}'\n";
    }
} catch (\Exception $e) {
    echo "  ⚠ Aggregation with CAST failed: " . $e->getMessage() . "\n";
}
echo "\n";

// Clear target for next example
$db->find()->table('target_users')->truncate();

// Example 5: Copy with CTE (Common Table Expression)
echo "6. Example 5: Copy with CTE (Common Table Expression)...\n";
try {
    $affected = $db->find()
        ->table('target_users')
        ->insertFrom(function ($query) {
            $query->with('filtered_source', function ($q) {
                $q->from('source_users')
                    ->where('age', 30, '>=')
                    ->select(['name', 'email', 'age', 'status']);
            })
            ->from('filtered_source')
            ->select(['name', 'email', 'age', 'status']);
        }, ['name', 'email', 'age', 'status']);

    echo "  ✓ Copied {$affected} rows using CTE\n";
    $count = $db->find()->from('target_users')->select([Db::count()])->getValue();
    echo "  ✓ Total rows copied: {$count}\n\n";
} catch (\Exception $e) {
    echo "  ⚠ CTE not supported: " . $e->getMessage() . "\n\n";
}

// Clear target for next example
$db->find()->table('target_users')->truncate();

// Example 6: Copy with JOIN
echo "7. Example 6: Copy data with JOIN...\n";
recreateTable($db, 'user_profiles', [
    'id' => $schema->primaryKey(),
    'user_id' => $schema->integer(),
    'bio' => $schema->text(),
    'location' => $schema->string(100),
]);

$db->find()->table('user_profiles')->insert(['user_id' => 1, 'bio' => 'Alice bio', 'location' => 'New York']);
$db->find()->table('user_profiles')->insert(['user_id' => 2, 'bio' => 'Bob bio', 'location' => 'London']);

$affected = $db->find()
    ->table('target_users')
    ->insertFrom(function ($query) {
        $query->from('source_users')
            ->join('user_profiles', 'source_users.id = user_profiles.user_id')
            ->select([
                'source_users.name',
                'source_users.email',
                'source_users.age',
                'source_users.status'
            ]);
    }, ['name', 'email', 'age', 'status']);

echo "  ✓ Copied {$affected} rows with JOIN\n";
$count = $db->find()->from('target_users')->select([Db::count()])->getValue();
echo "  ✓ Total rows copied: {$count}\n\n";

// Example 7: Copy with ON DUPLICATE handling
echo "8. Example 7: Copy with ON DUPLICATE handling...\n";
try {
    // Clear target table first
    $db->find()->table('target_users')->truncate();
    
    // Create unique index for conflict handling
    try {
        $schema->dropIndex('target_users_name_unique', 'target_users');
    } catch (\Exception $e) {
        // Index might not exist
    }
    $schema->createIndex('target_users_name_unique', 'target_users', 'name', true);
    
    // Insert initial target data
    $db->find()->table('target_users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 20, 'status' => 'old']);

    // Copy with upsert - library handles dialect-specific syntax automatically
    $affected = $db->find()
        ->table('target_users')
        ->insertFrom(function ($query) {
            $query->from('source_users')
                ->select(['name', 'email', 'age', 'status']);
        }, ['name', 'email', 'age', 'status'], ['age', 'status']);

    echo "  ✓ Upserted {$affected} rows\n";
    $alice = $db->find()->from('target_users')->where('name', 'Alice')->getOne();
    echo "  ✓ Alice updated: age={$alice['age']}, status='{$alice['status']}'\n\n";
} catch (\Exception $e) {
    echo "  ⚠ Upsert not supported: " . $e->getMessage() . "\n\n";
}

// Example 8: Copy with LIMIT
echo "9. Example 8: Copy limited rows...\n";
$db->find()->table('target_users')->truncate();

// Library handles LIMIT cross-dialectally (TOP for MSSQL, LIMIT for others)
$affected = $db->find()
    ->table('target_users')
    ->insertFrom(function ($query) {
        $query->from('source_users')
            ->orderBy('id', 'ASC')
            ->limit(2);
    });

echo "  ✓ Copied {$affected} rows (limited to 2)\n";
$count = $db->find()->from('target_users')->select([Db::count()])->getValue();
echo "  ✓ Total rows in target: {$count}\n\n";

echo "=== INSERT ... SELECT Example Complete ===\n";
echo "\nKey Takeaways:\n";
echo "  • Use insertFrom() to copy data between tables efficiently\n";
echo "  • Support for table names, QueryBuilder, and Closures\n";
echo "  • Automatic parameter merging from source queries\n";
echo "  • Support for CTE, JOINs, aggregations, and filters\n";
echo "  • Column mapping for selective data copying\n";
echo "  • ON DUPLICATE handling for upsert operations\n";

