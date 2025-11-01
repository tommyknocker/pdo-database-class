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

**Note:** This example covers manual pagination. For advanced pagination features (full pagination, simple pagination, cursor pagination), see [13-pagination/](../13-pagination/).

### 04-transactions.php
Transaction management for data consistency.

**Topics covered:**
- Starting transactions with `begin()`
- Committing transactions with `commit()`
- Rolling back with `rollback()`
- Nested operations in transactions
- Error handling in transactions
- Savepoints (if supported by dialect)
- Transaction isolation levels

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

## Next Steps

After mastering these intermediate concepts, explore:
- [Advanced Examples](../03-advanced/) - Connection pooling, upserts, subqueries, MERGE statements
- [Helper Functions](../05-helpers/) - SQL helper functions
- [Real-World Examples](../06-real-world/) - Complete application patterns

