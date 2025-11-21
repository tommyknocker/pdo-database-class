# PDOdb Documentation

Complete documentation for the PDOdb library - a lightweight, framework-agnostic PHP database library providing a unified API across MySQL, MariaDB, PostgreSQL, SQLite, and Microsoft SQL Server (MSSQL).

## 📖 Table of Contents

### Getting Started
- [01. Installation](01-getting-started/01-installation.md) - Install via Composer
- [02. Choosing Your Database](01-getting-started/02-choosing-database.md) - Which database to use?
- [03. Learning Path](01-getting-started/03-learning-path.md) - Structured learning guide
- [04. Configuration](01-getting-started/04-configuration.md) - Database configuration
- [05. First Connection](01-getting-started/05-first-connection.md) - Your first connection
- [06. Hello World](01-getting-started/06-hello-world.md) - Simple example
- [07. Quick Reference](01-getting-started/07-quick-reference.md) - Common tasks with code snippets

### Core Concepts
- [01. Architecture Overview](02-core-concepts/01-architecture-overview.md) - How PDOdb works internally
- [02. Connection Management](02-core-concepts/02-connection-management.md) - Single and pooled connections
- [03. Query Builder Basics](02-core-concepts/03-query-builder-basics.md) - Fluent API overview
- [04. Parameter Binding](02-core-concepts/04-parameter-binding.md) - Prepared statements and security
- [05. Dialect Support](02-core-concepts/05-dialect-support.md) - Database differences

### Query Builder
- [01. SELECT Operations](03-query-builder/01-select-operations.md) - SELECT, FROM, WHERE, JOIN
- [02. Data Manipulation](03-query-builder/02-data-manipulation.md) - INSERT, UPDATE, DELETE, REPLACE
- [03. Filtering Conditions](03-query-builder/03-filtering-conditions.md) - WHERE, HAVING, complex conditions
- [04. Joins](03-query-builder/04-joins.md) - JOIN types and usage
- [05. Aggregations](03-query-builder/05-aggregations.md) - GROUP BY, HAVING, aggregates
- [06. Ordering & Pagination](03-query-builder/06-ordering-pagination.md) - ORDER BY, LIMIT, OFFSET
- [07. Subqueries](03-query-builder/07-subqueries.md) - Subqueries and EXISTS
- [08. Common Table Expressions (CTEs)](03-query-builder/08-cte.md) - WITH clauses and recursive CTEs
- [09. Window Functions](03-query-builder/09-window-functions.md) - ROW_NUMBER, RANK, LAG, LEAD
- [10. Full-Text Search](03-query-builder/10-fulltext-search.md) - Cross-database FTS
- [11. Schema Introspection](03-query-builder/11-schema-introspection.md) - Query indexes, foreign keys
- [12. DDL Operations](03-query-builder/12-ddl-operations.md) - Create, alter, and manage database schema
- [13. DISTINCT](03-query-builder/13-distinct.md) - Remove duplicates
- [14. FILTER Clause](03-query-builder/14-filter-clause.md) - Conditional aggregates
- [15. Set Operations](03-query-builder/15-set-operations.md) - UNION, INTERSECT, EXCEPT
- [16. Raw Queries](03-query-builder/16-raw-queries.md) - Raw SQL with binding

### JSON Operations
- [01. JSON Basics](04-json-operations/01-json-basics.md) - Creating and storing JSON
- [02. JSON Querying](04-json-operations/02-json-querying.md) - Querying JSON data
- [03. JSON Filtering](04-json-operations/03-json-filtering.md) - JSON path filtering
- [04. JSON Modification](04-json-operations/04-json-modification.md) - Updating JSON values
- [05. JSON Aggregations](04-json-operations/05-json-aggregations.md) - JSON functions and operations

### Advanced Features
- [01. Transactions](05-advanced-features/01-transactions.md) - Transaction management
- [02. Table Locking](05-advanced-features/02-table-locking.md) - Lock tables
- [03. Batch Processing](05-advanced-features/03-batch-processing.md) - batch(), each(), stream()
- [04. Bulk Operations](05-advanced-features/04-bulk-operations.md) - insertMulti, bulk updates
- [05. Upsert Operations](05-advanced-features/05-upsert-operations.md) - onDuplicate/INSERT...ON CONFLICT
- [06. File Loading](05-advanced-features/06-file-loading.md) - CSV/XML loaders
- [07. Connection Retry](05-advanced-features/07-connection-retry.md) - Retry mechanism
- [08. Query Analysis](05-advanced-features/08-query-analysis.md) - EXPLAIN, EXPLAIN ANALYZE
- [09. Query Caching](05-advanced-features/09-query-caching.md) - PSR-16 result caching
- [10. Query Compilation Cache](05-advanced-features/10-query-compilation-cache.md) - Cache compiled SQL strings
- [11. Query Profiling](05-advanced-features/11-query-profiling.md) - Query performance profiling
- [12. Pagination](05-advanced-features/12-pagination.md) - Full, simple, and cursor-based pagination
- [13. Read/Write Splitting](05-advanced-features/13-read-write-splitting.md) - Master-replica architecture
- [14. Sharding](05-advanced-features/14-sharding.md) - Horizontal partitioning across multiple databases
- [15. Database Migrations](05-advanced-features/15-migrations.md) - Version-controlled schema changes
- [16. ActiveRecord](05-advanced-features/16-active-record.md) - Lightweight ORM pattern for object-based database operations
- [17. ActiveRecord Relationships](05-advanced-features/17-active-record-relationships.md) - hasOne, hasMany, belongsTo relationships with lazy and eager loading
- [18. Query Builder Macros](05-advanced-features/18-query-macros.md) - Custom query methods for extending QueryBuilder
- [19. Query Scopes](05-advanced-features/19-query-scopes.md) - Global and local scopes for reusable query logic
- [20. Plugin System](05-advanced-features/20-plugins.md) - Extend PdoDb with custom plugins for macros, scopes, and event listeners
- [21. CLI Tools](05-advanced-features/21-cli-tools.md) - Database management, migration generator, model generator, schema inspector, and interactive query tester (REPL)
- [20. Dialect-Specific Schema](05-advanced-features/20-dialect-specific-schema.md) - **NEW!** Database-specific types (MySQL ENUM/SET, PostgreSQL UUID/JSONB/arrays, MSSQL UNIQUEIDENTIFIER, SQLite type affinity)

### Error Handling
- [01. Exception Hierarchy](06-error-handling/01-exception-hierarchy.md) - Exception types
- [02. Error Codes](06-error-handling/02-error-codes.md) - DbError constants
- [03. Error Diagnostics](06-error-handling/03-error-diagnostics.md) - Query context and debug information
- [04. Retry Logic](06-error-handling/04-retry-logic.md) - Building retry logic
- [05. Logging](06-error-handling/05-logging.md) - Query and error logging
- [06. Monitoring](06-error-handling/06-monitoring.md) - Error tracking and alerting

### Helper Functions
- [01. Core Helpers](07-helper-functions/01-core-helpers.md) - raw(), escape(), config()
- [02. String Helpers](07-helper-functions/02-string-helpers.md) - concat(), upper(), lower()
- [03. Numeric Helpers](07-helper-functions/03-numeric-helpers.md) - inc(), dec(), abs()
- [04. Date Helpers](07-helper-functions/04-date-helpers.md) - now(), curDate()
- [05. NULL Helpers](07-helper-functions/05-null-helpers.md) - isNull(), ifNull()
- [06. Comparison Helpers](07-helper-functions/06-comparison-helpers.md) - like(), between(), in()
- [07. JSON Helpers](07-helper-functions/07-json-helpers.md) - jsonObject(), jsonArray()
- [08. Aggregate Helpers](07-helper-functions/08-aggregate-helpers.md) - count(), sum(), avg()
- [09. Window Helpers](07-helper-functions/09-window-helpers.md) - rowNumber(), rank(), lag(), lead()
- [10. Export Helpers](07-helper-functions/10-export-helpers.md) - toJson(), toCsv(), toXml()

### Best Practices
- [01. Security](08-best-practices/01-security.md) - SQL injection prevention
- [02. Performance](08-best-practices/02-performance.md) - Optimizing queries
- [03. Memory Management](08-best-practices/03-memory-management.md) - Handling large datasets
- [04. Code Organization](08-best-practices/04-code-organization.md) - Structuring your code
- [05. Common Pitfalls](08-best-practices/05-common-pitfalls.md) - Mistakes to avoid

### Reference
- [01. API Reference](09-reference/01-api-reference.md) - Complete API documentation
- [02. Query Builder Methods](09-reference/02-query-builder-methods.md) - All QueryBuilder methods
- [03. PdoDb Methods](09-reference/03-pdo-db-methods.md) - All PdoDb methods
- [04. Helper Functions Reference](09-reference/04-helper-functions-reference.md) - All helper functions
- [05. Dialect Differences](09-reference/05-dialect-differences.md) - Database-specific differences

### Cookbook
- [01. Common Patterns](10-cookbook/01-common-patterns.md) - Reusable code patterns
- [02. Real-World Examples](10-cookbook/02-real-world-examples.md) - Complete app examples
- [03. Migration Guide](10-cookbook/03-migration-guide.md) - Migrating from other libraries
- [04. Troubleshooting](10-cookbook/04-troubleshooting.md) - Common issues and solutions

## 🚀 Quick Start

```bash
composer require tommyknocker/pdo-database-class
```

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

// Works with MySQL, MariaDB, PostgreSQL, SQLite, and MSSQL
$db = new PdoDb('sqlite', [
    'path' => ':memory:'  // SQLite - no setup required!
]);

// Or use MySQL/MariaDB/PostgreSQL/MSSQL
// $db = new PdoDb('mysql', ['host' => 'localhost', 'username' => 'user', 'password' => 'pass', 'dbname' => 'mydb']);

$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();
```

## 📚 Key Features

- **Fluent Query Builder** - Intuitive chainable API
- **Cross-Database Support** - Works with MySQL, MariaDB, PostgreSQL, SQLite, and Microsoft SQL Server (MSSQL)
- **Query Caching** - PSR-16 integration for 10-1000x faster queries
- **Read/Write Splitting** - Horizontal scaling with master-replica architecture
- **Sharding** - Horizontal partitioning across multiple databases with automatic query routing
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

- **New to PDOdb?** Start with [Installation](01-getting-started/01-installation.md) → [First Connection](01-getting-started/05-first-connection.md) → [Hello World](01-getting-started/06-hello-world.md)
- **Not sure which database to use?** See [Choosing Your Database](01-getting-started/02-choosing-database.md)
- **Want a structured learning path?** See [Learning Path](01-getting-started/03-learning-path.md)
- **Need quick examples?** See [Quick Reference](01-getting-started/07-quick-reference.md)
- **Building queries?** See [SELECT Operations](03-query-builder/01-select-operations.md)
- **Working with JSON?** See [JSON Basics](04-json-operations/01-json-basics.md)
- **Performance issues?** See [Performance](08-best-practices/02-performance.md)
- **Need a quick reference?** See [API Reference](09-reference/01-api-reference.md)
- **Troubleshooting?** See [Troubleshooting](10-cookbook/04-troubleshooting.md)

## 📖 Version

This documentation corresponds to PDOdb version **2.x**.

## 🤝 Contributing

Found an issue or have a suggestion? Please [open an issue](https://github.com/tommyknocker/pdo-database-class/issues) on GitHub.

## 📄 License

This library is open source. See the [LICENSE](../LICENSE) file for details.
