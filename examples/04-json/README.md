# JSON Operations Examples

Working with JSON data across all six database dialects (MySQL, MariaDB, PostgreSQL, SQLite, MSSQL, Oracle).

## Overview

PDOdb provides a unified API for JSON operations that works seamlessly across:
- **MySQL**: Native JSON type
- **PostgreSQL**: JSONB type
- **SQLite**: TEXT type with JSON functions
- **MSSQL**: NVARCHAR(MAX) or JSON type
- **Oracle**: VARCHAR2(4000) IS JSON or JSON type (12c+)

All examples work identically on all six databases.

## Examples

### 01-json-basics.php
Creating and storing JSON data.

**Topics covered:**
- Creating tables with JSON columns
- Storing JSON data with `json_encode()`
- Inserting records with JSON fields
- Basic JSON column queries
- JSON data types across dialects
- Auto-detection of JSON columns

### 02-json-queries.php
Querying and filtering JSON data.

**Topics covered:**
- Extracting JSON values with `jsonExtract()`
- Filtering by JSON values with `jsonContains()`
- Checking JSON key existence with `jsonExists()`
- Array operations with `jsonArrayContains()`
- Complex JSON path queries
- Nested JSON navigation
- WHERE conditions with JSON

### 03-json-modification.php
Modifying JSON data using helper methods.

**Topics covered:**
- Setting JSON values with `Db::jsonSet()`
- Removing JSON paths with `Db::jsonRemove()`
- Replacing JSON values with `Db::jsonReplace()`
- Creating nested paths
- Removing array elements
- Comparison between `jsonSet` and `jsonReplace`
- Complex nested JSON operations

## JSON Helper Functions

PDOdb provides these JSON helpers (from `Db` class):

### Extraction
- `jsonExtract(column, path)` - Extract value from JSON
- `jsonUnquote(column, path)` - Extract and unquote value

### Filtering
- `jsonContains(column, value, path?)` - Check if JSON contains value
- `jsonExists(column, path)` - Check if path exists
- `jsonArrayContains(column, value)` - Check if array contains value

### Aggregation
- `jsonLength(column, path?)` - Get JSON array length
- `jsonType(column, path?)` - Get JSON type
- `jsonKeys(column, path?)` - Get object keys

### Modification
- `Db::jsonSet(column, path, value)` - Set JSON value (creates path if missing)
- `Db::jsonRemove(column, path)` - Remove JSON path
- `Db::jsonReplace(column, path, value)` - Replace JSON value (only if path exists)

## Running Examples

### SQLite (default)
```bash
php 01-json-basics.php
```

### MySQL
```bash
PDODB_DRIVER=mysql php 01-json-basics.php
```

### PostgreSQL
```bash
PDODB_DRIVER=pgsql php 01-json-basics.php
```

### MSSQL
```bash
PDODB_DRIVER=sqlsrv php 01-json-basics.php
```

### Oracle
```bash
PDODB_DRIVER=oci php 01-json-basics.php
```

## Dialect Differences

While the API is unified, there are some internal differences:

| Feature | MySQL | PostgreSQL | SQLite | MSSQL | Oracle |
|---------|-------|------------|--------|-------|--------|
| Native type | JSON | JSONB | TEXT | NVARCHAR(MAX)/JSON | VARCHAR2(4000) IS JSON |
| Path syntax | `$.path` | Array notation | `$.path` | `$.path` | `$.path` |
| Performance | Good | Excellent | Good | Good | Good |
| Indexing | Supported | Supported | Limited | Supported | Limited |

## Next Steps

For more JSON operations, see:
- [Documentation: JSON Basics](../../documentation/04-json-operations/json-basics.md)
- [Documentation: JSON Querying](../../documentation/04-json-operations/json-querying.md)
- [Documentation: JSON Filtering](../../documentation/04-json-operations/json-filtering.md)
- [Helper Functions Reference](../../documentation/07-helper-functions/json-helpers.md)

