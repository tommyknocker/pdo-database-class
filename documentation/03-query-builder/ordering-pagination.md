# Ordering and Pagination

Learn how to order results and implement pagination.

## ORDER BY

### Single Column

```php
// Ascending
$users = $db->find()
    ->from('users')
    ->orderBy('name', 'ASC')
    ->get();

// Descending
$users = $db->find()
    ->from('users')
    ->orderBy('created_at', 'DESC')
    ->get();
```

### Multiple Columns

```php
$users = $db->find()
    ->from('users')
    ->orderBy('status', 'ASC')
    ->orderBy('created_at', 'DESC')
    ->orderBy('name', 'ASC')
    ->get();
```

### Order by Expression

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->orderBy(Db::concat('first_name', ' ', 'last_name'), 'ASC')
    ->get();
```

## LIMIT

### Basic LIMIT

```php
$users = $db->find()
    ->from('users')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();
```

### LIMIT with OFFSET

```php
$users = $db->find()
    ->from('users')
    ->orderBy('id', 'ASC')
    ->limit(20)
    ->offset(40)
    ->get();
```

## Pagination

### Basic Pagination

```php
function getUsers($page = 1, $perPage = 20) {
    $offset = ($page - 1) * $perPage;
    
    return $db->find()
        ->from('users')
        ->where('active', 1)
        ->orderBy('created_at', 'DESC')
        ->limit($perPage)
        ->offset($offset)
        ->get();
}
```

### Pagination with Total Count

```php
function getUsersWithCount($page = 1, $perPage = 20) {
    $offset = ($page - 1) * $perPage;
    
    $users = $db->find()
        ->from('users')
        ->where('active', 1)
        ->orderBy('created_at', 'DESC')
        ->limit($perPage)
        ->offset($offset)
        ->get();
    
    $total = $db->find()
        ->from('users')
        ->where('active', 1)
        ->select(Db::count())
        ->getValue();
    
    return [
        'users' => $users,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage)
    ];
}
```

## Top N Records

### Highest Scoring Users

```php
$topUsers = $db->find()
    ->from('users')
    ->orderBy('score', 'DESC')
    ->limit(10)
    ->get();
```

### Recent Records

```php
$recentPosts = $db->find()
    ->from('posts')
    ->where('published', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(5)
    ->get();
```

## Random Records

```php
use tommyknocker\pdodb\helpers\Db;

$randomUser = $db->find()
    ->from('users')
    ->orderBy(Db::raw('RAND()'))
    ->limit(1)
    ->getOne();
```

## Order by Aggregate

```php
$topSellers = $db->find()
    ->from('products AS p')
    ->select([
        'p.name',
        'total_sold' => Db::count('oi.id')
    ])
    ->leftJoin('order_items AS oi', 'oi.product_id = p.id')
    ->groupBy('p.id', 'p.name')
    ->orderBy('total_sold', 'DESC')
    ->limit(10)
    ->get();
```

## SQLite Limitations

### OFFSET Requires LIMIT

```php
// ❌ Doesn't work in SQLite
$users = $db->find()->from('users')->offset(10)->get();

// ✅ Works - always use LIMIT with OFFSET
$users = $db->find()
    ->from('users')
    ->limit(20)
    ->offset(10)
    ->get();
```

## Next Steps

- [SELECT Operations](select-operations.md) - SELECT queries
- [Joins](joins.md) - JOIN operations
- [Aggregations](aggregations.md) - GROUP BY, HAVING
