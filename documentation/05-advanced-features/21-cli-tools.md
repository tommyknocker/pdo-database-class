# CLI Tools

PDOdb provides convenient command-line tools for common development tasks, including database management, migration generation, model generation, schema inspection, seed management, and interactive query testing.

## Table of Contents

- [Overview](#overview)
- [Configuration](#configuration)
- [Project Initialization](#project-initialization)
- [Database Management](#database-management)
- [Connection Management](#connection-management)
- [User Management](#user-management)
- [Database Dump and Restore](#database-dump-and-restore)
- [Table Management](#table-management)
- [Migration Generator](#migration-generator)
- [Model Generator](#model-generator)
- [Repository Generator](#repository-generator)
- [Service Generator](#service-generator)
- [Extended Code Generation](#extended-code-generation)
- [Schema Inspector](#schema-inspector)
- [Query Tester (REPL)](#query-tester-repl)
- [Database Monitoring](#database-monitoring)
- [TUI Dashboard](#tui-dashboard)
- [AI-Powered Analysis](#ai-powered-analysis)
- [Cache Management](#cache-management)
- [Benchmark and Performance Testing](#benchmark-and-performance-testing)
- [Seed Management](#seed-management)
- [Installation](#installation)
- [Usage](#usage)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)

## Overview

The CLI tools are designed to streamline your development workflow:

- **Project Initialization** - Interactive wizard to set up PDOdb configuration
- **Database Management** - Create, drop, list, and check database existence
- **Connection Management** - Test, inspect, and manage database connections
- **User Management** - Create, drop, list, and manage database users and privileges
- **Database Dump and Restore** - Export and import database schema and data
- **Table Management** - Create, drop, rename, truncate, and manage table structure
- **Migration Generator** - Create database migrations with interactive prompts
- **Model Generator** - Generate ActiveRecord models from existing database tables
- **Repository Generator** - Generate repository classes with CRUD operations
- **Service Generator** - Generate service classes for business logic
- **Extended Code Generation** - Generate API endpoints, tests, DTOs, Enum classes, and API documentation
- **Schema Inspector** - Inspect database schema structure
- **Query Tester** - Interactive REPL for testing SQL queries
- **Database Monitoring** - Monitor queries, connections, and performance metrics
- **TUI Dashboard** - Interactive full-screen terminal dashboard for real-time monitoring
- **Cache Management** - Manage query result cache (clear, invalidate, statistics)
- **Benchmark and Performance Testing** - Benchmark queries and operations, compare configurations
- **Database Optimization** - Analyze schema structure, slow queries, and query performance
- **Seed Management** - Create, run, and rollback database seeds for test and initial data

## Database Dump and Restore

Export and import database schema and data with support for compression, automatic naming, and backup rotation.

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

# Automatic naming with timestamp
vendor/bin/pdodb dump --auto-name

# Automatic naming with custom date format
vendor/bin/pdodb dump --auto-name --date-format=Y-m-d

# Compressed dump (gzip)
vendor/bin/pdodb dump --output=backup.sql --compress=gzip

# Compressed dump (bzip2)
vendor/bin/pdodb dump --output=backup.sql --compress=bzip2

# Automatic naming with compression
vendor/bin/pdodb dump --auto-name --compress=gzip

# Backup with rotation (keep last 7 backups)
vendor/bin/pdodb dump --auto-name --rotate=7

# Combined: auto-name, compress, and rotate
vendor/bin/pdodb dump --auto-name --compress=gzip --rotate=30

# Restore from dump file
vendor/bin/pdodb dump restore backup.sql

# Restore from compressed file (auto-detected)
vendor/bin/pdodb dump restore backup.sql.gz

# Restore without confirmation
vendor/bin/pdodb dump restore backup.sql --force
```

### Options

- `--schema-only` - Dump only schema (CREATE TABLE, indexes, etc.)
- `--data-only` - Dump only data (INSERT statements)
- `--output=<file>` - Write dump to file instead of stdout
- `--no-drop-tables` - Do not add DROP TABLE IF EXISTS before CREATE TABLE (by default, DROP TABLE IF EXISTS is included)
- `--compress=<format>` - Compress output using `gzip` or `bzip2` format
- `--auto-name` - Automatically name backup file with timestamp (format: `backup_YYYY-MM-DD_HH-II-SS.sql`)
- `--date-format=<format>` - Custom date format for auto-naming (default: `Y-m-d_H-i-s`)
- `--rotate=<N>` - Keep only N most recent backups, delete older ones
- `--force` - Skip confirmation prompt (for restore)

### Compression

The `--compress` option supports two formats:

- **gzip** - Uses gzip compression (`.gz` extension). Fast compression with good compression ratio.
- **bzip2** - Uses bzip2 compression (`.bz2` extension). Slower but better compression ratio.

Compressed files are automatically detected during restore. The restore command will decompress `.gz` and `.bz2` files automatically.

### Automatic Naming

When using `--auto-name`, backup files are automatically named with a timestamp:

- Default format: `backup_YYYY-MM-DD_HH-II-SS.sql` (e.g., `backup_2024-01-15_14-30-00.sql`)
- Custom format: Use `--date-format` to specify a different date format (e.g., `Y-m-d` for `backup_2024-01-15.sql`)

If compression is enabled, the compression extension is automatically added (e.g., `backup_2024-01-15_14-30-00.sql.gz`).

### Backup Rotation

The `--rotate=<N>` option automatically manages backup files by keeping only the N most recent backups and deleting older ones. This is useful for automated backup scripts to prevent disk space issues.

Rotation works by:
1. Finding all backup files matching the current backup's naming pattern
2. Sorting them by modification time (newest first)
3. Keeping only the N most recent files
4. Deleting all older files

For auto-named files (starting with `backup_`), rotation matches all files with the `backup_*.sql` pattern. For custom-named files, rotation matches files with the same base name.

### Behavior

By default, `pdodb dump` includes `DROP TABLE IF EXISTS` statements before each `CREATE TABLE` statement. This ensures that restoring a dump will replace existing tables. Use the `--no-drop-tables` option to exclude these statements if you want to preserve existing tables.

### Notes

- Dump format is SQL-compatible across all supported dialects
- Schema dumps include CREATE TABLE statements and indexes
- Data dumps use batched INSERT statements for efficiency
- Restore executes SQL statements sequentially with error handling
- Use `--force` with restore to continue on errors (skips failed statements)
- Compressed dumps are automatically decompressed during restore
- Rotation only affects files in the same directory as the current backup
- For production backups, consider using `--auto-name --compress=gzip --rotate=30` to keep 30 days of compressed backups

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

# Count rows
vendor/bin/pdodb table count users

# Show sample data (default: 10 rows, table format)
vendor/bin/pdodb table sample users
vendor/bin/pdodb table sample users --limit=5

# Show sample data in JSON format
vendor/bin/pdodb table sample users --format=json

# Alias for sample
vendor/bin/pdodb table select users --limit=20

# Search for value across all columns
vendor/bin/pdodb table search users "john"
vendor/bin/pdodb table search users "john" --column="name"
vendor/bin/pdodb table search users "example" --limit=10
vendor/bin/pdodb table search users "New York" --json=1
vendor/bin/pdodb table search users "test" --format=json

# Columns
vendor/bin/pdodb table columns list users --format=json
vendor/bin/pdodb table columns add users price --type=float
vendor/bin/pdodb table columns alter users price --type=float --nullable
vendor/bin/pdodb table columns drop users price --force

# Indexes
vendor/bin/pdodb table indexes list users --format=json
vendor/bin/pdodb table indexes add users idx_users_name --columns="name" --unique
vendor/bin/pdodb table indexes drop users idx_users_name --force
vendor/bin/pdodb table indexes suggest users
vendor/bin/pdodb table indexes suggest users --priority=high --format=json

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

#### Index Suggestions

Analyze table structure and get intelligent suggestions for missing indexes:

```bash
# Analyze table and suggest indexes
vendor/bin/pdodb table indexes suggest users

# Filter by priority (high, medium, low, all)
vendor/bin/pdodb table indexes suggest users --priority=high

# Output in JSON format
vendor/bin/pdodb table indexes suggest users --format=json

# Output in YAML format
vendor/bin/pdodb table indexes suggest users --format=yaml
```

**Options:**
- `--format=table|json|yaml` - Output format (default: table)
- `--priority=high|medium|low|all` - Filter suggestions by priority (default: all)

**What It Analyzes:**

The `suggest` command analyzes your table structure and identifies:

1. **Foreign Keys Without Indexes** (High Priority)
   - Foreign key columns that don't have indexes
   - Critical for JOIN performance

2. **Common Patterns** (Medium Priority)
   - Status/enum columns (`status`, `type`, `category`) frequently used in WHERE clauses
   - Soft delete columns (`deleted_at`, `is_deleted`) for filtering active records
   - Composite indexes for common patterns (e.g., `status` + `created_at`)

3. **Timestamp Columns** (Low Priority)
   - Timestamp columns (`created_at`, `updated_at`, `last_login_at`) that may be used for sorting
   - Useful for ORDER BY optimization on large tables

**Example Output:**

```bash
$ vendor/bin/pdodb table indexes suggest users

Analyzing table 'users'...

🔴 High Priority:
  1. Index on (user_id)
     Reason: Foreign key column "user_id" (references profiles.id) without index. Foreign keys should be indexed for JOIN performance.
     SQL: CREATE INDEX idx_users_user_id ON users (user_id);

  2. Index on (deleted_at)
     Reason: Soft delete column "deleted_at" should be indexed for efficient filtering of active records (WHERE deleted_at IS NULL).
     SQL: CREATE INDEX idx_users_deleted_at ON users (deleted_at);

🟡 Medium Priority:
  3. Index on (status, created_at)
     Reason: Common pattern: filtering by status and ordering by created_at. Composite index can optimize both operations.
     SQL: CREATE INDEX idx_users_status_created_at ON users (status, created_at);

ℹ️  Low Priority:
  4. Index on (last_login_at)
     Reason: Timestamp column "last_login_at" may be used for sorting (ORDER BY). Index can improve sorting performance for large tables.
     SQL: CREATE INDEX idx_users_last_login_at ON users (last_login_at);

Total: 4 suggestion(s)
```

**JSON Format:**

```bash
$ vendor/bin/pdodb table indexes suggest users --format=json

{
    "suggestions": [
        {
            "priority": "high",
            "type": "foreign_key",
            "columns": ["user_id"],
            "reason": "Foreign key column \"user_id\" (references profiles.id) without index...",
            "sql": "CREATE INDEX idx_users_user_id ON users (user_id);",
            "index_name": "idx_users_user_id"
        },
        ...
    ]
}
```

**Best Practices:**

1. **Review High Priority First** - Foreign keys and soft delete columns are critical for performance
2. **Consider Your Query Patterns** - The suggestions are based on common patterns, but review against your actual queries
3. **Test Before Applying** - Create indexes in a development environment first and measure performance impact
4. **Monitor Index Usage** - Use `EXPLAIN` to verify indexes are being used after creation

**Notes:**
- The analyzer checks existing indexes to avoid duplicate suggestions
- Suggestions are prioritized based on impact (foreign keys > common patterns > timestamps)
- Index names are automatically generated using the pattern `idx_{table}_{columns}`
- The command works with all supported database dialects

#### Row Count and Sample Data

Get row count and view sample data from tables:

```bash
# Count rows in a table
vendor/bin/pdodb table count users

# Show sample data (default: 10 rows, formatted table)
vendor/bin/pdodb table sample users

# Show sample data with custom limit
vendor/bin/pdodb table sample users --limit=5

# Show sample data in JSON format
vendor/bin/pdodb table sample users --format=json

# Show sample data in YAML format
vendor/bin/pdodb table sample users --format=yaml

# Use select alias (same as sample)
vendor/bin/pdodb table select users --limit=20
```

**Options:**
- `--limit=N` - Number of rows to show (default: 10)
- `--format=table|json|yaml` - Output format (default: table)

**Notes:**
- The `count` command outputs only the number as a plain integer.
- The `sample` command displays data in a formatted table by default (80 characters width).
- Table format automatically adjusts column widths to fit within the terminal width.
- Use `--format=json` or `--format=yaml` for structured output suitable for scripting.
- The `select` command is an alias for `sample` and behaves identically.

#### Table Search

Search for values across all columns in a table or within a specific column:

```bash
# Search across all columns
vendor/bin/pdodb table search users "john"

# Search in specific column
vendor/bin/pdodb table search users "john" --column="name"

# Limit results
vendor/bin/pdodb table search users "example" --limit=10

# Search in JSON/array columns (enabled by default)
vendor/bin/pdodb table search users "New York" --json=1

# Disable JSON/array search
vendor/bin/pdodb table search users "test" --json=0

# Output in JSON format
vendor/bin/pdodb table search users "john" --format=json
```

**Options:**
- `--column=COL` - Search only in specific column (default: all columns)
- `--limit=N` - Maximum number of results (default: 100)
- `--json=0|1` - Search in JSON/array columns (default: 1)
- `--format=table|json|yaml` - Output format (default: table)

**How It Works:**

The `search` command searches for the specified term across all columns in the table (or a specific column if `--column` is provided). It uses SQL `LIKE` (or `ILIKE` for PostgreSQL) for text columns and handles different column types appropriately:

- **Text columns**: Uses `LIKE`/`ILIKE` for pattern matching (case-insensitive on PostgreSQL)
- **Numeric columns**: Tries exact match first, then searches in string representation
- **JSON columns**: Searches in JSON text representation (when `--json=1`)
- **Array columns** (PostgreSQL): Searches in array text representation (when `--json=1`)

**Examples:**

```bash
# Find all users with "john" in any column (name, email, etc.)
vendor/bin/pdodb table search users "john"

# Find users with "example" in email column only
vendor/bin/pdodb table search users "example" --column="email"

# Find users with age 30 (numeric search)
vendor/bin/pdodb table search users "30"

# Find users with "New York" in JSON metadata
vendor/bin/pdodb table search users "New York" --json=1
```

**Notes:**
- Search is case-insensitive on PostgreSQL (uses `ILIKE`), case-sensitive on other databases
- JSON/array search is enabled by default but can be disabled with `--json=0`
- The search uses `OR` conditions across all matching columns
- Results are limited to prevent large result sets (default: 100 rows)
- Use `--format=json` or `--format=yaml` for structured output suitable for scripting

## Configuration

CLI tools automatically detect database configuration from:

1. **`.env` file** in the current working directory (recommended for production)
2. **`config/db.php`** file in the current working directory
3. **Environment variables** (`PDODB_DRIVER`, `PDODB_HOST`, etc.)
4. **Examples config** (for testing only)

**Note:** The `.env` file support is unified across CLI tools and the `PdoDb` class. You can use the same `.env` file with both CLI commands and `PdoDb::fromEnv()` method. See [Configuration - Using .env Files](../01-getting-started/04-configuration.md#using-env-files) for details.

You can also explicitly point to custom locations using global options:

```bash
# Use a specific .env file
vendor/bin/pdodb db info --env=/path/to/.env.local

# Use a specific db.php configuration file
vendor/bin/pdodb db info --config=/path/to/db.php
```

## Project Initialization

The `pdodb init` command provides an interactive wizard to help you quickly set up PDOdb configuration for your project.

### Basic Usage

```bash
# Interactive wizard (recommended for first-time setup)
vendor/bin/pdodb init

# Create .env file only
vendor/bin/pdodb init --env-only

# Create config/db.php only
vendor/bin/pdodb init --config-only

# Skip connection test (useful if database is not yet available)
vendor/bin/pdodb init --skip-connection-test

# Overwrite existing files without confirmation
vendor/bin/pdodb init --force

# Don't create directory structure
vendor/bin/pdodb init --no-structure
```

### Interactive Wizard

The wizard guides you through:

1. **Database Connection Settings**
   - Database driver (mysql, mariadb, pgsql, sqlite, sqlsrv)
   - Host, port, database name, username, password
   - Driver-specific options (charset for MySQL, path for SQLite, etc.)

2. **Configuration Format Selection**
   - `.env` file (environment-based, simple) - Can be used with both CLI tools and `PdoDb::fromEnv()`
   - `config/db.php` (PHP array, more flexible)
   
   **Note:** PDOdb uses either `.env` or `config/db.php`, not both. If `PDODB_*` environment variables are set, `.env` takes priority and `config/db.php` is ignored. The `.env` file format is unified and works with both CLI tools and the `PdoDb::fromEnv()` static method.

3. **Project Structure**
   - Create directory structure (migrations, models, repositories, services, seeds)
   - Set namespace prefix
   - Configure paths for generated files

4. **Advanced Options (Optional)**
   - Table prefix
   - Query result caching (filesystem, Redis, Memcached, APCu)
   - Performance options (query compilation cache, prepared statement pool, connection retry)
   - Multiple database connections (manual configuration in config/db.php)

### Non-Interactive Mode

For automated setups, use environment variables with `PDODB_NON_INTERACTIVE=1`:

```bash
PDODB_NON_INTERACTIVE=1 \
PDODB_DRIVER=mysql \
PDODB_HOST=localhost \
PDODB_PORT=3306 \
PDODB_DATABASE=mydb \
PDODB_USERNAME=user \
PDODB_PASSWORD=pass \
PDODB_CHARSET=utf8mb4 \
vendor/bin/pdodb init --env-only --skip-connection-test --force
```

### Examples

**Quick Start (SQLite):**
```bash
vendor/bin/pdodb init
# Select: sqlite
# Path: ./database.sqlite
# Format: 1 (.env) or 2 (config/db.php)
# Create structure: Yes
```

**Production Setup (MySQL with cache):**
```bash
vendor/bin/pdodb init
# Select: mysql
# Host: db.example.com
# Database: production_db
# Format: 2 (config/db.php - for advanced options)
# Advanced options: Yes
#   - Enable caching: Yes (Redis)
#   - Enable compilation cache: Yes
#   - Enable statement pool: Yes
```

**CI/CD Setup (non-interactive):**
```bash
# .github/workflows/tests.yml
- name: Initialize PDOdb
  run: |
    export PDODB_NON_INTERACTIVE=1
    export PDODB_DRIVER=sqlite
    export PDODB_PATH=:memory:
    vendor/bin/pdodb init --env-only --skip-connection-test --force
```

### Options

| Option | Description |
|--------|-------------|
| `--skip-connection-test` | Skip database connection test during wizard |
| `--force` | Overwrite existing files without confirmation |
| `--env-only` | Create `.env` file only |
| `--config-only` | Create `config/db.php` only |
| `--no-structure` | Don't create directory structure |

**Note:** PDOdb uses either `.env` or `config/db.php`, not both simultaneously. Environment variables from `.env` (if set) take priority over `config/db.php`. The `.env` file format is unified and can be used with both CLI tools and `PdoDb::fromEnv()` method.

### Generated Files

#### `.env` file example:
```env
# Database Configuration
PDODB_DRIVER=mysql
PDODB_HOST=localhost
PDODB_PORT=3306
PDODB_DATABASE=mydb
PDODB_USERNAME=user
PDODB_PASSWORD=secret
PDODB_CHARSET=utf8mb4

# Cache Configuration (if enabled)
PDODB_CACHE_ENABLED=true
PDODB_CACHE_TYPE=filesystem
PDODB_CACHE_DIRECTORY=./storage/cache

# Project Paths
PDODB_MIGRATION_PATH=./migrations
PDODB_MODEL_PATH=./app/Models
PDODB_REPOSITORY_PATH=./app/Repositories
PDODB_SERVICE_PATH=./app/Services
PDODB_SEED_PATH=./database/seeds
```

#### `config/db.php` example:
```php
<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'mydb',
    'username' => 'user',
    'password' => 'secret',
    'charset' => 'utf8mb4',
    
    // Advanced options (if configured)
    'prefix' => 'app_',
    'cache' => [
        'enabled' => true,
        'type' => 'filesystem',
        'directory' => './storage/cache',
    ],
    'compilation_cache' => [
        'enabled' => true,
        'ttl' => 86400,
    ],
];
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
PDODB_REPOSITORY_PATH=/path/to/repositories
PDODB_SERVICE_PATH=/path/to/services
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

## Connection Management

Test, inspect, and manage database connections.

### Usage

```bash
# Test database connection
vendor/bin/pdodb connection test

# Test specific connection
vendor/bin/pdodb connection test --connection=reporting

# Show connection information
vendor/bin/pdodb connection info

# Show specific connection information
vendor/bin/pdodb connection info main
vendor/bin/pdodb connection info main --format=json

# List all available connections
vendor/bin/pdodb connection list
vendor/bin/pdodb connection list --format=json

# Ping database connection (check availability)
vendor/bin/pdodb connection ping
vendor/bin/pdodb connection ping --connection=reporting
```

### Options

- `--connection=<name>` - Use specific connection name
- `--format=table|json|yaml` - Output format (default: table)

### Examples

#### Test Connection

```bash
$ vendor/bin/pdodb connection test

✓ Connection test successful
```

#### Show Connection Information

```bash
$ vendor/bin/pdodb connection info

Connection Information:

Name: default
Driver: mysql
Host: localhost:3306
Database: myapp
Username: user
Status: connected
Server Version: 8.0.35
```

#### List All Connections

```bash
$ vendor/bin/pdodb connection list

Available Connections:

Connection: main (default)
  Driver: mysql
  Host: localhost:3306
  Database: myapp
  Username: user
  Status: connected
  Server Version: 8.0.35

Connection: reporting
  Driver: mysql
  Host: reporting.example.com:3306
  Database: reports
  Username: readonly
  Status: connected
  Server Version: 8.0.35
```

#### Ping Connection

```bash
$ vendor/bin/pdodb connection ping

Ping successful (response time: 2.45ms)
```

### Notes

- The `test` command verifies that a connection can be established and a simple query can be executed.
- The `info` command shows connection parameters (without passwords) and connection status.
- The `list` command shows all available connections from `config/db.php` (if configured).
- The `ping` command measures response time for a simple query, useful for monitoring database availability.
- Connection names are case-sensitive and must match the keys in your `config/db.php` file.

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

**Legacy syntax (still supported):**
```bash
# Auto-detect table name from model name
vendor/bin/pdodb model make User

# Specify table name explicitly
vendor/bin/pdodb model make User users

# Specify output path
vendor/bin/pdodb model make User users app/Models

# Specify namespace for generated model
vendor/bin/pdodb model make User users app/Models --namespace=app\\entities

# Overwrite without confirmation
vendor/bin/pdodb model make User users app/Models --force

# Use a named connection from config/db.php (global option)
vendor/bin/pdodb model make User users app/Models --connection=reporting
```

**Unified syntax (recommended):**
```bash
# Generate model with unified syntax
vendor/bin/pdodb generate model --model=User --table=users

# Specify output path and namespace
vendor/bin/pdodb generate model --model=User --table=users --output=app/Models --namespace=app\\entities

# Overwrite without confirmation
vendor/bin/pdodb generate model --model=User --table=users --force
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

## Repository Generator

Generate repository classes with CRUD operations for models and database tables.

### Usage

**Legacy syntax (still supported):**
```bash
# Auto-detect model name from repository name (UserRepository -> User)
vendor/bin/pdodb repository make UserRepository

# Specify model name explicitly
vendor/bin/pdodb repository make UserRepository User

# Specify output path
vendor/bin/pdodb repository make UserRepository User app/Repositories

# Specify namespaces
vendor/bin/pdodb repository make UserRepository User app/Repositories --namespace=app\\repositories --model-namespace=app\\models

# Overwrite without confirmation
vendor/bin/pdodb repository make UserRepository User app/Repositories --force
```

**Unified syntax (recommended):**
```bash
# Generate repository with unified syntax
vendor/bin/pdodb generate repository --repository=UserRepository --model=User

# Specify table name explicitly
vendor/bin/pdodb generate repository --repository=UserRepository --model=User --table=users

# Specify output path and namespaces
vendor/bin/pdodb generate repository --repository=UserRepository --model=User --output=app/Repositories --namespace=app\\repositories --model-namespace=app\\models

# Overwrite without confirmation
vendor/bin/pdodb generate repository --repository=UserRepository --model=User --force
```

### Example

```bash
$ vendor/bin/pdodb repository make UserRepository User

PDOdb Repository Generator
Database: mysql

✓ Repository file created: UserRepository.php
  Path: /path/to/repositories/UserRepository.php
  Model: App\Models\User
  Table: users
```

### Options

- `--namespace=NS` – Set PHP namespace for the generated repository (default: `App\\Repositories`)
- `--model-namespace=NS` – Set PHP namespace for the model class (default: `App\\Models`)
- `--force` – Overwrite existing file without confirmation
- `--connection=NAME` – Use a named connection from `config/db.php` (global option)

### Generated Repository File

The generated repository includes the following methods:

- `findById($id)` – Find record by primary key
- `findAll($conditions, $orderBy, $limit)` – Find all records with optional conditions
- `findBy($column, $value)` – Find records by column value
- `findOneBy($column, $value)` – Find one record by column value
- `create($data)` – Create new record
- `update($id, $data)` – Update record by primary key
- `delete($id)` – Delete record by primary key
- `exists($id)` – Check if record exists
- `count($conditions)` – Count records with optional conditions

Example generated code:

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use tommyknocker\pdodb\PdoDb;
use App\Models\User;

/**
 * Repository class for User model.
 *
 * Auto-generated by PDOdb Repository Generator
 */
class UserRepository
{
    protected PdoDb $db;

    public function __construct(PdoDb $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        return $this->db->find()
            ->from('users')
            ->where('id', $id)
            ->getOne();
    }

    public function create(array $data): int
    {
        return $this->db->find()
            ->table('users')
            ->insert($data);
    }

    // ... other CRUD methods
}
```

### Output Path

The generator searches for repositories directory in this order:

1. `PDODB_REPOSITORY_PATH` environment variable
2. `app/Repositories` directory
3. `src/Repositories` directory
4. `repositories` directory in current working directory
5. Creates `repositories` directory if none found

## Service Generator

Generate service classes with business logic structure, typically working with repositories.

### Usage

**Legacy syntax (still supported):**
```bash
# Auto-detect repository name from service name (UserService -> UserRepository)
vendor/bin/pdodb service make UserService

# Specify repository name explicitly
vendor/bin/pdodb service make UserService UserRepository

# Specify output path
vendor/bin/pdodb service make UserService UserRepository app/Services

# Specify namespaces
vendor/bin/pdodb service make UserService UserRepository app/Services --namespace=app\\services --repository-namespace=app\\repositories

# Overwrite without confirmation
vendor/bin/pdodb service make UserService UserRepository app/Services --force
```

**Unified syntax (recommended):**
```bash
# Generate service with unified syntax
vendor/bin/pdodb generate service --service=UserService --repository=UserRepository

# Specify output path and namespaces
vendor/bin/pdodb generate service --service=UserService --repository=UserRepository --output=app/Services --namespace=app\\services --repository-namespace=app\\repositories

# Overwrite without confirmation
vendor/bin/pdodb generate service --service=UserService --repository=UserRepository --force
```

### Example

```bash
$ vendor/bin/pdodb service make UserService UserRepository

PDOdb Service Generator
Database: mysql

✓ Service file created: UserService.php
  Path: /path/to/services/UserService.php
  Repository: App\Repositories\UserRepository
```

### Options

- `--namespace=NS` – Set PHP namespace for the generated service (default: `App\\Services`)
- `--repository-namespace=NS` – Set PHP namespace for the repository class (default: `App\\Repositories`)
- `--force` – Overwrite existing file without confirmation
- `--connection=NAME` – Use a named connection from `config/db.php` (global option)

### Generated Service File

The generated service provides a basic structure with:

- Database connection instance (`$db`)
- Repository instance (`$repository`)
- Protected method to access repository (`getRepository()`)
- Example comment showing how to add business logic methods

Example generated code:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use tommyknocker\pdodb\PdoDb;
use App\Repositories\UserRepository;

/**
 * Service class for UserRepository.
 *
 * Auto-generated by PDOdb Service Generator
 */
class UserService
{
    protected PdoDb $db;
    protected UserRepository $repository;

    public function __construct(PdoDb $db, UserRepository $repository)
    {
        $this->db = $db;
        $this->repository = $repository;
    }

    protected function getRepository(): UserRepository
    {
        return $this->repository;
    }

    // TODO: Add your business logic methods here
    // Example:
    //
    // public function createUserWithProfile(array $userData, array $profileData): int
    // {
    //     $this->db->startTransaction();
    //     try {
    //         $userId = $this->repository->create($userData);
    //         $profileData['user_id'] = $userId;
    //         $this->db->find()->table('profiles')->insert($profileData);
    //         $this->db->commit();
    //         return $userId;
    //     } catch (\Exception $e) {
    //         $this->db->rollback();
    //         throw $e;
    //     }
    // }
}
```

### Output Path

The generator searches for services directory in this order:

1. `PDODB_SERVICE_PATH` environment variable
2. `app/Services` directory
3. `src/Services` directory
4. `services` directory in current working directory
5. Creates `services` directory if none found

## Extended Code Generation

The `generate` command provides extended code generation capabilities for models, repositories, services, API endpoints, tests, DTOs, Enum classes, and API documentation. It follows a unified parameter syntax across all subcommands.

> **Note:** The `generate` command now includes `model`, `repository`, and `service` subcommands with unified syntax. The legacy commands (`pdodb model`, `pdodb repository`, `pdodb service`) continue to work for backward compatibility, but the unified syntax is recommended for new projects.

### Usage

```bash
# Show help
vendor/bin/pdodb generate --help

# Generate models, repositories, and services (unified syntax)
vendor/bin/pdodb generate model --model=User --table=users
vendor/bin/pdodb generate repository --repository=UserRepository --model=User
vendor/bin/pdodb generate service --service=UserService --repository=UserRepository

# Generate REST API endpoints
vendor/bin/pdodb generate api --table=users --format=rest
vendor/bin/pdodb generate api --model=User --format=rest

# Generate tests
vendor/bin/pdodb generate tests --model=User --type=unit
vendor/bin/pdodb generate tests --table=users --type=integration
vendor/bin/pdodb generate tests --repository=UserRepository --type=unit

# Generate DTO
vendor/bin/pdodb generate dto --table=users
vendor/bin/pdodb generate dto --model=User

# Generate Enum class from database ENUM column
vendor/bin/pdodb generate enum --table=users --column=status

# Generate API documentation
vendor/bin/pdodb generate docs --table=users --format=openapi
vendor/bin/pdodb generate docs --model=User --format=openapi
```

### Common Options

All generators support the following common options:

- `--table=<name>` - Work with table directly
- `--model=<name>` - Work with model class (if applicable)
- `--namespace=<ns>` - Set PHP namespace (default varies by generator)
- `--output=<path>` - Output directory path
- `--force` - Overwrite existing files without confirmation
- `--connection=<name>` - Use specific database connection (global option)

### Generate API

Generate REST API endpoints/controllers with CRUD operations.

#### Usage

```bash
# Generate from table
vendor/bin/pdodb generate api --table=users --format=rest

# Generate from model
vendor/bin/pdodb generate api --model=User --format=rest

# Specify output path and namespace
vendor/bin/pdodb generate api --table=users --format=rest --output=app/Controllers --namespace=App\\Controllers
```

#### Options

- `--table=<name>` or `--model=<name>` - Required (one of them)
- `--format=rest` - Output format (default: rest)
- `--namespace=<ns>` - Controller namespace (default: `App\\Controllers`)
- `--output=<path>` - Output directory path
- `--force` - Overwrite existing file

#### Generated Controller

The generated controller includes standard REST endpoints:

- `index()` - List all records (GET /table)
- `show($id)` - Get single record (GET /table/{id})
- `store($data)` - Create new record (POST /table)
- `update($id, $data)` - Update record (PUT /table/{id})
- `destroy($id)` - Delete record (DELETE /table/{id})

### Generate Tests

Generate unit or integration tests for models, tables, or repositories.

#### Usage

```bash
# Generate unit tests for model
vendor/bin/pdodb generate tests --model=User --type=unit

# Generate integration tests for table
vendor/bin/pdodb generate tests --table=users --type=integration

# Generate unit tests for repository
vendor/bin/pdodb generate tests --repository=UserRepository --type=unit
```

#### Options

- `--model=<name>`, `--table=<name>`, or `--repository=<name>` - Required (one of them)
- `--type=unit|integration` - Test type (default: unit)
- `--namespace=<ns>` - Test namespace (default: `Tests\\Unit` or `Tests\\Integration`)
- `--output=<path>` - Output directory path
- `--force` - Overwrite existing file

#### Generated Test

The generated test includes:
\
- Basic test structure with PHPUnit
- Setup and teardown methods (for integration tests)
- Example test methods
- Database connection setup (for integration tests)

### Generate DTO

Generate Data Transfer Objects with properties matching table columns.

#### Usage

```bash
# Generate from table
vendor/bin/pdodb generate dto --table=users

# Generate from model
vendor/bin/pdodb generate dto --model=User
```

#### Options

- `--table=<name>` or `--model=<name>` - Required (one of them)
- `--namespace=<ns>` - DTO namespace (default: `App\\DTOs`)
- `--output=<path>` - Output directory path
- `--force` - Overwrite existing file

#### Generated DTO

The generated DTO includes:

- Properties matching table columns with proper types
- Constructor accepting data array
- Getter methods for each property
- `toArray()` method for serialization

### Generate Enum

Generate PHP Enum classes from database ENUM columns.

#### Usage

```bash
# Generate enum from table column
vendor/bin/pdodb generate enum --table=users --column=status
```

#### Options

- `--table=<name>` - Required
- `--column=<name>` - Required (ENUM column name)
- `--namespace=<ns>` - Enum namespace (default: `App\\Enums`)
- `--output=<path>` - Output directory path
- `--force` - Overwrite existing file

#### Generated Enum

The generated enum includes:

- Enum cases for each value in the database ENUM column
- Backed enum with string values
- `values()` method to get all enum values as array
- `cases()` method to get all enum cases

### Generate Docs

Generate API documentation in OpenAPI format.

#### Usage

```bash
# Generate from table
vendor/bin/pdodb generate docs --table=users --format=openapi

# Generate from model
vendor/bin/pdodb generate docs --model=User --format=openapi
```

#### Options

- `--table=<name>` or `--model=<name>` - Required (one of them)
- `--format=openapi` - Output format (default: openapi)
- `--output=<path>` - Output directory path
- `--force` - Overwrite existing file

#### Generated Documentation

The generated OpenAPI specification includes:

- Complete API paths for CRUD operations
- Request/response schemas based on table structure
- Parameter definitions
- Response codes and descriptions

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

## TUI Dashboard

Interactive full-screen terminal dashboard for real-time database monitoring. Provides a k9s/htop-like interface with split panes showing active queries, connection pool, cache statistics, and server metrics.

### Usage

```bash
# Launch TUI Dashboard
vendor/bin/pdodb ui

# Launch with custom refresh interval (5 seconds)
vendor/bin/pdodb ui --refresh=5
```

### Features

The TUI Dashboard displays four panes in a 2x2 grid:

1. **Active Queries** (Top-left) - Currently executing queries with ID, execution time, database, and query text
2. **Connection Pool** (Top-right) - Active connections with summary (current/max/usage percentage)
3. **Cache Stats** (Bottom-left) - Query cache statistics (hits, misses, hit rate, sets, deletes)
4. **Server Metrics** (Bottom-right) - Database server performance metrics (version, uptime, key indicators)

### Navigation

- **1-4** - Switch directly to a specific pane
- **Tab / Arrow Keys** - Navigate between panes
- **q / Esc** - Quit dashboard
- **k** - Kill selected query/connection (future enhancement)

### Options

- `--refresh=<seconds>` - Refresh interval in seconds (default: 2)
- `--help` - Show help message

### Examples

#### Launch Dashboard

```bash
$ vendor/bin/pdodb ui

┌─────────────────────────────┐ ┌─────────────────────────────┐
│ Active Queries              │ │ Connection Pool             │
├─────────────────────────────┤ ├─────────────────────────────┤
│ ID    Time  DB    Query     │ │ Summary:                    │
│ 123   0.5s  myapp SELECT... │ │ Current: 5 / Max: 100       │
│ 124   1.2s  myapp UPDATE... │ │ Usage: 5.0%                 │
└─────────────────────────────┘ └─────────────────────────────┘
┌─────────────────────────────┐ ┌─────────────────────────────┐
│ Cache Stats                 │ │ Server Metrics              │
├─────────────────────────────┤ ├─────────────────────────────┤
│ Status: Enabled             │ │ Version: MySQL 8.0.35       │
│ Type: Redis                 │ │ Uptime: 5d 12h              │
│ Hits: 1,234                 │ │ Connections: 5              │
│ Misses: 456                 │ │ Queries: 12,345             │
│ Hit Rate: 73.02%            │ │ Slow Queries: 3             │
└─────────────────────────────┘ └─────────────────────────────┘
```

### Requirements

- **Interactive Terminal**: TUI Dashboard requires an interactive terminal (TTY). It will exit gracefully in non-interactive mode (CI, scripts).
- **ANSI Support**: Terminal must support ANSI escape codes for colors and cursor control.
- **Unix/Linux/macOS**: Full support. Windows has limited support (use WSL for best experience).

### Dialect Support

All database dialects are supported:

- **MySQL/MariaDB**: Full support for all panes
- **PostgreSQL**: Full support for all panes
- **MSSQL**: Full support for all panes
- **Oracle**: Full support for all panes
- **SQLite**: Limited support (no active queries/connections at database level, uses QueryProfiler for statistics)

### Notes

- **Performance**: Dashboard refreshes every 2 seconds by default. Adjust with `--refresh` option if needed.
- **Color Support**: Colors are automatically disabled if terminal doesn't support them or `NO_COLOR` environment variable is set.
- **Terminal Size**: Dashboard automatically adapts to terminal size. Minimum recommended: 80x24.
- **Non-Interactive Mode**: In CI or non-interactive environments, the command will display a message and exit gracefully.

## Cache Management

Manage query result cache using CLI commands for cache statistics, invalidation, and clearing.

### Usage

```bash
# Show cache statistics
vendor/bin/pdodb cache stats

# Show cache statistics in JSON format
vendor/bin/pdodb cache stats --format=json

# Invalidate cache entries by pattern (interactive)
vendor/bin/pdodb cache invalidate users

# Invalidate cache entries by pattern without confirmation
vendor/bin/pdodb cache invalidate "table:users" --force

# Invalidate cache entries by table prefix
vendor/bin/pdodb cache invalidate "table:users_*" --force

# Clear all cached query results (interactive)
vendor/bin/pdodb cache clear

# Clear cache without confirmation
vendor/bin/pdodb cache clear --force
```

### Options

- `--format=table|json` - Output format for stats (default: table)
- `--force` - Skip confirmation prompt (for clear/invalidate)

### Examples

#### Show Cache Statistics

```bash
$ vendor/bin/pdodb cache stats

Cache Statistics
================

Enabled:        Yes
Type:           Redis
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
    "type": "Redis",
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

#### Invalidate Cache by Table Name

```bash
$ vendor/bin/pdodb cache invalidate users

Are you sure you want to invalidate cache entries matching pattern 'users'? [y/N]: y
✓ Invalidated 25 cache entries matching pattern 'users'
```

#### Invalidate Cache by Table Pattern

```bash
$ vendor/bin/pdodb cache invalidate "table:users" --force

✓ Invalidated 25 cache entries matching pattern 'table:users'
```

#### Invalidate Cache by Table Prefix

```bash
$ vendor/bin/pdodb cache invalidate "table:users_*" --force

✓ Invalidated 50 cache entries matching pattern 'table:users_*'
```

**Note**: Table prefix patterns (`*` wildcards) work only with Redis and Memcached cache backends that support key pattern matching.

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

### Invalidation Patterns

The `cache invalidate` command supports several pattern formats:

1. **Table Name** - `users` - Invalidates all cache entries for the `users` table
2. **Table Prefix** - `table:users` - Same as table name (explicit form)
3. **Table Prefix with Wildcard** - `table:users_*` - Invalidates all cache entries for tables starting with `users_` (requires Redis/Memcached)
4. **Key Pattern** - `pdodb_table_users_*` - Invalidates by cache key pattern (requires Redis/Memcached)

**Examples:**

```bash
# Invalidate all entries for 'users' table
vendor/bin/pdodb cache invalidate users --force

# Invalidate all entries for 'users' table (explicit form)
vendor/bin/pdodb cache invalidate "table:users" --force

# Invalidate all entries for tables starting with 'users_' (Redis/Memcached only)
vendor/bin/pdodb cache invalidate "table:users_*" --force

# Invalidate by key pattern (Redis/Memcached only)
vendor/bin/pdodb cache invalidate "pdodb_table_users_*" --force
```

### Statistics Explained

- **Type** - Cache backend type (Redis, Memcached, APCu, Filesystem, Array, Unknown)
- **Hits** - Number of successful cache retrievals (query result found in cache)
- **Misses** - Number of cache lookups that didn't find a result (query executed)
- **Hit Rate** - Percentage of successful cache hits: `(hits / (hits + misses)) * 100`
- **Sets** - Number of query results stored in cache
- **Deletes** - Number of cache entries deleted
- **Total Requests** - Total number of cache operations (hits + misses)

### Notes

- **Cache Must Be Enabled**: Cache commands require cache to be enabled in your configuration. If cache is disabled, the command will show an error.
- **Persistent Statistics**: Cache statistics are stored in the cache backend itself, so they persist across requests. Statistics include both in-memory counters (current request) and persistent counters (accumulated across all requests).
- **Universal Support**: Cache statistics work with any PSR-16 cache adapter (Filesystem, Redis, APCu, Memcached, Array).
- **Pattern Matching**: Key pattern matching (wildcards) works only with Redis and Memcached. For other cache types, only exact table name matching is supported.
- **Clear Removes All Cache**: The `cache clear` command removes ALL cached query results and statistics. This cannot be undone.
- **Invalidate Removes Matching Entries**: The `cache invalidate` command removes only cache entries matching the specified pattern. Statistics are updated accordingly.
- **Statistics Tracking**: Statistics are tracked automatically when using `$db->find()->cache()` methods. Manual cache operations via `$db->cacheManager` also update statistics.

### When to Use

- **Development**: Monitor cache hit rates to optimize cache TTL settings
- **Debugging**: Check cache statistics when investigating performance issues
- **Deployment**: Clear cache after schema changes or data migrations
- **Maintenance**: Invalidate specific table caches after data updates without clearing all cache
- **Performance**: Use selective invalidation to maintain cache for unchanged tables while refreshing updated tables

## Benchmark and Performance Testing

The benchmark command provides comprehensive performance testing capabilities for your database queries and operations. This feature helps you identify performance bottlenecks, compare different configurations, and generate detailed performance reports.

### Usage

```bash
# Benchmark a specific query
vendor/bin/pdodb benchmark query "SELECT * FROM users WHERE id = :id"

# Benchmark with custom iterations and warmup
vendor/bin/pdodb benchmark query "SELECT * FROM users" --iterations=1000 --warmup=50

# Benchmark CRUD operations on a table
vendor/bin/pdodb benchmark crud users --iterations=1000

# Load testing with concurrent connections
vendor/bin/pdodb benchmark load --connections=50 --duration=60 --query="SELECT 1"

# Compare performance with/without cache
vendor/bin/pdodb benchmark compare --query="SELECT * FROM users" --iterations=100

# Profile queries with detailed statistics
vendor/bin/pdodb benchmark profile --query="SELECT * FROM users" --slow-threshold=100ms --iterations=100

# Generate HTML benchmark report
vendor/bin/pdodb benchmark report --query="SELECT * FROM users" --iterations=100 --output=report.html
```

### Subcommands

#### `benchmark query`

Benchmark a specific SQL query with detailed performance metrics.

**Usage:**
```bash
vendor/bin/pdodb benchmark query "<SQL>" [options]
```

**Options:**
- `--iterations=N` - Number of iterations to run (default: 100)
- `--warmup=N` - Number of warmup iterations before actual benchmark (default: 10)

**Example:**
```bash
vendor/bin/pdodb benchmark query "SELECT * FROM users WHERE id = :id" --iterations=500
```

**Output:**
```
Query Benchmark Results
=======================

Iterations: 500
Total time: 0.1234s
Average time: 0.2468ms
Min time: 0.1234ms
Max time: 0.5678ms
Queries per second: 4051.23
Average memory: 1.23 KB
```

#### `benchmark crud`

Benchmark Create, Read, Update, and Delete operations on a table.

**Usage:**
```bash
vendor/bin/pdodb benchmark crud <table> [options]
```

**Options:**
- `--iterations=N` - Number of iterations for each operation (default: 1000)

**Example:**
```bash
vendor/bin/pdodb benchmark crud users --iterations=500
```

**Output:**
```
CRUD Benchmark Results
=====================

Create:
  Iterations: 500
  Total time: 0.5678s
  Average time: 1.1356ms
  Operations per second: 880.23

Read:
  Iterations: 500
  Total time: 0.1234s
  Average time: 0.2468ms
  Operations per second: 4051.23

Update:
  Iterations: 500
  Total time: 0.3456s
  Average time: 0.6912ms
  Operations per second: 1446.78

Delete:
  Iterations: 500
  Total time: 0.2345s
  Average time: 0.4690ms
  Operations per second: 2132.20
```

#### `benchmark load`

Perform load testing with simulated concurrent connections.

**Usage:**
```bash
vendor/bin/pdodb benchmark load [options]
```

**Options:**
- `--connections=N` - Number of concurrent connections to simulate (default: 10)
- `--duration=N` - Duration in seconds (default: 60)
- `--query=SQL` - SQL query to execute (default: SELECT 1)

**Example:**
```bash
vendor/bin/pdodb benchmark load --connections=50 --duration=30 --query="SELECT * FROM users LIMIT 10"
```

**Output:**
```
Load Testing
============
Connections: 50
Duration: 30s
Query: SELECT * FROM users LIMIT 10

Results:
--------
Total queries: 15234
Errors: 0
Duration: 30.00s
Queries per second: 507.80
Avg query time: 1.97ms
Min query time: 0.12ms
Max query time: 15.67ms
```

#### `benchmark compare`

Compare performance between different configurations (e.g., with/without cache).

**Usage:**
```bash
vendor/bin/pdodb benchmark compare [options]
```

**Options:**
- `--cache` - Enable cache for comparison
- `--no-cache` - Disable cache for comparison
- `--query=SQL` - SQL query to benchmark (default: SELECT 1)
- `--iterations=N` - Number of iterations (default: 100)

**Example:**
```bash
vendor/bin/pdodb benchmark compare --query="SELECT * FROM users" --iterations=100
```

**Output:**
```
Benchmark Comparison
====================

No-cache:
  Iterations: 100
  Total time: 0.1234s
  Average time: 1.2340ms
  Queries per second: 810.46

Cache:
  Iterations: 100
  Total time: 0.0234s
  Average time: 0.2340ms
  Queries per second: 4273.50

Speedup with cache: 5.27x
```

#### `benchmark profile`

Profile queries with detailed statistics and slow query detection.

**Usage:**
```bash
vendor/bin/pdodb benchmark profile [options]
```

**Options:**
- `--query=SQL` - SQL query to profile (required)
- `--iterations=N` - Number of iterations (default: 100)
- `--slow-threshold=TIME` - Slow query threshold (e.g., 100ms, 1s) (default: 100ms)

**Example:**
```bash
vendor/bin/pdodb benchmark profile --query="SELECT * FROM users" --iterations=100 --slow-threshold=50ms
```

**Output:**
```
Query Profile Results
====================

Aggregated Statistics:
  Total queries: 100
  Total time: 0.1234s
  Average time: 1.2340ms
  Min time: 0.1234ms
  Max time: 5.6789ms
  Slow queries (>0.1s): 0

Slowest Queries:
  1. Avg: 5.6789ms, Max: 5.6789ms, Count: 1
     SQL: SELECT * FROM users...
```

#### `benchmark report`

Generate an HTML report with benchmark results and statistics.

**Usage:**
```bash
vendor/bin/pdodb benchmark report [options]
```

**Options:**
- `--query=SQL` - SQL query to benchmark (default: SELECT 1)
- `--iterations=N` - Number of iterations (default: 100)
- `--output=FILE` - Output file path (default: benchmark-report.html)

**Example:**
```bash
vendor/bin/pdodb benchmark report --query="SELECT * FROM users" --iterations=100 --output=users-benchmark.html
```

The generated HTML report includes:
- Query information
- Performance metrics (iterations, total time, average time, QPS)
- Detailed query statistics
- Formatted tables for easy reading

### Best Practices

1. **Use Warmup Iterations**: Always include warmup iterations to account for cold start effects and query plan caching.

2. **Run Multiple Iterations**: Use sufficient iterations (100+) to get statistically meaningful results.

3. **Test Realistic Queries**: Benchmark queries that represent your actual workload, not just simple SELECT 1 queries.

4. **Compare Configurations**: Use `benchmark compare` to evaluate the impact of caching, connection pooling, and other optimizations.

5. **Monitor Slow Queries**: Use `benchmark profile` with appropriate thresholds to identify performance bottlenecks.

6. **Generate Reports**: Use `benchmark report` to create shareable performance reports for documentation and analysis.

### Integration with Development Workflow

Benchmarking can be integrated into your development workflow:

```bash
# Before optimization
vendor/bin/pdodb benchmark query "SELECT * FROM users WHERE email = :email" > before.txt

# After optimization (e.g., adding index)
vendor/bin/pdodb benchmark query "SELECT * FROM users WHERE email = :email" > after.txt

# Compare results
diff before.txt after.txt
```

## Seed Management

Database seeds are classes that populate your database with test or initial data. Seeds are useful for development, testing, and setting up initial application data.

### Overview

Seeds provide a structured way to:

- **Populate test data** for development and testing
- **Set up initial data** like admin users, default categories, or configuration
- **Create reproducible datasets** across different environments
- **Rollback data changes** when needed

### Creating Seeds

#### Using CLI Generator

```bash
# Create a new seed
vendor/bin/pdodb seed create users_data

# This creates: s20240115123456_users_data.php
```

#### Manual Creation

Seeds are stored in the `seeds` directory (configurable via `PDODB_SEED_PATH`). Each seed file follows the naming convention:

```
s{timestamp}_{descriptive_name}.php
```

Example: `s20240115123456_users_data.php`

### Seed Structure

#### Basic Seed Class

```php
<?php

declare(strict_types=1);

use tommyknocker\pdodb\seeds\Seed;

class UsersDataSeed extends Seed
{
    /**
     * Run the seed - insert data.
     */
    public function run(): void
    {
        // Insert single record
        $this->insert('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => password_hash('secret', PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Insert multiple records
        $this->insertMulti('users', [
            [
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'password' => password_hash('secret', PASSWORD_DEFAULT),
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Bob Johnson',
                'email' => 'bob@example.com',
                'password' => password_hash('secret', PASSWORD_DEFAULT),
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Rollback the seed - remove data.
     */
    public function rollback(): void
    {
        // Remove specific records
        $this->delete('users', ['email' => 'john@example.com']);
        $this->delete('users', ['email' => 'jane@example.com']);
        $this->delete('users', ['email' => 'bob@example.com']);
    }
}
```

### Available Methods

#### Data Insertion

```php
// Insert single record
$userId = $this->insert('users', [
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// Insert multiple records
$count = $this->insertMulti('users', [
    ['name' => 'User 1', 'email' => 'user1@example.com'],
    ['name' => 'User 2', 'email' => 'user2@example.com'],
]);

// Batch insert (alias for insertMulti)
$count = $this->insertBatch('users', $users);
```

#### Data Modification

```php
// Update records
$affected = $this->update('users', 
    ['status' => 'active'], 
    ['created_at' => date('Y-m-d')]
);

// Delete records
$affected = $this->delete('users', ['status' => 'inactive']);
```

#### Query Operations

```php
// Use Query Builder
$users = $this->find()
    ->from('users')
    ->where('status', 'active')
    ->get();

// Use Schema Builder
$this->schema()->createTable('temp_table', [
    'id' => $this->schema()->primaryKey(),
    'data' => $this->schema()->text(),
]);

// Execute raw SQL
$results = $this->execute('SELECT COUNT(*) as count FROM users');

// Create raw values
$this->insert('posts', [
    'title' => 'Sample Post',
    'created_at' => $this->raw('NOW()'),
]);
```

### Running Seeds

#### CLI Commands

```bash
# List all seeds with status
vendor/bin/pdodb seed list

# Run all pending seeds
vendor/bin/pdodb seed run

# Run specific seed
vendor/bin/pdodb seed run s20240115123456_users_data

# Dry-run (show SQL without executing)
vendor/bin/pdodb seed run --dry-run

# Pretend mode (show what would be executed)
vendor/bin/pdodb seed run --pretend

# Force run (skip confirmations)
vendor/bin/pdodb seed run --force
```

#### Programmatic Usage

```php
use tommyknocker\pdodb\seeds\SeedRunner;

$runner = new SeedRunner($db, '/path/to/seeds');

// Run all pending seeds
$executed = $runner->run();

// Run specific seed
$executed = $runner->run('s20240115123456_users_data');

// Dry-run mode
$runner->setDryRun(true);
$runner->run();
$queries = $runner->getCollectedQueries();

// Get seed status
$allSeeds = $runner->getAllSeeds();
$newSeeds = $runner->getNewSeeds();
$executedSeeds = $runner->getExecutedSeeds();
```

### Rolling Back Seeds

#### CLI Rollback

```bash
# Rollback last batch
vendor/bin/pdodb seed rollback

# Rollback specific seed
vendor/bin/pdodb seed rollback s20240115123456_users_data
```

#### Programmatic Rollback

```php
// Rollback last batch
$rolledBack = $runner->rollback();

// Rollback specific seed
$rolledBack = $runner->rollback('s20240115123456_users_data');
```

### Advanced Examples

#### Complex Data Relationships

```php
class ProductsDataSeed extends Seed
{
    public function run(): void
    {
        // Create categories first
        $categoryIds = [];
        $categories = ['Electronics', 'Books', 'Clothing'];
        
        foreach ($categories as $category) {
            $categoryIds[$category] = $this->insert('categories', [
                'name' => $category,
                'slug' => strtolower($category),
            ]);
        }

        // Create products with category relationships
        $this->insertMulti('products', [
            [
                'name' => 'Laptop',
                'price' => 999.99,
                'category_id' => $categoryIds['Electronics'],
            ],
            [
                'name' => 'Programming Book',
                'price' => 49.99,
                'category_id' => $categoryIds['Books'],
            ],
        ]);
    }

    public function rollback(): void
    {
        // Delete in reverse order (products first, then categories)
        $this->delete('products', ['name' => 'Laptop']);
        $this->delete('products', ['name' => 'Programming Book']);
        
        $this->delete('categories', ['name' => 'Electronics']);
        $this->delete('categories', ['name' => 'Books']);
        $this->delete('categories', ['name' => 'Clothing']);
    }
}
```

#### Using External Data

```php
class ImportDataSeed extends Seed
{
    public function run(): void
    {
        // Load data from JSON file
        $jsonData = file_get_contents(__DIR__ . '/data/users.json');
        $users = json_decode($jsonData, true);

        // Process and insert data
        $processedUsers = [];
        foreach ($users as $user) {
            $processedUsers[] = [
                'name' => $user['full_name'],
                'email' => $user['email_address'],
                'password' => password_hash($user['password'], PASSWORD_DEFAULT),
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }

        $this->insertMulti('users', $processedUsers);
    }

    public function rollback(): void
    {
        // Load same data to know what to delete
        $jsonData = file_get_contents(__DIR__ . '/data/users.json');
        $users = json_decode($jsonData, true);

        foreach ($users as $user) {
            $this->delete('users', ['email' => $user['email_address']]);
        }
    }
}
```

#### Conditional Seeding

```php
class ConditionalDataSeed extends Seed
{
    public function run(): void
    {
        // Check if admin user already exists
        $adminExists = $this->find()
            ->from('users')
            ->where('email', 'admin@example.com')
            ->exists();

        if (!$adminExists) {
            $this->insert('users', [
                'name' => 'Administrator',
                'email' => 'admin@example.com',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'role' => 'admin',
            ]);
        }

        // Seed different data based on environment
        if (getenv('APP_ENV') === 'development') {
            $this->seedDevelopmentData();
        } elseif (getenv('APP_ENV') === 'testing') {
            $this->seedTestData();
        }
    }

    private function seedDevelopmentData(): void
    {
        // Development-specific data
        $this->insertMulti('users', [
            ['name' => 'Dev User 1', 'email' => 'dev1@example.com'],
            ['name' => 'Dev User 2', 'email' => 'dev2@example.com'],
        ]);
    }

    private function seedTestData(): void
    {
        // Test-specific data
        $this->insert('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    public function rollback(): void
    {
        $this->delete('users', ['email' => 'admin@example.com']);
        $this->delete('users', ['email' => 'dev1@example.com']);
        $this->delete('users', ['email' => 'dev2@example.com']);
        $this->delete('users', ['email' => 'test@example.com']);
    }
}
```

### Configuration

#### Environment Variables

```env
# Seed directory path
PDODB_SEED_PATH=./database/seeds
```

#### Directory Structure

```
project/
├── database/
│   └── seeds/
│       ├── s20240115120000_initial_users.php
│       ├── s20240115121000_categories_data.php
│       └── s20240115122000_products_data.php
└── .env
```

### Seed Tracking

Seeds are tracked in the `__seeds` table:

```sql
CREATE TABLE __seeds (
    id INT PRIMARY KEY AUTO_INCREMENT,
    seed VARCHAR(255) NOT NULL,
    batch INT NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

- **seed** - Seed filename without extension
- **batch** - Batch number for grouping seeds run together
- **executed_at** - When the seed was executed

### Best Practices

#### 1. Descriptive Names

```bash
# Good
vendor/bin/pdodb seed create initial_admin_user
vendor/bin/pdodb seed create product_categories
vendor/bin/pdodb seed create test_customer_data

# Avoid
vendor/bin/pdodb seed create data
vendor/bin/pdodb seed create seed1
```

#### 2. Implement Proper Rollbacks

```php
public function rollback(): void
{
    // Always provide a way to undo the seed
    $this->delete('users', ['email' => 'admin@example.com']);
    
    // Be specific about what to delete
    $this->delete('products', ['created_by_seed' => 'initial_products']);
}
```

#### 3. Use Transactions

Seeds automatically run in transactions, but you can use nested transactions for complex operations:

```php
public function run(): void
{
    $this->db->startTransaction();
    try {
        // Complex multi-table operations
        $userId = $this->insert('users', $userData);
        $this->insert('profiles', ['user_id' => $userId] + $profileData);
        $this->insert('permissions', ['user_id' => $userId] + $permissions);
        
        $this->db->commit();
    } catch (\Exception $e) {
        $this->db->rollback();
        throw $e;
    }
}
```

#### 4. Environment-Specific Seeds

```php
public function run(): void
{
    $env = getenv('APP_ENV') ?: 'production';
    
    switch ($env) {
        case 'development':
            $this->seedDevelopmentData();
            break;
        case 'testing':
            $this->seedTestData();
            break;
        case 'production':
            $this->seedProductionData();
            break;
    }
}
```

#### 5. Idempotent Seeds

Make seeds safe to run multiple times:

```php
public function run(): void
{
    // Check if data already exists
    $adminExists = $this->find()
        ->from('users')
        ->where('email', 'admin@example.com')
        ->exists();
    
    if (!$adminExists) {
        $this->insert('users', [
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);
    }
}
```

### Common Use Cases

#### 1. Initial Application Data

```php
class InitialConfigSeed extends Seed
{
    public function run(): void
    {
        $this->insertMulti('settings', [
            ['key' => 'site_name', 'value' => 'My Application'],
            ['key' => 'admin_email', 'value' => 'admin@example.com'],
            ['key' => 'maintenance_mode', 'value' => 'false'],
        ]);
    }
}
```

#### 2. Test Data for Development

```php
class DevelopmentUsersSeed extends Seed
{
    public function run(): void
    {
        if (getenv('APP_ENV') !== 'development') {
            return; // Only run in development
        }

        for ($i = 1; $i <= 50; $i++) {
            $this->insert('users', [
                'name' => "Test User {$i}",
                'email' => "user{$i}@example.com",
                'password' => password_hash('password', PASSWORD_DEFAULT),
            ]);
        }
    }
}
```

#### 3. Reference Data

```php
class CountriesSeed extends Seed
{
    public function run(): void
    {
        $countries = [
            ['code' => 'US', 'name' => 'United States'],
            ['code' => 'CA', 'name' => 'Canada'],
            ['code' => 'UK', 'name' => 'United Kingdom'],
            // ... more countries
        ];

        $this->insertMulti('countries', $countries);
    }
}
```

### Troubleshooting

#### Seed Not Found

```bash
Error: Seed file not found: /path/to/seeds/s20240115123456_users_data.php
```

**Solution:** Ensure the seed file exists and the path is correct.

#### Class Not Found

```bash
Error: Seed class not found: UsersDataSeed
```

**Solution:** Ensure the class name matches the expected format based on the filename.

#### Permission Denied

```bash
Error: Permission denied when creating seed file
```

**Solution:** Check directory permissions for the seeds directory.

#### Database Connection Error

```bash
Error: Could not connect to database
```

**Solution:** Verify database configuration in `.env` or `config/db.php`.

### Integration with Testing

Seeds are particularly useful in testing scenarios:

```php
// In your test setup
class DatabaseTest extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Run seeds before each test
        $runner = new SeedRunner($this->db, '/path/to/test/seeds');
        $runner->run();
    }
    
    protected function tearDown(): void
    {
        // Rollback seeds after each test
        $runner = new SeedRunner($this->db, '/path/to/test/seeds');
        $runner->rollback();
        
        parent::tearDown();
    }
}
```

Seeds provide a powerful and flexible way to manage test and initial data in your PDOdb applications, ensuring consistent and reproducible database states across different environments.

## Database Optimization

The `optimize` command provides comprehensive database optimization analysis tools to help identify performance issues, structural problems, and optimization opportunities.

### Overview

The optimization tools analyze:
- **Schema Structure** - Missing primary keys, redundant indexes, foreign key issues
- **Query Performance** - Slow queries, missing indexes, execution plan analysis
- **Slow Query Logs** - Parse and analyze MySQL/MariaDB slow query logs
- **Individual Queries** - EXPLAIN analysis with recommendations

### Subcommands

#### `pdodb optimize analyze`

Performs holistic analysis of the entire database schema, checking all tables for common issues.

**Usage:**
```bash
# Analyze entire schema
vendor/bin/pdodb optimize analyze

# Analyze specific schema (PostgreSQL)
vendor/bin/pdodb optimize analyze --schema=public

# Output as JSON
vendor/bin/pdodb optimize analyze --format=json

# Output as YAML
vendor/bin/pdodb optimize analyze --format=yaml
```

**What it checks:**
- Missing primary keys (critical issue)
- Redundant indexes (indexes covered by other indexes)
- Missing foreign key indexes
- Large tables (potential partitioning candidates)
- Overall schema statistics

**Example output:**
```
Schema Analysis Report
=====================

Critical Issues (2):
  ❌ Table 'users' has no PRIMARY KEY
  ❌ Table 'orders' has no PRIMARY KEY

Warnings (5):
  ⚠️  Table 'products': Redundant index 'idx_name' (covered by 'idx_name_status')
  ⚠️  Table 'orders': Missing index on foreign key 'user_id'
  ...

Statistics:
  - Total tables: 25
  - Tables with issues: 7
  - Total indexes: 48
  - Redundant indexes: 3
```

#### `pdodb optimize structure`

Analyzes table structure for optimization opportunities. Can analyze a single table or all tables.

**Usage:**
```bash
# Analyze specific table
vendor/bin/pdodb optimize structure --table=users

# Analyze all tables (same as analyze)
vendor/bin/pdodb optimize structure

# Output as JSON
vendor/bin/pdodb optimize structure --table=users --format=json
```

**What it checks:**
- Primary key presence
- Index structure and redundancy
- Foreign key indexes
- Index suggestions based on common patterns

**Example output:**
```
Table Structure Analysis: 'users'
===============================

Primary Key: ✓ Present (id)

Indexes (5):
  ✓ idx_email (email) - UNIQUE
  ⚠️  idx_name (name) - REDUNDANT (covered by idx_name_status)
  ✓ idx_name_status (name, status)
  ✓ idx_created_at (created_at)
  ⚠️  idx_user_id (user_id) - MISSING (FK column without index)

Foreign Keys (2):
  ✓ fk_user_role (role_id -> roles.id) - has index
  ⚠️  fk_user_company (company_id -> companies.id) - missing index

Suggestions:
  🔴 HIGH: Add index on 'company_id' (foreign key)
  🟡 MEDIUM: Remove redundant index 'idx_name'
  🟢 LOW: Consider index on 'status' for filtering
```

#### `pdodb optimize logs`

Analyzes MySQL/MariaDB slow query logs to identify performance bottlenecks.

**Usage:**
```bash
# Analyze slow query log
vendor/bin/pdodb optimize logs --file=/var/log/mysql/slow.log

# Output as JSON
vendor/bin/pdodb optimize logs --file=/var/log/mysql/slow.log --format=json
```

**What it does:**
- Parses slow query log file
- Groups queries by normalized SQL
- Calculates statistics (count, avg time, max time, total time)
- Sorts by total time (most problematic queries first)
- Provides recommendations based on query patterns

**Example output:**
```
Slow Query Log Analysis
=======================

Top 10 Slowest Queries (by total time):

1. SELECT * FROM orders WHERE user_id = ? AND status = ?
   - Count: 1,234
   - Avg time: 2.5s
   - Max time: 8.3s
   - Total time: 3,085s
   - Recommendation: Add index on (user_id, status)

2. SELECT u.*, o.* FROM users u JOIN orders o ON u.id = o.user_id
   - Count: 456
   - Avg time: 1.2s
   - Recommendation: Check JOIN performance, consider indexes

Summary:
  - Total queries: 5,678
  - Unique queries: 234
  - Queries > 1s: 123
  - Total slow time: 12,345s
```

#### `pdodb optimize query`

Analyzes a single SQL query using EXPLAIN and provides optimization recommendations.

**Usage:**
```bash
# Analyze query
vendor/bin/pdodb optimize query "SELECT * FROM users WHERE email = 'test@example.com'"

# Output as JSON
vendor/bin/pdodb optimize query "SELECT * FROM users WHERE id = 1" --format=json
```

**What it does:**
- Executes EXPLAIN on the query
- Analyzes execution plan
- Detects issues (full table scans, missing indexes, etc.)
- Provides recommendations with severity levels

**Example output:**
```
Query Analysis
==============

SQL: SELECT * FROM users WHERE email = ? AND status = 'active'

EXPLAIN Plan:
  type: ref
  key: idx_email
  rows: 1
  Extra: Using where

Issues:
  ⚠️  Full table scan on 'orders' (JOIN)
  ℹ️  Query requires filesort

Recommendations:
  🔴 HIGH: Add index on orders.user_id (for JOIN)
  🟡 MEDIUM: Consider composite index on (email, status) for better filtering
  🟢 LOW: Add index on ORDER BY column 'created_at'
```

#### `pdodb optimize db`

Analyze database server configuration and provide optimization recommendations based on available resources (memory, CPU cores, workload type, disk type).

**Usage:**

```bash
vendor/bin/pdodb optimize db --memory=5G --cpu-cores=32
```

**Required Options:**

- `--memory=SIZE` - Available memory for database server (e.g., `5G`, `512M`, `1024`)
- `--cpu-cores=N` - Number of CPU cores

**Optional Options:**

- `--workload=TYPE` - Workload type: `oltp` (default), `olap`, or `mixed`
- `--disk-type=TYPE` - Disk type: `ssd` (default), `hdd`, or `nvme`
- `--connections=N` - Expected number of concurrent connections
- `--format=FORMAT` - Output format: `table` (default), `json`, or `yaml`

**Examples:**

```bash
# Basic usage with memory and CPU cores
vendor/bin/pdodb optimize db --memory=5G --cpu-cores=32

# OLAP workload on NVMe storage
vendor/bin/pdodb optimize db --memory=8G --cpu-cores=16 --workload=olap --disk-type=nvme

# With expected connections
vendor/bin/pdodb optimize db --memory=5G --cpu-cores=32 --connections=200

# JSON output
vendor/bin/pdodb optimize db --memory=5G --cpu-cores=32 --format=json
```

**What It Does:**

The `optimize db` command analyzes your database server configuration and provides recommendations for optimal settings based on:

- **Available Memory** - Calculates optimal buffer pool sizes, cache sizes, and memory allocations
- **CPU Cores** - Recommends parallel processing settings, connection limits, and thread configurations
- **Workload Type** - Adjusts recommendations for OLTP (transactional) vs OLAP (analytical) workloads
- **Disk Type** - Optimizes I/O settings for SSD, HDD, or NVMe storage

**Supported Databases:**

- **MySQL/MariaDB**: `innodb_buffer_pool_size`, `max_connections`, `innodb_log_file_size`, `tmp_table_size`, `thread_cache_size`, `table_open_cache`, `innodb_flush_log_at_trx_commit`
- **PostgreSQL**: `shared_buffers`, `effective_cache_size`, `work_mem`, `maintenance_work_mem`, `max_connections`, `random_page_cost`, `effective_io_concurrency`
- **Oracle**: `sga_target`, `pga_aggregate_target`, `db_cache_size`, `shared_pool_size`, `processes`, `sessions`, `parallel_max_servers`
- **MSSQL**: `max server memory`, `min server memory`, `max degree of parallelism`, `cost threshold for parallelism`
- **SQLite**: `cache_size`, `page_size`, `journal_mode`, `synchronous`

**Output:**

The command provides:

1. **Current vs Recommended Settings** - Comparison table showing current values and recommended values with priority levels (high/medium/low)
2. **Summary** - Count of settings by priority and total settings requiring changes
3. **SQL Commands** - Ready-to-use SQL commands to apply the recommendations (review before applying in production)

**Example output:**

```
Database Configuration Optimization Report
==========================================

Server Resources:
  Memory: 5.0 GB
  CPU Cores: 32
  Workload: OLTP
  Disk Type: SSD

Current vs Recommended Settings:
─────────────────────────────────────────────────────────────
Setting                    Current      Recommended   Priority
─────────────────────────────────────────────────────────────
*innodb_buffer_pool_size   1.0 GB       3.5 GB        🔴 HIGH
*max_connections            151          500           🟡 MEDIUM
*innodb_log_file_size      48 MB        256 MB        🟡 MEDIUM
─────────────────────────────────────────────────────────────
* = needs change

Summary:
  🔴 High priority: 1 setting
  🟡 Medium priority: 2 settings
  🟢 Low priority: 2 settings

Total settings requiring changes: 5

SQL to Apply:
─────────────────────────────────────────────────────────────
SET GLOBAL innodb_buffer_pool_size = 3758096384;
SET GLOBAL max_connections = 500;
SET GLOBAL innodb_log_file_size = 268435456;
─────────────────────────────────────────────────────────────

Note: Review and test these commands before applying in production.
Some settings may require server restart.
```

**Note:** Some settings may require server restart. Always review and test recommendations before applying in production.

### Options

| Option | Description | Subcommands |
|--------|-------------|-------------|
| `--format=FORMAT` | Output format: `table`, `json`, or `yaml` | All |
| `--schema=SCHEMA` | Schema name (for analyze) | analyze |
| `--table=TABLE` | Table name (for structure) | structure |
| `--file=FILE` | Slow query log file path | logs |
| `--memory=SIZE` | Available memory (e.g., 5G, 512M) | db |
| `--cpu-cores=N` | Number of CPU cores | db |
| `--workload=TYPE` | Workload type: oltp, olap, mixed | db |
| `--disk-type=TYPE` | Disk type: ssd, hdd, nvme | db |
| `--connections=N` | Expected number of connections | db |

### Integration with Other Tools

The optimization tools integrate seamlessly with other PDOdb CLI commands:

```bash
# 1. Analyze schema
pdodb optimize analyze

# 2. Get index suggestions for specific table
pdodb table indexes suggest users

# 3. Analyze specific query
pdodb optimize query "SELECT * FROM users WHERE status = 'active'"

# 4. Database configuration optimization
pdodb optimize db --memory=5G --cpu-cores=32

# 5. Create suggested indexes
pdodb table indexes add idx_status users --columns=status
```

### Best Practices

1. **Regular Analysis** - Run `optimize analyze` regularly (e.g., in CI/CD) to catch structural issues early
2. **Before Major Releases** - Analyze schema before deploying to production
3. **After Schema Changes** - Run analysis after migrations to ensure no issues were introduced
4. **Slow Query Monitoring** - Regularly analyze slow query logs to identify performance regressions
5. **Query Optimization** - Use `optimize query` when developing new queries to ensure optimal performance
6. **Configuration Tuning** - Use `optimize db` when setting up new servers or after hardware upgrades to optimize database configuration

### Limitations

- **Slow Query Logs** - Currently supports MySQL/MariaDB format only
- **Index Recommendations** - Based on common patterns, may not cover all use cases
- **No AI Integration** - First phase without AI; AI recommendations will be added in future versions

The optimization tools provide a solid foundation for database performance analysis and will be enhanced with AI-powered recommendations in future releases.

## Installation

After installing PDOdb via Composer, the CLI tool is automatically available in `vendor/bin/`:

```bash
composer require tommyknocker/pdo-database-class
```

## Bash Completion

PDOdb includes a bash completion script for enhanced command-line experience. The completion script provides auto-completion for all commands, subcommands, and options.

### Installation

**Option 1: Source directly (temporary for current session)**
```bash
source <(curl -s https://raw.githubusercontent.com/tommyknocker/pdo-database-class/refs/heads/master/scripts/pdodb-completion.bash)
```

**Option 2: Install permanently for your user**
```bash
# Download the completion script
curl -o ~/.pdodb-completion.bash https://raw.githubusercontent.com/tommyknocker/pdo-database-class/refs/heads/master/scripts/pdodb-completion.bash

# Add to your ~/.bashrc or ~/.bash_profile
echo "source ~/.pdodb-completion.bash" >> ~/.bashrc

# Reload your shell configuration
source ~/.bashrc
```

**Option 3: System-wide installation (requires root)**
```bash
# Download to system bash completion directory
sudo curl -o /etc/bash_completion.d/pdodb-completion https://raw.githubusercontent.com/tommyknocker/pdo-database-class/refs/heads/master/scripts/pdodb-completion.bash

# Reload bash completion
source /etc/bash_completion.d/pdodb-completion
```

After installation, bash completion will work automatically when you type `pdodb` or `vendor/bin/pdodb` followed by `<TAB>`. The completion script supports:
- Command and subcommand completion
- Option completion with descriptions
- Context-aware suggestions based on previous arguments

## Usage

PDOdb provides a unified CLI tool with command-based structure (similar to Yii2):

```bash
vendor/bin/pdodb <command> [subcommand] [arguments] [options]
```

### Available Commands

- **`init`** - Project initialization wizard (interactive setup)
- **`db`** - Manage databases (create, drop, list, check existence, show info)
- **`connection`** - Manage database connections (test, info, list, ping)
- **`user`** - Manage database users (create, drop, list, grant/revoke privileges, change password)
- **`dump`** - Dump and restore database (schema and data export/import)
- **`table`** - Manage tables (info, list, exists, create, drop, rename, truncate, describe, columns, indexes, foreign keys)
- **`migrate`** - Manage database migrations
- **`model`** - Generate ActiveRecord models from existing tables
- **`repository`** - Generate repository classes with CRUD operations
- **`service`** - Generate service classes for business logic
- **`schema`** - Inspect database schema structure
- **`query`** - Test SQL queries interactively (REPL)
- **`monitor`** - Monitor database queries, connections, and performance
- **`cache`** - Manage query result cache (clear, invalidate, statistics)
- **`benchmark`** - Benchmark and performance testing
- **`optimize`** - Database optimization analysis (schema, structure, slow queries, query analysis)
- **`ai`** 🤖 - **AI-Powered Analysis** - Get intelligent database optimization recommendations (analyze, query, schema, optimize)
- **`seed`** - Manage database seeds (create, run, list, rollback)
- **`version`** - Show application version (also available as `--version` or `-v` flag)

### Getting Help

```bash
# Show all available commands
vendor/bin/pdodb

# Show application version
vendor/bin/pdodb version
vendor/bin/pdodb --version
vendor/bin/pdodb -v

# Show help for a specific command
vendor/bin/pdodb init --help
vendor/bin/pdodb db --help
vendor/bin/pdodb connection --help
vendor/bin/pdodb user --help
vendor/bin/pdodb dump --help
vendor/bin/pdodb table --help
vendor/bin/pdodb migrate --help
vendor/bin/pdodb model --help
vendor/bin/pdodb repository --help
vendor/bin/pdodb service --help
vendor/bin/pdodb schema --help
vendor/bin/pdodb query --help
vendor/bin/pdodb monitor --help
vendor/bin/pdodb cache --help
vendor/bin/pdodb benchmark --help
vendor/bin/pdodb optimize --help
vendor/bin/pdodb ai --help
vendor/bin/pdodb seed --help
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
