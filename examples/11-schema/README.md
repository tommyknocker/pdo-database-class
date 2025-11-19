# Schema Management Examples

Examples demonstrating database schema management: DDL operations and migrations.

## Examples

### 01-ddl.php
DDL Query Builder: fluent API for creating and managing database schema.

**Topics covered:**
- Create tables - With columns, indexes, foreign keys
- Alter tables - Add, drop, modify columns
- Indexes - Create and drop indexes
- Foreign keys - Define relationships between tables
- Dialect-specific DDL - Automatic SQL generation for each database

### 02-migrations.php
Database migrations: version-controlled schema changes with rollback support.

**Topics covered:**
- Create migrations - Generate migration files
- Apply migrations - Migrate to latest or specific version
- Rollback - Undo migrations
- History - View applied migrations
- Migration management - Track and manage schema changes

### 03-table-cli.php
Table management via CLI: create, alter, and manage tables using command-line interface.

**Topics covered:**
- Table operations - Create, drop, rename, truncate
- Column management - Add, alter, drop columns
- Index management - Create and drop indexes
- CLI interface - Command-line table operations

### 04-dump-restore.php
Database dump and restore: export and import database schema and data.

**Topics covered:**
- Database dumps - Export schema and data
- Restore operations - Import from dump files
- Schema-only dumps - Export structure without data
- Data-only dumps - Export data without structure

### 05-monitoring.php
Database monitoring: monitor queries, connections, and performance metrics.

**Topics covered:**
- Active queries - Monitor running SQL queries
- Active connections - Monitor database connections
- Slow queries - Identify and analyze slow queries
- Query statistics - View aggregated query performance data
- Real-time monitoring - Watch queries and connections in real-time

## Usage

```bash
# Run examples
php 01-ddl.php
php 02-migrations.php
php 03-table-cli.php
php 04-dump-restore.php
php 05-monitoring.php
```

## Schema Management Features

- **DDL Query Builder** - Fluent API for schema operations
- **Migrations** - Version-controlled schema changes
- **Rollback Support** - Undo migrations when needed
- **Table Management** - CLI commands for table operations
- **Database Dump/Restore** - Export and import database schema and data
- **Database Monitoring** - Monitor queries, connections, and performance
- **Cross-Database** - Works across all supported databases

## Related Examples

- [Basic Examples](../01-basic/) - Basic CRUD operations
- [ActiveRecord](../09-active-record/) - Model-based schema operations
