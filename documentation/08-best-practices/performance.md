# Performance Optimization

Optimize your database queries for better performance.

## Use Indexes

### Create Indexes for Frequently Queried Columns

```sql
-- Single column index
CREATE INDEX idx_users_email ON users(email);

-- Composite index
CREATE INDEX idx_users_name_email ON users(name, email);

-- Unique index
CREATE UNIQUE INDEX idx_users_email ON users(email);
```

### MySQL JSON Index

```sql
-- Virtual column + index for JSON path
ALTER TABLE users 
ADD COLUMN meta_age INT AS (JSON_EXTRACT(meta, '$.age'));

CREATE INDEX idx_meta_age ON users(meta_age);
```

### PostgreSQL JSONB Index

```sql
-- GIN index for JSONB
CREATE INDEX idx_users_meta ON users USING GIN (meta);

-- Index specific JSON path
CREATE INDEX idx_users_meta_city ON users((meta->>'city'));
```

## Limit Result Sets

### Always Use LIMIT

```php
// ❌ Bad: Could load millions of rows
$users = $db->find()->from('users')->get();

// ✅ Good: Limited results
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->orderBy('id', 'DESC')
    ->limit(100)
    ->get();
```

### Pagination

```php
$page = (int) $_GET['page'];
$perPage = 20;
$offset = ($page - 1) * $perPage;

$users = $db->find()
    ->from('users')
    ->orderBy('created_at', 'DESC')
    ->limit($perPage)
    ->offset($offset)
    ->get();
```

## Select Specific Columns

### Don't SELECT *

```php
// ❌ Bad: Selects all columns
$users = $db->find()->from('users')->get();

// ✅ Good: Select only what you need
$users = $db->find()
    ->from('users')
    ->select(['id', 'name', 'email'])
    ->get();
```

## Use Batch Operations

### Multi-Insert

```php
// ❌ Slow: Multiple single inserts
foreach ($users as $user) {
    $db->find()->table('users')->insert($user);
}

// ✅ Fast: Single multi-insert
$db->find()->table('users')->insertMulti($users);
```

### Batch Processing

```php
// Process large datasets in batches
foreach ($db->find()
    ->from('users')
    ->orderBy('id')
    ->batch(1000) as $batch) {
    
    processBatch($batch);
}
```

## Connection Management

### Reuse Connections

```php
// ❌ Bad: Creating new connections repeatedly
function getUsers() {
    $db = new PdoDb('mysql', $config);
    return $db->find()->from('users')->get();
}

// ✅ Good: Reuse connection
class UserRepository {
    protected PdoDb $db;
    
    public function __construct(PdoDb $db) {
        $this->db = $db;
    }
    
    public function getUsers() {
        return $this->db->find()->from('users')->get();
    }
}
```

## Use EXPLAIN to Optimize

### Analyze Query Performance

```php
// Get execution plan
$plan = $db->find()
    ->from('users')
    ->where('email', 'alice@example.com')
    ->explain();

// Check if index is used
print_r($plan);
```

### Check Slow Queries

```php
// Analyze query with timing
$analysis = $db->find()
    ->from('users')
    ->join('orders', 'orders.user_id = users.id')
    ->where('users.created_at', '2023-01-01', '>')
    ->explainAnalyze();

print_r($analysis);
```

## Avoid N+1 Queries

### Bad: N+1 Problem

```php
// ❌ Bad: N+1 queries
$posts = $db->find()->from('posts')->get();

foreach ($posts as $post) {
    $author = $db->find()
        ->from('users')
        ->where('id', $post['user_id'])
        ->getOne();
}
```

### Good: Use JOIN

```php
// ✅ Good: Single query with JOIN
$posts = $db->find()
    ->from('posts AS p')
    ->select([
        'p.id',
        'p.title',
        'p.content',
        'author_name' => 'u.name',
        'author_email' => 'u.email'
    ])
    ->join('users AS u', 'u.id = p.user_id')
    ->get();
```

## Use Transactions for Bulk Operations

### Batch Updates in Transaction

```php
$db->startTransaction();

try {
    foreach ($userIds as $userId) {
        $db->find()
            ->table('users')
            ->where('id', $userId)
            ->update(['status' => 'active']);
    }
    
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    throw $e;
}
```

## Memory Management

### Use Cursor for Large Datasets

```php
// Stream results with minimal memory
foreach ($db->find()
    ->from('users')
    ->orderBy('id')
    ->stream() as $user) {
    
    processUser($user);
}
```

### Process in Batches

```php
// Process data in chunks
foreach ($db->find()
    ->from('users')
    ->orderBy('id')
    ->batch(1000) as $batch) {
    
    foreach ($batch as $user) {
        processUser($user);
    }
    
    // Clear memory
    unset($batch);
    gc_collect_cycles();
}
```

## Query Caching

### Cache Frequent Queries

```php
// Cache results
$cacheKey = 'users_list';
$users = cache()->get($cacheKey);

if (!$users) {
    $users = $db->find()
        ->from('users')
        ->where('active', 1)
        ->orderBy('name')
        ->get();
    
    cache()->set($cacheKey, $users, 3600);  // 1 hour
}

return $users;
```

## Connection Pooling

### Multiple Connections

```php
// Use connection pool
$db = new PdoDb();

$db->addConnection('mysql_main', $mainConfig);
$db->addConnection('mysql_analytics', $analyticsConfig);

// Use appropriate connection
$users = $db->connection('mysql_main')->find()->from('users')->get();
$stats = $db->connection('mysql_analytics')->find()->from('stats')->get();
```

## Denormalization

### Store Frequently Accessed Data

```php
// Instead of always extracting from JSON
$users = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->get();

// Store age in separate column
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'age' => 30,  // Regular column
    'meta' => Db::jsonObject(['city' => 'NYC', 'settings' => [...]])
]);
```

## Monitoring

### Track Query Performance

```php
$start = microtime(true);

$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->get();

$duration = microtime(true) - $start;

if ($duration > 1.0) {
    error_log("Slow query detected: {$duration}s");
}
```

## Next Steps

- [Security](security.md) - Security best practices
- [Memory Management](memory-management.md) - Handle large datasets
- [Common Pitfalls](common-pitfalls.md) - Mistakes to avoid
