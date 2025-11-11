# PDOdb Examples

Complete collection of **53 runnable examples** demonstrating PDOdb features across **all 5 database dialects** (MySQL, MariaDB, PostgreSQL, SQLite, MSSQL).

## 🚀 Quick Start

### Option 1: SQLite (No setup required)
```bash
# SQLite is pre-configured and ready to use
php 01-basic/02-simple-crud.php
```

### Option 2: MySQL
```bash
# Edit config.mysql.php with your credentials
# File already exists in examples/ directory
nano config.mysql.php

# Run with MySQL
PDODB_DRIVER=mysql php 01-basic/02-simple-crud.php
```

### Option 3: MariaDB
```bash
# Edit config.mariadb.php with your credentials
# File already exists in examples/ directory
nano config.mariadb.php

# Run with MariaDB
PDODB_DRIVER=mariadb php 01-basic/02-simple-crud.php
```

### Option 4: PostgreSQL
```bash
# Edit config.pgsql.php with your credentials
# File already exists in examples/ directory
nano config.pgsql.php

# Run with PostgreSQL
PDODB_DRIVER=pgsql php 01-basic/02-simple-crud.php
```

### Option 5: Microsoft SQL Server (MSSQL)
```bash
# Edit config.mssql.php with your credentials
# File already exists in examples/ directory
nano config.mssql.php

# Run with MSSQL
PDODB_DRIVER=mssql php 01-basic/02-simple-crud.php
```

### Test All Examples on All Databases
```bash
./scripts/test-examples.sh
```
This script automatically:
- Detects which databases are configured
- Runs all examples on each available database
- Reports pass/fail status for each

## 🔧 Configuration Files

PDOdb examples use **separate config files per database**:

| File | Database | Status |
|------|----------|--------|
| `config.sqlite.php` | SQLite | ✅ Included, ready to use (`:memory:`) |
| `config.mysql.php` | MySQL | ✅ Included, update credentials |
| `config.mariadb.php` | MariaDB | ✅ Included, update credentials |
| `config.pgsql.php` | PostgreSQL | ✅ Included, update credentials |
| `config.mssql.php` | Microsoft SQL Server | ✅ Included, update credentials |

**Environment variable `PDODB_DRIVER`** controls which config to use:
- `sqlite` (default) - loads `config.sqlite.php`
- `mysql` - loads `config.mysql.php`
- `mariadb` - loads `config.mariadb.php`
- `pgsql` - loads `config.pgsql.php`
- `mssql` - loads `config.mssql.php`

**No environment variable?** Defaults to SQLite with `:memory:` database.

## 📚 Categories

### 01. Basic Examples
Essential operations to get started.

- **[01-connection.php](01-basic/01-connection.php)** - Database connection setup
- **[02-simple-crud.php](01-basic/02-simple-crud.php)** - Create, Read, Update, Delete
- **[03-where-conditions.php](01-basic/03-where-conditions.php)** - WHERE clauses and conditions
- **[04-insert-update.php](01-basic/04-insert-update.php)** - Data manipulation
- **[05-ordering.php](01-basic/05-ordering.php)** - Ordering results (single, multiple, array, comma-separated)

### 02. Intermediate Examples
Common patterns and operations.

- **[01-joins.php](02-intermediate/01-joins.php)** - JOIN operations (INNER, LEFT, RIGHT)
- **[02-aggregations.php](02-intermediate/02-aggregations.php)** - GROUP BY, HAVING, COUNT, SUM, AVG
- **[03-pagination.php](02-intermediate/03-pagination.php)** - LIMIT and OFFSET for pagination
- **[03-pagination-advanced.php](02-intermediate/03-pagination-advanced.php)** - Advanced pagination (full, simple, cursor)
- **[04-transactions.php](02-intermediate/04-transactions.php)** - Transaction management
- **[05-savepoints.php](02-intermediate/05-savepoints.php)** - Savepoints and nested transactions
- **[06-insert-select.php](02-intermediate/06-insert-select.php)** - INSERT ... SELECT operations
- **[07-update-delete-join.php](02-intermediate/07-update-delete-join.php)** - UPDATE/DELETE with JOIN clauses

### 03. Advanced Examples
Complex operations and patterns.

- **[01-connection-pooling.php](03-advanced/01-connection-pooling.php)** - Multiple database connections
- **[02-bulk-operations.php](03-advanced/02-bulk-operations.php)** - Bulk inserts, CSV/XML loading
- **[03-upsert.php](03-advanced/03-upsert.php)** - INSERT or UPDATE operations
- **[04-subqueries.php](03-advanced/04-subqueries.php)** - Subqueries in SELECT, WHERE, FROM
- **[05-merge.php](03-advanced/05-merge.php)** - MERGE statement (INSERT/UPDATE/DELETE based on match conditions)
- **[06-sql-formatter.php](03-advanced/06-sql-formatter.php)** - SQL Formatter for pretty-printing queries
- **[07-window-functions.php](03-advanced/07-window-functions.php)** - Window functions (ROW_NUMBER, RANK, LAG, LEAD, running totals)
- **[08-basic-cte.php](03-advanced/08-basic-cte.php)** - Basic CTE usage
- **[09-recursive-cte.php](03-advanced/09-recursive-cte.php)** - Recursive CTEs for hierarchical data
- **[10-materialized-cte.php](03-advanced/10-materialized-cte.php)** - Materialized CTEs for performance
- **[11-fulltext-search.php](03-advanced/11-fulltext-search.php)** - Full-text search across all databases
- **[12-set-operations.php](03-advanced/12-set-operations.php)** - UNION, INTERSECT, EXCEPT operations

### 04. JSON Operations
Working with JSON data across all databases.

- **[01-json-basics.php](04-json/01-json-basics.php)** - Creating and storing JSON
- **[02-json-queries.php](04-json/02-json-queries.php)** - Querying JSON data
- **[03-json-modification.php](04-json/03-json-modification.php)** - Modifying JSON data (jsonSet, jsonRemove, jsonReplace)

### 05. Helper Functions
SQL helper functions for common operations.

- **[01-string-helpers.php](05-helpers/01-string-helpers.php)** - String manipulation
- **[02-math-helpers.php](05-helpers/02-math-helpers.php)** - Mathematical operations
- **[03-date-helpers.php](05-helpers/03-date-helpers.php)** - Date and time functions
- **[04-null-helpers.php](05-helpers/04-null-helpers.php)** - NULL handling
- **[05-comparison-helpers.php](05-helpers/05-comparison-helpers.php)** - Comparison operations
- **[06-conditional-helpers.php](05-helpers/06-conditional-helpers.php)** - Conditional expressions
- **[07-boolean-helpers.php](05-helpers/07-boolean-helpers.php)** - Boolean operations
- **[08-type-helpers.php](05-helpers/08-type-helpers.php)** - Type conversion

### 06. Data Management
Data loading, batch processing, and export operations.

- **[01-file-loading.php](06-data-management/01-file-loading.php)** - Loading data from JSON files
- **[02-batch-processing.php](06-data-management/02-batch-processing.php)** - Memory-efficient batch processing
- **[03-export-helpers.php](06-data-management/03-export-helpers.php)** - Export to JSON, CSV, XML

### 07. Performance
Performance optimization: caching, profiling, and EXPLAIN analysis.

- **[01-caching.php](07-performance/01-caching.php)** - Query result caching
- **[02-query-compilation-cache.php](07-performance/02-query-compilation-cache.php)** - Compiled SQL caching
- **[03-query-profiling.php](07-performance/03-query-profiling.php)** - Query performance profiling
- **[04-explain-analysis.php](07-performance/04-explain-analysis.php)** - EXPLAIN query analysis

### 08. Architecture
Architectural patterns: read/write splitting and sharding.

- **[01-read-write-splitting.php](08-architecture/01-read-write-splitting.php)** - Master-replica architecture
- **[02-basic-sharding.php](08-architecture/02-basic-sharding.php)** - Basic sharding setup
- **[03-sharding-strategies.php](08-architecture/03-sharding-strategies.php)** - Sharding strategies (range, hash, modulo)
- **[04-sticky-writes.php](08-architecture/04-sticky-writes.php)** - Read-after-write consistency
- **[05-load-balancers.php](08-architecture/05-load-balancers.php)** - Load balancing strategies

### 09. ActiveRecord
Object-based database operations with relationships and scopes.

- **[01-basics.php](09-active-record/01-basics.php)** - ActiveRecord basics and CRUD
- **[02-relationships.php](09-active-record/02-relationships.php)** - Relationships (hasOne, hasMany, belongsTo, hasManyThrough)
- **[03-scopes.php](09-active-record/03-scopes.php)** - Global and local query scopes

### 10. Extensibility
Extending PdoDb: macros, plugins, and events.

- **[01-macros.php](10-extensibility/01-macros.php)** - Query Builder macros
- **[02-plugins.php](10-extensibility/02-plugins.php)** - Plugin system
- **[03-events.php](10-extensibility/03-events.php)** - Event dispatcher (PSR-14)

### 11. Schema Management
Database schema management: DDL and migrations.

- **[01-ddl.php](11-schema/01-ddl.php)** - DDL Query Builder basics
- **[02-advanced-ddl.php](11-schema/02-advanced-ddl.php)** - Advanced DDL features (Yii2-style constraints, partial indexes, fulltext/spatial indexes)
- **[02-migrations.php](11-schema/02-migrations.php)** - Database migrations

### 12. Reliability
Reliability features: exception handling and connection retry.

- **[01-exception-handling.php](12-reliability/01-exception-handling.php)** - Typed exceptions and error diagnostics
- **[02-connection-retry.php](12-reliability/02-connection-retry.php)** - Automatic retry with exponential backoff

### 13. Real-World Examples
Complete applications and patterns.

- **[01-blog-system.php](06-real-world/01-blog-system.php)** - Full blog with posts, comments, tags
- **[02-user-auth.php](06-real-world/02-user-auth.php)** - User authentication system
- **[03-search-filters.php](06-real-world/03-search-filters.php)** - Advanced search with filters
- **[04-multi-tenant.php](06-real-world/04-multi-tenant.php)** - Multi-tenant architecture

### 14. Miscellaneous
Various examples and demonstrations.

- **[01-readme-examples.php](14-misc/01-readme-examples.php)** - Examples from main README

## 💡 Tips

- All examples are self-contained and runnable
- Each file includes inline comments explaining the code
- Database connections are cleaned up automatically
- Use SQLite (`:memory:`) for quick testing without setup

## 🎯 How Examples Work

All examples use the **QueryBuilder fluent API** - no raw SQL except for table creation:

```php
// ✅ Good - uses QueryBuilder
$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->orderBy('name')
    ->get();

// ❌ Avoid - raw SQL (except for DDL)
$users = $db->rawQuery('SELECT * FROM users WHERE age > 18 ORDER BY name');
```

Examples automatically adapt to your database:
- **JSONB** for PostgreSQL, **TEXT** for MySQL/SQLite
- **SERIAL** for PostgreSQL, **AUTO_INCREMENT** for MySQL, **AUTOINCREMENT** for SQLite
- Dialect-specific date/time, string concat, and type conversions

## 🐛 Troubleshooting

If you encounter issues:
1. **Check database credentials** in `config.<driver>.php`
2. **Ensure PDO extensions** are installed:
   ```bash
   php -m | grep pdo_mysql
   php -m | grep pdo_pgsql
   php -m | grep pdo_sqlite
   ```
3. **Verify database server** is running
4. **Set PDODB_DRIVER** environment variable:
   ```bash
   PDODB_DRIVER=mysql php examples/01-basic/02-simple-crud.php
   ```
5. See main [README.md](../README.md#troubleshooting) for more help

