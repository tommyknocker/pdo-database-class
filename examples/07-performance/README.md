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

### 05-ai-query-advice.php

Three complete use cases: an ordered page, a selective JOIN, and an existing
index that already fits. Creates 100,000 deterministic orders and 2,000 customers
in a fresh SQLite database in memory. Always uses SQLite, even if `PDODB_DRIVER`
selects another database. No application tables or configured database are used.

```bash
# Real plans and local measurements; no API key or model calls
php examples/07-performance/05-ai-query-advice.php

# Explicitly request three live AI answers using private local settings
php examples/07-performance/05-ai-query-advice.php --ai
```

Run these commands from the repository root. `--ai` reads `.env.ai.local` (or
`--env=/private/file`) and caps each of three responses at 512 output tokens.
Use `--json` or `--output=/tmp/query-advice.json` for a machine-readable report.
See the [illustrated walkthrough](../../documentation/05-advanced-features/25-ai-query-advice-in-practice.md)
for captured model answers, real plans and measured results.

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
