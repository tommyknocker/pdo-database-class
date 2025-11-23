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
    ->andWhere('active', 1)
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

## From Yii2

### Before (Yii2 ActiveRecord)

```php
use yii\db\ActiveRecord;

class User extends ActiveRecord
{
    public static function tableName()
    {
        return 'users';
    }
}

// Query using ActiveRecord
$users = User::find()
    ->where(['>', 'age', 18])
    ->andWhere(['active' => 1])
    ->orderBy('created_at DESC')
    ->limit(10)
    ->all();
```

### After (PDOdb)

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'username' => 'user',
    'password' => 'pass'
]);

$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->andWhere('active', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();
```

### Before (Yii2 Query Builder)

```php
use Yii;

$users = (new \yii\db\Query())
    ->from('users')
    ->where(['>', 'age', 18])
    ->andWhere(['active' => 1])
    ->orderBy('created_at DESC')
    ->limit(10)
    ->all(Yii::$app->db);
```

### After (PDOdb)

```php
use tommyknocker\pdodb\PdoDb;

$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->andWhere('active', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
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

- [Installation](../01-getting-started/01-installation.md) - Install PDOdb
- [Configuration](../01-getting-started/04-configuration.md) - Configure database
- [Common Patterns](01-common-patterns.md) - Common patterns
