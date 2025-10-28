# NULL Helper Functions

Handle NULL values safely across all databases.

## NULL Value

### Use NULL

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'deleted_at' => Db::null()
]);
```

## IS NULL / IS NOT NULL

### Check for NULL

```php
use tommyknocker\pdodb\helpers\Db;

// Find users not deleted
$users = $db->find()
    ->from('users')
    ->where(Db::isNull('deleted_at'))
    ->get();
```

### Check for NOT NULL

```php
$users = $db->find()
    ->from('users')
    ->where(Db::isNotNull('email'))
    ->get();
```

## ifNull() - Default Value

### Provide Default

```php
$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'display_name' => Db::ifNull('username', 'Anonymous')
    ])
    ->get();
```

### Default for Missing Field

```php
$orders = $db->find()
    ->from('orders')
    ->select([
        'total',
        'discount' => Db::ifNull('discount', 0)
    ])
    ->get();
```

## coalesce() - First Non-NULL

### Get First Available Value

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'contact' => Db::coalesce('phone', 'email', 'N/A')
    ])
    ->get();
```

### Multiple Fallbacks

```php
$users = $db->find()
    ->from('products')
    ->select([
        'name',
        'image_url' => Db::coalesce('main_image', 'thumbnail', 'default.jpg')
    ])
    ->get();
```

## nullIf() - NULL if Equal

### Return NULL if Values Match

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'display_age' => Db::nullIf('age', 0)  // NULL if age = 0
    ])
    ->get();
```

## Common Patterns

### Soft Delete

```php
// Mark as deleted
$db->find()
    ->table('users')
    ->where('id', $userId)
    ->update([
        'deleted' => 1,
        'deleted_at' => Db::now()
    ]);

// Query active users
$active = $db->find()
    ->from('users')
    ->where(Db::isNull('deleted_at'))
    ->get();
```

### Optional Fields

```php
$orders = $db->find()
    ->from('orders')
    ->select([
        'id',
        'total',
        'discount' => Db::coalesce('discount_amount', '0'),
        'final_total' => Db::coalesce(
            Db::raw('total - COALESCE(discount_amount, 0)'),
            'total'
        )
    ])
    ->get();
```

### Data Migration

```php
// Migrate NULL to actual value
$db->find()
    ->table('users')
    ->update([
        'username' => Db::coalesce('username', 'email')
    ]);
```

## Next Steps

- [Comparison Helpers](comparison-helpers.md) - WHERE conditions
- [String Helpers](string-helpers.md) - String operations
- [Core Helpers](core-helpers.md) - Essential helpers

