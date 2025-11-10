# Dialect Differences

Differences between MySQL, MariaDB, PostgreSQL, and SQLite.

## JSON Operations

### MySQL

```php
// JSON_EXTRACT
Db::jsonExtract('data', '$.name')

// JSON_CONTAINS
Db::jsonContains('tags', 'php')

// JSON_SET
Db::jsonSet('data', '$.status', 'active')
```

### PostgreSQL

```php
// JSON extraction
Db::raw("data->>'name'")

// JSON contains
Db::raw("data @> '{\"tags\": [\"php\"]}'")

// JSON set
Db::raw("jsonb_set(data, '{status}', '\"active\"'::jsonb)")
```

### SQLite

```php
// JSON extraction
Db::raw("json_extract(data, '$.name')")

// JSON contains
Db::raw("json_extract(data, '$.tags') LIKE '%php%'")

// JSON set
Db::raw("json_set(data, '$.status', 'active')")
```

## String Functions

### SUBSTRING

```php
// MySQL, MariaDB, PostgreSQL, SQLite
Db::substring('name', 1, 10)
```

### CONCAT

```php
// MySQL
Db::concat('first', ' ', 'last')

// PostgreSQL
Db::raw("first || ' ' || last")

// SQLite
Db::raw("first || ' ' || last")
```

## Date Functions

### NOW()

```php
// MySQL
Db::now()  // NOW()

// PostgreSQL
Db::now()  // NOW()

// SQLite
Db::now()  // datetime('now')
```

### DATE_ADD

```php
// MySQL
Db::addInterval('created_at', '1 DAY')

// PostgreSQL
Db::raw("created_at + INTERVAL '1 DAY'")

// SQLite
Db::raw("datetime(created_at, '+1 day')")
```

## Identifier Quotes

### MySQL

```sql
SELECT * FROM `users`
```

### PostgreSQL

```sql
SELECT * FROM "users"
```

### SQLite

```sql
SELECT * FROM "users"
```

## Next Steps

- [API Reference](api-reference.md) - Complete API
- [Dialect Support](../02-core-concepts/05-dialect-support.md) - Dialect handling
- [Troubleshooting](../10-cookbook/troubleshooting.md) - Common issues
