# CLI Tools

PDOdb provides convenient command-line tools for common development tasks, including database management, migration generation, model generation, schema inspection, and interactive query testing.

## Overview

The CLI tools are designed to streamline your development workflow:

- **Database Management** - Create, drop, list, and check database existence
- **User Management** - Create, drop, list, and manage database users and privileges
- **Migration Generator** - Create database migrations with interactive prompts
- **Model Generator** - Generate ActiveRecord models from existing database tables
- **Schema Inspector** - Inspect database schema structure
- **Query Tester** - Interactive REPL for testing SQL queries

## Database Dump and Restore

Export and import database schema and data.

### Usage

```bash
# Full database dump to file
vendor/bin/pdodb dump --output=backup.sql

# Dump specific table to file
vendor/bin/pdodb dump users --output=users_backup.sql

# Schema only (no data)
vendor/bin/pdodb dump --schema-only --output=schema.sql

# Data only (no schema)
vendor/bin/pdodb dump --data-only --output=data.sql

# Dump without DROP TABLE IF EXISTS statements
vendor/bin/pdodb dump --no-drop-tables --output=backup.sql

# Restore from dump file
vendor/bin/pdodb dump restore backup.sql

# Restore without confirmation
vendor/bin/pdodb dump restore backup.sql --force
```

### Options

- `--schema-only` - Dump only schema (CREATE TABLE, indexes, etc.)
- `--data-only` - Dump only data (INSERT statements)
- `--output=<file>` - Write dump to file instead of stdout
- `--no-drop-tables` - Do not add DROP TABLE IF EXISTS before CREATE TABLE (by default, DROP TABLE IF EXISTS is included)
- `--force` - Skip confirmation prompt (for restore)

### Behavior

By default, `pdodb dump` includes `DROP TABLE IF EXISTS` statements before each `CREATE TABLE` statement. This ensures that restoring a dump will replace existing tables. Use the `--no-drop-tables` option to exclude these statements if you want to preserve existing tables.

### Notes

- Dump format is SQL-compatible across all supported dialects
- Schema dumps include CREATE TABLE statements and indexes
- Data dumps use batched INSERT statements for efficiency
- Restore executes SQL statements sequentially with error handling
- Use `--force` with restore to continue on errors (skips failed statements)

## Table Management

Manage tables (create, drop, rename, truncate, inspect structure).

### Usage

```bash
# Summary info
vendor/bin/pdodb table info users --format=json

# List tables
vendor/bin/pdodb table list --format=table

# Existence
vendor/bin/pdodb table exists users

# Create / Drop / Rename / Truncate
vendor/bin/pdodb table create users --columns="id:int, name:string:nullable" --force
vendor/bin/pdodb table drop users --force
vendor/bin/pdodb table rename users users_archive --force
vendor/bin/pdodb table truncate users --force

# Describe columns
vendor/bin/pdodb table describe users --format=yaml

# Columns
vendor/bin/pdodb table columns list users --format=json
vendor/bin/pdodb table columns add users price --type=float
vendor/bin/pdodb table columns alter users price --type=float --nullable
vendor/bin/pdodb table columns drop users price --force

# Indexes
vendor/bin/pdodb table indexes list users --format=json
vendor/bin/pdodb table indexes add users idx_users_name --columns="name" --unique
vendor/bin/pdodb table indexes drop users idx_users_name --force

# Foreign Keys
vendor/bin/pdodb table keys list users --format=json
vendor/bin/pdodb table keys add users fk_users_profile --columns="profile_id" --ref-table=profiles --ref-columns="id"
vendor/bin/pdodb table keys add users fk_users_profile --columns="profile_id" --ref-table=profiles --ref-columns="id" --on-delete=CASCADE --on-update=RESTRICT
vendor/bin/pdodb table keys drop users fk_users_profile --force
vendor/bin/pdodb table keys check
```

#### Foreign Key Management

Manage foreign key constraints:

```bash
# List foreign keys for a table
vendor/bin/pdodb table keys list users --format=json

# Add foreign key (interactive mode if parameters missing)
vendor/bin/pdodb table keys add users fk_users_profile --columns="profile_id" --ref-table=profiles --ref-columns="id"

# Add foreign key with ON DELETE/ON UPDATE actions
vendor/bin/pdodb table keys add users fk_users_profile --columns="profile_id" --ref-table=profiles --ref-columns="id" --on-delete=CASCADE --on-update=RESTRICT

# Drop foreign key (with confirmation)
vendor/bin/pdodb table keys drop users fk_users_profile --force

# Check all foreign key constraints for integrity violations
vendor/bin/pdodb table keys check
```

**Options:**
- `--columns="col1,col2"` - Column(s) in the table (comma-separated for composite keys)
- `--ref-table=table` - Referenced table name
- `--ref-columns="col1,col2"` - Referenced column(s) (comma-separated for composite keys)
- `--on-delete=ACTION` - Action on DELETE (CASCADE, SET NULL, RESTRICT, NO ACTION)
- `--on-update=ACTION` - Action on UPDATE (CASCADE, SET NULL, RESTRICT, NO ACTION)
- `--force` - Skip confirmation (for drop)

**Notes:**
- SQLite does not support ADD FOREIGN KEY via ALTER TABLE. Foreign keys must be defined during CREATE TABLE.
- The `check` command verifies all foreign key constraints across all tables and reports orphaned records.
- Options like ENGINE/CHARSET/COLLATION are dialect-specific and applied where supported.
- If an operation is not supported by a dialect, a typed exception is thrown.

## Configuration

CLI tools automatically detect database configuration from:

1. **`.env` file** in the current working directory (recommended for production)
2. **`config/db.php`** file in the current working directory
3. **Environment variables** (`PDODB_DRIVER`, `PDODB_HOST`, etc.)
4. **Examples config** (for testing only)

You can also explicitly point to custom locations using global options:

```bash
# Use a specific .env file
vendor/bin/pdodb db info --env=/path/to/.env.local

# Use a specific db.php configuration file
vendor/bin/pdodb db info --config=/path/to/db.php
```

Global options available for all commands:

```text
--connection=<name>  Use a named connection from config/db.php
--config=<path>      Path to db.php configuration file
--env=<path>         Path to .env file
```

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

## User Management

Manage database users and their privileges with simple commands. Note that SQLite does not support user management operations.

### Usage

```bash
# Create a user (username and password will be prompted if not provided)
vendor/bin/pdodb user create john
vendor/bin/pdodb user create john --password secret123
vendor/bin/pdodb user create john --password secret123 --host localhost
vendor/bin/pdodb user create

# Drop a user (username will be prompted if not provided, with confirmation)
vendor/bin/pdodb user drop john
vendor/bin/pdodb user drop john --force
vendor/bin/pdodb user drop

# Check if a user exists (username will be prompted if not provided)
vendor/bin/pdodb user exists john
vendor/bin/pdodb user exists

# List all users
vendor/bin/pdodb user list

# Show user information and privileges (username will be prompted if not provided)
vendor/bin/pdodb user info john
vendor/bin/pdodb user info

# Grant privileges to a user
vendor/bin/pdodb user grant john SELECT,INSERT,UPDATE --database myapp
vendor/bin/pdodb user grant john ALL --database myapp --table users
vendor/bin/pdodb user grant john SELECT,INSERT --database myapp --table users

# Revoke privileges from a user
vendor/bin/pdodb user revoke john DELETE --database myapp
vendor/bin/pdodb user revoke john ALL --database myapp --table users

# Change user password (password will be prompted if not provided)
vendor/bin/pdodb user password john
vendor/bin/pdodb user password john --password newpass123
```

### Options

- `--force` - Execute without confirmation (for create/drop)
- `--password <pass>` - Set password (for create/password commands)
- `--host <host>` - Host for MySQL/MariaDB (default: '%')
- `--database <db>` - Database name (for grant/revoke)
- `--table <table>` - Table name (for grant/revoke)

### Examples

#### Create User

```bash
$ vendor/bin/pdodb user create john

Enter password: ********
✓ User 'john@%' created successfully
```

Or with options:

```bash
$ vendor/bin/pdodb user create john --password secret123 --host localhost

✓ User 'john@localhost' created successfully
```

#### Grant Privileges

```bash
$ vendor/bin/pdodb user grant john SELECT,INSERT,UPDATE --database myapp

✓ Granted SELECT,INSERT,UPDATE on myapp.* to 'john@%'
```

#### List Users

```bash
$ vendor/bin/pdodb user list

Users (3):
  root@localhost
  testuser@%
  john@%
```

#### Show User Info

```bash
$ vendor/bin/pdodb user info john

User Information:

  Username: john
  Host: %
  User host: john@%
  Privileges (2):
    - GRANT SELECT, INSERT, UPDATE ON `myapp`.* TO `john`@`%`
    - GRANT USAGE ON *.* TO `john`@`%`
```

### Notes

- **MySQL/MariaDB**: Users are identified by username@host. Use `--host` option to specify host (default: '%').
- **PostgreSQL**: Users are identified by username only. Host option is ignored.
- **MSSQL**: Users are identified by login name. Host option is ignored.
- **SQLite**: User management is not supported and will throw an exception.

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

# Specify namespace for generated model
vendor/bin/pdodb model make User users app/Models --namespace=App\\Entities

# Overwrite without confirmation
vendor/bin/pdodb model make User users app/Models --force

# Use a named connection from config/db.php (global option)
vendor/bin/pdodb model make User users app/Models --connection=reporting
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

### Options

- `--namespace=NS` – Set PHP namespace for the generated class (default: `App\\Models`)
- `--force` – Overwrite existing file without confirmation
- `--connection=NAME` – Use a named connection from `config/db.php` (global option available for all commands)

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

Interactive REPL for testing SQL queries against the database, plus utilities to explain, format, and validate SQL.

### Usage

```bash
# Interactive mode
vendor/bin/pdodb query test

# Execute single query
vendor/bin/pdodb query test "SELECT * FROM users LIMIT 10"

# Explain a SQL query (database-specific EXPLAIN)
vendor/bin/pdodb query explain "SELECT * FROM users WHERE id = 1"

# Format SQL for readability
vendor/bin/pdodb query format "select  *  from users where  id=1 order   by  name"

# Validate SQL syntax (no execution; uses EXPLAIN)
vendor/bin/pdodb query validate "SELECT COUNT(*) FROM users"
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

## Database Monitoring

Monitor database queries, connections, and performance metrics in real-time.

### Usage

```bash
# Monitor active queries
vendor/bin/pdodb monitor queries

# Monitor active queries in real-time (updates every 2 seconds)
vendor/bin/pdodb monitor queries --watch

# Monitor active connections
vendor/bin/pdodb monitor connections

# Monitor active connections in real-time
vendor/bin/pdodb monitor connections --watch

# Monitor slow queries (threshold: 1 second)
vendor/bin/pdodb monitor slow --threshold=1s

# Monitor slow queries with custom threshold and limit
vendor/bin/pdodb monitor slow --threshold=500ms --limit=20

# Show query statistics (requires profiling enabled)
vendor/bin/pdodb monitor stats

# Output in JSON format
vendor/bin/pdodb monitor queries --format=json
```

### Options

- `--watch` - Update in real-time (for queries/connections)
- `--format=table|json` - Output format (default: table)
- `--threshold=TIME` - Slow query threshold (e.g., `1s`, `500ms`, `2.5s`)
- `--limit=N` - Maximum number of slow queries to show (default: 10)

### Examples

#### Monitor Active Queries

```bash
$ vendor/bin/pdodb monitor queries

Queries:
  id    user    host         db      command  time  state  query
  ----------------------------------------------------------------------------
  123   root    localhost    myapp   Query    0     NULL   SELECT * FROM users
  124   app     localhost    myapp   Query    1     NULL   UPDATE orders SET status = 'paid'
```

#### Monitor Active Connections

```bash
$ vendor/bin/pdodb monitor connections

Connections:
  id    user    host         db      command  time  state
  ----------------------------------------------------------------------------
  123   root    localhost    myapp   Sleep    10    NULL
  124   app     localhost    myapp   Query    0     NULL

Summary:
  current: 2
  max: 100
  usage_percent: 2.00
```

#### Monitor Slow Queries

```bash
$ vendor/bin/pdodb monitor slow --threshold=2s --limit=5

Slow queries:
  id    user    host         db      time  query
  ----------------------------------------------------------------------------
  125   app     localhost    myapp   5     SELECT * FROM orders WHERE status = 'pending' ORDER BY created_at
  126   app     localhost    myapp   3     SELECT COUNT(*) FROM users WHERE active = 1
```

#### Query Statistics

```bash
$ vendor/bin/pdodb monitor stats

Stats:
  aggregated:
    total_queries: 150
    total_time: 2.45s
    avg_time: 0.016s
    slow_queries: 3
  by_query:
    - sql: SELECT * FROM users WHERE id = ?
      count: 50
      avg_time: 0.001s
      max_time: 0.002s
```

### Real-time Monitoring

Use the `--watch` option to continuously monitor queries or connections:

```bash
$ vendor/bin/pdodb monitor queries --watch

Monitoring active queries (Press Ctrl+C to exit)
Updated: 2024-01-15 14:30:00

Queries:
  id    user    host         db      command  time  state  query
  ----------------------------------------------------------------------------
  123   root    localhost    myapp   Query    0     NULL   SELECT * FROM users
```

The display updates every 2 seconds. Press `Ctrl+C` to exit.

### Dialect Support

**MySQL/MariaDB:**
- Full support for all monitoring features
- Uses `SHOW PROCESSLIST` and `SHOW STATUS`

**PostgreSQL:**
- Full support for all monitoring features
- Uses `pg_stat_activity` and `pg_stat_statements` (if extension enabled)
- For slow queries, `pg_stat_statements` extension provides better statistics

**MSSQL:**
- Full support for all monitoring features
- Uses `sys.dm_exec_requests`, `sys.dm_exec_sessions`, and `sys.dm_exec_query_stats`

**SQLite:**
- Limited support (no built-in query/connection monitoring at database level)
- `monitor queries` returns empty (SQLite doesn't track active queries)
- `monitor connections` shows PDOdb connection pool only
- `monitor slow` and `monitor stats` require QueryProfiler to be enabled (`$db->enableProfiling()`)

### Notes

- **Query Profiling**: For `monitor stats` and `monitor slow` on SQLite, you must enable profiling in your application code:
  ```php
  $db->enableProfiling(1.0); // Enable with 1 second threshold
  ```

- **PostgreSQL Extensions**: For better slow query statistics on PostgreSQL, install the `pg_stat_statements` extension:
  ```sql
  CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
  ```

- **Real-time Updates**: The `--watch` option uses a 2-second polling interval. For production monitoring, consider using dedicated monitoring tools.

## Cache Management

Manage query result cache using CLI commands for cache statistics and clearing.

### Usage

```bash
# Show cache statistics
vendor/bin/pdodb cache stats

# Show cache statistics in JSON format
vendor/bin/pdodb cache stats --format=json

# Clear all cached query results (interactive)
vendor/bin/pdodb cache clear

# Clear cache without confirmation
vendor/bin/pdodb cache clear --force
```

### Options

- `--format=table|json` - Output format for stats (default: table)
- `--force` - Skip confirmation prompt (for clear)

### Examples

#### Show Cache Statistics

```bash
$ vendor/bin/pdodb cache stats

Cache Statistics
================

Enabled:        Yes
Prefix:         pdodb_
Default TTL:    3600 seconds
Hits:           1250
Misses:         350
Hit Rate:       78.13%
Sets:           350
Deletes:        15
Total Requests: 1600
```

#### Show Cache Statistics (JSON Format)

```bash
$ vendor/bin/pdodb cache stats --format=json

{
    "enabled": true,
    "prefix": "pdodb_",
    "default_ttl": 3600,
    "hits": 1250,
    "misses": 350,
    "hit_rate": 78.13,
    "sets": 350,
    "deletes": 15,
    "total_requests": 1600
}
```

#### Clear Cache

```bash
$ vendor/bin/pdodb cache clear

Are you sure you want to clear all cache? This cannot be undone [y/N]: y
✓ Cache cleared successfully
```

Or with `--force`:

```bash
$ vendor/bin/pdodb cache clear --force

✓ Cache cleared successfully
```

### Statistics Explained

- **Hits** - Number of successful cache retrievals (query result found in cache)
- **Misses** - Number of cache lookups that didn't find a result (query executed)
- **Hit Rate** - Percentage of successful cache hits: `(hits / (hits + misses)) * 100`
- **Sets** - Number of query results stored in cache
- **Deletes** - Number of cache entries deleted
- **Total Requests** - Total number of cache operations (hits + misses)

### Notes

- **Cache Must Be Enabled**: Cache commands require cache to be enabled in your configuration. If cache is disabled, the command will show an error.
- **Statistics Are In-Memory**: Cache statistics are runtime counters that reset when the application restarts. Use this for monitoring cache effectiveness during development and testing.
- **Universal Support**: Cache statistics work with any PSR-16 cache adapter (Filesystem, Redis, APCu, Memcached, Array).
- **Clear Removes All Cache**: The `cache clear` command removes ALL cached query results. This cannot be undone.
- **Statistics Tracking**: Statistics are tracked automatically when using `$db->find()->cache()` methods. Manual cache operations via `$db->cacheManager` also update statistics.

### When to Use

- **Development**: Monitor cache hit rates to optimize cache TTL settings
- **Debugging**: Check cache statistics when investigating performance issues
- **Deployment**: Clear cache after schema changes or data migrations
- **Maintenance**: Periodic cache clearing to ensure fresh data after updates

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
- **`user`** - Manage database users (create, drop, list, grant/revoke privileges, change password)
- **`dump`** - Dump and restore database (schema and data export/import)
- **`migrate`** - Manage database migrations
- **`schema`** - Inspect database schema
- **`query`** - Test SQL queries interactively
- **`model`** - Generate ActiveRecord models
- **`table`** - Manage tables (info, list, exists, create, drop, rename, truncate, describe, columns, indexes, foreign keys)
- **`monitor`** - Monitor database queries, connections, and performance
- **`cache`** - Manage query result cache (clear, statistics)

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
