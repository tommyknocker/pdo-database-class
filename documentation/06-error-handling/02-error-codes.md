# Error Codes

Reference for database error codes and their meanings.

## DbError Class

```php
use tommyknocker\pdodb\helpers\DbError;
```

## MySQL Error Codes

### Connection Errors

```php
DbError::MYSQL_CONNECTION_LOST      // 2006
DbError::MYSQL_CANNOT_CONNECT       // 2002
DbError::MYSQL_CONNECTION_KILLED    // 2013
DbError::MYSQL_CONNECTION_REFUSED   // 2003
```

### Constraint Errors

```php
DbError::MYSQL_DUPLICATE_KEY        // 1062
DbError::MYSQL_TABLE_EXISTS         // 1050
DbError::MYSQL_FOREIGN_KEY          // 1216
```

## PostgreSQL Error Codes

### Connection Errors

```php
DbError::POSTGRESQL_CONNECTION_FAILURE         // '08006'
DbError::POSTGRESQL_CONNECTION_DOES_NOT_EXIST   // '08003'
```

### Constraint Errors

```php
DbError::POSTGRESQL_UNIQUE_VIOLATION   // '23505'
DbError::POSTGRESQL_FOREIGN_KEY        // '23503'
DbError::POSTGRESQL_NOT_NULL           // '23502'
```

### Table Errors

```php
DbError::POSTGRESQL_UNDEFINED_TABLE    // '42P01'
DbError::POSTGRESQL_UNDEFINED_COLUMN   // '42703'
```

## SQLite Error Codes

```php
DbError::SQLITE_ERROR       // 1
DbError::SQLITE_BUSY        // 5
DbError::SQLITE_LOCKED      // 6
DbError::SQLITE_CONSTRAINT  // 19
DbError::SQLITE_ROW         // 100
DbError::SQLITE_DONE        // 101
```

## Helper Methods

### Get Retryable Errors

```php
$mysqlErrors = DbError::getRetryableErrors('mysql');
$pgsqlErrors = DbError::getRetryableErrors('pgsql');
$sqliteErrors = DbError::getRetryableErrors('sqlite');
```

### Check if Error is Retryable

```php
$isRetryable = DbError::isRetryable(2006, 'mysql');  // true
```

### Get Error Description

```php
$description = DbError::getDescription(2006, 'mysql');
// Returns: "MySQL server has gone away"
```

## Using in Retry Configuration

```php
use tommyknocker\pdodb\helpers\DbError;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'retry' => [
        'enabled' => true,
        'retryable_errors' => DbError::getRetryableErrors('mysql')
    ]
]);
```

## Next Steps

- [Exception Hierarchy](exception-hierarchy.md) - Error handling
- [Retry Logic](retry-logic.md) - Build retry mechanisms
- [Connection Retry](../05-advanced-features/07-connection-retry.md) - Automatic retry
