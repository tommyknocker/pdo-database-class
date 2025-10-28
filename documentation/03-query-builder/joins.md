# JOIN Operations

Learn how to join multiple tables with PDOdb.

## Basic JOIN

### INNER JOIN

Join two tables on a matching condition:

```php
$results = $db->find()
    ->from('users AS u')
    ->select([
        'u.id',
        'u.name',
        'o.order_id',
        'o.total'
    ])
    ->join('orders AS o', 'o.user_id = u.id')
    ->get();

// SQL: SELECT u.id, u.name, o.order_id, o.total 
//      FROM users u INNER JOIN orders o ON o.user_id = u.id
```

### LEFT JOIN

Include all rows from the left table:

```php
$results = $db->find()
    ->from('users AS u')
    ->select([
        'u.id',
        'u.name',
        'o.total' => 'SUM(o.amount)'
    ])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->groupBy('u.id', 'u.name')
    ->get();
```

### RIGHT JOIN

Include all rows from the right table:

```php
$results = $db->find()
    ->from('users AS u')
    ->select([
        'u.id',
        'u.name',
        'o.order_id'
    ])
    ->rightJoin('orders AS o', 'o.user_id = u.id')
    ->get();
```

## Multiple JOINs

### Chain JOINs

```php
$results = $db->find()
    ->from('users AS u')
    ->select([
        'u.name',
        'p.title',
        'c.content'
    ])
    ->join('posts AS p', 'p.user_id = u.id')
    ->leftJoin('comments AS c', 'c.post_id = p.id')
    ->get();
```

### Complex JOINs with Conditions

```php
$results = $db->find()
    ->from('orders AS o')
    ->select([
        'o.order_id',
        'u.name',
        'p.product_name',
        'oi.quantity',
        'oi.price'
    ])
    ->join('users AS u', 'u.id = o.user_id')
    ->join('order_items AS oi', 'oi.order_id = o.id')
    ->leftJoin('products AS p', 'p.id = oi.product_id')
    ->where('o.status', 'completed')
    ->get();
```

## JOIN with WHERE

### Filter Joined Results

```php
$results = $db->find()
    ->from('users AS u')
    ->select(['u.name', 'o.order_id', 'o.total'])
    ->join('orders AS o', 'o.user_id = u.id')
    ->where('u.active', 1)
    ->andWhere('o.status', 'completed')
    ->andWhere('o.total', 100, '>')
    ->get();
```

### JOIN Multiple Tables

```php
$results = $db->find()
    ->from('authors AS a')
    ->select([
        'a.name',
        'b.title',
        'c.name' => 'category_name'
    ])
    ->join('books AS b', 'b.author_id = a.id')
    ->join('categories AS c', 'c.id = b.category_id')
    ->where('a.country', 'USA')
    ->orderBy('b.title', 'ASC')
    ->get();
```

## Using Aliases

### Table Aliases

```php
$results = $db->find()
    ->from('users AS u')
    ->select(['u.id', 'u.name', 'p.title'])
    ->leftJoin('posts AS p', 'p.user_id = u.id')
    ->get();
```

### Column Aliases

```php
$results = $db->find()
    ->from('users AS u')
    ->select([
        'user_name' => 'u.name',
        'user_email' => 'u.email',
        'order_count' => 'COUNT(o.id)'
    ])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->groupBy('u.id', 'u.name', 'u.email')
    ->get();
```

## JOIN with Aggregate Functions

### Count Related Records

```php
$results = $db->find()
    ->from('users AS u')
    ->select([
        'u.id',
        'u.name',
        'total_orders' => 'COUNT(o.id)',
        'total_amount' => 'SUM(o.total)'
    ])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->groupBy('u.id', 'u.name')
    ->having('COUNT(o.id)', 0, '>')
    ->get();
```

### Average Related Values

```php
$results = $db->find()
    ->from('products AS p')
    ->select([
        'p.name',
        'avg_rating' => 'AVG(r.rating)',
        'review_count' => 'COUNT(r.id)'
    ])
    ->leftJoin('reviews AS r', 'r.product_id = p.id')
    ->groupBy('p.id', 'p.name')
    ->having('COUNT(r.id)', 10, '>=')
    ->orderBy('avg_rating', 'DESC')
    ->get();
```

## Schema Support (PostgreSQL)

### Cross-Schema JOINs

```php
$results = $db->find()
    ->from('public.users AS u')
    ->select(['u.id', 'a.user_id'])
    ->leftJoin('archive.users AS a', 'a.user_id = u.id')
    ->where('u.active', 1)
    ->get();

// SQL: SELECT u.id, a.user_id FROM public.users u 
//      LEFT JOIN archive.users a ON a.user_id = u.id
```

## JOIN Best Practices

### 1. Use INNER JOIN When Possible

```php
// ✅ Faster - only matching rows
$results = $db->find()
    ->from('users AS u')
    ->select(['u.name', 'o.total'])
    ->join('orders AS o', 'o.user_id = u.id')
    ->get();

// ❌ Slower - all users including those without orders
$results = $db->find()
    ->from('users AS u')
    ->select(['u.name', 'o.total'])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->get();
```

### 2. Index Foreign Keys

```sql
-- Create index for faster JOINs
CREATE INDEX idx_orders_user_id ON orders(user_id);
CREATE INDEX idx_posts_user_id ON posts(user_id);
```

### 3. Avoid Too Many JOINs

```php
// ✅ Good: 2-3 JOINs maximum
$results = $db->find()
    ->from('users AS u')
    ->join('orders AS o', 'o.user_id = u.id')
    ->leftJoin('products AS p', 'p.id = o.product_id')
    ->get();

// ❌ Bad: 5+ JOINs are slow
// Consider denormalizing data or using separate queries
```

## Complex Examples

### Users with Recent Orders

```php
$results = $db->find()
    ->from('users AS u')
    ->select([
        'u.name',
        'o.recent_order' => 'MAX(o.created_at)',
        'o.total_spent' => 'SUM(o.total)'
    ])
    ->join('orders AS o', 'o.user_id = u.id')
    ->where('o.created_at', '2023-01-01', '>=')
    ->groupBy('u.id', 'u.name')
    ->having('SUM(o.total)', 1000, '>')
    ->orderBy('o.total_spent', 'DESC')
    ->limit(10)
    ->get();
```

### Product Sales with Categories

```php
$results = $db->find()
    ->from('products AS p')
    ->select([
        'p.name',
        'c.category_name',
        'total_sold' => 'SUM(oi.quantity)',
        'revenue' => 'SUM(oi.quantity * oi.price)'
    ])
    ->join('categories AS c', 'c.id = p.category_id')
    ->join('order_items AS oi', 'oi.product_id = p.id')
    ->join('orders AS o', 'o.id = oi.order_id')
    ->where('o.status', 'completed')
    ->groupBy('p.id', 'p.name', 'c.category_name')
    ->orderBy('revenue', 'DESC')
    ->get();
```

## Next Steps

- [SELECT Operations](select-operations.md) - SELECT queries
- [Aggregations](aggregations.md) - GROUP BY, HAVING
- [Filtering Conditions](filtering-conditions.md) - WHERE clauses

