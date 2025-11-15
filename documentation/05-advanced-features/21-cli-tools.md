# CLI Tools

PDOdb provides convenient command-line tools for common development tasks, including database management, migration generation, model generation, schema inspection, and interactive query testing.

## Overview

The CLI tools are designed to streamline your development workflow:

- **Database Management** - Create, drop, list, and check database existence
- **Migration Generator** - Create database migrations with interactive prompts
- **Model Generator** - Generate ActiveRecord models from existing database tables
- **Schema Inspector** - Inspect database schema structure
- **Query Tester** - Interactive REPL for testing SQL queries

## Configuration

CLI tools automatically detect database configuration from:

1. **`.env` file** in the current working directory (recommended for production)
2. **`config.php`** file in the current working directory
3. **Environment variables** (`PDODB_DRIVER`, `PDODB_HOST`, etc.)
4. **Examples config** (for testing only)

### Environment Variables

The following environment variables are supported:

```bash
# Database driver (mysql, mariadb, pgsql, sqlite, sqlsrv)
PDODB_DRIVER=mysql

# Connection settings
PDODB_HOST=localhost
PDODB_PORT=3306
PDODB_DATABASE=mydb
PDODB_USERNAME=user
PDODB_PASSWORD=password
PDODB_CHARSET=utf8mb4

# SQLite specific
PDODB_PATH=/path/to/database.sqlite

# Paths
PDODB_MIGRATION_PATH=/path/to/migrations
PDODB_MODEL_PATH=/path/to/models
```

### .env File Example

Create a `.env` file in your project root:

```env
PDODB_DRIVER=mysql
PDODB_HOST=localhost
PDODB_PORT=3306
PDODB_DATABASE=mydb
PDODB_USERNAME=user
PDODB_PASSWORD=password
PDODB_CHARSET=utf8mb4
PDODB_MIGRATION_PATH=./database/migrations
PDODB_MODEL_PATH=./app/Models
```

## Database Management

Manage databases with simple commands for creating, dropping, listing, and checking database existence.

### Usage

```bash
# Create a database (name will be prompted if not provided)
vendor/bin/pdodb db create myapp
vendor/bin/pdodb db create

# Drop a database (name will be prompted if not provided, with confirmation)
vendor/bin/pdodb db drop myapp
vendor/bin/pdodb db drop

# Check if a database exists (name will be prompted if not provided)
vendor/bin/pdodb db exists myapp
vendor/bin/pdodb db exists

# List all databases
vendor/bin/pdodb db list

# Show information about current database
vendor/bin/pdodb db info
```

### Examples

#### Create Database

```bash
$ vendor/bin/pdodb db create myapp

✓ Database 'myapp' created successfully
```

Or interactively:

```bash
$ vendor/bin/pdodb db create

Enter database name: myapp
✓ Database 'myapp' created successfully
```

#### Drop Database

```bash
$ vendor/bin/pdodb db drop myapp

Are you sure you want to drop database 'myapp'? This action cannot be undone [y/N]: y
✓ Database 'myapp' dropped successfully
```

Or interactively:

```bash
$ vendor/bin/pdodb db drop

Enter database name: myapp
Are you sure you want to drop database 'myapp'? This action cannot be undone [y/N]: y
✓ Database 'myapp' dropped successfully
```

#### Check Database Existence

```bash
$ vendor/bin/pdodb db exists myapp

✓ Database 'myapp' exists
```

Or interactively:

```bash
$ vendor/bin/pdodb db exists

Enter database name: myapp
✓ Database 'myapp' exists
```

#### List Databases

```bash
$ vendor/bin/pdodb db list

Databases (5):

  information_schema
  mysql
  myapp
  performance_schema
  sys
```

#### Database Information

```bash
$ vendor/bin/pdodb db info

Database Information:

  Driver: mysql
  Current Database: myapp
  Version: 8.0.35
  Charset: utf8mb4
  Collation: utf8mb4_unicode_ci
```

### SQLite Support

For SQLite, database management works with file paths:

```bash
# Create SQLite database file
vendor/bin/pdodb db create /path/to/database.sqlite

# Drop SQLite database file
vendor/bin/pdodb db drop /path/to/database.sqlite

# Check if SQLite database file exists
vendor/bin/pdodb db exists /path/to/database.sqlite
```

Note: SQLite does not support listing multiple databases. Use file paths for database operations.

## Migration Generator

Generate database migrations with interactive prompts and helpful suggestions.

### Usage

```bash
# Interactive mode (will prompt for migration name)
vendor/bin/pdodb migrate create

# Non-interactive mode
vendor/bin/pdodb migrate create create_users_table
```

### Example

```bash
$ vendor/bin/pdodb migrate create create_users_table

PDOdb Migration Generator
Database: mysql

Migrations path: /path/to/migrations

Suggested migration types:
  1. create_table
  0. Custom (manual)

Select migration type [0]: 1
ℹ Selected: create_table
✓ Migration file created: m2024_01_15_123456_create_users_table.php
  Path: /path/to/migrations/m2024_01_15_123456_create_users_table.php
```

### Generated Migration File

```php
<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

/**
 * Migration: create_users_table
 *
 * Created: 2024_01_15_123456
 */
class m20240115123456CreateUsersTable extends Migration
{
    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        // TODO: Implement migration up logic
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        // TODO: Implement migration down logic
    }
}
```

## Model Generator

Generate ActiveRecord model classes from existing database tables.

### Usage

```bash
# Auto-detect table name from model name
vendor/bin/pdodb model make User

# Specify table name explicitly
vendor/bin/pdodb model make User users

# Specify output path
vendor/bin/pdodb model make User users app/Models
```

### Example

```bash
$ vendor/bin/pdodb model make User

PDOdb Model Generator
Database: mysql

Enter table name [users]: users
✓ Model file created: User.php
  Path: /path/to/models/User.php
  Table: users
  Primary key: id
```

### Generated Model File

```php
<?php

declare(strict_types=1);

namespace App\Models;

use tommyknocker\pdodb\orm\Model;

/**
 * Model class for table: users
 *
 * Auto-generated by PDOdb Model Generator
 */
class User extends Model
{
    /**
     * {@inheritDoc}
     */
    public static function tableName(): string
    {
        return 'users';
    }

    /**
     * {@inheritDoc}
     */
    public static function primaryKey(): array
    {
        return ['id'];
    }

    /**
     * Model attributes.
     *
     * @var array<string, mixed>
     */
    public array $attributes = [
        'id' => null,
        'name' => null,
        'email' => null,
        'created_at' => null,
    ];

    /**
     * {@inheritDoc}
     */
    public static function relations(): array
    {
        return [
            // TODO: Define relationships based on foreign keys
        ];
    }
}
```

## Schema Inspector

Inspect database schema structure, including tables, columns, indexes, foreign keys, and constraints.

### Usage

```bash
# List all tables
vendor/bin/pdodb schema inspect

# Inspect specific table
vendor/bin/pdodb schema inspect users

# Output in JSON format
vendor/bin/pdodb schema inspect users --format=json

# Output in YAML format
vendor/bin/pdodb schema inspect users --format=yaml
```

### Example Output (Table Format)

```bash
$ vendor/bin/pdodb schema inspect users

PDOdb Schema Inspector
Database: mysql

Table: users
============================================================

Columns:
------------------------------------------------------------
Name                 Type            Nullable   Default
------------------------------------------------------------
id                   int             NO         NULL
name                 varchar(100)    NO         NULL
email                varchar(255)    NO         NULL
created_at           timestamp       YES        CURRENT_TIMESTAMP

Indexes:
------------------------------------------------------------
  PRIMARY (id)
  idx_users_email UNIQUE (email)

Foreign Keys:
------------------------------------------------------------
```

### JSON Format

```bash
$ vendor/bin/pdodb schema inspect users --format=json

{
    "table": "users",
    "columns": [
        {
            "name": "id",
            "type": "int",
            "nullable": false,
            "default": null
        },
        ...
    ],
    "indexes": [...],
    "foreign_keys": [...],
    "constraints": [...]
}
```

## Query Tester (REPL)

Interactive REPL for testing SQL queries against the database.

### Usage

```bash
# Interactive mode
vendor/bin/pdodb query test

# Execute single query
vendor/bin/pdodb query test "SELECT * FROM users LIMIT 10"
```

### Interactive Commands

- `exit`, `quit`, `q` - Exit the query tester
- `help` - Show help message
- `clear`, `cls` - Clear the screen
- `history` - Show query history

### Example Session

```bash
$ vendor/bin/pdodb query test

PDOdb Query Tester (REPL)
Database: mysql
Type 'exit' or 'quit' to exit, 'help' for help

pdodb> SELECT * FROM users LIMIT 5

id    name              email                    created_at
------------------------------------------------------------
1     John Doe          john@example.com         2024-01-15 10:00:00
2     Jane Smith        jane@example.com         2024-01-15 10:01:00
3     Bob Johnson       bob@example.com          2024-01-15 10:02:00
4     Alice Brown       alice@example.com        2024-01-15 10:03:00
5     Charlie Wilson    charlie@example.com      2024-01-15 10:04:00

Total rows: 5

pdodb> SELECT COUNT(*) FROM users

COUNT(*)
------------------------------------------------------------
100

Total rows: 1

pdodb> exit
Goodbye!
```

## Installation

After installing PDOdb via Composer, the CLI tool is automatically available in `vendor/bin/`:

```bash
composer require tommyknocker/pdo-database-class
```

## Usage

PDOdb provides a unified CLI tool with command-based structure (similar to Yii2):

```bash
vendor/bin/pdodb <command> [subcommand] [arguments] [options]
```

### Available Commands

- **`db`** - Manage databases (create, drop, list, check existence, show info)
- **`migrate`** - Manage database migrations
- **`schema`** - Inspect database schema
- **`query`** - Test SQL queries interactively
- **`model`** - Generate ActiveRecord models

### Getting Help

```bash
# Show all available commands
vendor/bin/pdodb

# Show help for a specific command
vendor/bin/pdodb db --help
vendor/bin/pdodb migrate --help
vendor/bin/pdodb schema --help
vendor/bin/pdodb query --help
vendor/bin/pdodb model --help
```

## Best Practices

1. **Use `.env` files** for configuration in production environments
2. **Keep migrations organized** in a dedicated directory
3. **Review generated code** before committing to version control
4. **Use schema inspector** to verify table structures before writing queries
5. **Use query tester** for quick query debugging and testing

## Troubleshooting

### CLI tools can't find database configuration

Ensure you have:
- A `.env` file in the current working directory, OR
- A `config.php` file in the current working directory, OR
- Environment variables set (`PDODB_DRIVER`, etc.)

### Migration generator can't find migrations directory

Set the `PDODB_MIGRATION_PATH` environment variable or create a `migrations` directory in your project root.

### Model generator can't find models directory

Set the `PDODB_MODEL_PATH` environment variable or create a `models` directory in your project root.

### Schema inspector shows no tables

Verify that:
- The database connection is configured correctly
- You have the necessary permissions to query the database
- The database contains tables
