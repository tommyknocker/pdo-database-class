# Raw Queries

Execute raw SQL with parameter binding for when the query builder isn't sufficient.

## Basic Raw Queries

### Execute Raw Query

```php
// Get all rows
$users = $db->rawQuery('SELECT * FROM users WHERE active = :active', [
    'active' => 1
]);

// Get single row
$user = $db->rawQueryOne('SELECT * FROM users WHERE id = :id', [
    'id' => 1
]);

// Get single value
$count = $db->rawQueryValue('SELECT COUNT(*) FROM users', []);
```

## Parameter Binding

### Named Placeholders

```php
$users = $db->rawQuery(
    'SELECT * FROM users WHERE age > :min_age AND status = :status',
    [
        'min_age' => 18,
        'status' => 'active'
    ]
);
```

### Positional Placeholders

```php
$users = $db->rawQuery(
    'SELECT * FROM users WHERE age > ? AND status = ?',
    [18, 'active']
);
```

## DDL Operations

### CREATE TABLE

```php
$db->rawQuery('CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,  -- SERIAL in PostgreSQL, INTEGER AUTOINCREMENT in SQLite
    name VARCHAR(255) NOT NULL,           -- TEXT in SQLite
    email VARCHAR(255) NOT NULL          -- TEXT in SQLite
)');
```

### CREATE INDEX

```php
$db->rawQuery('CREATE INDEX idx_users_email ON users(email)');
$db->rawQuery('CREATE UNIQUE INDEX idx_users_email_unique ON users(email)');
```

### ALTER TABLE

```php
$db->rawQuery('ALTER TABLE users ADD COLUMN age INT');
$db->rawQuery('ALTER TABLE users DROP COLUMN age');
```

## WHEN Query Builder Isn't Enough

### Complex Aggregations

```php
$stats = $db->rawQuery('
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count,
        AVG(amount) as avg_amount,
        SUM(amount) as total
    FROM orders
    WHERE created_at >= :start_date
    GROUP BY DATE(created_at)
    HAVING count > :min_count
    ORDER BY date DESC
', [
    'start_date' => '2023-01-01',
    'min_count' => 10
]);
```

### Window Functions

```php
$rankings = $db->rawQuery('
    SELECT 
        name,
        score,
        ROW_NUMBER() OVER (ORDER BY score DESC) as rank
    FROM users
    ORDER BY score DESC
');
```

## Raw Queries in Query Builder

### Using Db::raw() in WHERE

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where(Db::raw('age BETWEEN :min AND :max', [
        'min' => 18,
        'max' => 65
    ]))
    ->get();
```

### Using Db::raw() in SELECT

```php
$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'full_age' => Db::raw('YEAR(CURRENT_DATE) - YEAR(birth_date)')
    ])
    ->get();
```

## Security Reminders

### ✅ Always Use Parameter Binding

```php
// ✅ Safe
$users = $db->rawQuery(
    'SELECT * FROM users WHERE email = :email',
    ['email' => $email]
);
```

### ❌ Never Concatenate

```php
// ❌ DANGEROUS - SQL Injection!
$users = $db->rawQuery("SELECT * FROM users WHERE email = '$email'");
```

## Next Steps

- [SELECT Operations](select-operations.md) - Query builder SELECT
- [Security](../08-best-practices/01-security.md) - Security best practices
- [Helper Functions](../07-helper-functions/01-core-helpers.md) - Helper functions
