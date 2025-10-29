# Common Table Expressions (CTEs) Examples

This directory contains examples demonstrating the use of Common Table Expressions (CTEs) in PDO Database Class.

## Files

### 01-basic-cte.php
Demonstrates basic CTE usage:
- Simple CTE with Closure
- CTE with QueryBuilder instance
- CTE with raw SQL
- Multiple CTEs
- CTE with explicit column list
- CTE with JOIN operations

### 02-recursive-cte.php
Demonstrates recursive CTE usage:
- Category hierarchy traversal
- Employee management chain
- Depth-limited recursion
- Counting subordinates in hierarchy

## Running Examples

### SQLite (default)
```bash
php 01-basic-cte.php
php 02-recursive-cte.php
```

### MySQL
```bash
PDODB_DRIVER=mysql \
PDODB_MYSQL_HOST=localhost \
PDODB_MYSQL_DATABASE=test \
PDODB_MYSQL_USERNAME=root \
PDODB_MYSQL_PASSWORD=secret \
php 01-basic-cte.php

PDODB_DRIVER=mysql php 02-recursive-cte.php
```

### PostgreSQL
```bash
PDODB_DRIVER=pgsql \
PDODB_PGSQL_HOST=localhost \
PDODB_PGSQL_DATABASE=test \
PDODB_PGSQL_USERNAME=postgres \
PDODB_PGSQL_PASSWORD=secret \
php 01-basic-cte.php

PDODB_DRIVER=pgsql php 02-recursive-cte.php
```

## Key Concepts

### Basic CTEs
- Define temporary result sets that can be referenced in the main query
- Improve query readability and organization
- Can be reused multiple times in the same query

### Recursive CTEs
- Process hierarchical or tree-structured data
- Consist of anchor (base) query and recursive query
- Useful for organizational charts, category trees, graphs

## Learn More

- [CTE Documentation](../../documentation/03-query-builder/cte.md)
- [CTE API Reference](../../documentation/03-query-builder/cte-api.md)

