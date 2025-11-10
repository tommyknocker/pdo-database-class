# Learning Path

A structured guide to learning PDOdb from beginner to advanced.

## Overview

This learning path is designed to take you from complete beginner to advanced PDOdb user. Each section builds on the previous one, so follow them in order.

**Estimated Time:** 
- Beginner: 1-2 hours
- Intermediate: 3-5 hours  
- Advanced: 5-10 hours

## Beginner Path

Perfect for those new to PDOdb or database libraries in general.

### Step 1: Installation (15 minutes)

**Goal:** Get PDOdb installed and verify it works.

**Tasks:**
- Install via Composer
- Verify PDO extensions are installed
- Create a simple test connection

**Resources:**
- [Installation Guide](installation.md)
- [First Connection](first-connection.md)

**Checkpoint:** Can you connect to SQLite and run a simple query?

### Step 2: First Connection (20 minutes)

**Goal:** Understand how to connect to different databases.

**Tasks:**
- Connect to SQLite (easiest - no setup)
- Understand connection configuration
- Test connection with `ping()`

**Resources:**
- [First Connection](first-connection.md)
- [Configuration](configuration.md)

**Checkpoint:** Can you connect to at least one database type?

### Step 3: Hello World (30 minutes)

**Goal:** Write your first complete application.

**Tasks:**
- Create tables
- Insert data
- Query data
- Update and delete records
- Understand basic CRUD operations

**Resources:**
- [Hello World](hello-world.md)
- [Quick Reference](quick-reference.md) - for quick lookups

**Checkpoint:** Can you create a table, insert data, and query it?

### Step 4: Query Builder Basics (30 minutes)

**Goal:** Understand the fluent API and method chaining.

**Tasks:**
- Learn `find()`, `from()`, `where()`, `get()`
- Understand method chaining
- Practice building simple queries

**Resources:**
- [Query Builder Basics](../02-core-concepts/query-builder-basics.md)
- [SELECT Operations](../03-query-builder/select-operations.md)

**Checkpoint:** Can you build queries using method chaining?

### Step 5: Basic CRUD Operations (30 minutes)

**Goal:** Master Create, Read, Update, Delete operations.

**Tasks:**
- Insert single and multiple rows
- Select with conditions
- Update records
- Delete records
- Understand affected rows

**Resources:**
- [Data Manipulation](../03-query-builder/data-manipulation.md)
- [Filtering Conditions](../03-query-builder/filtering-conditions.md)

**Checkpoint:** Can you perform all CRUD operations confidently?

**Beginner Path Complete!** 🎉

You now know the basics. Move on to intermediate topics when you're comfortable with CRUD operations.

---

## Intermediate Path

For those comfortable with basics and ready to learn advanced query building.

### Step 1: JOINs (45 minutes)

**Goal:** Understand how to join multiple tables.

**Tasks:**
- Learn INNER JOIN, LEFT JOIN, RIGHT JOIN
- Understand table aliases
- Practice joining 2-3 tables
- Handle NULL values in joins

**Resources:**
- [Joins](../03-query-builder/joins.md)
- [Query Builder Basics](../02-core-concepts/query-builder-basics.md)

**Checkpoint:** Can you join multiple tables and get correct results?

### Step 2: Aggregations (45 minutes)

**Goal:** Use GROUP BY, HAVING, and aggregate functions.

**Tasks:**
- COUNT, SUM, AVG, MIN, MAX
- GROUP BY with single and multiple columns
- HAVING vs WHERE
- Filtering aggregated results

**Resources:**
- [Aggregations](../03-query-builder/aggregations.md)
- [Aggregate Helpers](../07-helper-functions/aggregate-helpers.md)

**Checkpoint:** Can you write queries with aggregations and grouping?

### Step 3: Transactions (30 minutes)

**Goal:** Understand transaction management and data integrity.

**Tasks:**
- Start, commit, rollback transactions
- Use transaction callbacks
- Handle errors in transactions
- Understand when to use transactions

**Resources:**
- [Transactions](../05-advanced-features/transactions.md)
- [Common Pitfalls](../08-best-practices/common-pitfalls.md)

**Checkpoint:** Can you wrap multiple operations in a transaction?

### Step 4: JSON Operations (45 minutes)

**Goal:** Work with JSON data across all databases.

**Tasks:**
- Store JSON data
- Query JSON fields
- Filter by JSON values
- Update JSON documents

**Resources:**
- [JSON Basics](../04-json-operations/json-basics.md)
- [JSON Querying](../04-json-operations/json-querying.md)
- [JSON Filtering](../04-json-operations/json-filtering.md)

**Checkpoint:** Can you store and query JSON data?

### Step 5: Helper Functions (30 minutes)

**Goal:** Use helper functions for common operations.

**Tasks:**
- String helpers (concat, upper, lower)
- Date helpers (now, curDate)
- NULL helpers (coalesce, ifNull)
- Comparison helpers (like, between, in)

**Resources:**
- [String Helpers](../07-helper-functions/string-helpers.md)
- [Date Helpers](../07-helper-functions/date-helpers.md)
- [NULL Helpers](../07-helper-functions/null-helpers.md)
- [Comparison Helpers](../07-helper-functions/comparison-helpers.md)

**Checkpoint:** Can you use helpers instead of raw SQL for common operations?

### Step 6: Error Handling (30 minutes)

**Goal:** Handle errors gracefully and debug issues.

**Tasks:**
- Understand exception hierarchy
- Catch specific exception types
- Use error diagnostics
- Log errors appropriately

**Resources:**
- [Exception Hierarchy](../06-error-handling/exception-hierarchy.md)
- [Error Diagnostics](../06-error-handling/error-diagnostics.md)
- [Logging](../06-error-handling/logging.md)

**Checkpoint:** Can you handle errors and debug database issues?

**Intermediate Path Complete!** 🎉

You're now comfortable with most common database operations. Ready for advanced topics?

---

## Advanced Path

For those ready to master advanced features and optimizations.

### Step 1: Window Functions (60 minutes)

**Goal:** Use window functions for advanced analytics.

**Tasks:**
- ROW_NUMBER, RANK, DENSE_RANK
- LAG, LEAD for time-series data
- Running totals and moving averages
- Partitioning windows

**Resources:**
- [Window Functions](../03-query-builder/window-functions.md)
- [Window Helpers](../07-helper-functions/window-helpers.md)

**Checkpoint:** Can you write queries with window functions?

### Step 2: Common Table Expressions (CTEs) (45 minutes)

**Goal:** Use CTEs for complex queries and recursive operations.

**Tasks:**
- Basic CTEs (WITH clauses)
- Recursive CTEs for hierarchical data
- Materialized CTEs for performance
- Multiple CTEs in one query

**Resources:**
- [CTEs](../03-query-builder/cte.md)

**Checkpoint:** Can you write recursive CTEs for tree structures?

### Step 3: Subqueries (30 minutes)

**Goal:** Use subqueries effectively.

**Tasks:**
- Scalar subqueries
- EXISTS and NOT EXISTS
- IN and NOT IN with subqueries
- Correlated subqueries

**Resources:**
- [Subqueries](../03-query-builder/subqueries.md)

**Checkpoint:** Can you use subqueries in SELECT, WHERE, and FROM clauses?

### Step 4: Performance Optimization (60 minutes)

**Goal:** Optimize queries for better performance.

**Tasks:**
- Use EXPLAIN and EXPLAIN ANALYZE
- Create appropriate indexes
- Understand query execution plans
- Use query caching
- Batch processing for large datasets

**Resources:**
- [Performance](../08-best-practices/performance.md)
- [Query Analysis](../05-advanced-features/query-analysis.md)
- [Query Caching](../05-advanced-features/query-caching.md)
- [Batch Processing](../05-advanced-features/batch-processing.md)

**Checkpoint:** Can you identify and fix slow queries?

### Step 5: Advanced Features (90 minutes)

**Goal:** Master advanced PDOdb features.

**Tasks:**
- Read/Write splitting
- Sharding across multiple databases
- Database migrations
- Query macros and scopes
- Plugin system

**Resources:**
- [Read/Write Splitting](../05-advanced-features/read-write-splitting.md)
- [Sharding](../05-advanced-features/sharding.md)
- [Migrations](../05-advanced-features/migrations.md)
- [Query Macros](../05-advanced-features/query-macros.md)
- [Query Scopes](../05-advanced-features/query-scopes.md)
- [Plugin System](../05-advanced-features/plugins.md)

**Checkpoint:** Can you set up read/write splitting or sharding?

### Step 6: ActiveRecord Pattern (60 minutes)

**Goal:** Use the optional ORM for object-based operations.

**Tasks:**
- Define models
- Use relationships (hasOne, hasMany, belongsTo)
- Eager and lazy loading
- Model validation
- Query scopes

**Resources:**
- [ActiveRecord](../05-advanced-features/active-record.md)
- [ActiveRecord Relationships](../05-advanced-features/active-record-relationships.md)
- [Query Scopes](../05-advanced-features/query-scopes.md)

**Checkpoint:** Can you build a complete model with relationships?

**Advanced Path Complete!** 🎉

You're now a PDOdb expert! Continue exploring advanced topics and contributing to the community.

---

## Quick Reference

### Need to do something quickly?

- [Quick Reference](quick-reference.md) - Common tasks with code snippets
- [API Reference](../09-reference/api-reference.md) - Complete API documentation
- [Troubleshooting](../10-cookbook/troubleshooting.md) - Common issues and solutions

### Not sure where to start?

1. **Complete beginner?** → Start with [Installation](installation.md)
2. **Know SQL but new to PDOdb?** → Start with [Hello World](hello-world.md)
3. **Want to learn a specific feature?** → Use the search or table of contents
4. **Having issues?** → Check [Troubleshooting](../10-cookbook/troubleshooting.md)

### Learning Tips

- **Practice:** Type out examples yourself, don't just read them
- **Experiment:** Modify examples to see what happens
- **Build:** Create a small project to practice
- **Read:** Check the [Best Practices](../08-best-practices/) section regularly
- **Ask:** Use GitHub Issues for questions

## Next Steps

After completing your path:

- Explore [Real-World Examples](../10-cookbook/real-world-examples.md)
- Review [Common Patterns](../10-cookbook/common-patterns.md)
- Read [Best Practices](../08-best-practices/)
- Contribute to the project!

