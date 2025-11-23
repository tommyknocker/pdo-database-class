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

### Basic IN with Array

```php
$users = $db->find()
    ->from('users')
    ->whereIn('status', ['active', 'pending'])
    ->get();
```

You can also use the helper function:

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where(Db::in('status', ['active', 'pending']))
    ->get();
```

### NOT IN with Array

```php
$users = $db->find()
    ->from('users')
    ->whereNotIn('status', ['banned', 'deleted'])
    ->get();
```

### AND/OR Variants

```php
// AND variants
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->andWhereIn('status', ['active', 'pending'])
    ->get();

// OR variants
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->orWhereIn('status', ['pending', 'suspended'])
    ->get();
```

## BETWEEN

### Value Range

```php
$users = $db->find()
    ->from('users')
    ->whereBetween('age', 18, 65)
    ->get();
```

You can also use the helper function:

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
    ->whereNotBetween('age', 0, 17)
    ->get();
```

### AND/OR Variants

```php
// AND variants
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->andWhereBetween('age', 18, 65)
    ->get();

// OR variants
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->orWhereBetween('age', 18, 65)
    ->get();
```

## NULL Handling

### IS NULL

```php
$users = $db->find()
    ->from('users')
    ->whereNull('deleted_at')
    ->get();
```

You can also use the helper function:

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
    ->whereNotNull('email')
    ->get();
```

### AND/OR Variants

```php
// AND variants
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->andWhereNull('deleted_at')
    ->get();

$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->andWhereNotNull('email')
    ->get();

// OR variants
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->orWhereNull('deleted_at')
    ->get();

$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->orWhereNotNull('email')
    ->get();
```

## Column Comparison

### Compare Columns

```php
// Compare two columns
$products = $db->find()
    ->from('products')
    ->whereColumn('quantity', '=', 'threshold')
    ->get();

// Use different operators
$products = $db->find()
    ->from('products')
    ->whereColumn('price', '>', 'cost')
    ->get();

// AND/OR variants
$products = $db->find()
    ->from('products')
    ->where('active', 1)
    ->andWhereColumn('quantity', '>', 'threshold')
    ->get();

$products = $db->find()
    ->from('products')
    ->where('active', 1)
    ->orWhereColumn('price', '<', 'cost')
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

Note: `whereIn` and `whereNotIn` support both arrays and subqueries.

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

## Examples

- [WHERE Conditions](../../examples/01-basic/03-where-conditions.php) - Filtering examples

## Next Steps

- [Joins](04-joins.md) - Joining tables
- [Aggregations](05-aggregations.md) - GROUP BY, HAVING
- [Subqueries](07-subqueries.md) - Complex subqueries
