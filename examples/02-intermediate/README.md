# Intermediate Examples

Common patterns and operations for everyday database tasks.

## Examples

### 01-joins.php
JOIN operations across different join types.

**Topics covered:**
- INNER JOIN - matching records only
- LEFT JOIN - all records from left table
- RIGHT JOIN - all records from right table
- Multiple joins in a single query
- Join conditions with complex expressions
- Selecting columns from joined tables
- Alias usage in joins
- LATERAL JOIN - correlated subqueries (PostgreSQL/MySQL only)

### 02-aggregations.php
GROUP BY, HAVING, and aggregate functions.

**Topics covered:**
- COUNT, SUM, AVG, MIN, MAX functions
- GROUP BY for grouping results
- HAVING for filtering grouped results
- Multiple aggregate functions in one query
- Aggregations with JOINs
- Conditional aggregations
- DISTINCT counts

### 03-pagination.php
LIMIT and OFFSET for pagination.

**Topics covered:**
- Basic LIMIT for result limiting
- OFFSET for skipping records
- Calculating pagination parameters
- Page-based navigation
- Total count queries
- Efficient pagination patterns

**Note:** This example covers manual pagination. For advanced pagination features (full pagination, simple pagination, cursor pagination), see [03-pagination-advanced.php](03-pagination-advanced.php).

### 04-transactions.php
Transaction management for data consistency.

**Topics covered:**
- Starting transactions with `startTransaction()`
- Committing transactions with `commit()`
- Rolling back with `rollback()`
- Nested operations in transactions
- Error handling in transactions
- Transaction isolation levels

### 05-savepoints.php
Savepoints and nested transactions.

**Topics covered:**
- Creating savepoints within transactions
- Rolling back to specific savepoints
- Releasing savepoints without rolling back
- Nested savepoint management
- Error handling with savepoints
- Savepoint stack tracking

### 06-insert-select.php
INSERT ... SELECT operations for copying data between tables.

**Topics covered:**
- INSERT ... SELECT with QueryBuilder
- INSERT ... SELECT with subqueries
- INSERT ... SELECT with CTEs
- Column mapping
- ON DUPLICATE KEY UPDATE (MySQL/MariaDB)
- ON CONFLICT (PostgreSQL)

### 07-update-delete-join.php
UPDATE and DELETE operations with JOIN clauses.

**Topics covered:**
- UPDATE with JOIN (MySQL/MariaDB/PostgreSQL)
- DELETE with JOIN (MySQL/MariaDB/PostgreSQL)
- UPDATE with LEFT JOIN
- Multiple JOINs in UPDATE/DELETE
- Dialect-specific syntax handling

## Running Examples

### SQLite (default)
```bash
php 01-joins.php
```

### MySQL
```bash
PDODB_DRIVER=mysql php 01-joins.php
```

### PostgreSQL
```bash
PDODB_DRIVER=pgsql php 01-joins.php
```

## Related Documentation

- [Joins](../../documentation/03-query-builder/04-joins.md) - JOIN operations
- [Aggregations](../../documentation/03-query-builder/05-aggregations.md) - GROUP BY, HAVING
- [Ordering & Pagination](../../documentation/03-query-builder/06-ordering-pagination.md) - ORDER BY, LIMIT, OFFSET
- [Transactions](../../documentation/05-advanced-features/01-transactions.md) - Transaction management
- [Pagination](../../documentation/05-advanced-features/12-pagination.md) - Advanced pagination

## Next Steps

After mastering these intermediate concepts, explore:
- [Advanced Examples](../03-advanced/) - Connection pooling, upserts, subqueries, MERGE statements, window functions, CTEs
- [Helper Functions](../05-helpers/) - SQL helper functions
- [Real-World Examples](../06-real-world/) - Complete application patterns

