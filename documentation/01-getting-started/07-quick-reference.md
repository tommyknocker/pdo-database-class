# Quick Reference

Quick code snippets for common tasks. Copy, paste, and modify as needed.

## Connection

### SQLite (Development)
```php
$db = new PdoDb('sqlite', ['path' => ':memory:']);
```

### MySQL/MariaDB
```php
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb'
]);
```

### PostgreSQL
```php
$db = new PdoDb('pgsql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'port' => 5432
]);
```

### MSSQL
```php
$db = new PdoDb('sqlsrv', [
    'host' => 'localhost',
    'username' => 'sa',
    'password' => 'pass',
    'dbname' => 'mydb',
    'port' => 1433,
    'trust_server_certificate' => true,
    'encrypt' => true
]);
```

## SELECT Queries

### Get All Rows
```php
$users = $db->find()->from('users')->get();
```

### Get Single Row
```php
$user = $db->find()->from('users')->where('id', 1)->getOne();
```

### Get Single Value
```php
$count = $db->find()->from('users')->select('COUNT(*)')->getValue();
```

### Get Single Column
```php
$names = $db->find()->from('users')->select('name')->getColumn();
```

### Select Specific Columns
```php
$users = $db->find()
    ->from('users')
    ->select(['id', 'name', 'email'])
    ->get();
```

### Select with Aliases
```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'user_name' => 'name',
        'user_email' => 'email'
    ])
    ->get();
```

## WHERE Conditions

### Simple Equality
```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->get();
```

### Comparison Operators
```php
$users = $db->find()
    ->from('users')
    ->where('age', 18, '>=')
    ->get();
```

### Multiple Conditions (AND)
```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('age', 18, '>=')
    ->get();
```

### Multiple Conditions (OR)
```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->orWhere('status', 'pending')
    ->get();
```

### IN Clause
```php
$users = $db->find()
    ->from('users')
    ->where('id', [1, 2, 3], 'IN')
    ->get();
```

### BETWEEN
```php
$users = $db->find()
    ->from('users')
    ->where('age', [18, 65], 'BETWEEN')
    ->get();
```

### LIKE
```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where('name', Db::like('%john%'))
    ->get();
```

### IS NULL / IS NOT NULL
```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where('deleted_at', null, 'IS')
    ->get();

$users = $db->find()
    ->from('users')
    ->where('email', null, 'IS NOT')
    ->get();
```

## ORDER BY

### Single Column
```php
$users = $db->find()
    ->from('users')
    ->orderBy('name', 'ASC')
    ->get();
```

### Multiple Columns
```php
$users = $db->find()
    ->from('users')
    ->orderBy('status', 'ASC')
    ->orderBy('created_at', 'DESC')
    ->get();
```

## LIMIT and OFFSET

### Limit Results
```php
$users = $db->find()
    ->from('users')
    ->limit(10)
    ->get();
```

### Pagination
```php
$page = 2;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$users = $db->find()
    ->from('users')
    ->limit($perPage)
    ->offset($offset)
    ->get();
```

## JOINs

### INNER JOIN
```php
$results = $db->find()
    ->from('users AS u')
    ->join('posts AS p', 'p.user_id = u.id')
    ->select(['u.name', 'p.title'])
    ->get();
```

### LEFT JOIN
```php
$results = $db->find()
    ->from('users AS u')
    ->leftJoin('posts AS p', 'p.user_id = u.id')
    ->select(['u.name', 'p.title'])
    ->get();
```

### Multiple JOINs
```php
$results = $db->find()
    ->from('users AS u')
    ->join('posts AS p', 'p.user_id = u.id')
    ->leftJoin('comments AS c', 'c.post_id = p.id')
    ->select(['u.name', 'p.title', 'c.content'])
    ->get();
```

## Aggregations

### COUNT
```php
use tommyknocker\pdodb\helpers\Db;

$count = $db->find()
    ->from('users')
    ->select(Db::count())
    ->getValue();
```

### SUM, AVG, MIN, MAX
```php
use tommyknocker\pdodb\helpers\Db;

$stats = $db->find()
    ->from('orders')
    ->select([
        'total' => Db::sum('amount'),
        'average' => Db::avg('amount'),
        'min' => Db::min('amount'),
        'max' => Db::max('amount')
    ])
    ->getOne();
```

### GROUP BY
```php
$results = $db->find()
    ->from('orders')
    ->select([
        'user_id',
        'total' => Db::sum('amount'),
        'count' => Db::count()
    ])
    ->groupBy('user_id')
    ->get();
```

### HAVING
```php
$results = $db->find()
    ->from('orders')
    ->select([
        'user_id',
        'total' => Db::sum('amount')
    ])
    ->groupBy('user_id')
    ->having('total', 1000, '>')
    ->get();
```

## INSERT

### Single Row
```php
$id = $db->find()
    ->table('users')
    ->insert([
        'name' => 'John',
        'email' => 'john@example.com'
    ]);
```

### Multiple Rows
```php
$users = [
    ['name' => 'Alice', 'email' => 'alice@example.com'],
    ['name' => 'Bob', 'email' => 'bob@example.com']
];

$count = $db->find()
    ->table('users')
    ->insertMulti($users);
```

### Insert with Current Timestamp
```php
use tommyknocker\pdodb\helpers\Db;

$id = $db->find()
    ->table('users')
    ->insert([
        'name' => 'John',
        'email' => 'john@example.com',
        'created_at' => Db::now()
    ]);
```

## UPDATE

### Update Single Row
```php
$affected = $db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'name' => 'John Updated',
        'email' => 'john.new@example.com'
    ]);
```

### Update Multiple Rows
```php
$affected = $db->find()
    ->table('users')
    ->where('status', 'inactive')
    ->update(['status' => 'active']);
```

### Increment/Decrement
```php
use tommyknocker\pdodb\helpers\Db;

$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'views' => Db::inc(),
        'credits' => Db::dec()
    ]);
```

## DELETE

### Delete Single Row
```php
$deleted = $db->find()
    ->table('users')
    ->where('id', 1)
    ->delete();
```

### Delete Multiple Rows
```php
$deleted = $db->find()
    ->table('users')
    ->where('status', 'deleted')
    ->delete();
```

## Transactions

### Basic Transaction
```php
$db->startTransaction();
try {
    $db->find()->table('users')->insert(['name' => 'John']);
    $db->find()->table('posts')->insert(['title' => 'Post']);
    $db->commit();
} catch (\Exception $e) {
    $db->rollback();
    throw $e;
}
```

### Transaction Callback
```php
$result = $db->transaction(function($db) {
    $userId = $db->find()->table('users')->insert(['name' => 'John']);
    $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Post']);
    return $userId;
});
```

## JSON Operations

### Store JSON
```php
$id = $db->find()
    ->table('users')
    ->insert([
        'name' => 'John',
        'metadata' => json_encode(['age' => 30, 'city' => 'NYC'])
    ]);
```

### Query JSON
```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where(Db::jsonContains('metadata', 'NYC'))
    ->get();
```

### Extract JSON Value
```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'age' => Db::jsonExtract('metadata', '$.age')
    ])
    ->get();
```

## Helper Functions

### String Concatenation
```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select([
        'full_name' => Db::concat('first_name', ' ', 'last_name')
    ])
    ->get();
```

### Date Functions
```php
use tommyknocker\pdodb\helpers\Db;

$db->find()
    ->table('users')
    ->where('id', 1)
    ->update(['last_login' => Db::now()]);
```

### NULL Handling
```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'display_name' => Db::coalesce('nickname', 'name')
    ])
    ->get();
```

## Error Handling

### Try-Catch
```php
use tommyknocker\pdodb\exceptions\DatabaseException;

try {
    $users = $db->find()->from('users')->get();
} catch (DatabaseException $e) {
    echo "Error: {$e->getMessage()}\n";
}
```

### Check Last Error
```php
$result = $db->find()->from('users')->get();

if ($db->lastError) {
    echo "Error: {$db->lastError}\n";
    echo "Code: {$db->lastErrNo}\n";
}
```

## Common Patterns

### Pagination Helper
```php
function getUsers($db, $page = 1, $perPage = 20) {
    $offset = ($page - 1) * $perPage;
    
    return [
        'users' => $db->find()
            ->from('users')
            ->where('active', 1)
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get(),
        'total' => $db->find()
            ->from('users')
            ->where('active', 1)
            ->select('COUNT(*)')
            ->getValue()
    ];
}
```

### Soft Delete
```php
use tommyknocker\pdodb\helpers\Db;

// Soft delete
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'deleted' => 1,
        'deleted_at' => Db::now()
    ]);

// Get active users
$users = $db->find()
    ->from('users')
    ->where('deleted', 0)
    ->get();
```

## Next Steps

- [Learning Path](03-learning-path.md) - Structured learning guide
- [API Reference](../09-reference/01-api-reference.md) - Complete API documentation
- [Common Patterns](../10-cookbook/01-common-patterns.md) - More patterns and examples
