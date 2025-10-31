# Comparison Helper Functions

Build complex WHERE conditions using comparison helpers.

## LIKE Pattern Matching

### Basic LIKE

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where(Db::like('email', '%@example.com'))
    ->get();
```

### Case-Insensitive LIKE

```php
$users = $db->find()
    ->from('users')
    ->where(Db::ilike('name', 'john%'))
    ->get();
```

### NOT LIKE

```php
$users = $db->find()
    ->from('users')
    ->where(Db::not(Db::like('email', '%@spam.com')))
    ->get();
```

## BETWEEN Ranges

### Value Range

```php
$users = $db->find()
    ->from('users')
    ->where(Db::between('age', 18, 65))
    ->get();

// SQL: age BETWEEN 18 AND 65
```

### NOT BETWEEN

```php
$users = $db->find()
    ->from('users')
    ->where(Db::notBetween('age', 0, 17))
    ->get();
```

## IN Clauses

### Basic IN

```php
$users = $db->find()
    ->from('users')
    ->where(Db::in('status', ['active', 'pending', 'verified']))
    ->get();
```

### NOT IN

```php
$users = $db->find()
    ->from('users')
    ->where(Db::notIn('status', ['deleted', 'banned']))
    ->get();
```

## NULL Checks

### IS NULL

```php
$users = $db->find()
    ->from('users')
    ->where(Db::isNull('deleted_at'))
    ->get();
```

### IS NOT NULL

```php
$users = $db->find()
    ->from('users')
    ->where(Db::isNotNull('email'))
    ->get();
```

## Coalesce

### Handle NULL Values

```php
$users = $db->find()
    ->from('users')
    ->select([
        'display_name' => Db::coalesce('username', 'email', 'Anonymous')
    ])
    ->get();
```

## Common Patterns

### Search Multiple Fields

```php
$keyword = $_GET['search'];

$users = $db->find()
    ->from('users')
    ->where(function($query) use ($keyword) {
        $query->where(Db::like('name', "%{$keyword}%"))
             ->orWhere(Db::like('email', "%{$keyword}%"))
             ->orWhere(Db::like('bio', "%{$keyword}%"));
    })
    ->get();
```

### Active User Filter

```php
$activeUsers = $db->find()
    ->from('users')
    ->where(Db::isNull('deleted_at'))
    ->andWhere(Db::in('status', ['active', 'verified']))
    ->andWhere(Db::between('age', 18, 65))
    ->get();
```

## Next Steps

- [Filtering Conditions](../03-query-builder/filtering-conditions.md) - Complex WHERE
- [String Helpers](string-helpers.md) - String operations
- [NULL Helpers](null-helpers.md) - NULL handling
