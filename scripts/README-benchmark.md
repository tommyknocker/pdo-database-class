# Cache Benchmark Script

## Overview

The `benchmark-caches.php` script tests and compares performance of different cache configurations:

- **No Cache**: Baseline performance
- **Compilation Cache Only**: Caches compiled SQL strings
- **Result Cache Only**: Caches query results
- **Both Caches**: Uses both compilation and result caching

## Usage

### Basic Usage (MySQL)

```bash
php scripts/benchmark-caches.php
```

### With Environment Variables

```bash
# Customize database connection
DB_HOST=localhost \
DB_NAME=test \
DB_USER=root \
DB_PASS=password \
PDODB_DRIVER=mysql \
php scripts/benchmark-caches.php
```

### Customize Test Parameters

```bash
# 50,000 records, 500 iterations
BENCHMARK_RECORDS=50000 \
BENCHMARK_ITERATIONS=500 \
php scripts/benchmark-caches.php
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PDODB_DRIVER` | `mysql` | Database driver (mysql, pgsql, sqlite) |
| `DB_HOST` | `localhost` | Database host |
| `DB_NAME` | `test` | Database name |
| `DB_USER` | `root` | Database user |
| `DB_PASS` | `` | Database password |
| `BENCHMARK_RECORDS` | `10000` | Number of test records to create |
| `BENCHMARK_ITERATIONS` | `100` | Number of query iterations per test |

## Test Scenarios

The benchmark runs 4 different query patterns:

1. **Simple WHERE**: Basic filtered query with ordering
2. **Complex Query**: Multiple conditions with multiple orderings
3. **Aggregation**: GROUP BY with aggregate functions
4. **Multiple Conditions**: Multiple WHERE clauses with different operators

## Output

The script outputs:

1. **Setup progress**: Database creation and data insertion
2. **Test results table**: Performance for each scenario
3. **Performance improvements**: Percentage improvements vs baseline
4. **Summary**: Average performance across all tests
5. **Recommendations**: Best cache configuration for your workload

## Example Output

```
=== Benchmark Results ===

Test                      | No Cache     | Compilation  | Result      | Both        | Best        
----------------------------------------------------------------------------------------------------
Simple WHERE              | 245.23ms     | 218.45ms     | 12.34ms     | 10.12ms     | Both Caches 
Complex Query             | 512.67ms     | 478.91ms     | 25.67ms     | 22.45ms     | Both Caches 
Aggregation               | 389.12ms     | 356.78ms     | 18.90ms     | 16.23ms     | Both Caches 
Multiple Conditions       | 298.45ms     | 267.12ms     | 15.67ms     | 13.89ms     | Both Caches 

=== Performance Improvements ===

Simple WHERE:
  No Cache:         245.23ms
  Compilation Only: 218.45ms (+10.9%)
  Result Only:      12.34ms (+95.0%)
  Both Caches:      10.12ms (+95.9%)

=== Recommendations ===

✓ Use both compilation and result caches for best performance
```

## Notes

- Uses in-memory ArrayCache for testing (no persistent storage)
- Clears cache between scenarios for fair comparison
- For production testing, use actual cache backends (Redis, Memcached, etc.)
- Results may vary based on database size, hardware, and query patterns

## Requirements

- PHP 8.1+
- PSR-16 cache implementation (uses ArrayCache from tests)
- Database connection (MySQL/PostgreSQL/SQLite)
- Write permissions to create test table

## Troubleshooting

### Connection Errors

Make sure database credentials are correct:
```bash
DB_USER=myuser DB_PASS=mypass php scripts/benchmark-caches.php
```

### Memory Issues

For large datasets, increase PHP memory limit:
```bash
php -d memory_limit=512M scripts/benchmark-caches.php
```

### Table Already Exists

The script automatically drops the test table. If issues persist:
```sql
DROP TABLE IF EXISTS benchmark_users;
```
