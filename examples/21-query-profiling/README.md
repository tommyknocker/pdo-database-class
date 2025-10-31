# Query Performance Profiling Examples

This directory contains examples demonstrating the Query Performance Profiler feature.

## Overview

The Query Performance Profiler tracks query execution times, memory usage, and identifies slow queries to help you optimize database performance.

## Files

- `01-profiling-examples.php` - Basic profiling usage examples

## Running Examples

### Basic Usage

```bash
# Using SQLite (default)
php examples/21-query-profiling/01-profiling-examples.php

# Using MySQL
PDODB_DRIVER=mysql \
  DB_HOST=localhost \
  DB_NAME=testdb \
  DB_USER=testuser \
  DB_PASS=testpass \
  php examples/21-query-profiling/01-profiling-examples.php

# Using PostgreSQL
PDODB_DRIVER=pgsql \
  DB_HOST=localhost \
  DB_NAME=testdb \
  DB_USER=testuser \
  DB_PASS=testpass \
  php examples/21-query-profiling/01-profiling-examples.php
```

## Example Output

```
=== Query Performance Profiling Examples ===

Driver: sqlite

1. Basic Profiling Setup
   --------------------
Enable profiling with slow query threshold of 0.1 seconds:
   ✓ Profiling enabled

2. Inserting Test Data
   --------------------
   ✓ Inserted 50 test records

3. Executing Queries
   --------------------
   ✓ Executed multiple queries

4. Profiling Statistics
   --------------------
Aggregated Statistics:
  Total queries: 12
  Total execution time: 15.23 ms
  Average execution time: 1.27 ms
  Min execution time: 0.45 ms
  Max execution time: 8.90 ms
  ...
```

## Features Demonstrated

1. **Enabling/Disabling Profiling** - Control when profiling is active
2. **Slow Query Detection** - Automatic detection of queries exceeding threshold
3. **Aggregated Statistics** - Overall performance metrics across all queries
4. **Per-Query Statistics** - Detailed metrics for each unique query pattern
5. **Slowest Queries** - Identification of performance bottlenecks
6. **Memory Tracking** - Memory consumption per query
7. **Profiler Reset** - Clearing statistics for new measurement periods

## See Also

- [Query Profiling Documentation](../../documentation/05-advanced-features/query-profiling.md)
- [Performance Optimization Guide](../../documentation/05-advanced-features/)

