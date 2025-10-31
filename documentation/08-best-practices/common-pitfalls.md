# Common Pitfalls

Avoid these common mistakes when using PDOdb.

## 1. Not Using LIMIT

### ❌ Dangerous

```php
// Loads entire table - could be millions of rows!
$users = $db->find()->from('users')->get();
```

### ✅ Safe

```php
// Always limit your results
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(100)
    ->get();
```

## 2. N+1 Query Problem

### ❌ Bad: Multiple Queries

```php
$posts = $db->find()->from('posts')->get();

foreach ($posts as $post) {
    $author = $db->find()
        ->from('users')
        ->where('id', $post['user_id'])
        ->getOne();  // N queries!
}
```

### ✅ Good: Use JOIN

```php
$posts = $db->find()
    ->from('posts AS p')
    ->select([
        'p.id', 'p.title',
        'author_name' => 'u.name',
        'author_email' => 'u.email'
    ])
    ->join('users AS u', 'u.id = p.user_id')
    ->get();  // Single query
```

## 3. Forgetting Transaction Cleanup

### ❌ Bad: No Rollback

```php
$db->startTransaction();
$db->find()->table('users')->insert($user);
// If error occurs here, transaction stays open
$db->commit();
```

### ✅ Good: Always Rollback on Error

```php
$db->startTransaction();
try {
    $db->find()->table('users')->insert($user);
    $db->commit();
} catch (\Exception $e) {
    $db->rollback();
    throw $e;
}
```

## 4. UPDATE Without WHERE

### ❌ Very Bad: Updates All Rows

```php
// Updates EVERY user!
$db->find()->table('users')->update(['status' => 'active']);
```

### ✅ Good: Always Specify WHERE

```php
// Update only specific users
$db->find()
    ->table('users')
    ->where('id', $userId)
    ->update(['status' => 'active']);
```

## 5. String Concatenation in Queries

### ❌ Bad: SQL Injection

```php
$name = $_GET['name'];
$users = $db->rawQuery("SELECT * FROM users WHERE name = '$name'");
```

### ✅ Good: Parameter Binding

```php
$name = $_GET['name'];
$users = $db->find()->from('users')->where('name', $name)->get();
```

## 6. Loading Large Datasets

### ❌ Bad: Memory Exhaustion

```php
$users = $db->find()->from('users')->get();  // 1M users = 1GB+ RAM
```

### ✅ Good: Use Generators

```php
foreach ($db->find()->from('users')->stream() as $user) {
    processUser($user);  // Minimal memory usage
}
```

## 7. Not Using Indexes

### ❌ Bad: Slow Queries

```php
// No index on email column
$user = $db->find()
    ->from('users')
    ->where('email', 'alice@example.com')
    ->getOne();  // Full table scan!
```

### ✅ Good: Create Index

```sql
CREATE INDEX idx_users_email ON users(email);
```

```php
$user = $db->find()
    ->from('users')
    ->where('email', 'alice@example.com')
    ->getOne();  // Fast indexed lookup
```

## 8. Not Checking Results

### ❌ Bad: Assume Success

```php
$db->find()->table('users')->where('id', $id)->update(['name' => 'New Name']);
// No check if row exists or was updated
```

### ✅ Good: Check Affected Rows

```php
$affected = $db->find()
    ->table('users')
    ->where('id', $id)
    ->update(['name' => 'New Name']);

if ($affected === 0) {
    // User not found or no changes
}
```

## 9. Hardcoded Credentials

### ❌ Bad: Expose Credentials

```php
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'root',
    'password' => 'password123',
    'dbname' => 'mydb'
]);
```

### ✅ Good: Use Environment Variables

```php
$db = new PdoDb('mysql', [
    'host' => getenv('DB_HOST'),
    'username' => getenv('DB_USERNAME'),
    'password' => getenv('DB_PASSWORD'),
    'dbname' => getenv('DB_NAME')
]);
```

## 10. Not Using Prepared Statements

### ❌ Bad: String Interpolation

```php
$id = $_GET['id'];
$users = $db->rawQuery("SELECT * FROM users WHERE id = $id");
```

### ✅ Good: Automatic Parameter Binding

```php
$id = $_GET['id'];
$users = $db->find()->from('users')->where('id', $id)->get();
// Automatically uses prepared statements
```

## Summary Checklist

- [ ] Always use LIMIT
- [ ] Avoid N+1 queries with JOINs
- [ ] Use transactions with proper rollback
- [ ] Always specify WHERE for UPDATE/DELETE
- [ ] Never concatenate user input
- [ ] Use batch processing for large datasets
- [ ] Create indexes for frequently queried columns
- [ ] Check affected rows and results
- [ ] Store credentials in environment variables
- [ ] Let PDOdb use prepared statements automatically

## Next Steps

- [Security](security.md) - Security best practices
- [Performance](performance.md) - Performance optimization
- [Memory Management](memory-management.md) - Handle large datasets
