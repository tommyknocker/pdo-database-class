# Subqueries

Learn how to use subqueries in WHERE, SELECT, and JOIN clauses.

## WHERE IN with Subquery

### Using Callable

```php
// Find users who have placed orders > $1000
$users = $db->find()
    ->from('users')
    ->whereIn('id', function($query) {
        $query->from('orders')
            ->select('user_id')
            ->where('total', 1000, '>');
    })
    ->get();
```

### Using QueryBuilder Instance

```php
// Build subquery first
$subquery = $db->find()
    ->from('orders')
    ->select('user_id')
    ->where('total', 1000, '>');

// Use in main query
$users = $db->find()
    ->from('users')
    ->where('id', $subquery, 'IN')
    ->get();
```

## WHERE NOT IN with Subquery

```php
// Find users who have never ordered
$users = $db->find()
    ->from('users')
    ->whereNotIn('id', function($query) {
        $query->from('orders')->select('user_id');
    })
    ->get();
```

## WHERE EXISTS

### Simple EXISTS

```php
// Find users who have orders
$users = $db->find()
    ->from('users')
    ->whereExists(function($query) {
        $query->from('orders')
            ->where('user_id', Db::raw('users.id'))
            ->where('status', 'completed');
    })
    ->get();
```

### NOT EXISTS

```php
// Find users with no orders
$users = $db->find()
    ->from('users')
    ->whereNotExists(function($query) {
        $query->from('orders')
            ->where('user_id', Db::raw('users.id'));
    })
    ->get();
```

## External Reference Detection

PDOdb automatically detects external table references:

```php
// Automatic detection - no manual wrapping needed
$users = $db->find()
    ->from('users')
    ->whereExists(function($query) {
        $query->from('orders')
            ->where('user_id', 'users.id')  // Auto-detected
            ->where('total', Db::raw('users.balance'), '>')  // Auto-detected
            ->where('status', 'completed');
    })
    ->get();
```

## Subquery in SELECT

### Scalar Subquery

```php
// Get user with order count
$users = $db->find()
    ->from('users AS u')
    ->select([
        'u.id',
        'u.name',
        'order_count' => function($q) {
            $q->from('orders')
              ->select(Db::count())
              ->where('user_id', Db::raw('u.id'));
        }
    ])
    ->get();
```

### Using Db::raw() for Complex Subqueries

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users AS u')
    ->select([
        'u.id',
        'u.name',
        'total_orders' => Db::raw('(
            SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id
        )'),
        'total_spent' => Db::raw('(
            SELECT COALESCE(SUM(total), 0) FROM orders o WHERE o.user_id = u.id
        )')
    ])
    ->get();
```

## Correlated Subqueries

### Correlated EXISTS

```php
// Find products that have been ordered recently
$products = $db->find()
    ->from('products AS p')
    ->whereExists(function($query) {
        $query->from('order_items AS oi')
            ->where('oi.product_id', Db::raw('p.id'))
            ->where('oi.created_at', Db::now('-30 DAYS'), '>');
    })
    ->get();
```

## Complex Examples

### Users with Orders Above Average

```php
// Get average order total
$avgOrder = $db->find()
    ->from('orders')
    ->select(Db::avg('total'))
    ->getValue();

// Find users with orders above average
$users = $db->find()
    ->from('users')
    ->whereIn('id', function($query) use ($avgOrder) {
        $query->from('orders')
            ->select('user_id')
            ->where('total', $avgOrder, '>');
    })
    ->get();
```

### Products Never Ordered

```php
$products = $db->find()
    ->from('products')
    ->whereNotExists(function($query) {
        $query->from('order_items')
            ->where('product_id', Db::raw('products.id'));
    })
    ->get();
```

## Performance Considerations

### Use JOIN Instead of Subquery

```php
// ❌ Slow: Subquery in SELECT
$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'order_count' => Db::raw('(SELECT COUNT(*) FROM orders WHERE user_id = users.id)')
    ])
    ->get();

// ✅ Faster: Use JOIN
$users = $db->find()
    ->from('users AS u')
    ->select([
        'u.name',
        'order_count' => Db::count('o.id')
    ])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->groupBy('u.id', 'u.name')
    ->get();
```

## Next Steps

- [SELECT Operations](select-operations.md) - SELECT queries
- [Joins](joins.md) - JOIN operations
- [Filtering Conditions](filtering-conditions.md) - WHERE clauses
