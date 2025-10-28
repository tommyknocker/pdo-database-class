# Query Builder Basics

Learn how to use the PDOdb query builder fluent API.

## Overview

PDOdb provides a fluent, chainable API for building queries:

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', $config);

$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();
```

## Getting Started

### 1. Start with `find()`

Every query begins with `find()`:

```php
$query = $db->find();
// Returns a QueryBuilder instance
```

### 2. Set the Table

Use `from()` or `table()`:

```php
$db->find()->from('users');
$db->find()->table('users');  // Alias for from()
```

### 3. Build Your Query

Chain methods to build your query:

```php
$db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('age', 18, '>=')
    ->orderBy('name', 'ASC')
    ->limit(20);
```

### 4. Execute

Execute the query:

```php
// Get all matching rows
$users = $db->find()->from('users')->get();

// Get first row
$user = $db->find()->from('users')->getOne();

// Get single value
$count = $db->find()
    ->from('users')
    ->select('COUNT(*)')
    ->getValue();
```

## Query Building Flow

### 1. SELECT Operations

```php
// Basic SELECT
$users = $db->find()
    ->from('users')
    ->select(['id', 'name', 'email'])
    ->get();

// With conditions
$activeUsers = $db->find()
    ->from('users')
    ->where('active', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// With JOIN
$userPosts = $db->find()
    ->from('users AS u')
    ->select(['u.name', 'p.title'])
    ->leftJoin('posts AS p', 'p.user_id = u.id')
    ->get();
```

### 2. INSERT Operations

```php
// Single insert
$id = $db->find()
    ->table('users')
    ->insert([
        'name' => 'Alice',
        'email' => 'alice@example.com'
    ]);

// Multiple inserts
$count = $db->find()
    ->table('users')
    ->insertMulti([
        ['name' => 'Bob', 'email' => 'bob@example.com'],
        ['name' => 'Carol', 'email' => 'carol@example.com']
    ]);
```

### 3. UPDATE Operations

```php
$affected = $db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'name' => 'Alice Updated',
        'updated_at' => Db::now()
    ]);
```

### 4. DELETE Operations

```php
$deleted = $db->find()
    ->table('users')
    ->where('active', 0)
    ->andWhere('last_login', '2020-01-01', '<')
    ->delete();
```

## Method Chaining

All query builder methods return `$this`, allowing method chaining:

```php
$query = $db->find()
    ->from('users AS u')
    ->select(['u.id', 'u.name', 'total' => 'SUM(o.amount)'])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->where('u.active', 1)
    ->andWhere('o.status', 'completed')
    ->groupBy('u.id', 'u.name')
    ->having('SUM(o.amount)', 1000, '>')
    ->orderBy('total', 'DESC')
    ->limit(10);

$results = $query->get();
```

## Common Patterns

### SELECT with Conditions

```php
$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->andWhere('status', 'active')
    ->orWhere('verified', 1)
    ->orderBy('created_at', 'DESC')
    ->get();
```

### INSERT with Returning

```php
// Insert and get the ID
$userId = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com'
]);

// Insert multiple and get count
$count = $db->find()->table('users')->insertMulti([
    ['name' => 'Bob', 'email' => 'bob@example.com'],
    ['name' => 'Carol', 'email' => 'carol@example.com']
]);
```

### UPDATE with Conditions

```php
$affected = $db->find()
    ->table('users')
    ->where('last_login', '2020-01-01', '<')
    ->update([
        'status' => 'inactive',
        'updated_at' => Db::now()
    ]);
```

### DELETE with Conditions

```php
$deleted = $db->find()
    ->table('posts')
    ->where('user_id', 5)
    ->andWhere('deleted', 1)
    ->delete();
```

## Execution Methods

### GET - All Rows

```php
$users = $db->find()->from('users')->get();
// Returns: array<int, array<string, mixed>>
```

### GETONE - First Row

```php
$user = $db->find()->from('users')->getOne();
// Returns: array<string, mixed>|null
```

### GETCOLUMN - Single Column

```php
$names = $db->find()
    ->from('users')
    ->select('name')
    ->getColumn();
// Returns: array<int, string>
```

### GETVALUE - Single Value

```php
$count = $db->find()
    ->from('users')
    ->select('COUNT(*)')
    ->getValue();
// Returns: int|string|float|null
```

## Query Inspection

### Get Generated SQL

```php
$query = $db->find()
    ->from('users')
    ->where('age', 18, '>');

$sqlData = $query->toSQL();
echo "SQL: {$sqlData['sql']}\n";
print_r($sqlData['params']);
```

### Get Last Query

```php
$users = $db->find()->from('users')->get();
echo "Last query: {$db->lastQuery}\n";
```

## Query Options

### Fetch Mode

```php
// Return as objects instead of arrays
$users = $db->find()
    ->from('users')
    ->asObject()
    ->get();

foreach ($users as $user) {
    echo $user->name;  // Object property access
}
```

### Query Options

```php
// Add query options (dialect-specific)
$users = $db->find()
    ->from('users')
    ->option('DISTINCT')
    ->get();

// Or multiple options
$users = $db->find()
    ->from('users')
    ->option(['SQL_CALC_FOUND_ROWS', 'HIGH_PRIORITY'])
    ->get();
```

## Advanced Queries

### Subqueries

```php
// WHERE IN with subquery
$users = $db->find()
    ->from('users')
    ->whereIn('id', function($query) {
        $query->from('orders')
            ->select('user_id')
            ->where('total', 1000, '>');
    })
    ->get();

// WHERE EXISTS
$users = $db->find()
    ->from('users')
    ->whereExists(function($query) {
        $query->from('orders')
            ->where('user_id', Db::raw('users.id'))
            ->where('status', 'completed');
    })
    ->get();
```

### Complex WHERE Conditions

```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere(function($query) {
        $query->where('age', 18, '>')
             ->orWhere('verified', 1);
    })
    ->get();
```

### Aggregations with GROUP BY

```php
$stats = $db->find()
    ->from('orders AS o')
    ->select([
        'user_id',
        'total_orders' => 'COUNT(*)',
        'total_amount' => 'SUM(amount)',
        'avg_amount' => 'AVG(amount)'
    ])
    ->where('status', 'completed')
    ->groupBy('user_id')
    ->having('COUNT(*)', 5, '>')
    ->orderBy('total_amount', 'DESC')
    ->get();
```

## Best Practices

### 1. Reuse Query Builder

```php
// Build base query
$baseQuery = $db->find()
    ->from('users')
    ->where('active', 1);

// Reuse for different executions
$recent = $baseQuery->orderBy('created_at', 'DESC')->limit(10)->get();
$popular = $baseQuery->orderBy('views', 'DESC')->limit(10)->get();
```

### 2. Use Transactions for Multiple Operations

```php
$db->startTransaction();
try {
    $userId = $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Post']);
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    throw $e;
}
```

### 3. Always Use WHERE for UPDATE/DELETE

```php
// ❌ Bad: No WHERE clause
$db->find()->table('users')->update(['status' => 'deleted']);

// ✅ Good: Always specify WHERE
$db->find()
    ->table('users')
    ->where('id', $id)
    ->update(['status' => 'deleted']);
```

## Next Steps

- [SELECT Operations](../03-query-builder/select-operations.md) - Detailed SELECT documentation
- [Data Manipulation](../03-query-builder/data-manipulation.md) - INSERT, UPDATE, DELETE
- [Parameter Binding](parameter-binding.md) - Prepared statements and security

