# Window Functions

Window functions (also known as analytic functions) perform calculations across a set of table rows that are related to the current row. Unlike aggregate functions, window functions do not cause rows to be grouped into a single output row - each row retains its identity.

## Overview

Window functions are supported on:
- **MySQL 8.0+**
- **PostgreSQL 9.4+**
- **SQLite 3.25+**

## Basic Syntax

All window functions follow this general pattern:

```php
Db::windowFunction()
    ->partitionBy('column')   // Optional: divide into groups
    ->orderBy('column', 'DIR') // Optional/Required: define ordering
    ->rows('FRAME_CLAUSE')     // Optional: define window frame
```

## Ranking Functions

### ROW_NUMBER()

Assigns a unique sequential integer to rows within a partition.

```php
$results = $db->find()
    ->from('products')
    ->select([
        'name',
        'category',
        'price',
        'row_num' => Db::rowNumber()
            ->partitionBy('category')
            ->orderBy('price', 'DESC')
    ])
    ->get();
```

**Use cases:**
- Numbering items within groups
- Pagination within partitions
- Generating unique identifiers within groups

### RANK()

Assigns a rank to each row within a partition. Rows with equal values receive the same rank, with gaps in the sequence for ties.

```php
$results = $db->find()
    ->from('students')
    ->select([
        'name',
        'score',
        'student_rank' => Db::rank()->orderBy('score', 'DESC')
    ])
    ->get();

// Example output:
// Score 100: Rank 1
// Score 100: Rank 1  
// Score 95:  Rank 3  ← Gap after tie
// Score 90:  Rank 4
```

**Use cases:**
- Leaderboards with tie handling
- Top N with duplicates
- Competition rankings

### DENSE_RANK()

Similar to RANK(), but without gaps in the ranking sequence.

```php
$results = $db->find()
    ->from('students')
    ->select([
        'name',
        'score',
        'student_dense_rank' => Db::denseRank()->orderBy('score', 'DESC')
    ])
    ->get();

// Example output:
// Score 100: Rank 1
// Score 100: Rank 1
// Score 95:  Rank 2  ← No gap
// Score 90:  Rank 3
```

**Use cases:**
- Rankings where gaps are undesirable
- Categorizing by performance tiers

### NTILE(n)

Divides rows into *n* roughly equal buckets.

```php
$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'amount',
        'quartile' => Db::ntile(4)->orderBy('amount')
    ])
    ->get();
```

**Use cases:**
- Percentile analysis (quartiles, deciles)
- Dividing data into equal groups
- A/B testing groups

## Value Access Functions

### LAG()

Accesses data from a previous row in the same result set.

```php
$results = $db->find()
    ->from('sales')
    ->select([
        'date',
        'amount',
        'prev_amount' => Db::lag('amount', 1, 0)
            ->partitionBy('region')
            ->orderBy('date')
    ])
    ->get();
```

**Parameters:**
- `$column` - Column to access
- `$offset` - Number of rows back (default: 1)
- `$default` - Default value if no previous row exists

**Use cases:**
- Period-over-period comparisons
- Calculating differences/changes
- Detecting trends

### LEAD()

Accesses data from a subsequent row in the same result set.

```php
$results = $db->find()
    ->from('sales')
    ->select([
        'date',
        'amount',
        'next_amount' => Db::lead('amount', 1, 0)
            ->partitionBy('region')
            ->orderBy('date')
    ])
    ->get();
```

**Parameters:**
- `$column` - Column to access
- `$offset` - Number of rows forward (default: 1)
- `$default` - Default value if no next row exists

**Use cases:**
- Forward-looking predictions
- Detecting upcoming changes
- Gap analysis

### FIRST_VALUE()

Returns the first value in a window frame.

```php
$results = $db->find()
    ->from('stocks')
    ->select([
        'date',
        'price',
        'first_price' => Db::firstValue('price')
            ->partitionBy('ticker')
            ->orderBy('date')
    ])
    ->get();
```

**Use cases:**
- Baseline comparisons
- Start-of-period values
- Initial state tracking

### LAST_VALUE()

Returns the last value in a window frame.

```php
$results = $db->find()
    ->from('stocks')
    ->select([
        'date',
        'price',
        'last_price' => Db::lastValue('price')
            ->partitionBy('ticker')
            ->orderBy('date')
            ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING')
    ])
    ->get();
```

**Important:** `LAST_VALUE()` often requires an explicit frame clause to work as expected.

**Use cases:**
- End-of-period values
- Final state tracking
- Most recent value comparisons

### NTH_VALUE(n)

Returns the nth value in a window frame.

```php
$results = $db->find()
    ->from('races')
    ->select([
        'race_id',
        'runner',
        'time',
        'third_place_time' => Db::nthValue('time', 3)
            ->partitionBy('race_id')
            ->orderBy('time')
    ])
    ->get();
```

**Use cases:**
- Specific position analysis
- Benchmark comparisons
- Milestone tracking

## Aggregate Functions as Window Functions

All standard aggregate functions (SUM, AVG, MIN, MAX, COUNT) can be used as window functions.

### Running Totals

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

### Moving Averages

```php
$results = $db->find()
    ->from('metrics')
    ->select([
        'date',
        'value',
        'moving_avg_7day' => Db::windowAggregate('AVG', 'value')
            ->orderBy('date')
            ->rows('ROWS BETWEEN 6 PRECEDING AND CURRENT ROW')
    ])
    ->get();
```

### Percentage of Total

```php
$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'amount',
        'pct_of_total' => Db::raw('CAST(' .  
            Db::windowAggregate('SUM', 'amount')->orderBy('amount') .
            ' * 100.0 / ' . 
            Db::windowAggregate('SUM', 'amount') . 
            ' AS INTEGER)')
    ])
    ->get();
```

## Window Clauses

### PARTITION BY

Divides the result set into partitions. Window function is applied separately to each partition.

```php
// Each region gets its own ranking
Db::rowNumber()->partitionBy('region')->orderBy('sales')

// Multiple partition columns
Db::rank()->partitionBy(['department', 'team'])->orderBy('salary')
```

### ORDER BY

Defines the logical order of rows within each partition.

```php
// Single column
->orderBy('date', 'ASC')

// Multiple columns
->orderBy(['date' => 'ASC', 'id' => 'ASC'])
```

**Required for:** ROW_NUMBER, RANK, DENSE_RANK, NTILE, LAG, LEAD
**Optional for:** Aggregate functions (defines frame)

### Frame Clause

Defines the subset of the partition (the "window") to use for the calculation.

```php
// All rows from start to current
->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW')

// Last 7 rows (including current)
->rows('ROWS BETWEEN 6 PRECEDING AND CURRENT ROW')

// Last 3 to next 3 rows
->rows('ROWS BETWEEN 3 PRECEDING AND 3 FOLLOWING')

// All rows in partition
->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING')
```

**Frame Modes:**
- `ROWS` - Physical rows
- `RANGE` - Logical range (values)

## Common Patterns

### Top N per Group

```php
// Get top 3 products per category
$results = $db->find()
    ->from(function($q) use ($db) {
        $q->from('products')
          ->select([
              'id',
              'category',
              'name',
              'price',
              'rn' => Db::rowNumber()
                  ->partitionBy('category')
                  ->orderBy('price', 'DESC')
          ]);
    }, 'ranked')
    ->where('rn', '<=', 3)
    ->get();
```

### Period-over-Period Change

```php
$results = $db->find()
    ->from('monthly_sales')
    ->select([
        'month',
        'sales',
        'prev_month_sales' => Db::lag('sales', 1, 0)->orderBy('month'),
        'change' => Db::raw('sales - ' . Db::lag('sales', 1, 0)->orderBy('month')),
        'pct_change' => Db::raw('CAST((sales - ' . 
            Db::lag('sales', 1, 1)->orderBy('month') . 
            ') * 100.0 / ' . 
            Db::lag('sales', 1, 1)->orderBy('month') . 
            ' AS INTEGER)')
    ])
    ->get();
```

### Cumulative Percentage

```php
$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'amount',
        'cumulative_pct' => Db::raw('CAST(' .
            Db::windowAggregate('SUM', 'amount')
                ->orderBy('amount', 'DESC')
                ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW') .
            ' * 100.0 / ' .
            Db::windowAggregate('SUM', 'amount') .
            ' AS INTEGER)')
    ])
    ->orderBy('amount', 'DESC')
    ->get();
```

## Performance Considerations

### Indexing

Add indexes on columns used in:
- `PARTITION BY`
- `ORDER BY`

```sql
CREATE INDEX idx_sales_region_date ON sales(region, date);
```

### Limiting Results

Window functions on large datasets can be expensive. Use `LIMIT` when possible:

```php
$db->find()
    ->from('large_table')
    ->select([
        'col',
        'row_num' => Db::rowNumber()->orderBy('date')
    ])
    ->limit(1000)  // Limit result set
    ->get();
```

### Frame Clauses

Restrict window frames to only the rows you need:

```php
// Good: Only last 7 rows
->rows('ROWS BETWEEN 6 PRECEDING AND CURRENT ROW')

// Avoid if possible: Entire partition
->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING')
```

### Materialized Views

For frequently-used complex window queries, consider materialized views or caching.

## Cross-Database Compatibility

All window functions work identically across MySQL 8.0+, PostgreSQL 9.4+, and SQLite 3.25+.

### MySQL Notes
- Requires MySQL 8.0 or later
- Reserved words (`RANK`, `DENSE_RANK`) must be escaped in column aliases

### PostgreSQL Notes
- Full support since 9.4
- Excellent performance with proper indexing

### SQLite Notes
- Requires SQLite 3.25 or later (2018)
- Full feature parity with MySQL and PostgreSQL

## See Also

- [Helper Functions Reference](../07-helper-functions/window-helpers.md)
- [Examples: Window Functions](../../examples/16-window-functions/)
- [Best Practices: Performance](../08-best-practices/performance.md)
