# Database Migrations

PDOdb provides a database migration system for version-controlled schema changes, inspired by Yii2's migration system. Migrations allow you to track and manage database schema changes over time, enabling easy rollbacks and team collaboration.

## Overview

The migration system tracks applied migrations in a special `__migrations` table and provides methods to apply, rollback, and manage database schema changes.

## Key Features

- **Version Control**: Track all schema changes with timestamped migration files
- **Automatic Tracking**: Applied migrations are automatically recorded
- **Rollback Support**: Revert migrations individually or in batches
- **DDL Integration**: Full DDL Query Builder support within migrations
- **Transaction Safety**: Migrations run in transactions when possible
- **Cross-Database**: Works with MySQL, MariaDB, PostgreSQL, and SQLite

## Getting Started

### Setting Up Migrations

```php
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb'
]);

// Create migration runner
$runner = new MigrationRunner($db, __DIR__ . '/migrations');
```

The migration path should be a directory where migration files will be stored.

## Creating Migrations

### Creating a New Migration

```php
// Create a new migration file
$filename = $runner->create('create_users_table');

// Output: /path/to/migrations/m2025_11_01_120000_create_users_table.php
```

The migration file follows the naming pattern: `m{timestamp}_{name}.php`

### Migration File Structure

A migration file contains a class that extends `Migration`:

```php
<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

/**
 * Migration: create_users_table
 *
 * Created: 2025_11_01_120000_create_users_table
 */
class m20251101120000CreateUsersTable extends Migration
{
    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $this->schema()->createTable('users', [
            'id' => $this->schema()->primaryKey(),
            'username' => $this->schema()->string(100)->notNull(),
            'email' => $this->schema()->string(255)->notNull(),
            'password_hash' => $this->schema()->string(255)->notNull(),
            'created_at' => $this->schema()->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->schema()->dropTable('users');
    }
}
```

### Migration Methods

Each migration class must implement two methods:

- **`up()`**: Applies the migration (creates tables, adds columns, etc.)
- **`down()`**: Reverts the migration (drops tables, removes columns, etc.)

## Migration Helper Methods

The `Migration` base class provides helper methods:

### DDL Operations

```php
// Get DDL Query Builder
$schema = $this->schema();

// Create, alter, drop tables, columns, indexes, etc.
$this->schema()->createTable('users', [...]);
$this->schema()->addColumn('users', 'phone', $schema->string(20));
```

### DML Operations

```php
// Get Query Builder
$query = $this->query();

// Insert single row
$this->insert('users', [
    'username' => 'admin',
    'email' => 'admin@example.com',
]);

// Batch insert
$this->batchInsert('users', ['username', 'email'], [
    ['user1', 'user1@example.com'],
    ['user2', 'user2@example.com'],
]);
```

### Direct Database Access

```php
// Access PdoDb instance
$this->db->rawQuery('SET FOREIGN_KEY_CHECKS = 0');
$this->db->startTransaction();
// ... operations ...
$this->db->commit();
```

## Applying Migrations

### Apply All New Migrations

```php
// Get list of new (not yet applied) migrations
$newMigrations = $runner->getNewMigrations();
print_r($newMigrations);

// Apply all new migrations
$applied = $runner->migrate();
foreach ($applied as $version) {
    echo "Applied: {$version}\n";
}
```

### Apply Specific Migration

```php
$runner->migrateUp('2025_11_01_120000_create_users_table');
```

## Rolling Back Migrations

### Rollback Last Batch

```php
// Rollback the last batch of migrations (default: 1)
$rolledBack = $runner->rollback(1);

foreach ($rolledBack as $version) {
    echo "Rolled back: {$version}\n";
}
```

### Rollback Specific Migration

```php
$runner->migrateDown('2025_11_01_120000_create_users_table');
```

### Rollback to Specific Version

```php
// Go to a specific migration version
$runner->to('2025_11_01_120000_create_users_table');

// Rollback all migrations
$runner->to('0');
```

## Migration History

### View Migration History

```php
// Get all migration history
$history = $runner->getMigrationHistory();

foreach ($history as $record) {
    echo "Version: {$record['version']}\n";
    echo "Applied: {$record['apply_time']}\n";
    echo "Batch: {$record['batch']}\n";
    echo "---\n";
}

// Get limited history
$recent = $runner->getMigrationHistory(10); // Last 10 migrations
```

### Check Applied Migrations

```php
$history = $runner->getMigrationHistory();
$appliedVersions = array_column($history, 'version');

if (in_array('2025_11_01_120000_create_users_table', $appliedVersions)) {
    echo "Migration already applied\n";
}
```

## Advanced Usage

### Transactions

Migrations run in transactions automatically. If a migration fails, it's automatically rolled back:

```php
public function up(): void
{
    // All operations run in a transaction
    $this->schema()->createTable('users', [...]);
    $this->insert('users', [...]);
    
    // If any operation fails, all changes are rolled back
}
```

### Non-Transactional Migrations

Some operations (like `DROP COLUMN` in older MySQL versions) can't be rolled back. For these cases, you can override `safeUp()` and `safeDown()`:

```php
class m20251101120000DropColumn extends Migration
{
    public function safeUp(): void
    {
        // This won't be wrapped in a transaction
        $this->schema()->dropColumn('users', 'old_column');
    }

    public function safeDown(): void
    {
        // Rollback logic
        $this->schema()->addColumn('users', 'old_column', $this->schema()->string(100));
    }
}
```

### Complex Migrations

```php
class m20251101120000ComplexMigration extends Migration
{
    public function up(): void
    {
        $schema = $this->schema();
        
        // Create multiple tables
        $schema->createTable('categories', [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
        ]);
        
        $schema->createTable('posts', [
            'id' => $schema->primaryKey(),
            'category_id' => $schema->integer()->notNull(),
            'title' => $schema->string(255)->notNull(),
            'content' => $schema->text(),
        ]);
        
        // Add foreign key
        $schema->addForeignKey(
            'fk_posts_category',
            'posts',
            'category_id',
            'categories',
            'id',
            'CASCADE',
            'RESTRICT'
        );
        
        // Insert initial data
        $this->insert('categories', ['name' => 'Uncategorized']);
        
        // Create indexes
        $schema->createIndex('idx_posts_category', 'posts', 'category_id');
    }

    public function down(): void
    {
        $schema = $this->schema();
        
        // Drop in reverse order (foreign keys first)
        $schema->dropForeignKey('fk_posts_category', 'posts');
        $schema->dropTable('posts');
        $schema->dropTable('categories');
    }
}
```

## Migration File Naming

Migration files follow this pattern:

- Format: `m{YYYY}_{MM}_{DD}_{HHMMSS}_{name}.php`
- Example: `m2025_11_01_120000_create_users_table.php`
- Class name: `m{YYYYMMDDHHMMSS}{PascalCaseName}`
- Example: `m20251101120000CreateUsersTable`

The migration runner automatically:
- Extracts version from filename
- Converts to class name
- Loads and instantiates the migration class

## Best Practices

1. **Always Implement `down()`**: Every migration should be reversible.

2. **Keep Migrations Simple**: Each migration should do one logical change.

3. **Use DDL Query Builder**: Prefer the DDL Query Builder over raw SQL for cross-database compatibility.

4. **Test Rollbacks**: Always test that `down()` methods work correctly.

5. **Version Control**: Commit migration files to version control (Git, SVN, etc.).

6. **Don't Modify Applied Migrations**: Once applied, don't modify migration files. Create new migrations instead.

7. **Use Descriptive Names**: Migration names should clearly describe what they do.

8. **Data Migrations**: Use `insert()`, `batchInsert()`, or `query()` for data migrations within schema changes.

## Migration Table

The migration system creates a `__migrations` table to track applied migrations:

```sql
CREATE TABLE __migrations (
    version VARCHAR(255) PRIMARY KEY,
    apply_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    batch INTEGER NOT NULL
);
```

- **version**: Migration version string (from filename)
- **apply_time**: When the migration was applied
- **batch**: Batch number for grouping migrations

## CLI Command Tool

PDOdb includes a built-in CLI tool for managing migrations (similar to Yii2's migration system).

### Using Composer Scripts

The easiest way to use migrations is through Composer scripts:

```bash
# Create a new migration
composer pdodb:migrate:create add_email_to_users

# Apply all new migrations
composer pdodb:migrate:up

# Rollback last migration
composer pdodb:migrate:down

# Show migration history
composer pdodb:migrate:history

# Show new (not applied) migrations
composer pdodb:migrate:new
```

### Direct Script Usage

You can also use the migration script directly:

```bash
# Create a new migration
php scripts/migrate.php create add_email_to_users

# Apply new migrations
php scripts/migrate.php migrate

# Apply specific migration
php scripts/migrate.php migrate/up 2025_11_01_120000_create_users_table

# Rollback specific migration
php scripts/migrate.php migrate/down 2025_11_01_120000_create_users_table

# Rollback last batch (default: 1)
php scripts/migrate.php rollback 2

# Migrate to specific version (or '0' for all)
php scripts/migrate.php to 2025_11_01_120000_create_users_table

# Show migration history
php scripts/migrate.php history 10

# Show new migrations
php scripts/migrate.php new
```

### Environment Variables

The migration tool automatically detects configuration:

- **`PDODB_DRIVER`**: Database driver (`mysql`, `mariadb`, `pgsql`, `sqlite`)
- **`PDODB_MIGRATION_PATH`**: Custom path to migration files directory

The tool will:
1. Load database config from `examples/config.{driver}.php` or root `config.php`
2. Look for migrations in common directories: `migrations/`, `database/migrations/`, or current directory
3. Create default `migrations/` directory if none exists

### Configuration

The migration tool uses the same configuration files as examples:

- `examples/config.mysql.php` for MySQL
- `examples/config.mariadb.php` for MariaDB
- `examples/config.pgsql.php` for PostgreSQL
- `examples/config.sqlite.php` for SQLite (default)

You can create a root `config.php` with multiple database configurations, and the tool will select based on `PDODB_DRIVER`.

## Related Documentation

- [DDL Operations](../03-query-builder/12-ddl-operations.md) - Schema management operations
- [Transactions](transactions.md) - Transaction management
- [Query Builder Basics](../02-core-concepts/03-query-builder-basics.md) - Query building fundamentals
