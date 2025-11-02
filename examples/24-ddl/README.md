# DDL Query Builder Examples

This directory contains examples demonstrating the DDL (Data Definition Language) Query Builder functionality.

## Overview

The DDL Query Builder provides a fluent API for database schema operations, eliminating the need for raw SQL when creating, altering, or dropping database objects.

## Examples

### 01-ddl-basics.php

Basic DDL operations including:
- Creating tables with ColumnSchema fluent API
- Creating tables with array definitions
- Creating indexes
- Adding, renaming, and dropping columns
- Renaming tables
- Dropping tables
- Truncating tables

## Running Examples

```bash
# Run on default database (SQLite)
php 01-ddl-basics.php

# Run on specific database
PDODB_DRIVER=mysql php 01-ddl-basics.php
PDODB_DRIVER=mariadb php 01-ddl-basics.php
PDODB_DRIVER=pgsql php 01-ddl-basics.php
PDODB_DRIVER=sqlite php 01-ddl-basics.php
```

## Key Features

- **Fluent API**: Chainable methods for building schema operations
- **Type-Safe**: ColumnSchema objects with type hints
- **Cross-Database**: Automatic SQL generation for all supported dialects
- **No Raw SQL**: All operations use Query Builder methods

## Usage

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', $config);
$schema = $db->schema();

// Create table
$schema->createTable('users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(100)->notNull(),
    'email' => $schema->string(255)->notNull()->unique(),
]);

// Create index
$schema->createIndex('idx_users_email', 'users', 'email', true);

// Add column
$schema->addColumn('users', 'phone', $schema->string(20));

// Drop table
$schema->dropTable('users');
```

