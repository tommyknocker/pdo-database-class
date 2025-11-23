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

### `Db::left(string|RawValue $value, int $length): CallableInterface`

Left substring.

```php
Db::left('name', 2);
```

### `Db::right(string|RawValue $value, int $length): CallableInterface`

Right substring.

```php
Db::right('name', 2);
```

### `Db::position(string|RawValue $substring, string|RawValue $value): CallableInterface`

Substring position (1-based).

```php
Db::position(Db::raw("'@'"), 'email');
```

### `Db::repeat(string|RawValue $value, int $count): CallableInterface`

Repeat string.

```php
Db::repeat(Db::raw("'-'"), 5);
```

### `Db::reverse(string|RawValue $value): CallableInterface`

Reverse string.

```php
Db::reverse('name');
```

### `Db::padLeft(string|RawValue $value, int $length, string $padString = ' '): CallableInterface`

Left pad.

```php
Db::padLeft('name', 8, ' ');
```

### `Db::padRight(string|RawValue $value, int $length, string $padString = ' '): CallableInterface`

Right pad.

```php
Db::padRight('name', 8, '.');
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

### `Db::ceil(string|RawValue $value): CallableInterface`

Ceiling (round up).

```php
Db::ceil('price');
```

### `Db::floor(string|RawValue $value): CallableInterface`

Floor (round down).

```php
Db::floor('price');
```

### `Db::power(string|RawValue $value, string|int|float|RawValue $exponent): CallableInterface`

Exponentiation.

```php
Db::power('score', 2);
```

### `Db::sqrt(string|RawValue $value): CallableInterface`

Square root.

```php
Db::sqrt('distance');
```

### `Db::exp(string|RawValue $value): CallableInterface`

Exponential function.

```php
Db::exp(1);
```

### `Db::ln(string|RawValue $value): CallableInterface`

Natural logarithm.

```php
Db::ln('value');
```

### `Db::log(string|RawValue $value, string|int|float|RawValue|null $base = null): CallableInterface`

Logarithm (base 10 by default).

```php
Db::log('value');
Db::log('value', 2);
```

### `Db::trunc(string|RawValue $value, int $precision = 0): CallableInterface`

Truncate without rounding.

```php
Db::trunc('price', 1);
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

### `Db::addInterval(string|RawValue $expr, string $value, string $unit): CallableInterface`

Add interval.

```php
Db::addInterval('created_at', '1', 'DAY');
```

### `Db::subInterval(string|RawValue $expr, string $value, string $unit): CallableInterface`

Subtract interval.

```php
Db::subInterval('created_at', '1', 'MONTH');
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

### `Db::groupConcat(string|RawValue $column, string $separator = ',', bool $distinct = false): CallableInterface`

Concatenate values per group (dialect-specific: GROUP_CONCAT/STRING_AGG).

```php
Db::groupConcat('name', ', ', true);
```

## Next Steps

- [API Reference](01-api-reference.md) - Complete API
- [Query Builder Methods](02-query-builder-methods.md) - Query builder methods
- [PdoDb Methods](03-pdo-db-methods.md) - PdoDb methods
