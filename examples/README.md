# PDOdb Examples

Complete collection of **26 runnable examples** demonstrating PDOdb features across **all 3 database dialects** (MySQL, PostgreSQL, SQLite).

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

### Option 3: PostgreSQL
```bash
# Edit config.pgsql.php with your credentials
# File already exists in examples/ directory
nano config.pgsql.php

# Run with PostgreSQL
PDODB_DRIVER=pgsql php 01-basic/02-simple-crud.php
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
| `config.pgsql.php` | PostgreSQL | ✅ Included, update credentials |

**Environment variable `PDODB_DRIVER`** controls which config to use:
- `sqlite` (default) - loads `config.sqlite.php`
- `mysql` - loads `config.mysql.php`
- `pgsql` - loads `config.pgsql.php`

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
- **[04-transactions.php](02-intermediate/04-transactions.php)** - Transaction management
- **[05-raw-queries.php](02-intermediate/05-raw-queries.php)** - Raw SQL with parameter binding

### 03. Advanced Examples
Complex operations and patterns.

- **[01-connection-pooling.php](03-advanced/01-connection-pooling.php)** - Multiple database connections
- **[02-bulk-operations.php](03-advanced/02-bulk-operations.php)** - Bulk inserts, CSV/XML loading
- **[03-upsert.php](03-advanced/03-upsert.php)** - INSERT or UPDATE operations
- **[04-subqueries.php](03-advanced/04-subqueries.php)** - Subqueries in SELECT, WHERE, FROM

### 04. JSON Operations
Working with JSON data across all databases.

- **[01-json-basics.php](04-json/01-json-basics.php)** - Creating and storing JSON
- **[02-json-queries.php](04-json/02-json-queries.php)** - Querying JSON data
- **[03-json-modifications.php](04-json/03-json-modifications.php)** - Modifying JSON values
- **[04-json-aggregations.php](04-json/04-json-aggregations.php)** - JSON length, type, keys

### 05. Helper Functions
SQL helper functions for common operations.

- **[01-string-helpers.php](05-helpers/01-string-helpers.php)** - String manipulation
- **[02-math-helpers.php](05-helpers/02-math-helpers.php)** - Mathematical operations
- **[03-date-helpers.php](05-helpers/03-date-helpers.php)** - Date and time functions
- **[04-null-helpers.php](05-helpers/04-null-helpers.php)** - NULL handling

### 06. Real-World Examples
Complete applications and patterns.

- **[01-blog-system.php](06-real-world/01-blog-system.php)** - Full blog with posts, comments, tags
- **[02-user-auth.php](06-real-world/02-user-auth.php)** - User authentication system
- **[03-search-filters.php](06-real-world/03-search-filters.php)** - Advanced search with filters
- **[04-multi-tenant.php](06-real-world/04-multi-tenant.php)** - Multi-tenant architecture

### 15. Read/Write Splitting
Horizontal database scaling with master-replica architecture.

- **[01-basic-setup.php](15-read-write-splitting/01-basic-setup.php)** - Setting up read/write splitting
- **[02-sticky-writes.php](15-read-write-splitting/02-sticky-writes.php)** - Read-after-write consistency
- **[03-load-balancers.php](15-read-write-splitting/03-load-balancers.php)** - Load balancing strategies

### 16. Window Functions
Advanced analytics with window functions (MySQL 8.0+, PostgreSQL 9.4+, SQLite 3.25+).

- **[01-window-functions.php](16-window-functions/01-window-functions.php)** - Complete window functions demo:
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

### 17. Common Table Expressions (CTEs)
WITH clauses for complex queries and hierarchical data (MySQL 8.0+, PostgreSQL 8.4+, SQLite 3.8.3+).

- **[01-basic-cte.php](17-cte/01-basic-cte.php)** - Basic CTE usage:
  - **Simple CTE** - Temporary result sets with Closure
  - **CTE with QueryBuilder** - Using query builder instances
  - **CTE with raw SQL** - Direct SQL in CTEs
  - **Multiple CTEs** - Combining multiple CTEs
  - **Column lists** - Explicit column definitions
  - **CTE with JOIN** - Joining CTEs with tables
- **[02-recursive-cte.php](17-cte/02-recursive-cte.php)** - Recursive CTE usage:
  - **Category hierarchy** - Tree traversal
  - **Employee chain** - Management hierarchy
  - **Depth limits** - Controlling recursion depth
  - **Subordinate counts** - Aggregating hierarchical data

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

