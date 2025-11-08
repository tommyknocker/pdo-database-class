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

## Usage

```bash
# Run examples
php 01-ddl.php
php 02-migrations.php
```

## Schema Management Features

- **DDL Query Builder** - Fluent API for schema operations
- **Migrations** - Version-controlled schema changes
- **Rollback Support** - Undo migrations when needed
- **Cross-Database** - Works across all supported databases

## Related Examples

- [Basic Examples](../01-basic/) - Basic CRUD operations
- [ActiveRecord](../09-active-record/) - Model-based schema operations
