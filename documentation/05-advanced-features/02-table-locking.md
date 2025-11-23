# Table Locking

Lock tables for exclusive access during critical operations.

## Basic Table Locking

### Lock in WRITE Mode

```php
// Lock tables for exclusive write access
$db->lock(['users', 'orders'])->setLockMethod('WRITE');

try {
    // Perform exclusive operations
    $db->find()->table('users')->where('id', 1)->update(['balance' => 100]);
    $db->find()->table('orders')->insert(['user_id' => 1, 'total' => 50]);
} finally {
    // Always unlock
    $db->unlock();
}
```

### Lock in READ Mode

```php
// Lock for read consistency
$db->lock(['users'])->setLockMethod('READ');

try {
    // Read operations with consistent snapshot
    $users = $db->find()->from('users')->get();
} finally {
    $db->unlock();
}
```

## Dialect Differences

### MySQL

```sql
LOCK TABLES users WRITE, orders WRITE;
-- Operations
UNLOCK TABLES;
```

### PostgreSQL

```sql
LOCK TABLE users, orders IN EXCLUSIVE MODE;
-- Operations
COMMIT;  -- Unlock
```

### SQLite

```sql
BEGIN IMMEDIATE;
-- Operations
COMMIT;
```

PDOdb handles these differences automatically.

## Common Use Cases

### Critical Section

```php
function updateBalance($userId, $amount) {
    $db->lock(['users']);
    
    try {
        $user = $db->find()
            ->from('users')
            ->where('id', $userId)
            ->getOne();
        
        $newBalance = $user['balance'] + $amount;
        
        $db->find()
            ->table('users')
            ->where('id', $userId)
            ->update(['balance' => $newBalance]);
    } finally {
        $db->unlock();
    }
}
```

### Prevent Concurrent Updates

```php
$db->lock(['products']);

try {
    $product = $db->find()
        ->from('products')
        ->where('id', $productId)
        ->getOne();
    
    if ($product['stock'] >= $quantity) {
        $db->find()
            ->table('products')
            ->where('id', $productId)
            ->update(['stock' => Db::dec($quantity)]);
    }
} finally {
    $db->unlock();
}
```

## Best Practices

### Always Unlock

```php
// ✅ Good: Always unlock in finally
$db->lock(['users']);

try {
    // Operations
} finally {
    $db->unlock();  // Always executed
}
```

### Keep Locks Short

```php
// ✅ Good: Quick lock
$db->lock(['users']);
try {
    // Quick operations
} finally {
    $db->unlock();
}

// ❌ Bad: Long-running lock
$db->lock(['users']);
sleep(10);  // Don't do this!
try {
    // Operations
} finally {
    $db->unlock();
}
```

## Examples

- [Table Locking](../../examples/06-locking/01-table-locking.php) - READ and WRITE locks

## Next Steps

- [Transactions](01-transactions.md) - Transaction management
- [Batch Processing](03-batch-processing.md) - Handle large datasets
- [Performance](../08-best-practices/02-performance.md) - Optimization
