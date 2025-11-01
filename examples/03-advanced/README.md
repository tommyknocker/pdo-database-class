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
- [Batch Processing](../10-batch-processing/) - Generator-based processing for large datasets
- [Exception Handling](../09-exception-handling/) - Robust error handling patterns
- [Connection Retry](../08-connection-retry/) - Automatic retry logic

