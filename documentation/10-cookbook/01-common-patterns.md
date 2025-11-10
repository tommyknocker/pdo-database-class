# Common Patterns

Reusable code patterns for common database operations.

## Pagination

### Basic Pagination

```php
function getUsers($page = 1, $perPage = 20) {
    $offset = ($page - 1) * $perPage;
    
    return $db->find()
        ->from('users')
        ->where('active', 1)
        ->orderBy('created_at', 'DESC')
        ->limit($perPage)
        ->offset($offset)
        ->get();
}
```

### With Total Count

```php
function getUsersWithCount($page = 1, $perPage = 20) {
    $offset = ($page - 1) * $perPage;
    
    $users = $db->find()
        ->from('users')
        ->where('active', 1)
        ->orderBy('created_at', 'DESC')
        ->limit($perPage)
        ->offset($offset)
        ->get();
    
    $total = $db->find()
        ->from('users')
        ->where('active', 1)
        ->select(Db::count())
        ->getValue();
    
    return [
        'users' => $users,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage)
    ];
}
```

## Soft Delete

### Update instead of Delete

```php
function softDelete($db, $userId) {
    $db->find()
        ->table('users')
        ->where('id', $userId)
        ->update([
            'deleted' => 1,
            'deleted_at' => Db::now()
        ]);
}

function getActiveUsers($db) {
    return $db->find()
        ->from('users')
        ->where('deleted', 0)
        ->get();
}
```

## Timestamps

### Automatic Timestamps

```php
use tommyknocker\pdodb\helpers\Db;

// Insert with timestamps
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'created_at' => Db::now(),
    'updated_at' => Db::now()
]);

// Update timestamp
$db->find()
    ->table('users')
    ->where('id', $id)
    ->update([
        'name' => 'Alice Updated',
        'updated_at' => Db::now()
    ]);
```

## Search and Filter

### Basic Search

```php
function searchUsers($keyword) {
    return $db->find()
        ->from('users')
        ->where(Db::like('name', "%{$keyword}%"))
        ->orWhere(Db::like('email', "%{$keyword}%"))
        ->limit(20)
        ->get();
}
```

### Advanced Filtering

```php
function filterUsers($filters = []) {
    $query = $db->find()->from('users');
    
    if (isset($filters['status'])) {
        $query->where('status', $filters['status']);
    }
    
    if (isset($filters['min_age'])) {
        $query->andWhere('age', $filters['min_age'], '>=');
    }
    
    if (isset($filters['max_age'])) {
        $query->andWhere('age', $filters['max_age'], '<=');
    }
    
    return $query->orderBy('name')->get();
}
```

## Caching

### Cache Queries

```php
function getCachedUsers() {
    $cacheKey = 'active_users';
    $users = cache()->get($cacheKey);
    
    if (!$users) {
        $users = $db->find()
            ->from('users')
            ->where('active', 1)
            ->get();
        
        cache()->set($cacheKey, $users, 3600);
    }
    
    return $users;
}
```

## Transaction Wrapper

### Safe Transaction

```php
function safeTransaction($db, callable $callback) {
    $db->startTransaction();
    
    try {
        $result = $callback($db);
        $db->commit();
        return $result;
    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// Usage
safeTransaction($db, function($db) use ($userId, $orderData) {
    $userId = $db->find()->table('users')->insert($userData);
    return $db->find()->table('orders')->insert([
        'user_id' => $userId,
        'total' => $orderData['total']
    ]);
});
```

## Next Steps

- [Real-World Examples](real-world-examples.md) - Complete applications
- [Migration Guide](migration-guide.md) - Migrating from other libraries
- [Troubleshooting](troubleshooting.md) - Common issues
