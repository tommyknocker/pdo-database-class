# Window Functions Examples

This directory contains examples demonstrating window functions (analytic functions) for advanced data analysis across MySQL, PostgreSQL, and SQLite.

## What are Window Functions?

Window functions perform calculations across a set of table rows that are related to the current row. Unlike aggregate functions, they don't collapse rows into a single output row - each row retains its identity while having access to aggregate information.

## Requirements

- MySQL 8.0+ (for window functions support)
- PostgreSQL 9.4+ (for window functions support)  
- SQLite 3.25+ (for window functions support)

## Examples

### 01-window-functions.php

Comprehensive demonstration of all window function capabilities:

1. **ROW_NUMBER()** - Assigns unique sequential numbers within partitions
2. **RANK()** - Ranks rows with gaps for ties
3. **DENSE_RANK()** - Ranks rows without gaps
4. **LAG()** - Access previous row's data
5. **LEAD()** - Access next row's data
6. **Running Totals** - Calculate cumulative sums
7. **Moving Averages** - Calculate rolling averages
8. **FIRST_VALUE() / LAST_VALUE()** - Access first and last values in window
9. **NTILE()** - Divide rows into buckets/quartiles
10. **Multiple Window Functions** - Combine multiple window functions

## Key Concepts

### PARTITION BY
Divides result set into groups/partitions:
```php
Db::rowNumber()->partitionBy('region')
```

### ORDER BY  
Defines the order within each partition:
```php
Db::rank()->partitionBy('region')->orderBy('amount', 'DESC')
```

### Frame Clause
Defines the window frame (subset of partition):
```php
Db::windowAggregate('SUM', 'amount')
    ->orderBy('date')
    ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW')
```

## Common Use Cases

- **Ranking**: Product rankings, leaderboards, top N per category
- **Running Totals**: Cumulative sales, balance calculations
- **Moving Averages**: 7-day average, smoothing metrics
- **Period-over-Period**: Month-over-month, year-over-year comparisons
- **Percentiles**: Top 10%, quartile analysis
- **Gap Analysis**: Finding differences between consecutive rows

## Running the Examples

Run on all three database dialects:

```bash
# SQLite
PDODB_DRIVER=sqlite php 01-window-functions.php

# MySQL
PDODB_DRIVER=mysql php 01-window-functions.php

# PostgreSQL  
PDODB_DRIVER=pgsql php 01-window-functions.php
```

## Performance Tips

1. **Add indexes** on columns used in PARTITION BY and ORDER BY
2. **Limit result sets** when possible - window functions on large datasets can be expensive
3. **Use appropriate frame clauses** - restrict window frames to only needed rows
4. **Consider materialized views** for frequently-used window function queries

## See Also

- [Documentation: Window Functions](../../documentation/03-query-builder/window-functions.md)
- [API Reference: Window Helper Functions](../../documentation/07-helper-functions/window-helpers.md)
- [Best Practices: Performance](../../documentation/08-best-practices/performance.md)




