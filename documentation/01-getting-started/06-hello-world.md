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
// What's happening: insert() returns the auto-increment ID (or last insert ID)
// This works the same way across all databases (MySQL, PostgreSQL, SQLite, MSSQL)
$userId = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'age' => 30
]);
echo "Inserted user with ID: $userId\n";

// Insert multiple users
// What's happening: insertMulti() inserts multiple rows in a single query
// This is much faster than calling insert() multiple times
$users = [
    ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 25],
    ['name' => 'Carol', 'email' => 'carol@example.com', 'age' => 28]
];
$count = $db->find()->table('users')->insertMulti($users);
echo "Inserted $count users\n";

// Insert with current timestamp
// What's happening: Db::now() generates the current timestamp in a database-agnostic way
// It uses CURRENT_TIMESTAMP for MySQL/PostgreSQL/SQLite and GETDATE() for MSSQL
$db->find()->table('posts')->insert([
    'user_id' => $userId,
    'title' => 'My First Post',
    'content' => 'This is my first blog post!',
    'created_at' => Db::now()
]);

echo "\n=== SELECT ===\n";

// Get all users
// What's happening: get() executes the query and returns all matching rows as an array
// Each row is an associative array with column names as keys
$allUsers = $db->find()->from('users')->get();
foreach ($allUsers as $user) {
    echo "User: {$user['name']} ({$user['email']}) - Age: {$user['age']}\n";
}

// Get single user
// What's happening: getOne() returns the first row or null if no rows found
// This is more efficient than get() when you only need one row
$user = $db->find()
    ->from('users')
    ->where('id', $userId)
    ->getOne();
echo "Found user: {$user['name']}\n";

// Get users with conditions
// What's happening: 
// - where('age', 18, '>=') filters rows where age >= 18
// - orderBy() sorts results (DESC = descending, ASC = ascending)
// - limit() restricts the number of rows returned
// Always use limit() in production to avoid loading too much data!
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
// What's happening: Db::inc() increments a numeric column by 1
// This is database-agnostic and safer than raw SQL like "age = age + 1"
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
// What's happening:
// - leftJoin() includes all users, even if they have no posts
// - Table aliases ('u' and 'p') make queries shorter and clearer
// - groupBy() is required when using aggregate functions like COUNT()
// - 'post_count' => 'COUNT(p.id)' creates an alias for the count column
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

## Common Mistakes to Avoid

### 1. Forgetting LIMIT
```php
// ❌ Dangerous: Could load millions of rows!
$users = $db->find()->from('users')->get();

// ✅ Safe: Always limit results
$users = $db->find()->from('users')->limit(100)->get();
```

### 2. UPDATE Without WHERE
```php
// ❌ Very bad: Updates ALL users!
$db->find()->table('users')->update(['status' => 'active']);

// ✅ Good: Always specify WHERE
$db->find()->table('users')->where('id', 1)->update(['status' => 'active']);
```

### 3. Not Using Transactions for Multiple Operations
```php
// ❌ Bad: If second insert fails, first one stays
$db->find()->table('users')->insert(['name' => 'Alice']);
$db->find()->table('posts')->insert(['title' => 'Post']);

// ✅ Good: Use transactions
$db->transaction(function($db) {
    $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->find()->table('posts')->insert(['title' => 'Post']);
});
```

### 4. String Concatenation in Queries
```php
// ❌ Bad: SQL injection risk!
$name = $_GET['name'];
$users = $db->rawQuery("SELECT * FROM users WHERE name = '$name'");

// ✅ Good: Use parameter binding (automatic in PDOdb)
$name = $_GET['name'];
$users = $db->find()->from('users')->where('name', $name)->get();
```

## What's Next?

Now that you understand the basics:

1. **Practice:** Modify this example and experiment
2. **Learn More:** Follow the [Learning Path](03-learning-path.md)
3. **Reference:** Use [Quick Reference](07-quick-reference.md) for common tasks
4. **Deep Dive:** Read [Query Builder Basics](../02-core-concepts/03-query-builder-basics.md)

## Next Steps

- [Learning Path](03-learning-path.md) - Structured learning guide
- [Quick Reference](07-quick-reference.md) - Common tasks with code snippets
- [Query Builder Basics](../02-core-concepts/03-query-builder-basics.md) - Deep dive into the query builder
- [SELECT Operations](../03-query-builder/01-select-operations.md) - Learn SELECT queries
- [Data Manipulation](../03-query-builder/02-data-manipulation.md) - INSERT, UPDATE, DELETE
