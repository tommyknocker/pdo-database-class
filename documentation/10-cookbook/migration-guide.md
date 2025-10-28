# Migration Guide

Migrate from other database libraries to PDOdb.

## From Native PDO

### Before (PDO)

```php
$pdo = new PDO(
    'mysql:host=localhost;dbname=test',
    'user',
    'pass'
);

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute(['user@example.com']);
$users = $stmt->fetchAll();
```

### After (PDOdb)

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'username' => 'user',
    'password' => 'pass'
]);

$users = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->get();
```

## From Eloquent ORM

### Before (Eloquent)

```php
$users = User::where('age', '>', 18)
    ->where('active', true)
    ->orderBy('created_at')
    ->limit(10)
    ->get();
```

### After (PDOdb)

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->where('active', 1)
    ->orderBy('created_at')
    ->limit(10)
    ->get();
```

## From Doctrine DBAL

### Before (Doctrine)

```php
$connection = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => 'localhost',
    'dbname' => 'test',
    'user' => 'user',
    'password' => 'pass'
]);

$users = $connection->fetchAllAssociative(
    'SELECT * FROM users WHERE age > ?',
    [18]
);
```

### After (PDOdb)

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'username' => 'user',
    'password' => 'pass'
]);

$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->get();
```

## Key Differences

### Configuration

```php
// PDOdb uses simple array config
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'username' => 'user',
    'password' => 'pass'
]);
```

### Query Building

```php
// Fluent API
$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->orderBy('created_at')
    ->get();
```

### Prepared Statements

```php
// Automatic binding
$users = $db->find()
    ->from('users')
    ->where('email', $email)
    ->get();

// Manual binding
$users = $db->find()
    ->from('users')
    ->where('email', Db::raw('?'))
    ->bind($email)
    ->get();
```

## Next Steps

- [Installation](../01-getting-started/installation.md) - Install PDOdb
- [Configuration](../01-getting-started/configuration.md) - Configure database
- [Common Patterns](common-patterns.md) - Common patterns
