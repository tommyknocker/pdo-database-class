<?php
/**
 * Examples from README.md
 * 
 * This file demonstrates the main features shown in the README documentation.
 * All examples are designed to work across MySQL, PostgreSQL, and SQLite.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

// Initialize database connection
$config = require __DIR__ . '/../config.sqlite.php';
$db = new PdoDb('sqlite', $config);

echo "=== README Examples Demo ===\n";
echo "Driver: " . getCurrentDriver($db) . "\n\n";

// Clean up and recreate tables
recreateTable($db, 'users', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'name' => 'TEXT NOT NULL',
    'email' => 'TEXT UNIQUE',
    'age' => 'INTEGER',
    'status' => 'TEXT DEFAULT "active"',
    'meta' => 'TEXT',
    'tags' => 'TEXT',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
    'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

recreateTable($db, 'orders', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'user_id' => 'INTEGER NOT NULL',
    'amount' => 'REAL NOT NULL',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
]);

echo "1. Basic CRUD Operations\n";
echo "------------------------\n";

// Insert single row
$userId = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'age' => 30,
    'email' => 'alice@example.com',
    'created_at' => Db::now()
]);
echo "Inserted user ID: $userId\n";

// Insert multiple rows
$insertedCount = $db->find()->table('users')->insertMulti([
    ['name' => 'Bob', 'age' => 25, 'email' => 'bob@example.com'],
    ['name' => 'Charlie', 'age' => 35, 'email' => 'charlie@example.com']
]);
echo "Inserted $insertedCount users\n";

// Update
$affected = $db->find()
    ->table('users')
    ->where('id', $userId)
    ->update([
        'age' => Db::inc(),  // Increment by 1
        'updated_at' => Db::now()
    ]);
echo "Updated $affected rows\n";

// Select
$user = $db->find()
    ->from('users')
    ->select(['id', 'name', 'email', 'age'])
    ->where('id', $userId)
    ->getOne();
echo "User: " . json_encode($user) . "\n";

echo "\n2. Filtering and Joining\n";
echo "-------------------------\n";

// WHERE conditions
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('age', 18, '>')
    ->andWhere(Db::like('email', '%@example.com'))
    ->get();
echo "Found " . count($users) . " active users over 18\n";

// Add some orders
$db->find()->table('orders')->insertMulti([
    ['user_id' => $userId, 'amount' => 150.50],
    ['user_id' => $userId, 'amount' => 200.75],
    ['user_id' => $userId + 1, 'amount' => 99.99]  // Bob's ID
]);

// JOIN with aggregation
$stats = $db->find()
    ->from('users AS u')
    ->select(['u.id', 'u.name', Db::raw('SUM(o.amount) AS total')])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->groupBy('u.id', 'u.name')
    ->having(Db::raw('SUM(o.amount)'), 100, '>')
    ->orderBy('total', 'DESC')
    ->get();
echo "Users with orders > 100: " . count($stats) . "\n";

echo "\n3. JSON Operations\n";
echo "-----------------\n";

// Insert JSON data
$jsonUserId = $db->find()->table('users')->insert([
    'name' => 'John',
    'meta' => Db::jsonObject(['city' => 'NYC', 'age' => 30, 'verified' => true]),
    'tags' => Db::jsonArray('php', 'mysql', 'docker')
]);
echo "Inserted user with JSON data: $jsonUserId\n";

// Query JSON
$adults = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->get();
echo "Users with age > 25 in JSON: " . count($adults) . "\n";

// JSON contains
$phpDevs = $db->find()
    ->from('users')
    ->where(Db::jsonContains('tags', 'php'))
    ->get();
echo "Users with 'php' tag: " . count($phpDevs) . "\n";

// Extract JSON values
$withCity = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'city' => Db::jsonGet('meta', ['city']),
        'age' => Db::jsonGet('meta', ['age'])
    ])
    ->where(Db::jsonExists('meta', ['city']))
    ->get();
echo "Users with city in JSON: " . count($withCity) . "\n";

echo "\n4. Transactions\n";
echo "---------------\n";

$db->startTransaction();
try {
    $newUserId = $db->find()->table('users')->insert(['name' => 'Transaction User']);
    $db->find()->table('orders')->insert(['user_id' => $newUserId, 'amount' => 100]);
    $db->commit();
    echo "Transaction committed successfully\n";
} catch (\Throwable $e) {
    $db->rollBack();
    echo "Transaction rolled back: " . $e->getMessage() . "\n";
}

echo "\n5. Raw Queries\n";
echo "--------------\n";

// Raw query
$users = $db->rawQuery(
    'SELECT * FROM users WHERE age > :age',
    ['age' => 25]
);
echo "Raw query returned " . count($users) . " users\n";

// Raw SQL fragments
$db->find()
    ->table('users')
    ->where('id', $userId)
    ->update([
        'age' => Db::raw('age + :inc', ['inc' => 5]),
        'name' => Db::raw('CONCAT(name, :suffix)', ['suffix' => '_updated'])
    ]);
echo "Updated user with raw SQL\n";

echo "\n6. Complex Conditions\n";
echo "---------------------\n";

// Nested OR conditions
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('age', 18, '>')
    ->orWhere('email', 'alice@example.com')
    ->get();
echo "Complex condition query returned " . count($users) . " users\n";

// Subquery
$users = $db->find()
    ->from('users')
    ->where(Db::raw('id IN (SELECT user_id FROM orders WHERE amount > 100)'))
    ->get();
echo "Subquery returned " . count($users) . " users\n";

echo "\n7. Callback Subqueries (New Feature)\n";
echo "------------------------------------\n";

// Using callbacks for subqueries
$users = $db->find()
    ->from('users')
    ->where('id', function($q) {
        $q->from('orders')
          ->select('user_id')
          ->where('amount', 100, '>');
    }, 'IN')
    ->get();
echo "Callback subquery returned " . count($users) . " users\n";

echo "\n8. Helper Functions\n";
echo "------------------\n";

// Date helpers
$today = $db->find()
    ->from('users')
    ->select([
        'name',
        'created_date' => Db::curDate(),
        'created_time' => Db::curTime()
    ])
    ->where('id', $userId)
    ->getOne();
echo "Date helpers: " . json_encode($today) . "\n";

// Math helpers
$db->find()
    ->table('users')
    ->where('id', $userId)
    ->update([
        'age' => Db::mod('age', 10)  // age % 10
    ]);
echo "Updated age with modulo\n";

echo "\n9. Error Handling Examples\n";
echo "---------------------------\n";

// Demonstrate exception handling
try {
    // This will work fine
    $users = $db->find()->from('users')->get();
    echo "Successfully retrieved " . count($users) . " users\n";
    
    // Try to insert duplicate email (will cause constraint violation)
    $db->find()->table('users')->insert([
        'name' => 'Duplicate User',
        'email' => 'alice@example.com',  // This email already exists
        'age' => 25
    ]);
} catch (\tommyknocker\pdodb\exceptions\ConstraintViolationException $e) {
    echo "Constraint violation caught: " . $e->getMessage() . "\n";
    echo "Constraint: " . $e->getConstraintName() . "\n";
    echo "Table: " . $e->getTableName() . "\n";
    echo "Retryable: " . ($e->isRetryable() ? 'Yes' : 'No') . "\n";
} catch (\tommyknocker\pdodb\exceptions\DatabaseException $e) {
    echo "Database error caught: " . $e->getMessage() . "\n";
    echo "Driver: " . $e->getDriver() . "\n";
    echo "Category: " . $e->getCategory() . "\n";
}

// Demonstrate error context
try {
    // Try invalid query
    $db->find()->from('nonexistent_table')->get();
} catch (\tommyknocker\pdodb\exceptions\QueryException $e) {
    echo "Query error caught: " . $e->getMessage() . "\n";
    echo "Query: " . $e->getQuery() . "\n";
    echo "Context: " . json_encode($e->getContext()) . "\n";
}

echo "\n10. Advanced JSON Operations\n";
echo "-----------------------------\n";

// JSON length and type
$jsonUsers = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'tag_count' => Db::jsonLength('tags'),
        'tags_type' => Db::jsonType('tags')
    ])
    ->where(Db::jsonLength('tags'), 2, '>')
    ->get();
echo "Users with more than 2 tags: " . count($jsonUsers) . "\n";

// JSON ordering
$sortedByAge = $db->find()
    ->from('users')
    ->orderBy(Db::jsonGet('meta', ['age']), 'DESC')
    ->where(Db::jsonExists('meta', ['age']))
    ->get();
echo "Users sorted by JSON age: " . count($sortedByAge) . "\n";

echo "\n11. Query Analysis Examples\n";
echo "---------------------------\n";

// Get SQL without execution
$query = $db->find()
    ->table('users')
    ->where('age', 25, '>')
    ->andWhere('status', 'active')
    ->toSQL();
echo "Generated SQL: " . $query['sql'] . "\n";
echo "Parameters: " . json_encode($query['params']) . "\n";

// Table structure analysis
$structure = $db->find()->table('users')->describe();
echo "Table structure columns: " . count($structure) . "\n";

echo "\n12. Bulk Operations\n";
echo "------------------\n";

// UPSERT example (works differently on SQLite)
try {
    $upsertResult = $db->find()->table('users')->onDuplicate([
        'age' => Db::inc(),
        'updated_at' => Db::now()
    ])->insert([
        'email' => 'upsert@example.com',
        'name' => 'Upsert User',
        'age' => 30
    ]);
    echo "UPSERT result: " . $upsertResult . "\n";
} catch (\Exception $e) {
    // SQLite doesn't support ON DUPLICATE KEY UPDATE, so we'll do a manual upsert
    $existing = $db->find()
        ->from('users')
        ->where('email', 'upsert@example.com')
        ->getOne();
    
    if ($existing) {
        $db->find()
            ->table('users')
            ->where('email', 'upsert@example.com')
            ->update([
                'age' => Db::inc(),
                'updated_at' => Db::now()
            ]);
        echo "Updated existing user\n";
    } else {
        $db->find()->table('users')->insert([
            'email' => 'upsert@example.com',
            'name' => 'Upsert User',
            'age' => 30
        ]);
        echo "Inserted new user\n";
    }
}

// Check if user exists after upsert
$exists = $db->find()
    ->from('users')
    ->where('email', 'upsert@example.com')
    ->exists();
echo "User exists after upsert: " . ($exists ? 'Yes' : 'No') . "\n";

echo "\n=== Demo Complete ===\n";
