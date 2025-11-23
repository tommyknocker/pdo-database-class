# Common Table Expressions (CTEs)

Common Table Expressions (CTEs) provide a way to write auxiliary statements for use in a larger query. They allow you to define temporary named result sets that exist only during the execution of a single query.

## Table of Contents

- [Overview](#overview)
- [Basic CTEs](#basic-ctes)
- [Recursive CTEs](#recursive-ctes)
- [Advanced Usage](#advanced-usage)
- [Database Support](#database-support)
- [Best Practices](#best-practices)

## Overview

CTEs offer several advantages:

- **Improved Readability** - Complex queries can be broken down into logical, named components
- **Code Reuse** - A CTE can be referenced multiple times in the same query
- **Recursive Queries** - Process hierarchical or tree-structured data
- **Better Organization** - Separate query logic into manageable parts

### Syntax

```php
$pdoDb->find()
    ->with('cte_name', $query)  // Basic CTE
    ->from('cte_name')
    ->get();

$pdoDb->find()
    ->withRecursive('cte_name', $query, ['col1', 'col2'])  // Recursive CTE
    ->from('cte_name')
    ->get();
```

## Basic CTEs

### Using Closure

The most common way to define a CTE is using a closure:

```php
$results = $pdoDb->find()
    ->with('high_value_products', function ($q) {
        $q->from('products')
          ->where('price', 1000, '>');
    })
    ->from('high_value_products')
    ->orderBy('price', 'DESC')
    ->get();
```

### Using QueryBuilder Instance

You can also pass a QueryBuilder instance:

```php
$subQuery = $pdoDb->find()
    ->from('orders')
    ->select(['customer_id', 'total' => Db::sum('amount')])
    ->groupBy('customer_id');

$results = $pdoDb->find()
    ->with('customer_totals', $subQuery)
    ->from('customers')
    ->join('customer_totals', 'customers.id = customer_totals.customer_id')
    ->get();
```

### Using Raw SQL

For complex logic, use raw SQL:

```php
$results = $pdoDb->find()
    ->with('stats', Db::raw('
        SELECT 
            category,
            COUNT(*) as count,
            AVG(price) as avg_price,
            MAX(price) as max_price
        FROM products
        GROUP BY category
    '))
    ->from('stats')
    ->where('count', 10, '>')
    ->get();
```

### Multiple CTEs

Define multiple CTEs in a single query:

```php
$results = $pdoDb->find()
    ->with('electronics', function ($q) {
        $q->from('products')->where('category', 'Electronics');
    })
    ->with('furniture', function ($q) {
        $q->from('products')->where('category', 'Furniture');
    })
    ->with('combined', Db::raw('
        SELECT * FROM electronics
        UNION ALL
        SELECT * FROM furniture
    '))
    ->from('combined')
    ->orderBy('price')
    ->get();
```

### Explicit Column Lists

Specify column names for the CTE:

```php
$results = $pdoDb->find()
    ->with('product_summary', function ($q) {
        $q->from('products')
          ->select(['name', 'price', 'stock']);
    }, ['product_name', 'product_price', 'product_stock'])
    ->from('product_summary')
    ->where('product_stock', 0, '>')
    ->get();
```

## Recursive CTEs

Recursive CTEs are used to query hierarchical or tree-structured data.

### Category Hierarchy

```php
$results = $pdoDb->find()
    ->withRecursive('category_tree', Db::raw('
        -- Anchor: Start with root categories
        SELECT id, name, parent_id, 0 as level
        FROM categories
        WHERE parent_id IS NULL
        
        UNION ALL
        
        -- Recursive: Get child categories
        SELECT c.id, c.name, c.parent_id, ct.level + 1
        FROM categories c
        INNER JOIN category_tree ct ON c.parent_id = ct.id
    '), ['id', 'name', 'parent_id', 'level'])
    ->from('category_tree')
    ->orderBy('level')
    ->orderBy('name')
    ->get();
```

### Organization Chart

```php
$results = $pdoDb->find()
    ->withRecursive('emp_hierarchy', Db::raw('
        -- Anchor: Start with CEO
        SELECT id, name, manager_id, salary, 0 as level
        FROM employees
        WHERE manager_id IS NULL
        
        UNION ALL
        
        -- Recursive: Get subordinates
        SELECT e.id, e.name, e.manager_id, e.salary, eh.level + 1
        FROM employees e
        INNER JOIN emp_hierarchy eh ON e.manager_id = eh.id
    '), ['id', 'name', 'manager_id', 'salary', 'level'])
    ->from('emp_hierarchy')
    ->orderBy('level')
    ->get();
```

### Depth-Limited Recursion

Control recursion depth:

```php
$results = $pdoDb->find()
    ->withRecursive('limited_tree', Db::raw('
        SELECT id, name, parent_id, 0 as depth
        FROM categories
        WHERE parent_id IS NULL
        
        UNION ALL
        
        SELECT c.id, c.name, c.parent_id, lt.depth + 1
        FROM categories c
        INNER JOIN limited_tree lt ON c.parent_id = lt.id
        WHERE lt.depth < 3  -- Limit to 3 levels
    '), ['id', 'name', 'parent_id', 'depth'])
    ->from('limited_tree')
    ->get();
```

### Building Paths

Track the path through the hierarchy:

```php
$results = $pdoDb->find()
    ->withRecursive('hierarchy_path', Db::raw('
        SELECT id, name, manager_id, name as path
        FROM employees
        WHERE manager_id IS NULL
        
        UNION ALL
        
        SELECT e.id, e.name, e.manager_id, 
               hp.path || \' -> \' || e.name as path
        FROM employees e
        INNER JOIN hierarchy_path hp ON e.manager_id = hp.id
    '), ['id', 'name', 'manager_id', 'path'])
    ->from('hierarchy_path')
    ->get();
```

## Advanced Usage

### CTE with Aggregations

```php
$results = $pdoDb->find()
    ->with('monthly_sales', function ($q) {
        $q->from('orders')
          ->select([
              'month' => Db::raw('DATE_FORMAT(order_date, "%Y-%m")'),
              'total' => Db::sum('amount'),
          ])
          ->groupBy(Db::raw('DATE_FORMAT(order_date, "%Y-%m")'));
    })
    ->from('monthly_sales')
    ->where('total', 10000, '>')
    ->orderBy('month')
    ->get();
```

### CTE with Window Functions

```php
$results = $pdoDb->find()
    ->with('ranked_products', function ($q) {
        $q->from('products')
          ->select([
              'name',
              'category',
              'price',
              'rank' => Db::rank()->partitionBy('category')->orderBy('price', 'DESC'),
          ]);
    })
    ->from('ranked_products')
    ->where('rank', 3, '<=')
    ->get();
```

### Chaining CTEs

Each CTE can reference previously defined CTEs:

```php
$results = $pdoDb->find()
    ->with('step1', function ($q) {
        $q->from('raw_data')->where('status', 'active');
    })
    ->with('step2', function ($q) {
        $q->from('step1')->where('score', 50, '>');
    })
    ->with('step3', function ($q) {
        $q->from('step2')->orderBy('score', 'DESC')->limit(10);
    })
    ->from('step3')
    ->get();
```

### CTE in Subqueries

Use CTEs in conjunction with subqueries:

```php
$results = $pdoDb->find()
    ->with('active_users', function ($q) {
        $q->from('users')->where('status', 'active');
    })
    ->from('orders')
    ->whereIn('user_id', function ($q) {
        $q->from('active_users')->select(['id']);
    })
    ->get();
```

## Materialized CTEs

Materialized CTEs cache the result set, improving performance for expensive queries that are referenced multiple times. The result is computed once and stored in memory.

### When to Use Materialized CTEs

Materialized CTEs are beneficial when:
- An expensive computation is referenced multiple times in the query
- Complex aggregations need to be reused
- Performance optimization is critical for large datasets

### Syntax

```php
$pdoDb->find()
    ->withMaterialized('cte_name', $query)  // Materialized CTE
    ->from('cte_name')
    ->get();
```

### Example: Expensive Aggregation

```php
// Materialize expensive aggregation for reuse
$results = $pdoDb->find()
    ->withMaterialized('customer_stats', function ($q) {
        $q->from('orders')
          ->select([
              'customer_id',
              'order_count' => Db::count('*'),
              'total_spent' => Db::sum('amount'),
          ])
          ->groupBy('customer_id');
    })
    ->from('customers')
    ->join('customer_stats', 'customers.id = customer_stats.customer_id')
    ->select([
        'customers.name',
        'customer_stats.order_count',
        'customer_stats.total_spent',
    ])
    ->where('customer_stats.total_spent', 1000, '>')
    ->get();
```

### Database Support for Materialized CTEs

- **PostgreSQL**: ✅ Supported (PostgreSQL 12+) - Uses `MATERIALIZED` keyword
- **MySQL**: ✅ Supported (MySQL 8.0+) - Uses optimizer hints
- **SQLite**: ❌ Not supported - Throws `RuntimeException`

### Multiple Materialized CTEs

```php
$results = $pdoDb->find()
    ->withMaterialized('expensive_cte1', function ($q) {
        // Complex computation
    })
    ->withMaterialized('expensive_cte2', function ($q) {
        // Another complex computation
    })
    ->from('main_table')
    ->join('expensive_cte1', 'main_table.id = expensive_cte1.id')
    ->join('expensive_cte2', 'main_table.id = expensive_cte2.id')
    ->get();
```

## Database Support

### MySQL 8.0+
- Basic CTEs: ✅ Supported
- Recursive CTEs: ✅ Supported
- Materialized CTEs: ✅ Supported (using optimizer hints)
- Notes: Requires MySQL 8.0.1 or higher

### PostgreSQL 8.4+
- Basic CTEs: ✅ Supported
- Recursive CTEs: ✅ Supported
- Materialized CTEs: ✅ Supported (PostgreSQL 12+) - Uses `MATERIALIZED` keyword
- Notes: Best CTE implementation, native MATERIALIZED support since PostgreSQL 12

### SQLite 3.8.3+
- Basic CTEs: ✅ Supported
- Recursive CTEs: ✅ Supported
- Materialized CTEs: ❌ Not supported
- Notes: Excellent CTE support since version 3.8.3, but materialization not available

## Best Practices

### 1. Use CTEs for Readability

**Bad:**
```php
$results = $pdoDb->find()
    ->from('products')
    ->join('categories', 'products.category_id = categories.id')
    ->join('suppliers', 'products.supplier_id = suppliers.id')
    ->where('products.price', 100, '>')
    ->where('categories.name', 'Electronics')
    ->where('suppliers.country', 'USA')
    ->get();
```

**Good:**
```php
$results = $pdoDb->find()
    ->with('expensive_products', function ($q) {
        $q->from('products')->where('price', 100, '>');
    })
    ->with('electronics', function ($q) {
        $q->from('categories')->where('name', 'Electronics');
    })
    ->with('us_suppliers', function ($q) {
        $q->from('suppliers')->where('country', 'USA');
    })
    ->from('expensive_products')
    ->join('electronics', 'expensive_products.category_id = electronics.id')
    ->join('us_suppliers', 'expensive_products.supplier_id = us_suppliers.id')
    ->get();
```

### 2. Always Define Columns for Recursive CTEs

```php
// Recommended
$pdoDb->find()
    ->withRecursive('tree', $query, ['id', 'name', 'parent_id', 'level'])
    ->from('tree')
    ->get();
```

### 3. Add Recursion Depth Limits

Prevent infinite recursion:

```php
$pdoDb->find()
    ->withRecursive('tree', Db::raw('
        SELECT id, parent_id, 0 as depth FROM nodes WHERE parent_id IS NULL
        UNION ALL
        SELECT n.id, n.parent_id, t.depth + 1 
        FROM nodes n 
        JOIN tree t ON n.parent_id = t.id
        WHERE t.depth < 100  -- Safety limit
    '), ['id', 'parent_id', 'depth'])
    ->from('tree')
    ->get();
```

### 4. Use CTEs for Complex Filtering

Break down complex WHERE clauses:

```php
$pdoDb->find()
    ->with('qualified_customers', function ($q) {
        $q->from('customers')
          ->where('status', 'active')
          ->where('credit_score', 700, '>=')
          ->where('account_age_months', 12, '>=');
    })
    ->from('qualified_customers')
    ->get();
```

### 5. Combine CTEs with Pagination

```php
$results = $pdoDb->find()
    ->with('filtered_data', function ($q) {
        $q->from('large_table')
          ->where('category', 'important')
          ->where('date', '2024-01-01', '>=');
    })
    ->from('filtered_data')
    ->orderBy('created_at', 'DESC')
    ->paginate(25, $page);
```

## See Also

- [CTE Examples](../../examples/03-advanced/08-basic-cte.php) - Basic CTE usage
- [Recursive CTE Examples](../../examples/03-advanced/09-recursive-cte.php) - Recursive CTEs for hierarchical data
- [Materialized CTE Examples](../../examples/03-advanced/10-materialized-cte.php) - Materialized CTEs for performance
- [Query Builder](../03-query-builder/README.md)
- [Window Functions](./window-functions.md)
- [Subqueries](./subqueries.md)
