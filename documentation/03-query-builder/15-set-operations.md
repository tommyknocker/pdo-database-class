# Set Operations (UNION, INTERSECT, EXCEPT)

Set operations allow you to combine results from multiple queries. All operations work across MySQL 8.0+, PostgreSQL, and SQLite 3.8.3+.

## Overview

Set operations combine rows from two or more SELECT queries:
- **UNION** - Combines results and removes duplicates
- **UNION ALL** - Combines results keeping all rows (faster)
- **INTERSECT** - Returns rows that appear in both queries
- **EXCEPT** - Returns rows from first query not in second

## Basic Usage

### UNION - Remove Duplicates

```php
$results = $db->find()
    ->from('products_eu')
    ->select(['name', 'price'])
    ->union(function ($qb) {
        $qb->from('products_us')->select(['name', 'price']);
    })
    ->get();
```

### UNION ALL - Keep Duplicates

```php
$results = $db->find()
    ->from('orders_2023')
    ->select(['product_id', 'amount'])
    ->unionAll(function ($qb) {
        $qb->from('orders_2024')->select(['product_id', 'amount']);
    })
    ->get();
```

### INTERSECT - Common Rows

```php
$common = $db->find()
    ->from('active_users')
    ->select(['email'])
    ->intersect(function ($qb) {
        $qb->from('premium_users')->select(['email']);
    })
    ->get();
```

### EXCEPT - Difference

```php
$onlyInFirst = $db->find()
    ->from('all_products')
    ->select(['product_id'])
    ->except(function ($qb) {
        $qb->from('discontinued_products')->select(['product_id']);
    })
    ->get();
```

## Multiple Set Operations

You can chain multiple set operations:

```php
$results = $db->find()
    ->from('sales_q1')
    ->select(['product_id', 'quarter' => 'Q1'])
    ->unionAll(function ($qb) {
        $qb->from('sales_q2')->select(['product_id', 'quarter' => 'Q2']);
    })
    ->unionAll(function ($qb) {
        $qb->from('sales_q3')->select(['product_id', 'quarter' => 'Q3']);
    })
    ->unionAll(function ($qb) {
        $qb->from('sales_q4')->select(['product_id', 'quarter' => 'Q4']);
    })
    ->orderBy('quarter')
    ->orderBy('product_id')
    ->get();
```

## With WHERE Conditions

```php
$results = $db->find()
    ->from('products_eu')
    ->select(['name', 'price'])
    ->where('price', 100, '>')
    ->union(function ($qb) {
        $qb->from('products_us')
            ->select(['name', 'price'])
            ->where('price', 100, '>');
    })
    ->orderBy('price', 'DESC')
    ->get();
```

## With Aggregations

```php
$results = $db->find()
    ->from('sales_2023')
    ->select([
        'year' => '2023',
        'total' => Db::sum('amount'),
    ])
    ->unionAll(function ($qb) {
        $qb->from('sales_2024')->select([
            'year' => '2024',
            'total' => Db::sum('amount'),
        ]);
    })
    ->orderBy('year')
    ->get();
```

## ORDER BY, LIMIT, OFFSET

These clauses apply to the final combined result:

```php
$results = $db->find()
    ->from('products_eu')
    ->select(['name', 'price'])
    ->union(function ($qb) {
        $qb->from('products_us')->select(['name', 'price']);
    })
    ->orderBy('price', 'DESC')  // Applies to combined result
    ->limit(10)                  // Top 10 from combined result
    ->get();
```

## Using QueryBuilder Instances

Instead of closures, you can pass QueryBuilder instances:

```php
$query1 = $db->find()->from('table1')->select(['col1', 'col2']);
$query2 = $db->find()->from('table2')->select(['col1', 'col2']);

$results = $query1->union($query2)->get();
```

## Important Notes

### Column Requirements
- All queries must return the **same number of columns**
- Column types should be **compatible** across queries
- Column names from the first query are used in the result

### Performance
- `UNION` is slower than `UNION ALL` (deduplication overhead)
- Use `UNION ALL` when you know there are no duplicates
- Add indexes on columns used in WHERE clauses

### Database Support
- **MySQL 8.0+**: Full support (UNION, UNION ALL, INTERSECT, EXCEPT)
- **PostgreSQL**: Full support (all operations)
- **SQLite 3.8.3+**: Full support (all operations)

## Best Practices

1. **Use appropriate operation:**
   - `UNION` when duplicates matter
   - `UNION ALL` for better performance
   - `INTERSECT` for common elements
   - `EXCEPT` for differences

2. **Column alignment:**
```php
// ✅ Good - aligned columns
$qb->from('users')->select(['id', 'name', 'email'])
    ->union(fn($q) => $q->from('admins')->select(['id', 'name', 'email']));

// ❌ Bad - mismatched columns
$qb->from('users')->select(['id', 'name'])
    ->union(fn($q) => $q->from('admins')->select(['id', 'name', 'role']));
```

3. **Apply filters before UNION:**
```php
// ✅ Good - filter before union
$qb->from('products')->where('active', 1)->select(['name'])
    ->union(fn($q) => $q->from('services')->where('active', 1)->select(['name']));

// Less efficient - filtering combined result
$qb->from('products')->select(['name', 'active'])
    ->union(fn($q) => $q->from('services')->select(['name', 'active']))
    ->where('active', 1);
```

## Examples

See [examples/18-set-operations/01-set-operations.php](../../examples/18-set-operations/01-set-operations.php) for complete working examples.

## See Also

- [CTEs](./cte.md) - Complex queries with WITH clauses
- [Subqueries](./subqueries.md) - Nested queries
- [Aggregations](../../examples/02-intermediate/02-aggregations.php) - GROUP BY and aggregate functions
