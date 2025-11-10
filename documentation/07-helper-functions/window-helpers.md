# Window Function Helpers

Window function helpers provide convenient access to SQL window functions (analytic functions) for advanced data analysis.

## Requirements

- **MySQL 8.0+**
- **PostgreSQL 9.4+**
- **SQLite 3.25+**

## Overview

All window function helpers are accessed via the `Db` class and return `WindowFunctionValue` objects that can be further configured with `partitionBy()`, `orderBy()`, and `rows()` methods.

```php
use tommyknocker\pdodb\helpers\Db;

$windowFunc = Db::rowNumber()
    ->partitionBy('category')
    ->orderBy('price', 'DESC');
```

## Ranking Functions

### `Db::rowNumber()`

Assigns a unique sequential integer to rows within a partition.

**Signature:**
```php
public static function rowNumber(): WindowFunctionValue
```

**Example:**
```php
$results = $db->find()
    ->from('products')
    ->select([
        'name',
        'category',
        'row_num' => Db::rowNumber()
            ->partitionBy('category')
            ->orderBy('price', 'DESC')
    ])
    ->get();
```

**Returns:**
- Sequential integers starting from 1 within each partition
- No duplicates, even for ties

---

### `Db::rank()`

Assigns a rank with gaps for ties.

**Signature:**
```php
public static function rank(): WindowFunctionValue
```

**Example:**
```php
$results = $db->find()
    ->from('students')
    ->select([
        'name',
        'score',
        'student_rank' => Db::rank()->orderBy('score', 'DESC')
    ])
    ->get();

// Scores: 100, 100, 95 → Ranks: 1, 1, 3 (gap after tie)
```

**Note:** Use `item_rank` or similar alias instead of `rank` to avoid MySQL reserved word conflicts.

---

### `Db::denseRank()`

Assigns a rank without gaps for ties.

**Signature:**
```php
public static function denseRank(): WindowFunctionValue
```

**Example:**
```php
$results = $db->find()
    ->from('students')
    ->select([
        'name',
        'score',
        'student_dense_rank' => Db::denseRank()->orderBy('score', 'DESC')
    ])
    ->get();

// Scores: 100, 100, 95 → Ranks: 1, 1, 2 (no gap)
```

**Note:** Use `item_dense_rank` or similar alias instead of `dense_rank` to avoid MySQL reserved word conflicts.

---

### `Db::ntile(int $buckets)`

Divides rows into *n* roughly equal buckets.

**Signature:**
```php
public static function ntile(int $buckets): WindowFunctionValue
```

**Parameters:**
- `$buckets` - Number of buckets to create

**Example:**
```php
// Divide into quartiles
$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'amount',
        'quartile' => Db::ntile(4)->orderBy('amount')
    ])
    ->get();
```

**Use Cases:**
- Quartiles: `ntile(4)`
- Deciles: `ntile(10)`
- Percentiles: `ntile(100)`

---

## Value Access Functions

### `Db::lag()`

Accesses data from a previous row.

**Signature:**
```php
public static function lag(
    string|RawValue $column,
    int $offset = 1,
    mixed $default = null
): WindowFunctionValue
```

**Parameters:**
- `$column` - Column name or expression to access
- `$offset` - Number of rows back (default: 1)
- `$default` - Default value when no previous row exists (default: null)

**Example:**
```php
$results = $db->find()
    ->from('sales')
    ->select([
        'date',
        'amount',
        'prev_day' => Db::lag('amount', 1, 0)
            ->orderBy('date'),
        'prev_week' => Db::lag('amount', 7, 0)
            ->orderBy('date')
    ])
    ->get();
```

**Use Cases:**
- Month-over-month comparisons
- Day-over-day changes
- Trend detection

---

### `Db::lead()`

Accesses data from a subsequent row.

**Signature:**
```php
public static function lead(
    string|RawValue $column,
    int $offset = 1,
    mixed $default = null
): WindowFunctionValue
```

**Parameters:**
- `$column` - Column name or expression to access
- `$offset` - Number of rows forward (default: 1)
- `$default` - Default value when no next row exists (default: null)

**Example:**
```php
$results = $db->find()
    ->from('sales')
    ->select([
        'date',
        'amount',
        'next_day' => Db::lead('amount', 1, 0)
            ->orderBy('date')
    ])
    ->get();
```

**Use Cases:**
- Forward-looking predictions
- Detecting upcoming changes
- Gap analysis

---

### `Db::firstValue()`

Returns the first value in a window frame.

**Signature:**
```php
public static function firstValue(string|RawValue $column): WindowFunctionValue
```

**Parameters:**
- `$column` - Column name to get first value from

**Example:**
```php
$results = $db->find()
    ->from('stocks')
    ->select([
        'date',
        'price',
        'opening_price' => Db::firstValue('price')
            ->partitionBy('ticker')
            ->orderBy('date')
    ])
    ->get();
```

---

### `Db::lastValue()`

Returns the last value in a window frame.

**Signature:**
```php
public static function lastValue(string|RawValue $column): WindowFunctionValue
```

**Parameters:**
- `$column` - Column name to get last value from

**Example:**
```php
$results = $db->find()
    ->from('stocks')
    ->select([
        'date',
        'price',
        'closing_price' => Db::lastValue('price')
            ->partitionBy('ticker')
            ->orderBy('date')
            ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING')
    ])
    ->get();
```

**Important:** Often requires explicit frame clause to work correctly.

---

### `Db::nthValue()`

Returns the nth value in a window frame.

**Signature:**
```php
public static function nthValue(
    string|RawValue $column,
    int $n
): WindowFunctionValue
```

**Parameters:**
- `$column` - Column name
- `$n` - Position (1-based index)

**Example:**
```php
$results = $db->find()
    ->from('races')
    ->select([
        'race_id',
        'runner',
        'time',
        'silver_time' => Db::nthValue('time', 2)
            ->partitionBy('race_id')
            ->orderBy('time')
    ])
    ->get();
```

---

## Aggregate Window Functions

### `Db::windowAggregate()`

Uses aggregate functions (SUM, AVG, MIN, MAX, COUNT) with OVER clause.

**Signature:**
```php
public static function windowAggregate(
    string $function,
    string|RawValue $column
): WindowFunctionValue
```

**Parameters:**
- `$function` - Aggregate function name (SUM, AVG, MIN, MAX, COUNT)
- `$column` - Column name or expression

**Examples:**

**Running Total:**
```php
$results = $db->find()
    ->from('transactions')
    ->select([
        'date',
        'amount',
        'running_total' => Db::windowAggregate('SUM', 'amount')
            ->orderBy('date')
            ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW')
    ])
    ->get();
```

**Moving Average (7-day):**
```php
$results = $db->find()
    ->from('metrics')
    ->select([
        'date',
        'value',
        'moving_avg' => Db::windowAggregate('AVG', 'value')
            ->orderBy('date')
            ->rows('ROWS BETWEEN 6 PRECEDING AND CURRENT ROW')
    ])
    ->get();
```

**Running Count:**
```php
$results = $db->find()
    ->from('events')
    ->select([
        'date',
        'event',
        'event_count' => Db::windowAggregate('COUNT', '*')
            ->partitionBy('user_id')
            ->orderBy('date')
            ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW')
    ])
    ->get();
```

**Running Min/Max:**
```php
$results = $db->find()
    ->from('prices')
    ->select([
        'date',
        'price',
        'highest_so_far' => Db::windowAggregate('MAX', 'price')
            ->partitionBy('product_id')
            ->orderBy('date')
            ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW')
    ])
    ->get();
```

---

## Window Configuration Methods

All window function helpers return `WindowFunctionValue` objects that support these configuration methods:

### `partitionBy()`

Divide the result set into partitions.

```php
// Single column
->partitionBy('category')

// Multiple columns
->partitionBy(['department', 'team'])
```

### `orderBy()`

Define the order within each partition.

```php
// Single column with direction
->orderBy('price', 'DESC')

// Multiple columns
->orderBy(['date' => 'ASC', 'id' => 'ASC'])

// Array with default direction
->orderBy(['col1', 'col2'], 'ASC')
```

### `rows()`

Define the window frame.

```php
// All rows from start to current
->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW')

// Last N rows
->rows('ROWS BETWEEN 6 PRECEDING AND CURRENT ROW')

// All rows in partition
->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING')
```

---

## Complete Examples

### Ranking with Ties

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()
    ->from('employees')
    ->select([
        'name',
        'department',
        'salary',
        'dept_rank' => Db::rank()
            ->partitionBy('department')
            ->orderBy('salary', 'DESC'),
        'dept_dense_rank' => Db::denseRank()
            ->partitionBy('department')
            ->orderBy('salary', 'DESC'),
        'row_num' => Db::rowNumber()
            ->partitionBy('department')
            ->orderBy('salary', 'DESC')
    ])
    ->orderBy('department')
    ->orderBy('salary', 'DESC')
    ->get();
```

### Period Comparisons

```php
$db->find()
    ->from('monthly_sales')
    ->select([
        'month',
        'revenue',
        'prev_month' => Db::lag('revenue', 1, 0)
            ->orderBy('month'),
        'prev_year' => Db::lag('revenue', 12, 0)
            ->orderBy('month'),
        'next_month' => Db::lead('revenue', 1, 0)
            ->orderBy('month')
    ])
    ->get();
```

### Moving Statistics

```php
$db->find()
    ->from('stock_prices')
    ->select([
        'date',
        'price',
        'ma_7' => Db::windowAggregate('AVG', 'price')
            ->orderBy('date')
            ->rows('ROWS BETWEEN 6 PRECEDING AND CURRENT ROW'),
        'ma_30' => Db::windowAggregate('AVG', 'price')
            ->orderBy('date')
            ->rows('ROWS BETWEEN 29 PRECEDING AND CURRENT ROW'),
        'max_52w' => Db::windowAggregate('MAX', 'price')
            ->orderBy('date')
            ->rows('ROWS BETWEEN 364 PRECEDING AND CURRENT ROW')
    ])
    ->get();
```

---

## Tips and Best Practices

### Reserved Words

Avoid using SQL reserved words as column aliases:
```php
// Bad (MySQL error)
'rank' => Db::rank()

// Good
'item_rank' => Db::rank()
'product_rank' => Db::rank()
```

### Performance

1. **Add indexes** on columns used in `PARTITION BY` and `ORDER BY`
2. **Limit result sets** when possible
3. **Use appropriate frame clauses** to restrict window size
4. **Consider materialized views** for complex repeated queries

### Frame Clauses

For `LAST_VALUE()` to work as expected, use:
```php
->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING')
```

For moving averages, specify exact window size:
```php
// 7-day moving average (current + 6 preceding)
->rows('ROWS BETWEEN 6 PRECEDING AND CURRENT ROW')
```

---

## See Also

- [Query Builder: Window Functions](../03-query-builder/09-window-functions.md)
- [Examples: Window Functions](../../examples/16-window-functions/)
- [API Reference](../09-reference/helper-functions-reference.md)
