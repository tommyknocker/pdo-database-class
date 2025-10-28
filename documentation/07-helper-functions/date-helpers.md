# Date/Time Helper Functions

Work with dates and times across all databases using PDOdb helpers.

## Current Timestamp

### NOW()

```php
use tommyknocker\pdodb\helpers\Db;

// Current timestamp
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'created_at' => Db::now()
]);
```

### NOW() with Offset

```php
// Future timestamp (1 day from now)
$db->find()->table('posts')->insert([
    'title' => 'Post',
    'expires_at' => Db::now('+1 DAY')
]);

// Past timestamp (2 hours ago)
$db->find()->table('logs')->insert([
    'message' => 'Log',
    'created_at' => Db::now('-2 HOURS')
]);
```

### Unix Timestamp

```php
// Current Unix timestamp
$db->find()->table('users')->update([
    'last_login' => Db::ts()  // Returns UNIX_TIMESTAMP()
]);
```

## Current Date/Time

### CURDATE()

```php
$users = $db->find()
    ->from('users')
    ->where('created_at', Db::curDate(), '>=')
    ->get();
```

### CURTIME()

```php
$logs = $db->find()
    ->from('logs')
    ->where('time', Db::curTime(), '>')
    ->get();
```

## Date Extraction

### DATE()

```php
$orders = $db->find()
    ->from('orders')
    ->select([
        'order_date' => Db::date('created_at')
    ])
    ->get();
```

### TIME()

```php
$logs = $db->find()
    ->from('logs')
    ->select([
        'log_time' => Db::time('created_at')
    ])
    ->get();
```

### YEAR/MONTH/DAY

```php
$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'birth_year' => Db::year('birth_date'),
        'birth_month' => Db::month('birth_date'),
        'birth_day' => Db::day('birth_date')
    ])
    ->get();
```

### HOUR/MINUTE/SECOND

```php
$events = $db->find()
    ->from('events')
    ->select([
        'event_hour' => Db::hour('started_at'),
        'event_minute' => Db::minute('started_at')
    ])
    ->get();
```

## Common Patterns

### Records from Last 7 Days

```php
$recent = $db->find()
    ->from('orders')
    ->where('created_at', Db::now('-7 DAYS'), '>=')
    ->get();
```

### Records from Current Month

```php
$monthly = $db->find()
    ->from('sales')
    ->where('MONTH(created_at)', 'MONTH(NOW())')
    ->where('YEAR(created_at)', 'YEAR(NOW())')
    ->get();
```

### Update Timestamp

```php
$db->find()
    ->table('users')
    ->where('id', $userId)
    ->update([
        'last_login' => Db::now(),
        'updated_at' => Db::now()
    ]);
```

### Compare Dates

```php
// Users who haven't logged in for 30 days
$inactive = $db->find()
    ->from('users')
    ->where('last_login', Db::now('-30 DAYS'), '<')
    ->get();
```

## Next Steps

- [String Helpers](string-helpers.md) - String operations
- [Numeric Helpers](numeric-helpers.md) - Math functions
- [Core Helpers](core-helpers.md) - Essential helpers
