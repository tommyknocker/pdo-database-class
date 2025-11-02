# PDOdb Documentation

Complete documentation for the PDOdb library - a lightweight, framework-agnostic PHP database library providing a unified API across MySQL, PostgreSQL, and SQLite.

## 📖 Table of Contents

### Getting Started
- [Installation](01-getting-started/installation.md) - Install via Composer
- [Configuration](01-getting-started/configuration.md) - Database configuration
- [First Connection](01-getting-started/first-connection.md) - Your first connection
- [Hello World](01-getting-started/hello-world.md) - Simple example

### Core Concepts
- [Connection Management](02-core-concepts/connection-management.md) - Single and pooled connections
- [Query Builder Basics](02-core-concepts/query-builder-basics.md) - Fluent API overview
- [Parameter Binding](02-core-concepts/parameter-binding.md) - Prepared statements and security
- [Dialect Support](02-core-concepts/dialect-support.md) - Database differences

### Query Builder
- [SELECT Operations](03-query-builder/select-operations.md) - SELECT, FROM, WHERE, JOIN
- [Data Manipulation](03-query-builder/data-manipulation.md) - INSERT, UPDATE, DELETE, REPLACE
- [Filtering Conditions](03-query-builder/filtering-conditions.md) - WHERE, HAVING, complex conditions
- [Joins](03-query-builder/joins.md) - JOIN types and usage
- [Aggregations](03-query-builder/aggregations.md) - GROUP BY, HAVING, aggregates
- [Ordering & Pagination](03-query-builder/ordering-pagination.md) - ORDER BY, LIMIT, OFFSET
- [Subqueries](03-query-builder/subqueries.md) - Subqueries and EXISTS
- [Common Table Expressions (CTEs)](03-query-builder/cte.md) - WITH clauses and recursive CTEs
- [Window Functions](03-query-builder/window-functions.md) - ROW_NUMBER, RANK, LAG, LEAD
- [Full-Text Search](03-query-builder/fulltext-search.md) - Cross-database FTS
- [Schema Introspection](03-query-builder/schema-introspection.md) - Query indexes, foreign keys
- [DDL Operations](03-query-builder/ddl-operations.md) - Create, alter, and manage database schema
- [Raw Queries](03-query-builder/raw-queries.md) - Raw SQL with binding

### JSON Operations
- [JSON Basics](04-json-operations/json-basics.md) - Creating and storing JSON
- [JSON Querying](04-json-operations/json-querying.md) - Querying JSON data
- [JSON Filtering](04-json-operations/json-filtering.md) - JSON path filtering
- [JSON Modification](04-json-operations/json-modification.md) - Updating JSON values
- [JSON Aggregations](04-json-operations/json-aggregations.md) - JSON functions and operations

### Advanced Features
- [Transactions](05-advanced-features/transactions.md) - Transaction management
- [Table Locking](05-advanced-features/table-locking.md) - Lock tables
- [Batch Processing](05-advanced-features/batch-processing.md) - batch(), each(), stream()
- [Query Compilation Cache](05-advanced-features/query-compilation-cache.md) - Cache compiled SQL strings
- [Bulk Operations](05-advanced-features/bulk-operations.md) - insertMulti, bulk updates
- [Upsert Operations](05-advanced-features/upsert-operations.md) - onDuplicate/INSERT...ON CONFLICT
- [File Loading](05-advanced-features/file-loading.md) - CSV/XML loaders
- [Connection Retry](05-advanced-features/connection-retry.md) - Retry mechanism
- [Query Analysis](05-advanced-features/query-analysis.md) - EXPLAIN, EXPLAIN ANALYZE
- [Query Caching](05-advanced-features/query-caching.md) - PSR-16 result caching
- [Pagination](05-advanced-features/pagination.md) - Full, simple, and cursor-based pagination
- [Read/Write Splitting](05-advanced-features/read-write-splitting.md) - Master-replica architecture
- [Database Migrations](05-advanced-features/migrations.md) - Version-controlled schema changes
- [ActiveRecord](05-advanced-features/active-record.md) - Lightweight ORM pattern for object-based database operations
- [ActiveRecord Relationships](05-advanced-features/active-record-relationships.md) - hasOne, hasMany, belongsTo relationships with lazy and eager loading

### Error Handling
- [Exception Hierarchy](06-error-handling/exception-hierarchy.md) - Exception types
- [Error Codes](06-error-handling/error-codes.md) - DbError constants
- [Retry Logic](06-error-handling/retry-logic.md) - Building retry logic
- [Logging](06-error-handling/logging.md) - Query and error logging
- [Monitoring](06-error-handling/monitoring.md) - Error tracking and alerting

### Helper Functions
- [Core Helpers](07-helper-functions/core-helpers.md) - raw(), escape(), config()
- [String Helpers](07-helper-functions/string-helpers.md) - concat(), upper(), lower()
- [Numeric Helpers](07-helper-functions/numeric-helpers.md) - inc(), dec(), abs()
- [Date Helpers](07-helper-functions/date-helpers.md) - now(), curDate()
- [NULL Helpers](07-helper-functions/null-helpers.md) - isNull(), ifNull()
- [Comparison Helpers](07-helper-functions/comparison-helpers.md) - like(), between(), in()
- [JSON Helpers](07-helper-functions/json-helpers.md) - jsonObject(), jsonArray()
- [Aggregate Helpers](07-helper-functions/aggregate-helpers.md) - count(), sum(), avg()
- [Window Helpers](07-helper-functions/window-helpers.md) - rowNumber(), rank(), lag(), lead()
- [Export Helpers](07-helper-functions/export-helpers.md) - toJson(), toCsv(), toXml()

### Best Practices
- [Security](08-best-practices/security.md) - SQL injection prevention
- [Performance](08-best-practices/performance.md) - Optimizing queries
- [Memory Management](08-best-practices/memory-management.md) - Handling large datasets
- [Code Organization](08-best-practices/code-organization.md) - Structuring your code
- [Common Pitfalls](08-best-practices/common-pitfalls.md) - Mistakes to avoid

### Reference
- [API Reference](09-reference/api-reference.md) - Complete API documentation
- [Query Builder Methods](09-reference/query-builder-methods.md) - All QueryBuilder methods
- [PdoDb Methods](09-reference/pdo-db-methods.md) - All PdoDb methods
- [Helper Functions Reference](09-reference/helper-functions-reference.md) - All helper functions
- [Dialect Differences](09-reference/dialect-differences.md) - Database-specific differences

### Cookbook
- [Common Patterns](10-cookbook/common-patterns.md) - Reusable code patterns
- [Real-World Examples](10-cookbook/real-world-examples.md) - Complete app examples
- [Migration Guide](10-cookbook/migration-guide.md) - Migrating from other libraries
- [Troubleshooting](10-cookbook/troubleshooting.md) - Common issues and solutions

## 🚀 Quick Start

```bash
composer require tommyknocker/pdo-database-class
```

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb'
]);

$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->andWhere(Db::jsonContains('tags', 'php'))
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();
```

## 📚 Key Features

- **Fluent Query Builder** - Intuitive chainable API
- **Cross-Database Support** - Works with MySQL, MariaDB, PostgreSQL, SQLite
- **Query Caching** - PSR-16 integration for 10-1000x faster queries
- **Read/Write Splitting** - Horizontal scaling with master-replica architecture
- **Window Functions** - Advanced analytics with ROW_NUMBER, RANK, LAG, LEAD
- **Common Table Expressions (CTEs)** - WITH clauses for complex queries, recursive CTEs
- **Full-Text Search** - Cross-database FTS with unified API
- **Schema Introspection** - Query indexes, foreign keys, and constraints
- **DDL Query Builder** - Fluent API for schema operations without raw SQL
- **Database Migrations** - Version-controlled schema changes with rollback support
- **JSON Operations** - Unified JSON API across all databases
- **Advanced Pagination** - Full, simple, and cursor-based pagination
- **Prepared Statements** - Automatic parameter binding for security
- **Transactions** - Full transaction support
- **Batch Processing** - Handle large datasets efficiently
- **Connection Pooling** - Manage multiple connections
- **ActiveRecord Pattern** - Optional lightweight ORM for object-based database operations
- **Comprehensive Error Handling** - Detailed exception hierarchy
- **Zero Dependencies** - Lightweight and fast
- **Well Tested** - Comprehensive test coverage
- **Type Safe** - PHPStan level 8 compliant

## 💡 Navigation Tips

- **New to PDOdb?** Start with [Installation](01-getting-started/installation.md)
- **Building queries?** See [SELECT Operations](03-query-builder/select-operations.md)
- **Working with JSON?** See [JSON Basics](04-json-operations/json-basics.md)
- **Performance issues?** See [Performance](08-best-practices/performance.md)
- **Need a quick reference?** See [API Reference](09-reference/api-reference.md)
- **Troubleshooting?** See [Troubleshooting](10-cookbook/troubleshooting.md)

## 📖 Version

This documentation corresponds to PDOdb version **2.x**.

## 🤝 Contributing

Found an issue or have a suggestion? Please [open an issue](https://github.com/tommyknocker/pdo-database-class/issues) on GitHub.

## 📄 License

This library is open source. See the [LICENSE](../LICENSE) file for details.
