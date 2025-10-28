# Helper Functions Reference

Complete reference for all helper functions.

## Core Helpers

### `Db::raw(string $value): RawValue`

Raw SQL value.

```php
Db::raw('NOW()');
```

### `Db::escape(string $value): string`

Escape string.

```php
Db::escape($userInput);
```

### `Db::config(string $name, $value): ValueInterface`

Configuration value.

```php
Db::config('FOREIGN_KEY_CHECKS', 0);
```

## String Helpers

### `Db::concat(...$values): CallableInterface`

CONCAT function.

```php
Db::concat('first_name', ' ', 'last_name');
```

### `Db::upper(string $value): CallableInterface`

UPPER function.

```php
Db::upper('email');
```

### `Db::lower(string $value): CallableInterface`

LOWER function.

```php
Db::lower('email');
```

### `Db::trim(string $value, ?string $chars = null): CallableInterface`

TRIM function.

```php
Db::trim('name');
```

### `Db::substring(string $value, int $start, ?int $length = null): CallableInterface`

SUBSTRING function.

```php
Db::substring('name', 1, 10);
```

### `Db::length(string $value): CallableInterface`

LENGTH function.

```php
Db::length('name');
```

## Numeric Helpers

### `Db::inc(int|float $value, int|float $step = 1): CallableInterface`

Increment value.

```php
Db::inc('views');
Db::inc('views', 5);
```

### `Db::dec(int|float $value, int|float $step = 1): CallableInterface`

Decrement value.

```php
Db::dec('stock');
```

### `Db::abs(int|float $value): CallableInterface`

Absolute value.

```php
Db::abs('price');
```

### `Db::round(int|float $value, int $precision = 0): CallableInterface`

Round value.

```php
Db::round('price', 2);
```

## Date Helpers

### `Db::now(): RawValue`

Current timestamp.

```php
Db::now();
```

### `Db::curDate(): RawValue`

Current date.

```php
Db::curDate();
```

### `Db::curTime(): RawValue`

Current time.

```php
Db::curTime();
```

### `Db::addInterval(string $expr, string $unit): CallableInterface`

Add interval.

```php
Db::addInterval('created_at', '1 DAY');
```

### `Db::subInterval(string $expr, string $unit): CallableInterface`

Subtract interval.

```php
Db::subInterval('created_at', '1 MONTH');
```

## NULL Helpers

### `Db::isNull($value): CallableInterface`

IS NULL check.

```php
Db::isNull('deleted_at');
```

### `Db::isNotNull($value): CallableInterface`

IS NOT NULL check.

```php
Db::isNotNull('email');
```

### `Db::ifNull($value, $default): CallableInterface`

IFNULL function.

```php
Db::ifNull('nickname', 'Anonymous');
```

### `Db::nullIf($value, $compare): CallableInterface`

NULLIF function.

```php
Db::nullIf('nickname', '');
```

## Comparison Helpers

### `Db::like(string $column, string $pattern): CallableInterface`

LIKE pattern.

```php
Db::like('name', '%john%');
```

### `Db::between($column, $min, $max): CallableInterface`

BETWEEN values.

```php
Db::between('age', 18, 65);
```

### `Db::in(string $column, array $values): CallableInterface`

IN values.

```php
Db::in('status', ['active', 'pending']);
```

### `Db::notIn(string $column, array $values): CallableInterface`

NOT IN values.

```php
Db::notIn('status', ['deleted', 'banned']);
```

## JSON Helpers

### `Db::jsonExtract(string $column, string $path): CallableInterface`

Extract JSON value.

```php
Db::jsonExtract('data', '$.name');
```

### `Db::jsonContains(string $column, $value, ?string $path = null): CallableInterface`

Check JSON contains.

```php
Db::jsonContains('tags', 'php');
```

### `Db::jsonSet(string $column, string $path, $value): CallableInterface`

Set JSON value.

```php
Db::jsonSet('data', '$.status', 'active');
```

## Aggregate Helpers

### `Db::count(?string $column = '*'): CallableInterface`

COUNT aggregate.

```php
Db::count();
Db::count('id');
```

### `Db::sum(string $column): CallableInterface`

SUM aggregate.

```php
Db::sum('price');
```

### `Db::avg(string $column): CallableInterface`

AVG aggregate.

```php
Db::avg('price');
```

### `Db::min(string $column): CallableInterface`

MIN aggregate.

```php
Db::min('price');
```

### `Db::max(string $column): CallableInterface`

MAX aggregate.

```php
Db::max('price');
```

## Next Steps

- [API Reference](api-reference.md) - Complete API
- [Query Builder Methods](query-builder-methods.md) - Query builder methods
- [PdoDb Methods](pdo-db-methods.md) - PdoDb methods

