# Core Helper Functions

Essential helper functions for raw SQL, escaping, and configuration.

## Db::raw()

Execute raw SQL expressions with parameter binding:

```php
use tommyknocker\pdodb\helpers\Db;

// Simple raw SQL
$db->find()->table('users')->update([
    'score' => Db::raw('score + 10')
]);

// Raw SQL with parameters
$db->find()->table('users')->update([
    'score' => Db::raw('score + :bonus', ['bonus' => 100])
]);

// Complex expressions
$db->find()->table('users')->update([
    'age' => Db::raw('TIMESTAMPDIFF(YEAR, birth_date, CURDATE())')
]);
```

### When to Use

Use `Db::raw()` when there's no helper function available:

```php
// ❌ Don't use raw when helper exists
$db->find()->table('users')->update([
    'counter' => Db::raw('counter + 1')  // ❌
]);

// ✅ Use helper function
$db->find()->table('users')->update([
    'counter' => Db::inc()  // ✅
]);
```

## Db::escape()

Escape strings for safe SQL usage:

```php
use tommyknocker\pdodb\helpers\Db;

$unsafe = "O'Reilly";
$safe = Db::escape($unsafe)->getValue($db->connection->getDialect());

// Note: PDOdb uses prepared statements automatically,
// so escaping is usually not needed in query building
```

## Db::config()

Set database configuration options:

```php
use tommyknocker\pdodb\helpers\Db;

// MySQL SET statement
$db->rawQuery(
    Db::config('FOREIGN_KEY_CHECKS', 1)->getValue($db->connection->getDialect())
);

// PostgreSQL SET
$db->rawQuery(
    Db::config('timezone', 'UTC')->getValue($db->connection->getDialect())
);

// SQLite PRAGMA
$db->rawQuery(
    Db::config('foreign_keys', 1, true, false)->getValue($db->connection->getDialect())
);
```

### Common Configuration

#### MySQL

```php
// Foreign key checks
Db::config('FOREIGN_KEY_CHECKS', 0)  // Disable
Db::config('FOREIGN_KEY_CHECKS', 1)  // Enable

// Character set
Db::config('NAMES', 'utf8mb4', true, true)

// SQL mode
Db::config('sql_mode', 'STRICT_TRANS_TABLES,NO_ZERO_DATE')
```

#### PostgreSQL

```php
// Timezone
Db::config('timezone', 'UTC')

// Statement timeout
Db::config('statement_timeout', '30000')

// Application name
Db::config('application_name', 'MyApp')
```

#### SQLite

```php
// Foreign keys
Db::config('foreign_keys', 1, true, false)  // PRAGMA foreign_keys = ON

// Journal mode
Db::config('journal_mode', 'WAL', true, false)  // PRAGMA journal_mode = WAL

// Synchronous
Db::config('synchronous', 'NORMAL', true, false)  // PRAGMA synchronous = NORMAL
```

## Db::ref()

Manual external table reference (rarely needed):

```php
use tommyknocker\pdodb\helpers\Db;

// External reference in subquery
$users = $db->find()
    ->from('users')
    ->whereExists(function($query) {
        $query->from('orders')
            ->where('user_id', Db::ref('users.id'));  // Manual reference
    })
    ->get();
```

Note: PDOdb automatically detects external references in most cases.

## Example: Using Raw SQL

### Complex Calculations

```php
$stats = $db->find()
    ->from('orders')
    ->select([
        'total' => 'SUM(amount)',
        'avg_order' => 'AVG(amount)',
        'growth' => Db::raw('SUM(amount) - (SELECT SUM(amount) FROM orders WHERE created_at < DATE_SUB(CURDATE(), INTERVAL 1 MONTH))')
    ])
    ->where('status', 'completed')
    ->getOne();
```

### JSON Operations (when helper not available)

```php
// For complex JSON operations not covered by helpers
$db->find()->table('users')->update([
    'meta' => Db::raw("JSON_SET(meta, '$.city', :city)", ['city' => 'NYC'])
]);
```

## Summary Table

| Function | Purpose | Usage |
|----------|---------|-------|
| `Db::raw()` | Raw SQL with binding | Complex expressions |
| `Db::escape()` | Escape strings | Rarely needed |
| `Db::config()` | Database configuration | SET/PRAGMA statements |
| `Db::ref()` | External references | Rarely needed |

## Next Steps

- [String Helpers](02-string-helpers.md) - String manipulation
- [Numeric Helpers](03-numeric-helpers.md) - Math operations
- [Comparison Helpers](06-comparison-helpers.md) - WHERE conditions
