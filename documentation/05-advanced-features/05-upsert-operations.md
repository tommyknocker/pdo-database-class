# UPSERT Operations

Insert or update with a single operation using PDOdb's portable UPSERT.

## Basic UPSERT

### onDuplicate()

Insert or update if duplicate key exists:

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()->table('users')
    ->onDuplicate([
        'last_login' => Db::now(),
        'login_count' => Db::inc()
    ])
    ->insert([
        'email' => 'alice@example.com',
        'name' => 'Alice',
        'last_login' => Db::now()
    ]);
```

### Full Example

```php
$db->find()->table('users')
    ->onDuplicate([
        'name' => Db::raw('VALUES(name)'),
        'age' => Db::raw('VALUES(age)'),
        'updated_at' => Db::now()
    ])
    ->insert([
        'email' => 'alice@example.com',  // Unique key
        'name' => 'Alice',
        'age' => 30
    ]);
```

## Generated SQL (Dialect-Specific)

### MySQL

```sql
INSERT INTO users (email, name, age) 
VALUES (:email, :name, :age)
ON DUPLICATE KEY UPDATE 
    name = VALUES(name), 
    age = VALUES(age), 
    updated_at = NOW()
```

### PostgreSQL

```sql
INSERT INTO users (email, name, age) 
VALUES (:email, :name, :age)
ON CONFLICT (email) DO UPDATE SET 
    name = EXCLUDED.name, 
    age = EXCLUDED.age, 
    updated_at = CURRENT_TIMESTAMP
```

### SQLite

```sql
INSERT INTO users (email, name, age) 
VALUES (:email, :name, :age)
ON CONFLICT (email) DO UPDATE SET 
    name = EXCLUDED.name, 
    age = EXCLUDED.age, 
    updated_at = CURRENT_TIMESTAMP
```

## Common Patterns

### Counter Increment

```php
// Insert or increment counter
$db->find()->table('views')
    ->onDuplicate([
        'count' => Db::inc(1),
        'last_viewed' => Db::now()
    ])
    ->insert([
        'page_id' => $pageId,
        'count' => 1,
        'last_viewed' => Db::now()
    ]);
```

### Update Last Active

```php
// Track user activity
$db->find()->table('user_activity')
    ->onDuplicate([
        'last_active' => Db::now(),
        'visit_count' => Db::inc()
    ])
    ->insert([
        'user_id' => $userId,
        'last_active' => Db::now(),
        'visit_count' => 1
    ]);
```

### Update or Create

```php
// Update existing or create new
$db->find()->table('user_settings')
    ->onDuplicate([
        'theme' => Db::raw('VALUES(theme)'),
        'updated_at' => Db::now()
    ])
    ->insert([
        'user_id' => $userId,
        'theme' => 'dark',
        'updated_at' => Db::now()
    ]);
```

## Specifying Conflict Columns

### Multiple Columns

```php
$db->find()->table('user_preferences')
    ->onDuplicate([
        'value' => Db::raw('VALUES(value)'),
        'updated_at' => Db::now()
    ])
    ->insert([
        'user_id' => $userId,
        'key' => 'theme',
        'value' => 'dark',
        'updated_at' => Db::now()
    ]);
// Conflicts on (user_id, key)
```

## REPLACE Operations

### Single Row REPLACE

```php
$db->find()->table('users')->replace([
    'id' => 1,
    'name' => 'Alice Updated',
    'email' => 'alice@example.com'
]);
```

### Multiple Rows

```php
$users = [
    ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
    ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com']
];

$db->find()->table('users')->replaceMulti($users);
```

## Dialect Differences

### MySQL: Native REPLACE

```sql
REPLACE INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com');
```

### PostgreSQL/SQLite: UPSERT Emulation

```sql
INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com')
ON CONFLICT (id) DO UPDATE SET 
    name = EXCLUDED.name, 
    email = EXCLUDED.email
```

## Best Practices

### 1. Use UPSERT for Idempotent Operations

```php
// Safe to call multiple times
function trackUserLogin($userId) {
    $db->find()->table('user_activity')
        ->onDuplicate(['last_login' => Db::now(), 'count' => Db::inc()])
        ->insert(['user_id' => $userId, 'last_login' => Db::now(), 'count' => 1]);
}
```

### 2. Specify All Required Fields

```php
// ✅ Good: Include all data
$db->find()->table('users')
    ->onDuplicate(['updated_at' => Db::now()])
    ->insert([
        'email' => 'alice@example.com',
        'name' => 'Alice',
        'age' => 30
    ]);

// ❌ Bad: Missing data on conflict
$db->find()->table('users')
    ->onDuplicate(['updated_at' => Db::now()])
    ->insert(['email' => 'alice@example.com']);  // Only email set
```

## Examples

- [UPSERT Operations](../../examples/02-intermediate/05-upsert.php) - onDuplicate(), INSERT ... ON CONFLICT

## Next Steps

- [Data Manipulation](../03-query-builder/02-data-manipulation.md) - INSERT, UPDATE, DELETE
- [Batch Operations](04-bulk-operations.md) - Bulk inserts
- [Transactions](01-transactions.md) - Transaction management
