# FILTER Clause for Aggregate Functions

The `FILTER` clause allows conditional aggregation without subqueries, making queries more readable and efficient. It's part of the SQL:2003 standard and automatically falls back to `CASE WHEN` on MySQL.

## Overview

Use `FILTER` for:
- Conditional aggregations in a single query
- Calculating multiple metrics with different conditions
- Cleaner alternative to `CASE WHEN` expressions
- Cross-database compatibility (automatic fallback)

## Basic Usage

### Simple FILTER

```php
use tommyknocker\pdodb\helpers\Db;

$results = $db->find()
    ->from('orders')
    ->select([
        'total_orders' => Db::count('*'),
        'paid_orders' => Db::count('*')->filter('status', 'paid'),
        'pending_orders' => Db::count('*')->filter('status', 'pending'),
    ])
    ->get();

// Result: [
//     'total_orders' => 100,
//     'paid_orders' => 75,
//     'pending_orders' => 25
// ]
```

### With SUM

```php
$results = $db->find()
    ->from('orders')
    ->select([
        'total_revenue' => Db::sum('amount'),
        'paid_revenue' => Db::sum('amount')->filter('status', 'paid'),
        'pending_amount' => Db::sum('amount')->filter('status', 'pending'),
    ])
    ->get();
```

## With GROUP BY

The real power of `FILTER` shines with `GROUP BY`:

```php
$results = $db->find()
    ->from('orders')
    ->select([
        'user_id',
        'total_orders' => Db::count('*'),
        'paid_orders' => Db::count('*')->filter('status', 'paid'),
        'pending_amount' => Db::sum('amount')->filter('status', 'pending'),
        'high_value_orders' => Db::count('*')->filter('amount', 100, '>'),
    ])
    ->groupBy('user_id')
    ->get();
```

## All Aggregate Functions

`FILTER` works with all aggregate functions:

### COUNT with FILTER

```php
Db::count('*')->filter('status', 'active')
Db::count('*')->filter('amount', 100, '>')
```

### SUM with FILTER

```php
Db::sum('amount')->filter('status', 'paid')
Db::sum('quantity')->filter('category', 'Electronics')
```

### AVG with FILTER

```php
Db::avg('rating')->filter('verified', 1)
Db::avg('price')->filter('category', 'Premium')
```

### MIN/MAX with FILTER

```php
Db::min('price')->filter('available', 1)
Db::max('temperature')->filter('sensor_status', 'active')
```

## Multiple Conditions

```php
// Using multiple filter() calls creates AND condition
$result = $db->find()
    ->from('products')
    ->select([
        'premium_electronics' => Db::count('*')
            ->filter('category', 'Electronics')
            ->filter('price', 500, '>'),
    ])
    ->get();
```

## Comparison Operators

```php
// Equals (default)
Db::count('*')->filter('status', 'active')

// Greater than
Db::sum('amount')->filter('amount', 100, '>')

// Less than
Db::count('*')->filter('age', 18, '<')

// Greater than or equal
Db::avg('rating')->filter('rating', 4, '>=')

// Less than or equal
Db::count('*')->filter('stock', 10, '<=')

// Not equal
Db::count('*')->filter('status', 'deleted', '!=')
```

## Real-World Examples

### E-commerce Dashboard

```php
$dashboard = $db->find()
    ->from('orders')
    ->select([
        'total_orders' => Db::count('*'),
        'completed_orders' => Db::count('*')->filter('status', 'completed'),
        'cancelled_orders' => Db::count('*')->filter('status', 'cancelled'),
        'total_revenue' => Db::sum('amount'),
        'completed_revenue' => Db::sum('amount')->filter('status', 'completed'),
        'refunded_amount' => Db::sum('amount')->filter('status', 'refunded'),
        'high_value_orders' => Db::count('*')->filter('amount', 200, '>'),
    ])
    ->getOne();
```

### User Segmentation

```php
$segments = $db->find()
    ->from('users')
    ->select([
        'country',
        'total_users' => Db::count('*'),
        'active_users' => Db::count('*')->filter('last_login_days', 7, '<'),
        'premium_users' => Db::count('*')->filter('subscription', 'premium'),
        'trial_users' => Db::count('*')->filter('subscription', 'trial'),
        'avg_age' => Db::avg('age'),
        'avg_active_age' => Db::avg('age')->filter('last_login_days', 30, '<'),
    ])
    ->groupBy('country')
    ->orderBy('total_users', 'DESC')
    ->get();
```

### Regional Sales Analysis

```php
$analysis = $db->find()
    ->from('sales')
    ->select([
        'region',
        'total_sales' => Db::count('*'),
        'q1_sales' => Db::count('*')->filter('quarter', 'Q1'),
        'q2_sales' => Db::count('*')->filter('quarter', 'Q2'),
        'q3_sales' => Db::count('*')->filter('quarter', 'Q3'),
        'q4_sales' => Db::count('*')->filter('quarter', 'Q4'),
        'total_revenue' => Db::sum('amount'),
        'q1_revenue' => Db::sum('amount')->filter('quarter', 'Q1'),
        'q2_revenue' => Db::sum('amount')->filter('quarter', 'Q2'),
    ])
    ->groupBy('region')
    ->get();
```

## Database Support

### Native FILTER Clause
- **PostgreSQL**: ✅ Full native support
- **SQLite 3.30+**: ✅ Full native support

Generated SQL:
```sql
COUNT(*) FILTER (WHERE status = 'paid')
SUM(amount) FILTER (WHERE status = 'paid')
```

### CASE WHEN Fallback
- **MySQL**: ✅ Automatic fallback to CASE WHEN

Generated SQL:
```sql
COUNT(CASE WHEN status = 'paid' THEN 1 END)
SUM(CASE WHEN status = 'paid' THEN amount END)
```

The library automatically detects the database and uses the appropriate syntax.

## Performance

### Advantages

1. **Single Query**: All metrics in one query instead of multiple
2. **Efficient**: Database optimizers handle FILTER well
3. **Readable**: Clear intent vs complex CASE statements

```php
// ✅ Good - Single query with FILTER
$db->find()->select([
    'paid' => Db::count('*')->filter('status', 'paid'),
    'pending' => Db::count('*')->filter('status', 'pending'),
])->getOne();

// ❌ Bad - Multiple queries
$paid = $db->find()->where('status', 'paid')->count();
$pending = $db->find()->where('status', 'pending')->count();
```

### With Indexes

Add indexes on filtered columns for better performance:

```sql
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_amount ON orders(amount);
```

## Comparison with Alternatives

### FILTER vs Multiple Queries

```php
// ❌ Multiple queries - slower
$total = $db->find()->from('orders')->count();
$paid = $db->find()->from('orders')->where('status', 'paid')->count();
$pending = $db->find()->from('orders')->where('status', 'pending')->count();

// ✅ Single query with FILTER - faster
$stats = $db->find()
    ->from('orders')
    ->select([
        'total' => Db::count('*'),
        'paid' => Db::count('*')->filter('status', 'paid'),
        'pending' => Db::count('*')->filter('status', 'pending'),
    ])
    ->getOne();
```

### FILTER vs CASE WHEN

```php
// ❌ Manual CASE WHEN - verbose
$db->find()->select([
    'paid' => Db::raw('COUNT(CASE WHEN status = ? THEN 1 END)', ['paid']),
])->get();

// ✅ FILTER - clean and cross-database
$db->find()->select([
    'paid' => Db::count('*')->filter('status', 'paid'),
])->get();
```

### FILTER vs Subqueries

```php
// ❌ Subqueries - complex and slower
$db->find()->select([
    'total' => Db::count('*'),
    'paid' => Db::raw('(SELECT COUNT(*) FROM orders WHERE status = ?)'),
])->get();

// ✅ FILTER - efficient and readable
$db->find()->select([
    'total' => Db::count('*'),
    'paid' => Db::count('*')->filter('status', 'paid'),
])->get();
```

## Best Practices

1. **Use FILTER for conditional aggregations:**
```php
// ✅ Good - clean conditional aggregation
Db::count('*')->filter('status', 'active')

// ❌ Avoid - manual CASE WHEN
Db::raw('COUNT(CASE WHEN status = ? THEN 1 END)', ['active'])
```

2. **Combine with GROUP BY for segmentation:**
```php
$db->find()
    ->select([
        'category',
        'total' => Db::count('*'),
        'active' => Db::count('*')->filter('status', 'active'),
    ])
    ->groupBy('category')
    ->get();
```

3. **Use appropriate operators:**
```php
// Numeric comparisons
Db::sum('amount')->filter('amount', 100, '>')

// String equality
Db::count('*')->filter('status', 'paid')
```

4. **Add indexes on filtered columns:**
```sql
CREATE INDEX idx_status ON orders(status);
CREATE INDEX idx_amount ON orders(amount);
```

## Limitations

1. **Parameter binding**: Currently uses direct parameter management
2. **Complex conditions**: For complex logic, consider WHERE or HAVING
3. **Cross-column filters**: Filter applies to single column comparisons

## Examples

See [examples/02-intermediate/02-aggregations.php](../../examples/02-intermediate/02-aggregations.php) for complete working examples (Examples 8-9).

## See Also

- [Aggregations](../07-helper-functions/aggregate-helpers.md) - Aggregate helper functions
- [Conditional Logic](../../examples/02-intermediate/02-aggregations.php) - GROUP BY and HAVING
- [Performance Tips](../08-best-practices/performance.md) - Query optimization

