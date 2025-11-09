# DISTINCT

The `DISTINCT` clause removes duplicate rows from query results, returning only unique combinations of the selected columns.

## Overview

Use `DISTINCT` when you need unique values:
- Remove duplicate rows
- Find unique combinations of columns
- Count unique values

**DISTINCT ON** is PostgreSQL-only and requires specific columns.

## Basic Usage

### Single Column

```php
// Get unique categories
$categories = $db->find()
    ->from('products')
    ->select(['category'])
    ->distinct()
    ->orderBy('category')
    ->get();
```

### Multiple Columns

```php
// Get unique combinations of category and region
$combinations = $db->find()
    ->from('sales')
    ->select(['category', 'region'])
    ->distinct()
    ->orderBy('category')
    ->get();
```

### With WHERE Clause

```php
// Get unique active product categories
$activeCategories = $db->find()
    ->from('products')
    ->select(['category'])
    ->where('active', 1)
    ->distinct()
    ->get();
```

## DISTINCT with Aggregations

### Count Unique Values

```php
// Count unique customers
$uniqueCustomers = $db->find()
    ->from('orders')
    ->select(['unique_customers' => Db::count('DISTINCT customer_id')])
    ->getOne();

echo "Unique customers: " . $uniqueCustomers['unique_customers'];
```

### With GROUP BY

```php
// Get unique products per category
$results = $db->find()
    ->from('products')
    ->select([
        'category',
        'unique_products' => Db::count('DISTINCT product_id'),
    ])
    ->groupBy('category')
    ->get();
```

## DISTINCT ON (PostgreSQL Only)

**Note:** `DISTINCT ON` is only supported in PostgreSQL. For other databases, use `DISTINCT` or window functions.

```php
try {
    // Get first product from each category (PostgreSQL only)
    $results = $db->find()
        ->from('products')
        ->select(['category', 'name', 'price'])
        ->distinctOn('category')
        ->orderBy('category')
        ->orderBy('price', 'DESC')
        ->get();
} catch (\RuntimeException $e) {
    // Will throw exception on MySQL and SQLite
    echo "DISTINCT ON not supported: " . $e->getMessage();
}
```

### Multiple Columns (PostgreSQL)

```php
// Get first product per category and region
$results = $db->find()
    ->from('products')
    ->select(['category', 'region', 'name'])
    ->distinctOn(['category', 'region'])
    ->orderBy('category')
    ->orderBy('region')
    ->get();
```

## Performance Considerations

### DISTINCT Performance

`DISTINCT` requires sorting/hashing to identify duplicates:

```php
// ✅ Good - with index on category
$db->find()
    ->from('products')
    ->select(['category'])
    ->distinct()
    ->get();

// Consider adding index:
// CREATE INDEX idx_products_category ON products(category);
```

### Avoid Unnecessary DISTINCT

```php
// ❌ Unnecessary - id is unique
$db->find()
    ->from('users')
    ->select(['id', 'email'])
    ->distinct()
    ->get();

// ✅ Better - no DISTINCT needed
$db->find()
    ->from('users')
    ->select(['id', 'email'])
    ->get();
```

## Common Use Cases

### 1. Dropdown Options

```php
// Get unique countries for dropdown
$countries = $db->find()
    ->from('users')
    ->select(['country'])
    ->distinct()
    ->orderBy('country')
    ->get();
```

### 2. Tag Cloud

```php
// Get unique tags
$tags = $db->find()
    ->from('post_tags')
    ->select(['tag'])
    ->distinct()
    ->orderBy('tag')
    ->get();
```

### 3. Available Filters

```php
// Get available filter options
$filters = [
    'categories' => $db->find()
        ->from('products')
        ->where('active', 1)
        ->select(['category'])
        ->distinct()
        ->get(),
    'brands' => $db->find()
        ->from('products')
        ->where('active', 1)
        ->select(['brand'])
        ->distinct()
        ->get(),
];
```

### 4. Unique Customer Analysis

```php
// Analyze unique customers by month
$results = $db->find()
    ->from('orders')
    ->select([
        'month' => Db::month('created_at'),
        'unique_customers' => Db::count('DISTINCT customer_id'),
        'total_orders' => Db::count('*'),
    ])
    ->groupBy(Db::month('created_at'))
    ->orderBy('month')
    ->get();
```

## DISTINCT vs GROUP BY

Sometimes `GROUP BY` can replace `DISTINCT`:

```php
// Using DISTINCT
$categories = $db->find()
    ->from('products')
    ->select(['category'])
    ->distinct()
    ->get();

// Equivalent with GROUP BY (can be faster with proper indexes)
$categories = $db->find()
    ->from('products')
    ->select(['category'])
    ->groupBy('category')
    ->get();
```

Use `GROUP BY` when you also need aggregations:

```php
// Get categories with product count
$categories = $db->find()
    ->from('products')
    ->select([
        'category',
        'product_count' => Db::count('*'),
    ])
    ->groupBy('category')
    ->get();
```

## Important Notes

### Column Selection
- `DISTINCT` applies to **all selected columns**
- Unique combination of all columns in SELECT

```php
// Returns unique (category, brand) combinations
$db->find()
    ->from('products')
    ->select(['category', 'brand'])
    ->distinct()
    ->get();
```

### With ORDER BY

```php
// DISTINCT works well with ORDER BY
$db->find()
    ->from('products')
    ->select(['category'])
    ->distinct()
    ->orderBy('category')
    ->get();
```

### Database Support
- **MySQL**: `DISTINCT` fully supported, no `DISTINCT ON`
- **PostgreSQL**: Both `DISTINCT` and `DISTINCT ON` supported
- **SQLite**: `DISTINCT` fully supported, no `DISTINCT ON`

## Best Practices

1. **Use indexes on columns in DISTINCT queries:**
```sql
CREATE INDEX idx_products_category ON products(category);
```

2. **Be specific with column selection:**
```php
// ✅ Good - only select needed columns
$db->find()->select(['category'])->distinct()->get();

// ❌ Bad - unnecessary columns slow down DISTINCT
$db->find()->select(['*'])->distinct()->get();
```

3. **Consider GROUP BY for aggregations:**
```php
// ✅ Good - use GROUP BY when aggregating
$db->find()
    ->select(['category', 'count' => Db::count('*')])
    ->groupBy('category')
    ->get();
```

4. **PostgreSQL DISTINCT ON requires proper ORDER BY:**
```php
// ✅ Good - DISTINCT ON columns match ORDER BY
$db->find()
    ->distinctOn('category')
    ->orderBy('category')  // Required!
    ->orderBy('price', 'DESC')
    ->get();
```

## Examples

See [examples/01-basic/05-ordering.php](../../examples/01-basic/05-ordering.php) for complete working examples (Examples 11-12).

## See Also

- [Select Operations](./select-operations.md) - Basic SELECT queries
- [Aggregations](../../examples/02-intermediate/02-aggregations.php) - GROUP BY and aggregate functions
- [Ordering](../../examples/01-basic/05-ordering.php) - ORDER BY examples



