# Query Analysis

Analyze query performance using EXPLAIN.

## EXPLAIN

### MySQL

```php
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explain();

// Returns execution plan
/*
Array
(
    [0] => Array
        (
            [id] => 1
            [select_type] => SIMPLE
            [table] => users
            [type] => ref
            [possible_keys] => email
            [key] => email
            [key_len] => 203
            [ref] => const
            [rows] => 1
            [Extra] => Using where
        )
)
*/
```

### PostgreSQL

```php
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explain();

// Returns query plan with nodes
```

## EXPLAIN ANALYZE

```php
// PostgreSQL only
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explainAnalyze();

// Returns actual execution time
```

## DESCRIBE

### Describe Table

```php
$columns = $db->find()
    ->from('users')
    ->describe();

// Returns column information
/*
Array
(
    [0] => Array
        (
            [Field] => id
            [Type] => int(11)
            [Null] => NO
            [Key] => PRI
            [Default] => NULL
            [Extra] => auto_increment
        )
    ...
)
*/
```

### Describe Index

```php
$indexes = $db->rawQuery('SHOW INDEX FROM users');
```

## Performance Tips

### Check Index Usage

```php
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explain();

if ($result[0]['key'] === null) {
    echo "No index used!";
}
```

### Analyze Slow Queries

```php
// Enable query log
$db->enableQueryLog();

$users = $db->find()
    ->from('users')
    ->join('profiles', 'profiles.user_id', 'users.id')
    ->get();

$log = $db->getQueryLog();

foreach ($log as $query) {
    echo $query['sql'] . "\n";
    echo "Time: " . $query['time'] . "ms\n";
}
```

## Next Steps

- [Performance](../08-best-practices/performance.md) - Query optimization
- [Common Pitfalls](../08-best-practices/common-pitfalls.md) - Mistakes to avoid
- [Troubleshooting](../10-cookbook/troubleshooting.md) - Common issues