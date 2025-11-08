# INSERT ... SELECT Examples

This directory contains examples demonstrating the `insertFrom()` method for copying data between tables using INSERT ... SELECT statements.

## Features Demonstrated

- **Basic table copying**: Copy all data from one table to another
- **Column mapping**: Copy specific columns only
- **QueryBuilder integration**: Use QueryBuilder as source with filters, joins, etc.
- **Closure support**: Build complex source queries using closures
- **CTE support**: Use Common Table Expressions in source queries
- **JOIN support**: Copy data from joined tables
- **Aggregation**: Copy aggregated/transformed data
- **ON DUPLICATE handling**: Upsert operations with conflict resolution
- **LIMIT support**: Copy limited number of rows

## Files

- `01-insert-from-examples.php` - Comprehensive examples of INSERT ... SELECT functionality

## Running Examples

```bash
# Run with SQLite (default)
php 01-insert-from-examples.php

# Run with MySQL
PDODB_DRIVER=mysql php 01-insert-from-examples.php

# Run with MariaDB
PDODB_DRIVER=mariadb php 01-insert-from-examples.php

# Run with PostgreSQL
PDODB_DRIVER=pgsql php 01-insert-from-examples.php
```

## Usage

```php
// Copy all data from source table
$db->find()
    ->table('target_table')
    ->insertFrom('source_table');

// Copy specific columns
$db->find()
    ->table('target_table')
    ->insertFrom('source_table', ['name', 'email', 'age']);

// Copy filtered data using QueryBuilder
$db->find()
    ->table('target_table')
    ->insertFrom(function ($query) {
        $query->from('source_table')
            ->where('status', 'active')
            ->select(['name', 'email', 'age']);
    });

// Copy with ON DUPLICATE handling
$db->find()
    ->table('target_table')
    ->insertFrom('source_table', null, ['age', 'status']);
```

