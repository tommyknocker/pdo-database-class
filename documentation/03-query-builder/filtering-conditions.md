# Filtering Conditions

Learn how to build complex WHERE and HAVING conditions.

## Basic WHERE

### Simple Equality

```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->get();
```

### Comparison Operators

```php
// Greater than
$users = $db->find()->from('users')->where('age', 18, '>')->get();

// Less than or equal
$users = $db->find()->from('users')->where('age', 65, '<=')->get();

// Not equal
$users = $db->find()->from('users')->where('status', 'inactive', '!=')->get();
```

## Multiple Conditions

### AND Conditions

```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('age', 18, '>=')
    ->andWhere('email', '%@example.com', 'LIKE')
    ->get();
```

### OR Conditions

```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->orWhere('verified', 1)
    ->get();
```

### Mixed AND/OR

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->andWhere(function($query) {
        $query->where('age', 18, '>=')
              ->orWhere('verified', 1);
    })
    ->get();
```

## LIKE and Pattern Matching

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

## IN and NOT IN

### Basic IN

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where(Db::in('status', ['active', 'pending']))
    ->get();
```

### NOT IN

```php
$users = $db->find()
    ->from('users')
    ->where(Db::notIn('status', ['banned', 'deleted']))
    ->get();
```

## BETWEEN

### Value Range

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where(Db::between('age', 18, 65))
    ->get();
```

### NOT BETWEEN

```php
$users = $db->find()
    ->from('users')
    ->where(Db::notBetween('age', 0, 17))
    ->get();
```

## NULL Handling

### IS NULL

```php
use tommyknocker\pdodb\helpers\Db;

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

## Subqueries

### WHERE IN with Subquery

```php
$users = $db->find()
    ->from('users')
    ->whereIn('id', function($query) {
        $query->from('orders')
            ->select('user_id')
            ->where('total', 1000, '>');
    })
    ->get();
```

### WHERE NOT IN with Subquery

```php
$users = $db->find()
    ->from('users')
    ->whereNotIn('id', function($query) {
        $query->from('banned_users')
            ->select('user_id');
    })
    ->get();
```

### WHERE EXISTS

```php
$users = $db->find()
    ->from('users')
    ->whereExists(function($query) {
        $query->from('orders')
            ->where('user_id', 'users.id')
            ->where('status', 'completed');
    })
    ->get();
```

### WHERE NOT EXISTS

```php
$users = $db->find()
    ->from('users')
    ->whereNotExists(function($query) {
        $query->from('bans')
            ->where('user_id', 'users.id')
            ->where('active', 1);
    })
    ->get();
```

## HAVING Clause

### Filter After Aggregation

```php
$stats = $db->find()
    ->from('orders')
    ->select([
        'user_id',
        'total' => Db::sum('amount')
    ])
    ->groupBy('user_id')
    ->having(Db::sum('amount'), 1000, '>')
    ->get();
```

### Multiple HAVING Conditions

```php
$stats = $db->find()
    ->from('orders')
    ->select(['user_id', 'total' => Db::sum('amount'), 'count' => Db::count()])
    ->groupBy('user_id')
    ->having(Db::count(), 10, '>=')
    ->andHaving('total', 5000, '<')
    ->get();
```

## Complex Examples

### User Search

```php
function searchUsers($filters) {
    $query = $db->find()->from('users');
    
    if (isset($filters['keyword'])) {
        $keyword = $filters['keyword'];
        $query->where(function($q) use ($keyword) {
            $q->where(Db::like('name', "%{$keyword}%"))
             ->orWhere(Db::like('email', "%{$keyword}%"));
        });
    }
    
    if (isset($filters['age_min'])) {
        $query->andWhere('age', $filters['age_min'], '>=');
    }
    
    if (isset($filters['age_max'])) {
        $query->andWhere('age', $filters['age_max'], '<=');
    }
    
    if (isset($filters['statuses'])) {
        $query->andWhere(Db::in('status', $filters['statuses']));
    }
    
    return $query->orderBy('created_at', 'DESC')->get();
}
```

## Next Steps

- [Joins](joins.md) - Joining tables
- [Aggregations](aggregations.md) - GROUP BY, HAVING
- [Subqueries](subqueries.md) - Complex subqueries

