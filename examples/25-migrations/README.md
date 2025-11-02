# Database Migrations Examples

This directory contains examples demonstrating the database migration system for version-controlled schema changes.

## Overview

The migration system provides a structured way to manage database schema changes over time, similar to Yii2's migration system. Migrations allow you to:
- Track schema changes in version control
- Apply migrations in order
- Rollback migrations when needed
- Collaborate with team members

## Examples

### 01-migration-basics.php

Basic migration operations including:
- Creating migration files
- Applying migrations
- Checking migration history
- Rolling back migrations

## Running Examples

```bash
# Run on default database (SQLite)
php 01-migration-basics.php

# Run on specific database
PDODB_DRIVER=mysql php 01-migration-basics.php
PDODB_DRIVER=mariadb php 01-migration-basics.php
PDODB_DRIVER=pgsql php 01-migration-basics.php
PDODB_DRIVER=sqlite php 01-migration-basics.php
```

## Migration File Format

Migration files follow the naming convention: `mYYYY_MM_DD_HHMMSS_description.php`

Example: `m2025_11_01_120000_create_users_table.php`

## Migration Class Structure

```php
<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

class m20251101120000_example extends Migration
{
    public function up(): void
    {
        // Apply migration
        $this->schema()->createTable('users', [
            'id' => $this->schema()->primaryKey(),
            'name' => $this->schema()->string(100),
        ]);
    }

    public function down(): void
    {
        // Revert migration
        $this->schema()->dropTable('users');
    }
}
```

## Usage

```php
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', $config);
$runner = new MigrationRunner($db, __DIR__ . '/migrations');

// Apply new migrations
$applied = $runner->migrate();

// Rollback last migration
$runner->migrateDown(1);

// Create new migration
$filename = $runner->create('add_email_to_users');

// Get migration history
$history = $runner->getMigrationHistory();
```

