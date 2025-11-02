# DDL Operations (Schema Management)

PDOdb provides a fluent DDL Query Builder for database schema operations without writing raw SQL. This includes creating, altering, and dropping tables, columns, indexes, and foreign keys across all supported databases.

## Overview

The DDL Query Builder provides a unified API for schema operations across MySQL, MariaDB, PostgreSQL, and SQLite. It automatically handles dialect-specific differences and syntax requirements.

## Getting Started

### Accessing the DDL Builder

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb'
]);

// Get DDL Query Builder
$schema = $db->schema();
```

## Creating Tables

### Using ColumnSchema Fluent API

The most intuitive way to create tables is using the fluent ColumnSchema API:

```php
$schema->createTable('users', [
    'id' => $schema->primaryKey(),
    'username' => $schema->string(100)->notNull(),
    'email' => $schema->string(255)->notNull()->unique(),
    'password_hash' => $schema->string(255)->notNull(),
    'age' => $schema->integer()->defaultValue(0),
    'status' => $schema->integer()->defaultValue(1),
    'created_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
    'updated_at' => $schema->timestamp()->null(),
    'bio' => $schema->text(),
    'balance' => $schema->decimal(10, 2)->defaultValue(0.00),
    'is_active' => $schema->boolean()->defaultValue(true),
]);
```

### Using Array Definitions

You can also use array definitions for a more concise syntax:

```php
$schema->createTable('posts', [
    'id' => ['type' => 'INT', 'autoIncrement' => true, 'null' => false],
    'user_id' => ['type' => 'INT', 'null' => false],
    'title' => ['type' => 'VARCHAR', 'length' => 255, 'null' => false],
    'content' => ['type' => 'TEXT'],
    'published' => ['type' => 'BOOLEAN', 'default' => false],
    'views' => ['type' => 'INT', 'default' => 0],
]);
```

### Using String Types

For simple cases, you can use string type definitions:

```php
$schema->createTable('tags', [
    'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
    'name' => 'VARCHAR(100) NOT NULL',
    'slug' => 'VARCHAR(100) NOT NULL UNIQUE',
]);
```

### Column Schema Methods

The ColumnSchema fluent API provides many methods for column definition:

#### Type Methods

- `primaryKey(?int $length = null)` - Integer primary key with auto-increment
- `bigPrimaryKey(?int $length = null)` - Big integer primary key with auto-increment
- `string(int $length = 255)` - VARCHAR column
- `text()` - TEXT column
- `integer(?int $length = null)` - INTEGER column
- `bigInteger(?int $length = null)` - BIGINT column
- `smallInteger(?int $length = null)` - SMALLINT column
- `boolean()` - BOOLEAN column
- `float(int $precision = 10, int $scale = 2)` - FLOAT column
- `decimal(int $precision = 10, int $scale = 2)` - DECIMAL column
- `dateTime()` - DATETIME/TIMESTAMP column
- `timestamp()` - TIMESTAMP column
- `date()` - DATE column
- `time()` - TIME column
- `binary(?int $length = null)` - BLOB/BINARY column
- `uuid()` - UUID column (PostgreSQL)
- `json()` - JSON column

#### Column Attributes

- `->notNull()` - Set column as NOT NULL
- `->null()` - Allow NULL values (default)
- `->defaultValue(mixed $value)` - Set default value
- `->defaultExpression(string $expr)` - Set default expression (e.g., 'CURRENT_TIMESTAMP')
- `->autoIncrement()` - Set auto-increment (MySQL/MariaDB)
- `->unsigned()` - Set unsigned (MySQL/MariaDB)
- `->unique()` - Mark column as unique
- `->comment(string $comment)` - Add column comment (MySQL/MariaDB)
- `->after(string $column)` - Place column after another (MySQL/MariaDB)
- `->first()` - Place column first (MySQL/MariaDB)

### Table Options

Some dialects support table options:

```php
// MySQL/MariaDB
$schema->createTable('users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(255),
], [
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',
    'collate' => 'utf8mb4_unicode_ci',
    'comment' => 'User accounts table',
]);

// PostgreSQL
$schema->createTable('users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(255),
], [
    'tablespace' => 'pg_default',
    'with' => ['fillfactor' => 70],
]);
```

### Creating Tables Conditionally

```php
// Create table only if it doesn't exist
$schema->createTableIfNotExists('users', [
    'id' => $schema->primaryKey(),
    'username' => $schema->string(100)->notNull(),
]);
```

## Altering Tables

### Adding Columns

```php
// Add a single column
$schema->addColumn('users', 'phone', $schema->string(20));

// Add column with position (MySQL/MariaDB)
$schema->addColumn('users', 'phone', $schema->string(20)->after('email'));

// Add column at the beginning (MySQL/MariaDB)
$schema->addColumn('users', 'priority', $schema->integer()->first());
```

### Dropping Columns

```php
$schema->dropColumn('users', 'phone');
```

**Note:** SQLite has limited support for DROP COLUMN. It's only available in SQLite 3.35.0+.

### Altering Columns

Alter column definition (type, nullability, default, etc.):

```php
// Change column type and attributes
$schema->alterColumn('users', 'email', $schema->string(200)->notNull());

// Modify default value
$schema->alterColumn('users', 'status', $schema->integer()->defaultValue(0));

// Change nullability
$schema->alterColumn('users', 'bio', $schema->text()->null());
```

**Note:** SQLite has limited support for ALTER COLUMN. You can rename columns but changing types requires recreating the table.

### Renaming Columns

```php
$schema->renameColumn('users', 'phone', 'phone_number');
```

**Note:** Requires MySQL 8.0.13+, MariaDB 10.5.2+, PostgreSQL 10+, or SQLite 3.25.0+.

### Renaming Tables

```php
$schema->renameTable('posts', 'articles');
```

## Indexes

### Creating Indexes

```php
// Simple index
$schema->createIndex('idx_users_email', 'users', 'email');

// Unique index
$schema->createIndex('idx_users_username', 'users', 'username', true);

// Composite index
$schema->createIndex('idx_posts_user_date', 'posts', ['user_id', 'created_at']);
```

### Dropping Indexes

```php
$schema->dropIndex('idx_users_email', 'users');
```

## Foreign Keys

### Adding Foreign Keys

```php
// Simple foreign key
$schema->addForeignKey(
    'fk_posts_user',
    'posts',
    'user_id',
    'users',
    'id'
);

// Foreign key with actions
$schema->addForeignKey(
    'fk_posts_user',
    'posts',
    'user_id',
    'users',
    'id',
    'CASCADE',  // ON DELETE CASCADE
    'RESTRICT'  // ON UPDATE RESTRICT
);

// Composite foreign key
$schema->addForeignKey(
    'fk_order_items_order',
    'order_items',
    ['order_id', 'order_date'],
    'orders',
    ['id', 'order_date']
);
```

### Dropping Foreign Keys

```php
$schema->dropForeignKey('fk_posts_user', 'posts');
```

## Dropping Tables

### Drop Table

```php
$schema->dropTable('users');
```

### Drop Table If Exists

```php
$schema->dropTableIfExists('old_table');
```

### Truncate Table

```php
$schema->truncateTable('users');
```

## Checking Table Existence

```php
if ($schema->tableExists('users')) {
    echo "Table 'users' exists\n";
}
```

## Dialect-Specific Considerations

### MySQL / MariaDB

- Auto-increment columns automatically become PRIMARY KEY
- Supports `ENGINE`, `CHARSET`, `COLLATE` table options
- Supports `COMMENT` on columns and tables
- Supports `FIRST` and `AFTER` for column positioning
- Supports `UNSIGNED` for numeric columns
- `RENAME COLUMN` requires MySQL 8.0.13+ / MariaDB 10.5.2+

### PostgreSQL

- Uses `SERIAL`/`BIGSERIAL` for auto-increment (instead of `AUTO_INCREMENT`)
- Supports `TABLESPACE` and `WITH` table options
- `RENAME COLUMN` requires PostgreSQL 10+

### SQLite

- Limited `ALTER TABLE` support:
  - `DROP COLUMN` requires SQLite 3.35.0+
  - `RENAME COLUMN` requires SQLite 3.25.0+
  - `ALTER COLUMN` (type changes) not supported
- Type system is flexible (affinity-based)
- No support for `UNSIGNED`, `FIRST`, `AFTER`, `COMMENT`
- Foreign keys must be enabled with `PRAGMA foreign_keys = ON`

## Best Practices

1. **Use Fluent API**: Prefer the ColumnSchema fluent API for better readability and type safety.

2. **Check Table Existence**: Always check if a table exists before creating it, or use `createTableIfNotExists()`.

3. **Handle Dialect Differences**: Be aware of dialect-specific limitations, especially for SQLite.

4. **Transaction Safety**: Wrap schema changes in transactions when possible (though some operations like `DROP COLUMN` in MySQL can't be rolled back).

5. **Version Control**: Use migrations (see [Database Migrations](../05-advanced-features/migrations.md)) for schema version control.

## Examples

### Complete Table Creation

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'blog'
]);

$schema = $db->schema();

// Drop table if exists (cleanup)
$schema->dropTableIfExists('posts');

// Create posts table
$schema->createTable('posts', [
    'id' => $schema->primaryKey(),
    'user_id' => $schema->integer()->notNull(),
    'title' => $schema->string(255)->notNull(),
    'slug' => $schema->string(255)->notNull()->unique(),
    'content' => $schema->text(),
    'excerpt' => $schema->text(),
    'status' => $schema->string(20)->defaultValue('draft'),
    'views' => $schema->integer()->defaultValue(0)->unsigned(),
    'published_at' => $schema->timestamp()->null(),
    'created_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
    'updated_at' => $schema->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
], [
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',
    'collate' => 'utf8mb4_unicode_ci',
]);

// Create indexes
$schema->createIndex('idx_posts_user_id', 'posts', 'user_id');
$schema->createIndex('idx_posts_slug', 'posts', 'slug', true);
$schema->createIndex('idx_posts_status', 'posts', 'status');

// Add foreign key
$schema->addForeignKey(
    'fk_posts_user',
    'posts',
    'user_id',
    'users',
    'id',
    'CASCADE',
    'RESTRICT'
);

echo "Table 'posts' created successfully\n";
```

## Related Documentation

- [Schema Introspection](schema-introspection.md) - Querying existing schema
- [Database Migrations](../05-advanced-features/migrations.md) - Version-controlled schema changes
- [Dialect Support](../02-core-concepts/dialect-support.md) - Database-specific differences
