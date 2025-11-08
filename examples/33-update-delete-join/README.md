# UPDATE/DELETE with JOIN Examples

This directory contains examples demonstrating UPDATE and DELETE operations with JOIN clauses.

## Features Demonstrated

- **UPDATE with JOIN**: Update rows based on conditions from joined tables
- **DELETE with JOIN**: Delete rows based on conditions from joined tables
- **LEFT JOIN**: Use LEFT JOIN in UPDATE/DELETE operations
- **Multiple JOINs**: Chain multiple JOINs in UPDATE/DELETE operations
- **Dialect-specific behavior**: Different SQL syntax for MySQL/MariaDB vs PostgreSQL vs SQLite

## Files

- `01-update-delete-join-examples.php` - Comprehensive examples of UPDATE/DELETE with JOIN functionality

## Running Examples

```bash
# Run with SQLite (default)
# Note: SQLite doesn't support JOIN in UPDATE/DELETE, examples will show error messages
php 01-update-delete-join-examples.php

# Run with MySQL
PDODB_DRIVER=mysql php 01-update-delete-join-examples.php

# Run with MariaDB
PDODB_DRIVER=mariadb php 01-update-delete-join-examples.php

# Run with PostgreSQL
PDODB_DRIVER=pgsql php 01-update-delete-join-examples.php
```

## Usage

### UPDATE with JOIN

```php
// Update user balance based on order amount
$db->find()
    ->table('users')
    ->join('orders', 'orders.user_id = users.id')
    ->where('orders.status', 'completed')
    ->update(['balance' => Db::raw('users.balance + orders.amount')]);
```

### DELETE with JOIN

```php
// Delete users who have cancelled orders
$db->find()
    ->table('users')
    ->join('orders', 'orders.user_id = users.id')
    ->where('orders.status', 'cancelled')
    ->delete();
```

### UPDATE with LEFT JOIN

```php
// Update users who have orders (MySQL/MariaDB)
$db->find()
    ->table('users')
    ->leftJoin('orders', 'orders.user_id = users.id')
    ->where('orders.id', null, 'IS NOT')
    ->update(['users.status' => 'has_orders']);

// PostgreSQL - column name doesn't need table prefix
$db->find()
    ->table('users')
    ->leftJoin('orders', 'orders.user_id = users.id')
    ->where('orders.id', null, 'IS NOT')
    ->update(['status' => 'has_orders']);
```

## Dialect-Specific Notes

- **MySQL/MariaDB**: Supports JOIN in UPDATE/DELETE. Column names in SET clause are automatically qualified with table name when JOIN is used.
- **PostgreSQL**: Uses FROM clause instead of JOIN in UPDATE, and USING clause in DELETE. Column names in SET clause don't need table prefix.
- **SQLite**: Doesn't support JOIN in UPDATE/DELETE statements. An exception will be thrown if JOIN is used.

