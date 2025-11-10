# Aggregate Helper Functions

Use aggregate functions for calculations and statistics.

## COUNT

### Count All Rows

```php
use tommyknocker\pdodb\helpers\Db;

$total = $db->find()
    ->from('users')
    ->select(Db::count())
    ->getValue();
```

### Count Specific Column

```php
$count = $db->find()
    ->from('users')
    ->select(Db::count('id'))
    ->getValue();
```

### Count Distinct

```php
$unique = $db->find()
    ->from('users')
    ->select(Db::count('DISTINCT country'))
    ->getValue();
```

## SUM

### Sum Values

```php
$total = $db->find()
    ->from('orders')
    ->select(Db::sum('amount'))
    ->where('status', 'completed')
    ->getValue();
```

## AVG

### Average Values

```php
$average = $db->find()
    ->from('products')
    ->select(Db::avg('price'))
    ->getValue();
```

## MIN/MAX

### Minimum Value

```php
$minPrice = $db->find()
    ->from('products')
    ->select(Db::min('price'))
    ->getValue();
```

### Maximum Value

```php
$maxPrice = $db->find()
    ->from('products')
    ->select(Db::max('price'))
    ->getValue();
```

## With GROUP BY

### Grouped Aggregations

```php
$stats = $db->find()
    ->from('orders')
    ->select([
        'user_id',
        'total_orders' => Db::count(),
        'total_amount' => Db::sum('amount'),
        'avg_amount' => Db::avg('amount')
    ])
    ->groupBy('user_id')
    ->get();
```

## Common Patterns

### Sales Statistics

```php
$stats = $db->find()
    ->from('sales')
    ->select([
        'total_sales' => Db::sum('amount'),
        'total_orders' => Db::count(),
        'avg_order' => Db::avg('amount'),
        'min_order' => Db::min('amount'),
        'max_order' => Db::max('amount')
    ])
    ->where('status', 'completed')
    ->getOne();
```

## Next Steps

- [Aggregations](../03-query-builder/05-aggregations.md) - GROUP BY, HAVING
- [Core Helpers](core-helpers.md) - Essential helpers
- [Numeric Helpers](numeric-helpers.md) - Math functions
