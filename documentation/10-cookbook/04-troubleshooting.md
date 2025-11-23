# Troubleshooting

Common issues and solutions when using PDOdb.

## Connection Issues

### "Driver not found" Error

**Problem:** PDO extension not installed.

**Solution:**
```bash
# Ubuntu/Debian
sudo apt-get install php8.4-mysql php8.4-pgsql php8.4-sqlite3

# MSSQL requires additional setup
curl https://packages.microsoft.com/keys/microsoft.asc | sudo apt-key add -
curl https://packages.microsoft.com/config/ubuntu/$(lsb_release -rs)/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt-get update
sudo ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev
sudo pecl install pdo_sqlsrv

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

### MSSQL Connection Issues

**Problem:** "Login failed for user" or "Driver not found"

**Solutions:**

1. **Verify pdo_sqlsrv extension is installed:**
```bash
php -m | grep pdo_sqlsrv
```

2. **Check MSSQL server is running:**
```bash
# Linux
sudo systemctl status mssql-server

# Windows
# Check SQL Server service in Services
```

3. **Verify connection string:**
```php
// MSSQL requires trust_server_certificate for local development
$db = new PdoDb('sqlsrv', [
    'host' => 'localhost',
    'username' => 'sa',
    'password' => 'your_password',
    'dbname' => 'testdb',
    'port' => 1433,
    'trust_server_certificate' => true,  // Required!
    'encrypt' => true
]);
```

4. **Check SQL Server authentication mode:**
```sql
-- Run in SQL Server Management Studio
SELECT SERVERPROPERTY('IsIntegratedSecurityOnly') AS 'Login Mode';
-- 0 = Mixed Mode (SQL Server + Windows), 1 = Windows Only
```

5. **Enable SQL Server authentication (if needed):**
```sql
-- Enable mixed mode authentication
EXEC xp_instance_regwrite N'HKEY_LOCAL_MACHINE', 
     N'Software\Microsoft\MSSQLServer\MSSQLServer', 
     N'LoginMode', REG_DWORD, 2;
-- Then restart SQL Server service
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

- [Common Patterns](01-common-patterns.md) - Reusable patterns
- [Real-World Examples](02-real-world-examples.md) - Complete apps
- [Best Practices](../08-best-practices/01-security.md) - Security and performance
