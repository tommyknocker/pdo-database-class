# Troubleshooting

Common issues and solutions when using PDOdb.

## Connection Issues

### "Driver not found" Error

**Problem:** PDO extension not installed.

**Solution:**
```bash
# Ubuntu/Debian
sudo apt-get install php8.4-mysql php8.4-pgsql php8.4-sqlite3

# Check installed extensions
php -m | grep pdo
```

### "Connection refused"

**Problem:** Database server not running or wrong credentials.

**Solution:**
```php
// Test connection
try {
    $db = new PdoDb('mysql', [
        'host' => 'localhost',
        'username' => 'user',
        'password' => 'pass',
        'dbname' => 'mydb'
    ]);
    
    if ($db->ping()) {
        echo "Connected successfully\n";
    }
} catch (\Exception $e) {
    echo "Connection failed: {$e->getMessage()}\n";
}
```

## JSON Support Issues

### "JSON functions not available" (SQLite)

**Problem:** SQLite compiled without JSON1 extension.

**Solution:**
```bash
# Check JSON support
sqlite3 :memory: "SELECT json_valid('{}')"

# If error, SQLite needs recompilation with JSON support
```

## Query Issues

### "SQLSTATE[HY000]: General error: 1 near 'OFFSET'"

**Problem:** Using OFFSET without LIMIT in SQLite.

**Solution:**
```php
// ❌ Doesn't work in SQLite
$db->find()->from('users')->offset(10)->get();

// ✅ Works
$db->find()
    ->from('users')
    ->limit(20)
    ->offset(10)
    ->get();
```

### "Column not found" Error

**Problem:** Table or column doesn't exist.

**Solution:**
```php
// Check if table exists
$exists = $db->find()->table('users')->tableExists();

if (!$exists) {
    // Create table
    $db->rawQuery('CREATE TABLE users (...)');
}
```

## Performance Issues

### Slow Queries

**Problem:** Missing indexes.

**Solution:**
```sql
-- Create index
CREATE INDEX idx_users_email ON users(email);

-- Check with EXPLAIN
```

```php
// Analyze query
$plan = $db->find()
    ->from('users')
    ->where('email', 'alice@example.com')
    ->explain();

print_r($plan);
```

### Memory Exhaustion

**Problem:** Loading too much data.

**Solution:**
```php
// ✅ Use batch processing
foreach ($db->find()
    ->from('users')
    ->orderBy('id')
    ->batch(1000) as $batch) {
    
    processBatch($batch);
}

// ✅ Use stream for streaming
foreach ($db->find()
    ->from('users')
    ->stream() as $user) {
    
    processUser($user);
}
```

## Transaction Issues

### "Already in transaction"

**Problem:** Nested transactions.

**Solution:**
```php
// Check transaction status
if (!$db->inTransaction()) {
    $db->startTransaction();
}

// Or use transaction callback
$result = $db->transaction(function($db) {
    return $db->find()->table('users')->insert(['name' => 'Alice']);
});
```

## Best Practices

1. Always use prepared statements (automatic in PDOdb)
2. Use transactions for multiple operations
3. Create indexes for frequently queried columns
4. Use LIMIT for all SELECT queries
5. Monitor slow queries with EXPLAIN

## Getting Help

1. Check error messages carefully
2. Enable query logging
3. Use EXPLAIN to analyze queries
4. Check database-specific documentation
5. Review PDOdb examples

## Next Steps

- [Common Patterns](common-patterns.md) - Reusable patterns
- [Real-World Examples](real-world-examples.md) - Complete apps
- [Best Practices](../08-best-practices/security.md) - Security and performance
