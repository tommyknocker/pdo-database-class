# Advanced Examples

Complex operations and patterns for advanced use cases.

## Examples

### 01-connection-pooling.php
Managing multiple database connections and switching between them.

**Topics covered:**
- Creating connections without default connection
- Adding multiple named connections
- Switching between connections with `connection()`
- Managing connections to different database types
- Connection reuse and cleanup
- Multi-database applications

### 02-bulk-operations.php
Bulk inserts and data loading from files.

**Topics covered:**
- Bulk inserts with `insertMulti()`
- Loading data from CSV files with `loadFromCsv()`
- Loading data from XML files with `loadFromXml()`
- Batch size configuration
- Performance optimization for large datasets
- Error handling in bulk operations

**Note:** For JSON loading, see [05-file-loading/](../05-file-loading/).

### 03-upsert.php
INSERT or UPDATE operations (UPSERT).

**Topics covered:**
- MySQL REPLACE operations
- INSERT ON DUPLICATE KEY UPDATE (MySQL)
- INSERT ON CONFLICT (PostgreSQL)
- INSERT OR REPLACE (SQLite)
- Dialect-specific upsert strategies
- Handling unique constraints
- Batch upserts

### 04-subqueries.php
Subqueries in SELECT, WHERE, and FROM clauses.

**Topics covered:**
- Scalar subqueries in SELECT
- Subqueries in WHERE conditions
- EXISTS and NOT EXISTS
- Subqueries with IN operator
- Subqueries in FROM clause (derived tables)
- Correlated subqueries
- Multiple subqueries in one query
- QueryBuilder subquery support

### 05-merge.php
MERGE statement operations (INSERT/UPDATE/DELETE based on match conditions).

**Topics covered:**
- MERGE with table source
- MERGE with subquery source
- MERGE with QueryBuilder source
- WHEN MATCHED clause (UPDATE operations)
- WHEN NOT MATCHED clause (INSERT operations)
- PostgreSQL native MERGE support
- MySQL emulation via INSERT ... ON DUPLICATE KEY UPDATE
- SQLite emulation via INSERT OR REPLACE
- Synchronizing data between tables
- Dialect-specific MERGE implementations

### 06-sql-formatter.php
SQL Formatter for pretty-printing SQL queries during debugging.

**Topics covered:**
- Unformatted SQL output (default behavior)
- Formatted SQL with proper indentation and line breaks
- Complex queries with JOINs, GROUP BY, ORDER BY
- Multiple WHERE conditions formatting
- Subquery formatting
- CTE (Common Table Expressions) formatting
- Human-readable SQL for debugging

### 07-window-functions.php
Advanced analytics with window functions (MySQL 8.0+, PostgreSQL 9.4+, SQLite 3.25+).

**Topics covered:**
- **ROW_NUMBER()** - Sequential numbering within partitions
- **RANK()** - Ranking with gaps for ties
- **DENSE_RANK()** - Ranking without gaps
- **LAG()** - Access previous row data
- **LEAD()** - Access next row data
- **Running totals** - Cumulative sums
- **Moving averages** - Rolling statistics
- **FIRST_VALUE() / LAST_VALUE()** - First and last values in window
- **NTILE()** - Divide into buckets/quartiles
- **Multiple window functions** - Combining window functions

### 08-basic-cte.php
Basic CTE usage with Common Table Expressions.

**Topics covered:**
- Simple CTE - Temporary result sets with Closure
- CTE with QueryBuilder - Using query builder instances
- CTE with raw SQL - Direct SQL in CTEs
- Multiple CTEs - Combining multiple CTEs
- Column lists - Explicit column definitions
- CTE with JOIN - Joining CTEs with tables

### 09-recursive-cte.php
Recursive CTE usage for hierarchical data.

**Topics covered:**
- Category hierarchy - Tree traversal
- Employee chain - Management hierarchy
- Depth limits - Controlling recursion depth
- Subordinate counts - Aggregating hierarchical data

### 10-materialized-cte.php
Materialized CTEs for performance optimization.

**Topics covered:**
- Materialized CTEs - Cached CTE results
- Performance optimization - Faster repeated queries
- Dialect support - PostgreSQL native, MySQL/SQLite emulation

### 11-fulltext-search.php
Full-text search across all databases.

**Topics covered:**
- MySQL FULLTEXT - Native full-text search
- PostgreSQL tsvector - Text search vectors
- SQLite FTS5 - Full-text search extension
- Cross-database FTS - Unified API across all databases

### 12-set-operations.php
SQL set operations for combining query results.

**Topics covered:**
- **UNION** - Combine queries, remove duplicates
- **UNION ALL** - Combine queries, keep duplicates
- **INTERSECT** - Find common rows
- **EXCEPT** - Find rows in first query not in second
- **Multiple UNION** - Chaining set operations
- **UNION with aggregation** - Combining aggregated results
- **UNION with filters** - Complex filtering

## Running Examples

### SQLite (default)
```bash
php 01-connection-pooling.php
```

### MySQL
```bash
PDODB_DRIVER=mysql php 01-connection-pooling.php
```

### PostgreSQL
```bash
PDODB_DRIVER=pgsql php 01-connection-pooling.php
```

## Performance Considerations

- **Connection pooling** reduces overhead in multi-database applications
- **Bulk operations** are significantly faster than individual inserts
- **Upserts** reduce the need for SELECT-then-INSERT/UPDATE patterns
- **MERGE statements** provide atomic INSERT/UPDATE operations based on match conditions
- **Subqueries** can sometimes be replaced with JOINs for better performance

## Next Steps

Explore more advanced topics:
- [Data Management](../06-data-management/) - File loading, batch processing, export helpers
- [Performance](../07-performance/) - Caching, profiling, EXPLAIN analysis
- [Architecture](../08-architecture/) - Read/write splitting, sharding
- [Reliability](../12-reliability/) - Exception handling, connection retry

