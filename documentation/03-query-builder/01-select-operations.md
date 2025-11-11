# SELECT Operations

Learn how to build SELECT queries with the PDOdb query builder.

## Basic SELECT

### Get All Rows

```php
$users = $db->find()->from('users')->get();
// Returns: array<int, array<string, mixed>>
```

### Get Single Row

```php
$user = $db->find()
    ->from('users')
    ->where('id', 1)
    ->getOne();
// Returns: array<string, mixed>|null
```

### Get Single Value

```php
$count = $db->find()
    ->from('users')
    ->select('COUNT(*)')
    ->getValue();
// Returns: int|string|float|null
```

### Get Single Column

```php
// Get column values (uses first column from select() if not specified)
$names = $db->find()
    ->from('users')
    ->select('name')
    ->getColumn();
// Returns: array<int, string>

// Get specific column from multiple selected columns
$names = $db->find()
    ->from('users')
    ->select(['id', 'name', 'email'])
    ->getColumn('name');
// Returns: array<int, string>

// Get column with index preservation
$names = $db->find()
    ->from('measurements')
    ->select('id,name')
    ->index('id')
    ->getColumn('name');
// Returns: array<int|string, string> (keys preserved from index)
// Example: [1 => 'test1', 2 => 'test2', 3 => 'test3']
```

## Selecting Columns

### Select All Columns

```php
$users = $db->find()->from('users')->get();
// SQL: SELECT * FROM users
```

### Select Specific Columns

```php
$users = $db->find()
    ->from('users')
    ->select(['id', 'name', 'email'])
    ->get();

// SQL: SELECT id, name, email FROM users
```

### Select with Aliases

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'user_name' => 'name',
        'user_email' => 'email',
        'full_name' => Db::concat('first_name', ' ', 'last_name')
    ])
    ->get();

// SQL: SELECT id, name AS user_name, email AS user_email, 
//      CONCAT(first_name, ' ', last_name) AS full_name 
//      FROM users
```

### Select with Expressions

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'age_plus_10' => 'age + 10',
        'full_name' => "CONCAT(first_name, ' ', last_name)"
    ])
    ->get();
```

## WHERE Conditions

### Simple WHERE

```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->get();

// SQL: SELECT * FROM users WHERE status = :status
// Parameters: :status => 'active'
```

### WHERE with Comparison Operators

```php
// Greater than
$adults = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->get();

// Less than or equal
$seniors = $db->find()
    ->from('users')
    ->where('age', 65, '<=')
    ->get();

// Not equal
$inactive = $db->find()
    ->from('users')
    ->where('status', 'active', '!=')
    ->get();
```

### Multiple WHERE Conditions

```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('age', 18, '>=')
    ->andWhere('email', '%@example.com', 'LIKE')
    ->get();

// SQL: SELECT * FROM users WHERE status = :status 
//      AND age >= :age AND email LIKE :email
```

### OR Conditions

```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->orWhere('verified', 1)
    ->get();

// SQL: SELECT * FROM users WHERE status = :status OR verified = :verified
```

### Complex WHERE with Nested Conditions

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere(function($query) {
        $query->where('age', 25, '>=')
              ->orWhere('verified', 1);
    })
    ->get();

// SQL: SELECT * FROM users WHERE status = :status 
//      AND (age >= :age OR verified = :verified)
```

## ORDER BY

### Single Column

```php
$users = $db->find()
    ->from('users')
    ->orderBy('name', 'ASC')
    ->get();

// Or descending
$users = $db->find()
    ->from('users')
    ->orderBy('created_at', 'DESC')
    ->get();
```

### Multiple Columns

```php
$users = $db->find()
    ->from('users')
    ->orderBy('status', 'ASC')
    ->orderBy('created_at', 'DESC')
    ->orderBy('name', 'ASC')
    ->get();

// SQL: SELECT * FROM users ORDER BY status ASC, created_at DESC, name ASC
```

### ORDER BY with Expressions

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->orderBy(Db::concat('first_name', ' ', 'last_name'), 'ASC')
    ->get();
```

## LIMIT and OFFSET

### Limit Results

```php
$users = $db->find()
    ->from('users')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// SQL: SELECT * FROM users ORDER BY created_at DESC LIMIT 10
```

### Pagination with OFFSET

```php
$page = 2;
$perPage = 10;

$users = $db->find()
    ->from('users')
    ->orderBy('name', 'ASC')
    ->limit($perPage)
    ->offset(($page - 1) * $perPage)
    ->get();

// SQL: SELECT * FROM users ORDER BY name ASC LIMIT 10 OFFSET 10
```

### Top N Records

```php
$topUsers = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'score' => 'SUM(points)'
    ])
    ->leftJoin('scores', 'scores.user_id = users.id')
    ->groupBy('users.id', 'users.name')
    ->orderBy('score', 'DESC')
    ->limit(10)
    ->get();
```

## GROUP BY

### Basic GROUP BY

```php
$stats = $db->find()
    ->from('orders')
    ->select([
        'user_id',
        'total_orders' => 'COUNT(*)',
        'total_amount' => 'SUM(amount)'
    ])
    ->groupBy('user_id')
    ->get();
```

### Multiple Columns

```php
$sales = $db->find()
    ->from('sales')
    ->select([
        'year',
        'month',
        'region',
        'revenue' => 'SUM(amount)'
    ])
    ->groupBy('year', 'month', 'region')
    ->get();
```

## HAVING

### Using HAVING

```php
$highValueCustomers = $db->find()
    ->from('orders')
    ->select([
        'user_id',
        'total' => 'SUM(amount)'
    ])
    ->groupBy('user_id')
    ->having('SUM(amount)', 1000, '>')
    ->get();

// SQL: SELECT user_id, SUM(amount) AS total FROM orders 
//      GROUP BY user_id HAVING SUM(amount) > :having_0
```

### HAVING with Aggregate Functions

```php
$popularProducts = $db->find()
    ->from('order_items')
    ->select([
        'product_id',
        'sales_count' => 'COUNT(*)',
        'total_quantity' => 'SUM(quantity)'
    ])
    ->groupBy('product_id')
    ->having('COUNT(*)', 10, '>=')
    ->orderBy('sales_count', 'DESC')
    ->get();
```

## DISTINCT

### Get Distinct Values

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select('email')
    ->option('DISTINCT')
    ->get();

// SQL: SELECT DISTINCT email FROM users
```

### Count Distinct

```php
$uniqueEmails = $db->find()
    ->from('users')
    ->select(Db::count('DISTINCT email'))
    ->getValue();
```

## Common Patterns

### Latest Records

```php
$latestPost = $db->find()
    ->from('posts')
    ->where('published', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(1)
    ->getOne();
```

### Random Records

```php
$randomUser = $db->find()
    ->from('users')
    ->orderBy(Db::raw('RAND()'))
    ->limit(1)
    ->getOne();
```

### Top N by Column

```php
$topSellers = $db->find()
    ->from('products AS p')
    ->select([
        'p.name',
        'total_sold' => 'SUM(oi.quantity)',
        'revenue' => 'SUM(oi.quantity * oi.price)'
    ])
    ->leftJoin('order_items AS oi', 'oi.product_id = p.id')
    ->where('oi.status', 'completed')
    ->groupBy('p.id', 'p.name')
    ->orderBy('total_sold', 'DESC')
    ->limit(10)
    ->get();
```

## Fetch Modes

### Default (Associative Arrays)

```php
$users = $db->find()->from('users')->get();

foreach ($users as $user) {
    echo $user['name'];  // Array access
}
```

### Objects

```php
$users = $db->find()
    ->from('users')
    ->asObject()
    ->get();

foreach ($users as $user) {
    echo $user->name;  // Object property access
}
```

## Checking Results

### EXISTS

```php
$hasActiveUsers = $db->find()
    ->from('users')
    ->where('active', 1)
    ->exists();

// Returns: bool
```

### NOT EXISTS

```php
$noDeletedUsers = $db->find()
    ->from('users')
    ->where('deleted', 1)
    ->notExists();

// Returns: bool
```

## Debugging Queries

### Get SQL and Parameters

```php
$query = $db->find()
    ->from('users')
    ->where('age', 25, '>')
    ->andWhere('status', 'active');

$result = $query->toSQL();
echo "SQL: {$result['sql']}\n";
print_r($result['params']);

// Output:
// SQL: SELECT * FROM users WHERE age > :age AND status = :status
// Array (
//     [:age] => 25
//     [:status] => active
// )
```

### Get Last Query

```php
$users = $db->find()->from('users')->get();

echo "Last query: {$db->lastQuery}\n";
```

## Performance Tips

### 1. Use LIMIT

```php
// ❌ Bad: Could load millions of rows
$users = $db->find()->from('users')->get();

// ✅ Good: Limit results
$users = $db->find()->from('users')->limit(1000)->get();
```

### 2. Use Specific Columns

```php
// ❌ Bad: Select all columns
$users = $db->find()->from('users')->get();

// ✅ Good: Select only what you need
$users = $db->find()
    ->from('users')
    ->select(['id', 'name', 'email'])
    ->get();
```

### 3. Use Indexes

```php
// Create index for frequently queried column
$db->rawQuery('CREATE INDEX idx_users_email ON users(email)');

// Now this query is fast
$user = $db->find()
    ->from('users')
    ->where('email', 'alice@example.com')
    ->getOne();
```

### 4. Use EXPLAIN

```php
$plan = $db->find()
    ->from('users')
    ->where('age', 25, '>')
    ->orderBy('created_at', 'DESC')
    ->explain();

// Check if indexes are used
print_r($plan);
```

## Next Steps

- [Data Manipulation](data-manipulation.md) - INSERT, UPDATE, DELETE
- [Filtering Conditions](filtering-conditions.md) - Complex WHERE clauses
- [Joins](joins.md) - Joining tables
- [Aggregations](aggregations.md) - GROUP BY, HAVING
- [Query Builder Basics](../02-core-concepts/03-query-builder-basics.md) - Fluent API overview
