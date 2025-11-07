# EXPLAIN Analysis with Recommendations Examples

This directory contains examples demonstrating the EXPLAIN analysis feature with optimization recommendations.

## Examples

### 01-explain-recommendations.php

Demonstrates EXPLAIN analysis with recommendations:

- Analyzing queries with and without indexes
- Detecting full table scans
- Getting index suggestions
- Understanding query execution plans
- Identifying optimization opportunities

## Running Examples

```bash
# Run on SQLite (default)
php 01-explain-recommendations.php

# Run on MySQL
PDODB_DRIVER=mysql php 01-explain-recommendations.php

# Run on PostgreSQL
PDODB_DRIVER=pgsql php 01-explain-recommendations.php
```

## Features Demonstrated

1. **Basic Analysis**: Analyze query execution plans
2. **Full Table Scan Detection**: Identify queries that scan entire tables
3. **Index Recommendations**: Get suggestions for creating indexes
4. **Query Optimization**: See how indexes improve query performance
5. **Warnings Detection**: Identify filesort and temporary table usage
6. **Filter Ratio Analysis**: Detect low index selectivity (MySQL/MariaDB)
7. **Cost Analysis**: Analyze query cost (PostgreSQL)
8. **JOIN Analysis**: Detect inefficient JOIN operations
9. **Subquery Detection**: Identify dependent subqueries
10. **GROUP BY Optimization**: Detect GROUP BY without index usage

## Notes

- Different database dialects return different EXPLAIN formats
- Index recommendations are dialect-aware
- Full table scans are automatically detected
- Recommendations include severity levels (critical, warning, info)

## Related Documentation

- [Query Analysis Guide](../../documentation/05-advanced-features/query-analysis.md)
- [Performance Best Practices](../../documentation/08-best-practices/performance.md)

