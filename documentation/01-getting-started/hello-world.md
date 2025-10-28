# Hello World

Your first complete application using PDOdb.

## Complete Example

```php
<?php
require 'vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

// Connect to SQLite (no setup required)
$db = new PdoDb('sqlite', [
    'path' => ':memory:'
]);

// Create tables
$db->rawQuery('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,  -- INT AUTO_INCREMENT in MySQL, SERIAL in PostgreSQL
        name TEXT NOT NULL,                      -- VARCHAR(255) in MySQL/PostgreSQL
        email TEXT NOT NULL,                     -- VARCHAR(255) in MySQL/PostgreSQL
        age INTEGER,                             -- INT in MySQL/PostgreSQL
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP  -- TIMESTAMP in MySQL/PostgreSQL
    )
');

$db->rawQuery('
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,  -- INT AUTO_INCREMENT in MySQL, SERIAL in PostgreSQL
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users (id)
    )
');

echo "=== INSERT ===\n";

// Insert single user
$userId = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'age' => 30
]);
echo "Inserted user with ID: $userId\n";

// Insert multiple users
$users = [
    ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 25],
    ['name' => 'Carol', 'email' => 'carol@example.com', 'age' => 28]
];
$count = $db->find()->table('users')->insertMulti($users);
echo "Inserted $count users\n";

// Insert with current timestamp
$db->find()->table('posts')->insert([
    'user_id' => $userId,
    'title' => 'My First Post',
    'content' => 'This is my first blog post!',
    'created_at' => Db::now()
]);

echo "\n=== SELECT ===\n";

// Get all users
$allUsers = $db->find()->from('users')->get();
foreach ($allUsers as $user) {
    echo "User: {$user['name']} ({$user['email']}) - Age: {$user['age']}\n";
}

// Get single user
$user = $db->find()
    ->from('users')
    ->where('id', $userId)
    ->getOne();
echo "Found user: {$user['name']}\n";

// Get users with conditions
$adults = $db->find()
    ->from('users')
    ->where('age', 18, '>=')
    ->orderBy('age', 'DESC')
    ->limit(5)
    ->get();

echo "\nAdults (age >= 18):\n";
foreach ($adults as $user) {
    echo "- {$user['name']} (Age: {$user['age']})\n";
}

echo "\n=== UPDATE ===\n";

// Update single user
$affected = $db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'age' => 31,
        'email' => 'alice.new@example.com'
    ]);
echo "Updated $affected user(s)\n";

// Increment age
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'age' => Db::inc()
    ]);
echo "Incremented age\n";

echo "\n=== DELETE ===\n";

// Delete users
$deleted = $db->find()
    ->table('users')
    ->where('id', 3)
    ->delete();
echo "Deleted $deleted user(s)\n";

echo "\n=== JOIN ===\n";

// Join users and posts
$results = $db->find()
    ->from('users AS u')
    ->select([
        'u.id',
        'u.name',
        'post_count' => 'COUNT(p.id)'
    ])
    ->leftJoin('posts AS p', 'p.user_id = u.id')
    ->groupBy('u.id', 'u.name')
    ->get();

foreach ($results as $result) {
    echo "User: {$result['name']} has {$result['post_count']} posts\n";
}

echo "\n=== FINAL STATE ===\n";

$finalUsers = $db->find()->from('users')->get();
echo "Total users: " . count($finalUsers) . "\n";
foreach ($finalUsers as $user) {
    echo "- {$user['name']} (ID: {$user['id']})\n";
}
```

## Running the Example

### SQLite (Default - No Setup)

```bash
php hello-world.php
```

### MySQL

```bash
PDODB_DRIVER=mysql php hello-world.php
```

### PostgreSQL

```bash
PDODB_DRIVER=pgsql php hello-world.php
```

## What This Example Demonstrates

1. **Connection** - Connect to a database
2. **Database Structure** - Create tables (DDL operations)
3. **Insert** - Single and multiple inserts
4. **Select** - Basic SELECT queries
5. **Where** - Filtering with conditions
6. **Update** - Updating records
7. **Delete** - Deleting records
8. **Join** - Joining tables
9. **Aggregations** - Using COUNT with GROUP BY

## Key Concepts

### 1. Fluent API

The query builder uses a fluent, chainable API:

```php
$db->find()
    ->from('users')
    ->where('age', 18, '>=')
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->get();
```

### 2. Method Chaining

Methods can be chained together:

```php
$db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('age', 18, '>')
    ->orderBy('created_at', 'DESC')
    ->limit(20);
```

### 3. Prepared Statements

All queries automatically use prepared statements for security:

```php
// Safe from SQL injection
$users = $db->find()
    ->from('users')
    ->where('id', $userId)  // Automatically parameterized
    ->get();
```

### 4. Helper Functions

Use helper functions for common operations:

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()->table('users')->update([
    'age' => Db::inc(),              // Increment by 1
    'updated_at' => Db::now()         // Current timestamp
]);
```

## Next Steps

- [Query Builder Basics](../02-core-concepts/query-builder-basics.md) - Deep dive into the query builder
- [SELECT Operations](../03-query-builder/select-operations.md) - Learn SELECT queries
- [Data Manipulation](../03-query-builder/data-manipulation.md) - INSERT, UPDATE, DELETE
