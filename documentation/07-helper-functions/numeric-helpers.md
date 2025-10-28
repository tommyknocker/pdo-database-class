# Numeric Helper Functions

Common mathematical operations with PDOdb helpers.

## Increment/Decrement

### Increment

```php
use tommyknocker\pdodb\helpers\Db;

// Increment by 1 (default)
$db->find()->table('users')->where('id', 1)->update([
    'counter' => Db::inc()
]);

// Increment by specific amount
$db->find()->table('users')->where('id', 1)->update([
    'score' => Db::inc(5)
]);
```

### Decrement

```php
// Decrement by 1 (default)
$db->find()->table('users')->where('id', 1)->update([
    'stock' => Db::dec()
]);

// Decrement by specific amount
$db->find()->table('users')->where('id', 1)->update([
    'credits' => Db::dec(10)
]);
```

## Absolute Value

```php
$users = $db->find()
    ->from('transactions')
    ->select(['amount' => Db::abs('amount')])
    ->get();
```

## Rounding

```php
$products = $db->find()
    ->from('products')
    ->select(['price' => Db::round('price', 2)])
    ->get();
```

## Modulo

```php
$users = $db->find()
    ->from('users')
    ->select(['remainder' => Db::mod('score', 10)])
    ->get();
```

## Common Patterns

### Update Score

```php
// Add 100 points
$db->find()->table('users')->where('id', $userId)->update([
    'score' => Db::inc(100)
]);
```

### Decrease Stock

```php
// Reduce stock by quantity
$db->find()
    ->table('products')
    ->where('id', $productId)
    ->update(['stock' => Db::dec($quantity)]);
```

## Next Steps

- [String Helpers](string-helpers.md) - String operations
- [Date Helpers](date-helpers.md) - Date/time functions
- [Core Helpers](core-helpers.md) - Essential helpers

