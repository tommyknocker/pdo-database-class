# Transactions

Manage database transactions with PDOdb for atomic operations.

## Basic Usage

### Start Transaction

```php
$db->startTransaction();

try {
    // Multiple operations
    $userId = $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->find()->table('orders')->insert(['user_id' => $userId, 'total' => 100]);
    
    $db->commit();
    echo "Transaction committed successfully\n";
} catch (\Exception $e) {
    $db->rollBack();
    echo "Transaction rolled back: {$e->getMessage()}\n";
    throw $e;
}
```

## Transaction Methods

### startTransaction()

Begin a new transaction:

```php
$db->startTransaction();
```

### commit()

Commit the current transaction:

```php
$db->commit();
```

### rollBack()

Roll back the current transaction:

```php
$db->rollBack();
```

### transaction() - Callback

Execute a callback within a transaction:

```php
$result = $db->transaction(function($db) {
    $userId = $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Post']);
    return $userId;
});

echo "Transaction completed: $result\n";
```

This automatically commits on success or rolls back on exception.

## Checking Transaction Status

```php
if ($db->inTransaction()) {
    echo "Currently in transaction\n";
}
```

## Common Patterns

### Money Transfer

```php
function transfer($db, $fromId, $toId, $amount) {
    $db->startTransaction();
    
    try {
        // Check balance
        $from = $db->find()
            ->from('users')
            ->where('id', $fromId)
            ->getOne();
        
        if ($from['balance'] < $amount) {
            throw new Exception("Insufficient balance");
        }
        
        // Deduct from sender
        $db->find()
            ->table('users')
            ->where('id', $fromId)
            ->update(['balance' => Db::raw('balance - :amount', ['amount' => $amount])]);
        
        // Add to receiver
        $db->find()
            ->table('users')
            ->where('id', $toId)
            ->update(['balance' => Db::raw('balance + :amount', ['amount' => $amount])]);
        
        // Record transaction
        $db->find()->table('transactions')->insert([
            'from_id' => $fromId,
            'to_id' => $toId,
            'amount' => $amount,
            'created_at' => Db::now()
        ]);
        
        $db->commit();
        return true;
    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }
}
```

### Bulk Insert with Validation

```php
function bulkInsertUsers($db, $users) {
    $db->startTransaction();
    
    try {
        foreach ($users as $user) {
            // Validate
            if (!isset($user['email'])) {
                throw new Exception("Email required");
            }
            
            // Check for duplicates
            $exists = $db->find()
                ->from('users')
                ->where('email', $user['email'])
                ->exists();
            
            if ($exists) {
                throw new Exception("Email {$user['email']} already exists");
            }
            
            // Insert
            $db->find()->table('users')->insert($user);
        }
        
        $db->commit();
        return true;
    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }
}
```

### Update with Lock

```php
function updateWithLock($db, $userId, $newData) {
    $db->startTransaction();
    
    try {
        // Lock the row
        $user = $db->find()
            ->from('users')
            ->where('id', $userId)
            ->forUpdate()  // MySQL/SQLite specific
            ->getOne();
        
        if (!$user) {
            throw new Exception("User not found");
        }
        
        // Update with incremented version
        $db->find()
            ->table('users')
            ->where('id', $userId)
            ->andWhere('version', $user['version'])  // Optimistic locking
            ->update([
                ...$newData,
                'version' => $user['version'] + 1
            ]);
        
        $db->commit();
        return true;
    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }
}
```

## Nested Transactions

PDOdb doesn't support nested transactions directly. Each connection maintains a single transaction state:

```php
$db->startTransaction();

// This doesn't create a nested transaction
$db->transaction(function($db) {
    // This will use the outer transaction
});

$db->commit();
```

## Savepoints

For complex scenarios, you may need to use savepoints:

```php
// Start transaction
$db->startTransaction();

try {
    $userId = $db->find()->table('users')->insert(['name' => 'Alice']);
    
    // Create savepoint
    $db->connection->query("SAVEPOINT sp1");
    
    try {
        $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Post']);
        
        // Rollback to savepoint
    } catch (\Exception $e) {
        $db->connection->query("ROLLBACK TO SAVEPOINT sp1");
    }
    
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
}
```

## Dialect-Specific Behavior

### MySQL

```php
$db->startTransaction();  // Equivalent to START TRANSACTION
// Operations
$db->commit();           // Equivalent to COMMIT
```

### PostgreSQL

```php
$db->startTransaction();  // Equivalent to BEGIN
// Operations
$db->commit();           // Equivalent to COMMIT
```

### SQLite

```php
$db->startTransaction();  // Equivalent to BEGIN TRANSACTION
// Operations
$db->commit();           // Equivalent to COMMIT
```

## Best Practices

### 1. Always Use Try-Catch

```php
$db->startTransaction();

try {
    // Your operations
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    throw $e;
}
```

### 2. Keep Transactions Short

```php
// ❌ Bad: Long-running transaction
$db->startTransaction();
sleep(10);  // Don't do this!
$db->find()->table('users')->insert(['name' => 'Alice']);
$db->commit();

// ✅ Good: Keep transactions short
$db->startTransaction();
$db->find()->table('users')->insert(['name' => 'Alice']);
$db->commit();
```

### 3. Use Transaction Callback

```php
// Convenient wrapper
$result = $db->transaction(function($db) {
    // Automatically commits or rolls back
    return $db->find()->table('users')->insert(['name' => 'Alice']);
});
```

### 4. Don't Commit Twice

```php
$db->startTransaction();
$db->find()->table('users')->insert(['name' => 'Alice']);
$db->commit();

// This will fail - no active transaction
$db->commit();  // Error!
```

## Error Handling

### Connection Lost

```php
use tommyknocker\pdodb\exceptions\TransactionException;

try {
    $db->startTransaction();
    $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->commit();
} catch (TransactionException $e) {
    if ($e->isRetryable()) {
        // Retry the transaction
    }
}
```

### Deadlock Detection

```php
try {
    $db->startTransaction();
    // Operations
    $db->commit();
} catch (TransactionException $e) {
    if (str_contains($e->getMessage(), 'deadlock')) {
        // Retry with backoff
    }
}
```

## Next Steps

- [Table Locking](table-locking.md) - Lock tables for exclusive access
- [Batch Processing](batch-processing.md) - Process large datasets
- [Connection Retry](connection-retry.md) - Automatic retry mechanism
