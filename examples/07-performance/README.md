# Performance Examples

Examples demonstrating performance optimization features: caching, query compilation cache, profiling, and EXPLAIN analysis.

## Examples

### 01-caching.php
Query result caching with PSR-16 cache adapters.

**Topics covered:**
- Enabling query result caching
- Cache TTL configuration
- Cache key generation
- Cache invalidation
- PSR-16 cache adapter integration

### 02-query-compilation-cache.php
Caching compiled SQL queries for improved performance.

**Topics covered:**
- Query compilation cache
- Cache hit/miss statistics
- Performance improvements (10-30% faster)
- Cache management

### 03-query-profiling.php
Query performance profiling and analysis.

**Topics covered:**
- Query execution time tracking
- Memory usage monitoring
- Slow query detection
- Profiling statistics
- Performance bottlenecks identification

### 04-explain-analysis.php
EXPLAIN query analysis and optimization recommendations.

**Topics covered:**
- Execution plan analysis
- Full table scan detection
- Missing index detection
- Optimization recommendations
- Dialect-specific EXPLAIN formats

## Usage

```bash
# Run examples
php 01-caching.php
php 02-query-compilation-cache.php
php 03-query-profiling.php
php 04-explain-analysis.php
```

## Performance Tips

- **Caching** - Cache frequently accessed query results (10-1000x faster)
- **Compilation Cache** - Cache compiled SQL strings (10-30% faster)
- **Profiling** - Identify slow queries and optimize them
- **EXPLAIN** - Analyze execution plans to optimize queries

## Related Examples

- [Batch Processing](../06-data-management/02-batch-processing.php) - Efficient processing of large datasets
- [Bulk Operations](../03-advanced/02-bulk-operations.php) - Bulk inserts for better performance
