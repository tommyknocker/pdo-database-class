# PDOdb Examples

Complete collection of examples demonstrating PDOdb features.

## 🚀 Quick Start

1. Copy `config.example.php` to `config.php`
2. Update database credentials
3. Run any example: `php 01-basic/01-connection.php`

## 📚 Categories

### 01. Basic Examples
Essential operations to get started.

- **[01-connection.php](01-basic/01-connection.php)** - Database connection setup
- **[02-simple-crud.php](01-basic/02-simple-crud.php)** - Create, Read, Update, Delete
- **[03-where-conditions.php](01-basic/03-where-conditions.php)** - WHERE clauses and conditions
- **[04-insert-update.php](01-basic/04-insert-update.php)** - Data manipulation

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

## 💡 Tips

- All examples are self-contained and runnable
- Each file includes inline comments explaining the code
- Database connections are cleaned up automatically
- Use SQLite (`:memory:`) for quick testing without setup

## 🐛 Troubleshooting

If you encounter issues:
1. Check database credentials in `config.php`
2. Ensure PDO extensions are installed (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`)
3. Verify database server is running
4. See main [README.md](../README.md#troubleshooting) for more help

