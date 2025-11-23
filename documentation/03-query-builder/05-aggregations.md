# Aggregations

Learn how to use aggregate functions with GROUP BY and HAVING.

## Basic Aggregates

### COUNT

```php
use tommyknocker\pdodb\helpers\Db;

$count = $db->find()
    ->from('users')
    ->select(Db::count())
    ->getValue();

// SQL: SELECT COUNT(*) FROM users
```

### SUM

```php
$total = $db->find()
    ->from('orders')
    ->select(Db::sum('amount'))
    ->where('status', 'completed')
    ->getValue();

// SQL: SELECT SUM(amount) FROM orders WHERE status = 'completed'
```

### AVG

```php
$average = $db->find()
    ->from('products')
    ->select(Db::avg('price'))
    ->getValue();
```

### MIN/MAX

```php
$minPrice = $db->find()
    ->from('products')
    ->select(Db::min('price'))
    ->getValue();

$maxPrice = $db->find()
    ->from('products')
    ->select(Db::max('price'))
    ->getValue();
```

## GROUP BY

### Basic GROUP BY

```php
$stats = $db->find()
    ->from('orders')
    ->select([
        'user_id',
        'total_orders' => Db::count(),
        'total_amount' => Db::sum('amount')
    ])
    ->groupBy('user_id')
    ->get();
```

### Multiple Columns

```php
$stats = $db->find()
    ->from('sales')
    ->select([
        'year',
        'month',
        'region',
        'revenue' => Db::sum('amount')
    ])
    ->groupBy('year', 'month', 'region')
    ->get();
```

## HAVING

### Filter After Grouping

```php
$highValueCustomers = $db->find()
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
$popularProducts = $db->find()
    ->from('order_items')
    ->select([
        'product_id',
        'sales_count' => Db::count(),
        'total_quantity' => Db::sum('quantity')
    ])
    ->groupBy('product_id')
    ->having(Db::count(), 10, '>=')
    ->orderBy('sales_count', 'DESC')
    ->get();
```

## Complex Examples

### User Statistics

```php
$userStats = $db->find()
    ->from('users AS u')
    ->select([
        'u.id',
        'u.name',
        'total_orders' => Db::count('o.id'),
        'total_spent' => Db::sum('o.total'),
        'avg_order' => Db::avg('o.total')
    ])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->groupBy('u.id', 'u.name')
    ->having(Db::count('o.id'), 0, '>')
    ->orderBy('total_spent', 'DESC')
    ->get();
```

### Monthly Sales Report

```php
$monthlySales = $db->find()
    ->from('orders')
    ->select([
        'year' => 'YEAR(created_at)',
        'month' => 'MONTH(created_at)',
        'total_sales' => Db::sum('total'),
        'order_count' => Db::count(),
        'avg_order' => Db::avg('total')
    ])
    ->where('status', 'completed')
    ->groupBy('year', 'month')
    ->orderBy('year', 'DESC')
    ->orderBy('month', 'DESC')
    ->get();
```

## Examples

- [Aggregations](../../examples/02-intermediate/02-aggregations.php) - GROUP BY, HAVING, COUNT, SUM, AVG examples

## Next Steps

- [SELECT Operations](01-select-operations.md) - SELECT queries
- [Joins](04-joins.md) - JOIN operations
- [Filtering Conditions](03-filtering-conditions.md) - WHERE clauses
