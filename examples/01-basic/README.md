# Basic Examples

Essential operations to get started with PDOdb.

## Examples

### 01-connection.php
Demonstrates database connection setup for all five database dialects (MySQL, MariaDB, PostgreSQL, SQLite, MSSQL).

**Topics covered:**
- SQLite connection with `:memory:` database
- MySQL connection with host, credentials, and database name
- PostgreSQL connection configuration
- Connection validation and error handling

### 02-simple-crud.php
Basic Create, Read, Update, Delete (CRUD) operations.

**Topics covered:**
- Creating (inserting) records with `insert()`
- Reading records with `get()`, `getOne()`, `getValue()`
- Updating records with `update()`
- Deleting records with `delete()`
- Working with primary keys and affected rows

### 03-where-conditions.php
Various WHERE clause patterns and operators.

**Topics covered:**
- Simple equality conditions
- Comparison operators (`>`, `<`, `>=`, `<=`, `!=`)
- Multiple AND/OR conditions
- IN operator for multiple values
- BETWEEN operator for ranges
- LIKE pattern matching
- IS NULL / IS NOT NULL checks
- NOT operator
- Complex conditions with raw SQL

### 04-insert-update.php
Data manipulation patterns.

**Topics covered:**
- Single row inserts
- Bulk inserts with `insertMulti()`
- Conditional updates
- Updates with calculations
- Working with auto-increment IDs
- Affected row counts

### 05-ordering.php
Ordering query results with various syntaxes.

**Topics covered:**
- Single column ordering (ASC/DESC)
- Multiple column ordering (chained calls)
- Array syntax with explicit directions
- Array syntax with default direction
- Comma-separated string syntax
- Order by expressions (CASE WHEN)
- Mixed ordering methods
- Pagination with ordering

## Running Examples

### SQLite (default)
```bash
php 01-connection.php
```

### MySQL
```bash
PDODB_DRIVER=mysql php 01-connection.php
```

### PostgreSQL
```bash
PDODB_DRIVER=pgsql php 01-connection.php
```

### MSSQL
```bash
PDODB_DRIVER=sqlsrv php 01-connection.php
```

## Related Documentation

- [Query Builder Basics](../../documentation/02-core-concepts/03-query-builder-basics.md) - Fluent API overview
- [SELECT Operations](../../documentation/03-query-builder/01-select-operations.md) - SELECT, FROM, WHERE
- [Data Manipulation](../../documentation/03-query-builder/02-data-manipulation.md) - INSERT, UPDATE, DELETE
- [Filtering Conditions](../../documentation/03-query-builder/03-filtering-conditions.md) - WHERE clauses

## Next Steps

After mastering these basics, explore:
- [Intermediate Examples](../02-intermediate/) - JOINs, aggregations, transactions
- [Advanced Examples](../03-advanced/) - Connection pooling, bulk operations, subqueries, MERGE statements
- [JSON Operations](../04-json/) - Working with JSON data

