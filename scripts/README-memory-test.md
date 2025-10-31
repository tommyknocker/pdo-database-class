# Memory Usage Test Script

## Overview

The `memory-test.php` script tests memory consumption for various database operations to detect potential memory leaks and measure memory efficiency.

## Usage

### Basic Usage (SQLite)

```bash
php scripts/memory-test.php
```

### With Environment Variables

```bash
# Customize database connection
DB_HOST=localhost \
DB_NAME=test \
DB_USER=root \
DB_PASS=password \
PDODB_DRIVER=mysql \
php scripts/memory-test.php
```

### Customize Test Parameters

```bash
# 10,000 records, 2000 queries per test
MEMORY_TEST_RECORDS=10000 \
MEMORY_TEST_QUERIES=2000 \
php scripts/memory-test.php
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PDODB_DRIVER` | `sqlite` | Database driver (mysql, pgsql, sqlite) |
| `DB_HOST` | `localhost` | Database host (not needed for SQLite) |
| `DB_NAME` | `test` | Database name (not needed for SQLite) |
| `DB_USER` | `testuser` | Database user (not needed for SQLite) |
| `DB_PASS` | `testpass` | Database password (not needed for SQLite) |
| `MEMORY_TEST_RECORDS` | `10000` | Number of test records to create |
| `MEMORY_TEST_QUERIES` | `5000` | Number of queries per test scenario |

## Test Scenarios

The script tests memory usage for:

1. **Simple SELECT**: Basic filtered queries with ordering
2. **Complex Queries**: Aggregations with GROUP BY and HAVING
3. **Streaming**: Using `stream()` for large result sets
4. **Batch Processing**: Using `batch()` for chunked processing
5. **UPDATE Operations**: Update queries with WHERE conditions
6. **Caching**: Queries with result cache and compilation cache enabled

## Output

The script outputs:

1. **Setup progress**: Database creation and data insertion with initial memory usage
2. **Per-test results**: Memory consumption for each operation type
3. **Memory usage summary**: Total memory, peak memory, and per-query averages
4. **Memory leak detection**: Warnings if high memory usage detected
5. **Memory efficiency rating**: Overall assessment of memory efficiency

### Understanding Memory Metrics

The script reports three key metrics:

#### Total Memory
The difference between final memory usage (after all operations complete, but before clearing results) and initial memory (before the test started). This shows how much memory remains allocated after operations finish. Some data may be cleaned up by PHP's garbage collector between measurements.

**Formula**: `afterMemory - beforeMemory` (while results are still in memory)

#### Peak Increase
The maximum memory increase observed at any point during execution. This tracks the highest memory usage during the loop when all query results and objects are actively being stored. This is the most accurate measure of peak memory consumption during execution.

**Formula**: `max(maxMemoryDuring - beforeMemory, afterPeak - beforePeak, memoryUsed)`

#### Per Query
Average memory usage per query operation, calculated as Total Memory divided by number of queries.

**Key Difference**:
- **Total Memory** ≤ **Peak Increase** (usually equal, but Total may be lower if GC freed memory)
- If **Total Memory** < **Peak Increase**, it indicates garbage collection freed some memory after queries completed
- **Peak Increase** shows the "worst case" memory usage during execution
- **Total Memory** shows the "final" memory footprint after operations

**Example Interpretation**:
```
Operation: UPDATE
Total Memory: 2.86 MB  - Memory difference after all UPDATE queries complete
Peak Increase: 2.86 MB  - Maximum memory used at any point during execution
Per Query: 2.93 KB      - Average memory per UPDATE operation
```

## Example Output

```
=== Memory Usage Summary ===

Operation            | Total Memory    | Peak Increase   | Per Query      
----------------------------------------------------------------------
Simple SELECT        | 2.5 MB         | 1.2 MB          | 2.5 KB         
Complex Queries      | 5.1 MB         | 3.8 MB          | 51 KB          
Streaming            | 0.8 MB          | 0.5 MB          | 8 KB           
Batch Processing     | 1.2 MB         | 0.9 MB          | 12 KB          
UPDATE               | 3.4 MB         | 2.1 MB          | 17 KB          
Caching              | 6.2 MB         | 4.5 MB          | 31 KB          

=== Memory Leak Detection ===

✓ Memory usage appears normal
  Total increase: 12.5 MB

=== Memory Efficiency ===

Average memory per query: 25.6 KB
Memory efficiency rating: Good (< 100 KB per query)
```

## Memory Leak Detection

The script detects potential memory leaks by:

- Comparing initial and final memory usage
- Monitoring peak memory increases during operations
- Calculating memory per query averages
- Warning if total memory usage exceeds 10MB

### Warning Indicators

- **High total memory usage** (> 10MB): May indicate memory leaks
- **Increasing memory per query**: Suggests objects not being released
- **High peak memory spikes**: May indicate large temporary allocations

## Troubleshooting

### Connection Errors

Make sure database credentials are correct:
```bash
DB_USER=myuser DB_PASS=mypass php scripts/memory-test.php
```

### Memory Limit Issues

For large test datasets, increase PHP memory limit:
```bash
php -d memory_limit=512M scripts/memory-test.php
```

### Small Memory Changes Not Detected

For very small operations, memory changes may appear as 0 B due to PHP's memory allocation granularity or automatic garbage collection. This is normal for efficient operations. 

**Note**: The script stores query results in memory during testing to prevent premature garbage collection, simulating real-world scenarios where results are kept in memory. This means:
- Operations that process and discard results immediately may show 0 B
- Operations that accumulate results (like batch processing) will show actual memory usage
- Streaming operations may show low memory if results are processed and discarded

To see memory usage for efficient operations, increase `MEMORY_TEST_QUERIES` for more accurate measurements.

## Requirements

- PHP 8.1+
- PSR-16 cache implementation (uses ArrayCache from tests)
- Database connection (MySQL/PostgreSQL/SQLite)
- Write permissions to create test tables

## Interpretation

### Good Memory Efficiency
- < 1 KB per query: Excellent
- < 10 KB per query: Good
- < 100 KB per query: Moderate
- > 100 KB per query: May need optimization

### Memory Leak Indicators
- Steady increase in memory per query across iterations
- Final memory significantly higher than initial
- Peak memory increases continuously

## Notes

- Uses in-memory ArrayCache for caching tests
- SQLite uses `:memory:` database (no disk I/O)
- Results may vary based on PHP version, OS, and hardware
- Memory measurements use `memory_get_usage(true)` for real memory usage

