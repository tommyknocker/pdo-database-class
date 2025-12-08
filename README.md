# PDOdb

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-blue.svg)](https://php.net)
[![Latest Version](https://img.shields.io/packagist/v/tommyknocker/pdo-database-class.svg)](https://packagist.org/packages/tommyknocker/pdo-database-class)
[![Tests](https://github.com/tommyknocker/pdo-database-class/actions/workflows/tests.yml/badge.svg)](https://github.com/tommyknocker/pdo-database-class/actions)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)
[![Coverage](https://codecov.io/gh/tommyknocker/pdo-database-class/branch/master/graph/badge.svg)](https://codecov.io/gh/tommyknocker/pdo-database-class)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/tommyknocker/pdo-database-class.svg)](https://packagist.org/packages/tommyknocker/pdo-database-class)
[![GitHub Stars](https://img.shields.io/github/stars/tommyknocker/pdo-database-class?style=social)](https://github.com/tommyknocker/pdo-database-class)

**PDOdb** is a lightweight, framework-agnostic PHP database library providing a **unified API** across MySQL, MariaDB, PostgreSQL, SQLite, Microsoft SQL Server (MSSQL), and Oracle.

Built on top of PDO with **zero external dependencies**, it offers:

**Core Features:**
- **Fluent Query Builder** - Intuitive, chainable API for all database operations
- **Cross-Database Compatibility** - Automatic SQL dialect handling (MySQL, MariaDB, PostgreSQL, SQLite, MSSQL, Oracle)
- **80+ Helper Functions** - SQL helpers for strings, dates, math, JSON, aggregations, and more (REPEAT, REVERSE, LPAD, RPAD emulated for SQLite; REGEXP operations supported across all dialects)

**Performance:**
- **Query Caching** - PSR-16 integration for result caching (10-1000x faster repeated queries)
- **Query Compilation Cache** - Cache compiled SQL strings (10-30% performance improvement)
- **Prepared Statement Pool** - Automatic statement caching with LRU eviction (20-50% faster repeated queries)
- **Query Performance Profiling** - Built-in profiler for tracking execution times, memory usage, and slow query detection

**Advanced Features:**
- **Window Functions** - Advanced analytics with ROW_NUMBER, RANK, LAG, LEAD, running totals, moving averages
- **Common Table Expressions (CTEs)** - WITH clauses for complex queries, recursive CTEs for hierarchical data, materialized CTEs for performance optimization
- **LATERAL JOINs** - Correlated subqueries in FROM clause for PostgreSQL, MySQL, and MSSQL (CROSS APPLY/OUTER APPLY)
- **Set Operations** - UNION, INTERSECT, EXCEPT for combining query results with automatic deduplication
- **JSON Operations** - Native JSON support with consistent API across all databases
- **Full-Text Search** - Cross-database FTS with unified API (MySQL FULLTEXT, PostgreSQL tsvector, SQLite FTS5)
- **Read/Write Splitting** - Horizontal scaling with master-replica architecture and load balancing
- **Sharding** - Horizontal partitioning across multiple databases with automatic query routing (range, hash, modulo strategies)
- **ActiveRecord Pattern** - Optional lightweight ORM for object-based database operations with relationships (hasOne, hasMany, belongsTo, hasManyThrough), eager/lazy loading, and query scopes

**Developer Experience:**
- **🤖 AI-Powered Analysis** - Get intelligent database optimization recommendations using OpenAI, Anthropic, Google, Microsoft, or Ollama. Analyze queries, optimize schema, and get AI-powered suggestions through `explainAiAdvice()` method or CLI commands. Includes MCP server for IDE/agent integration.
- **🖥️ Interactive TUI Dashboard** - Real-time database monitoring with full-screen terminal interface. 8 panes across 2 screens: Active Queries, Connection Pool, Cache Statistics, Server Metrics, Schema Browser, Migration Manager, Server Variables, and SQL Scratchpad. Features include global search filter, query inspection, performance tracking, query management, and keyboard navigation. Launch with `pdodb ui`
- **CLI Tools** - Database management, user management, dump/restore, migration generator, seed generator, model generator, schema inspector, interactive query tester (REPL), AI analysis commands
- **Enhanced EXPLAIN** - Automatic detection of full table scans, missing indexes, and optimization recommendations
- **Exception Hierarchy** - Typed exceptions for precise error handling
- **Enhanced Error Diagnostics** - Query context, sanitized parameters, and debug information in exceptions
- **SQL Formatter/Pretty Printer** - Human-readable SQL output for debugging with indentation and line breaks
- **Query Debugging** - Comprehensive debug information and query inspection tools
- **PSR-14 Event Dispatcher** - Event-driven architecture for monitoring, auditing, and middleware
- **Plugin System** - Extend PdoDb with custom plugins for macros, scopes, and event listeners

**Production Ready:**
- **Fully Tested** - 3806 tests, 12180 assertions across all dialects
- **Type-Safe** - PHPStan level 8 validated, PSR-12 compliant
- **Zero Memory Leaks** - Production-tested memory management with automatic cursor cleanup
- **Connection Retry** - Automatic retry with exponential backoff
- **Transactions & Locking** - Full transaction support with table locking and savepoints for nested transactions
- **Batch Processing** - Memory-efficient generators for large datasets with zero memory leaks

**Additional Capabilities:**
- **Bulk Operations** - CSV/XML/JSON loaders, multi-row inserts, UPSERT support
- **INSERT ... SELECT** - Fluent API for copying data between tables with QueryBuilder, subqueries, and CTE support
- **UPDATE/DELETE with JOIN** - Update and delete operations with JOIN clauses (MySQL/MariaDB/PostgreSQL/MSSQL)
- **MERGE Statements** - INSERT/UPDATE/DELETE based on match conditions (PostgreSQL/MSSQL native, MySQL/SQLite emulated)
- **Schema Introspection** - Query indexes, foreign keys, and constraints programmatically
- **DDL Query Builder** - Production-ready fluent API for creating, altering, and managing database schema (tables, columns, indexes, foreign keys, constraints) with Yii2-style methods, partial indexes, fulltext/spatial indexes, cross-dialectal support, and **dialect-specific types** (MySQL ENUM/SET, PostgreSQL UUID/JSONB/arrays, MSSQL UNIQUEIDENTIFIER/NVARCHAR, SQLite type affinity)
- **Database Migrations** - Version-controlled schema changes with rollback support (Yii2-inspired)
- **Database Seeds** - Populate database with initial or test data, batch tracking, rollback support
- **Advanced Pagination** - Full, simple, and cursor-based pagination with metadata
- **Export Helpers** - Export results to JSON, CSV, and XML formats
- **DISTINCT & DISTINCT ON** - Remove duplicates with full PostgreSQL DISTINCT ON support
- **FILTER Clause** - Conditional aggregates (SQL:2003 standard) with automatic MySQL fallback to CASE WHEN

Inspired by [ThingEngineer/PHP-MySQLi-Database-Class](https://github.com/ThingEngineer/PHP-MySQLi-Database-Class) and [Yii2 framework](https://github.com/yiisoft/yii2-framework)

---

## Why PDOdb?

**Perfect for:**
- ✅ **Beginners** - Simple, intuitive API with zero configuration needed
- ✅ **Cross-database projects** - Switch between MySQL, PostgreSQL, SQLite, MSSQL, Oracle without code changes
- ✅ **Performance-critical apps** - Built-in caching, query optimization, profiling
- ✅ **Database monitoring** - Interactive TUI Dashboard for real-time monitoring (`pdodb ui`)
- ✅ **Modern PHP** - Type-safe, PSR-compliant, PHP 8.4+ features

**vs. Raw PDO:**
- ✅ Fluent query builder instead of manual SQL strings
- ✅ Automatic parameter binding (SQL injection protection built-in)
- ✅ Cross-database compatibility out of the box
- ✅ Helper functions for common operations

**vs. Eloquent/Doctrine:**
- ✅ Zero dependencies (no framework required)
- ✅ Lightweight (no ORM overhead)
- ✅ Direct SQL access when needed
- ✅ Better performance for complex queries
- ✅ Optional ActiveRecord pattern available
- ✅ **Built-in TUI Dashboard** - Real-time database monitoring without external tools

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [📖 Documentation](#-documentation)
- [📚 Examples](#-examples)
- [Quick Example](#quick-example)
- [5-Minute Tutorial](#5-minute-tutorial)
- [Configuration](#configuration)
- [Quick Start](#quick-start)
  - [AI-Powered Query Analysis](#-ai-powered-query-analysis)
- [JSON Operations](#json-operations)
- [Advanced Usage](#advanced-usage)
- [CLI Tools](#cli-tools)
- [Error Handling](#error-handling)
- [Performance Tips](#performance-tips)
- [Helper Functions](#helper-functions)
- [API Reference](#api-reference)
- [Dialect Differences](#dialect-differences)
- [Frequently Asked Questions](#frequently-asked-questions)
- [Migration Guide](#migration-guide)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

---

## Requirements

- **PHP**: 8.4 or higher
- **PDO Extensions**:
  - `pdo_mysql` for MySQL/MariaDB
  - `pdo_pgsql` for PostgreSQL
  - `pdo_sqlite` for SQLite
  - `sqlsrv` for Microsoft SQL Server (requires Microsoft ODBC Driver for SQL Server)
  - `oci` for Oracle Database
- **Supported Databases**:
  - MySQL 5.7+ / MariaDB 10.3+
  - PostgreSQL 9.4+
  - SQLite 3.38+
  - Microsoft SQL Server 2019+ / Azure SQL Database
  - Oracle Database 12c+

Check if your SQLite has JSON support:
```bash
sqlite3 :memory: "SELECT json_valid('{}')"
```

---

## Installation

Install via Composer:

```bash
composer require tommyknocker/pdo-database-class
```

For specific versions:

```bash
# Latest 2.x version
composer require tommyknocker/pdo-database-class:^2.0

# Latest 1.x version
composer require tommyknocker/pdo-database-class:^1.0

# Development version
composer require tommyknocker/pdo-database-class:dev-master
```

### Quick Setup with `pdodb init`

**Fastest way to get started:** Use the interactive wizard to configure your project:

```bash
vendor/bin/pdodb init
```

The wizard will guide you through:
- Database connection settings (MySQL, PostgreSQL, SQLite, MSSQL, Oracle)
- Configuration file format (`.env` or `config/db.php`)
- Directory structure creation (migrations, models, repositories, services)
- Connection testing
- Advanced options (caching, table prefix, multiple connections)

See [CLI Tools Documentation](documentation/05-advanced-features/21-cli-tools.md#project-initialization-pdodb-init) for more details.

---

## 📖 Documentation

Complete documentation is available in the [`documentation/`](documentation/) directory with 56+ detailed guides covering all features:

- **[Getting Started](documentation/01-getting-started/)** - Installation, configuration, your first connection
- **[Core Concepts](documentation/02-core-concepts/)** - Connection management, query builder, parameter binding, dialects
- **[Query Builder](documentation/03-query-builder/)** - SELECT, DML, filtering, joins, aggregations, subqueries
- **[JSON Operations](documentation/04-json-operations/)** - Working with JSON across all databases
- **[Advanced Features](documentation/05-advanced-features/)** - Transactions, batch processing, bulk operations, UPSERT, query scopes, query macros, plugin system
- **[Error Handling](documentation/06-error-handling/)** - Exception hierarchy, enhanced error diagnostics with query context, retry logic, logging, monitoring
- **[Helper Functions](documentation/07-helper-functions/)** - Complete reference for all helper functions
- **[Best Practices](documentation/08-best-practices/)** - Security, performance, memory management, code organization
- **[API Reference](documentation/09-reference/)** - Complete API documentation
- **[Cookbook](documentation/10-cookbook/)** - Common patterns, real-world examples, troubleshooting

Each guide includes working code examples, dialect-specific notes, security considerations, and best practices.

Start here: [Documentation Index](documentation/README.md)

## 📚 Examples

Comprehensive, runnable examples are available in the [`examples/`](examples/) directory:

- **[Basic](examples/01-basic/)** - Connection, CRUD, WHERE conditions
- **[Intermediate](examples/02-intermediate/)** - JOINs, aggregations, pagination, transactions, savepoints
- **[Advanced](examples/03-advanced/)** - Connection pooling, bulk operations, UPSERT, subqueries, MERGE, window functions, CTEs
- **[JSON Operations](examples/04-json/)** - Complete guide to JSON features
- **[Helper Functions](examples/05-helpers/)** - String, math, date/time, NULL, comparison helpers
- **[Performance](examples/07-performance/)** - Query caching, compilation cache, profiling, EXPLAIN analysis
- **[ActiveRecord](examples/09-active-record/)** - Object-based operations, relationships, scopes

Each example is self-contained with setup instructions. See [`examples/README.md`](examples/README.md) for the full catalog.

**Quick start:**
```bash
cd examples

# SQLite (ready to use, no setup required)
php 01-basic/02-simple-crud.php

# MySQL (update config.mysql.php with your credentials)
PDODB_DRIVER=mysql php 01-basic/02-simple-crud.php

# Test all examples on all available databases
./scripts/test-examples.sh
```

---

## Quick Example

**Fastest start:** Run `vendor/bin/pdodb init` for interactive setup, or get started manually:

```php
use tommyknocker\pdodb\PdoDb;

// Connect (SQLite - no setup needed!)
$db = new PdoDb('sqlite', ['path' => ':memory:']);

// Create table
$db->rawQuery('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    email TEXT,
    age INTEGER
)');

// Insert
$id = $db->find()->table('users')->insert([
    'name' => 'John',
    'email' => 'john@example.com',
    'age' => 30
]);

// Query
$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->get();

// Update
$db->find()
    ->table('users')
    ->where('id', $id)
    ->update(['age' => 31]);
```

**That's it!** No configuration, no dependencies, just works.

---

## 5-Minute Tutorial

### Step 1: Install

```bash
composer require tommyknocker/pdo-database-class
```

### Step 2: Initialize Project

Use the interactive wizard:

```bash
vendor/bin/pdodb init
```

**Alternative:** Manual configuration

```php
use tommyknocker\pdodb\PdoDb;

// SQLite (easiest - no database server needed)
$db = new PdoDb('sqlite', ['path' => ':memory:']);

// Or MySQL/PostgreSQL
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'mydb',
    'username' => 'user',
    'password' => 'pass'
]);
```

### Step 3: Create Table

```php
// Simple approach (raw SQL)
$db->rawQuery('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    email TEXT,
    age INTEGER
)');

// Or use DDL Query Builder
$db->schema()->createTable('products', [
    'id' => $db->schema()->primaryKey(),
    'name' => $db->schema()->string(255)->notNull(),
    'status' => $db->schema()->enum(['draft', 'published', 'archived'])
        ->defaultValue('draft'),
    'created_at' => $db->schema()->timestamp()->defaultExpression('CURRENT_TIMESTAMP')
]);
```

### Step 4: CRUD Operations

```php
// Create
$id = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'age' => 30
]);

// Read
$users = $db->find()->from('users')->get();
$user = $db->find()->from('users')->where('id', $id)->getOne();

// Update
$db->find()->table('users')
    ->where('id', $id)
    ->update(['name' => 'Bob']);

// Delete
$db->find()->table('users')->where('id', $id)->delete();
```

### Step 5: Monitor Your Database

Launch the interactive TUI Dashboard to monitor your database in real-time:

```bash
vendor/bin/pdodb ui
```

Monitor active queries, connection pool, cache statistics, and server metrics. Press `h` for help, `q` to quit.

**Next:** See [Quick Start](#quick-start) for more examples.

---

## Configuration

### Basic Configuration

```php
use tommyknocker\pdodb\PdoDb;

// MySQL
$db = new PdoDb('mysql', [
    'host' => '127.0.0.1',
    'username' => 'testuser',
    'password' => 'testpass',
    'dbname' => 'testdb',
    'port' => 3306,
    'charset' => 'utf8mb4',
]);

// PostgreSQL
$db = new PdoDb('pgsql', [
    'host' => '127.0.0.1',
    'username' => 'testuser',
    'password' => 'testpass',
    'dbname' => 'testdb',
    'port' => 5432,
]);

// SQLite
$db = new PdoDb('sqlite', [
    'path' => '/path/to/database.sqlite',  // or ':memory:' for in-memory
]);

// MSSQL
$db = new PdoDb('sqlsrv', [
    'host' => 'localhost',
    'username' => 'testuser',
    'password' => 'testpass',
    'dbname' => 'testdb',
    'port' => 1433,
]);
```

### Advanced Configuration

**Connection Pooling:**
```php
$db = new PdoDb();
$db->addConnection('mysql_main', ['driver' => 'mysql', ...]);
$db->addConnection('pgsql_analytics', ['driver' => 'pgsql', ...]);
$db->connection('mysql_main')->find()->from('users')->get();
```

**Read/Write Splitting:**
```php
$db->enableReadWriteSplitting(new RoundRobinLoadBalancer());
$db->addConnection('master', [...], ['type' => 'write']);
$db->addConnection('replica-1', [...], ['type' => 'read']);
// SELECTs automatically go to replicas, DML to master
```

**Query Caching:**
```php
$cache = CacheFactory::create(['type' => 'filesystem', 'directory' => '/var/cache']);
$db = new PdoDb('mysql', $config, [], null, $cache);
$users = $db->find()->from('users')->cache(3600)->get();
```

See [Configuration Documentation](documentation/01-getting-started/04-configuration.md) for complete configuration options.

---

## Quick Start

**Note**: All query examples start with `$db->find()` which returns a `QueryBuilder` instance.

### 🤖 AI-Powered Query Analysis

Get intelligent optimization recommendations for your queries using AI:

```php
use tommyknocker\pdodb\PdoDb;

$db = PdoDb::fromEnv();

// Configure AI (or use environment variables: PDODB_AI_OPENAI_KEY, etc.)
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'mydb',
    'username' => 'user',
    'password' => 'pass',
    'ai' => [
        'provider' => 'openai',
        'openai_key' => 'sk-...',
    ],
]);

// Get AI-enhanced analysis
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explainAiAdvice(tableName: 'users', provider: 'openai');

// Access base analysis (traditional EXPLAIN)
foreach ($result->baseAnalysis->issues as $issue) {
    echo "Issue: {$issue->message}\n";
}

// Access AI recommendations
echo "AI Analysis:\n";
echo $result->aiAnalysis . "\n";
```

Or use CLI:

```bash
# Set API key
export PDODB_AI_OPENAI_KEY=sk-...
export PDODB_AI_OPENAI_MODEL=gpt-4o-mini  # Optional: gpt-4, gpt-3.5-turbo
export PDODB_AI_GOOGLE_MODEL=gemini-2.5-flash  # Optional: gemini-2.5-pro, gemini-2.0-flash-001, gemini-flash-latest

# Analyze query
pdodb ai analyze "SELECT * FROM users WHERE email = 'user@example.com'" \
    --provider=openai \
    --table=users
```

**Supported Providers:** OpenAI, Anthropic, Google, Microsoft, DeepSeek, Ollama (local, no API key)

**Model Selection:** Configure models via environment variables (`PDODB_AI_<PROVIDER>_MODEL`) or config array (`ai.providers.<provider>.model`)

See [AI Analysis Documentation](documentation/05-advanced-features/23-ai-analysis.md) for complete guide.

### Basic CRUD Operations

```php
// SELECT
$user = $db->find()
    ->from('users')
    ->where('id', 10)
    ->getOne();

$users = $db->find()
    ->from('users')
    ->where('age', 18, '>=')
    ->get();

// INSERT
$id = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'age' => 30
]);

// UPDATE
$db->find()
    ->table('users')
    ->where('id', $id)
    ->update(['age' => 31]);

// DELETE
$db->find()
    ->table('users')
    ->where('id', $id)
    ->delete();
```

### Filtering and Joining

```php
use tommyknocker\pdodb\helpers\Db;

// WHERE conditions
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('age', 18, '>')
    ->andWhere(Db::like('email', '%@example.com'))
    ->get();

// JOIN and GROUP BY
$stats = $db->find()
    ->from('users AS u')
    ->select(['u.id', 'u.name', 'total' => Db::sum('o.amount')])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->groupBy('u.id')
    ->having(Db::sum('o.amount'), 1000, '>')
    ->get();
```

### Transactions

```php
$db->startTransaction();
try {
    $userId = $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->find()->table('orders')->insert(['user_id' => $userId, 'total' => 100]);
    $db->commit();
} catch (\Exception $e) {
    $db->rollback();
}
```

See [Query Builder Documentation](documentation/03-query-builder/) for more examples.

---

## JSON Operations

PDOdb provides a unified JSON API that works consistently across all databases.

```php
use tommyknocker\pdodb\helpers\Db;

// Create JSON data
$db->find()->table('users')->insert([
    'name' => 'John',
    'meta' => Db::jsonObject(['city' => 'NYC', 'age' => 30]),
    'tags' => Db::jsonArray('php', 'mysql', 'docker')
]);

// Query JSON
$adults = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->get();

// Extract JSON values
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'city' => Db::jsonGet('meta', ['city'])
    ])
    ->get();

// Update JSON
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'meta' => Db::jsonSet('meta', ['city'], 'London')
    ]);
```

See [JSON Operations Documentation](documentation/04-json-operations/) for complete guide.

---

## Advanced Usage

### Raw Queries

```php
$users = $db->rawQuery(
    'SELECT * FROM users WHERE age > :age',
    ['age' => 18]
);
```

### Subqueries

```php
$users = $db->find()
    ->from('users')
    ->whereIn('id', function($query) {
        $query->from('orders')
            ->select('user_id')
            ->where('total', 1000, '>');
    })
    ->get();
```

### Pagination

```php
// Full pagination (with total count)
$result = $db->find()
    ->from('posts')
    ->orderBy('created_at', 'DESC')
    ->paginate(20, 1);

// Cursor pagination (most efficient)
$result = $db->find()
    ->from('posts')
    ->orderBy('id', 'DESC')
    ->cursorPaginate(20);
```

### Batch Processing

```php
// Process in batches
foreach ($db->find()->from('users')->batch(100) as $batch) {
    foreach ($batch as $user) {
        processUser($user);
    }
}

// Stream results (minimal memory)
foreach ($db->find()->from('users')->stream() as $user) {
    processUser($user);
}
```

### Query Caching

```php
// Cache for 1 hour
$products = $db->find()
    ->from('products')
    ->where('category', 'Electronics')
    ->cache(3600)
    ->get();
```

See [Advanced Features Documentation](documentation/05-advanced-features/) for complete guide.

---

## CLI Tools

PDOdb provides convenient command-line tools for common development tasks:

```bash
vendor/bin/pdodb <command> [subcommand] [arguments] [options]
```

### 🖥️ Interactive TUI Dashboard

**Monitor your database in real-time** with a beautiful full-screen terminal interface:

```bash
vendor/bin/pdodb ui
```

**Features:**
- 📊 **8 Monitoring Panes** - Active Queries, Connection Pool, Cache Statistics, Server Metrics, Schema Browser, Migration Manager, Server Variables, SQL Scratchpad
- 🔍 **Global Search** - Press `/` to filter tables and server variables in real-time (case-insensitive)
- 📋 **Schema Browser** - Navigate database schema, view tables, columns, indexes, and foreign keys with tree navigation
- 🔧 **Migration Manager** - View migration status, run pending migrations, rollback last migration, create new migrations
- 📈 **Server Variables** - Browse all server configuration variables with highlighting for important performance settings
- 💻 **SQL Scratchpad** - Interactive SQL editor with query history, autocomplete, transaction mode, and result viewing
- 🔍 **Query inspection** - View full query text, execution time, user, database in detail view
- ⚡ **Performance tracking** - Cache hit rates, connection counts, server uptime, query execution times
- 🛑 **Query management** - Kill long-running queries and connections with a single keystroke
- ⌨️ **Keyboard navigation** - Switch between 8 panes (1-8 keys), navigate screens (Tab/arrows), fullscreen mode, refresh intervals
- 🎨 **Color-coded metrics** - Visual indicators for performance and health
- 📱 **Dual-screen support** - Navigate between two screens (panes 1-4 and 5-8)

Perfect for debugging, monitoring production databases, understanding query patterns, and managing database schema.

### Available Commands

- **`ui`** ⭐ - **Interactive TUI Dashboard** - Real-time database monitoring (full-screen terminal interface)
- **`ai`** 🤖 - **AI-Powered Analysis** - Get intelligent database optimization recommendations using OpenAI, Anthropic, Google, Microsoft, or Ollama
- **`db`** - Manage databases (create, drop, list, check existence)
- **`user`** - Manage database users (create, drop, grant/revoke privileges)
- **`dump`** - Dump and restore database (with compression, auto-naming, rotation)
- **`migrate`** - Manage database migrations
- **`schema`** - Inspect database schema
- **`query`** - Test SQL queries interactively (REPL)
- **`model`** - Generate ActiveRecord models
- **`table`** - Manage tables (info, create, drop, truncate)
- **`monitor`** - Monitor database queries and performance
- **`optimize`** - Database optimization analysis (analyze, structure, logs, query, db)

### Bash Completion

Install bash completion for enhanced CLI experience:

```bash
# Temporary (current session)
source <(curl -s https://raw.githubusercontent.com/tommyknocker/pdo-database-class/refs/heads/master/scripts/pdodb-completion.bash)

# Permanent
curl -o ~/.pdodb-completion.bash https://raw.githubusercontent.com/tommyknocker/pdo-database-class/refs/heads/master/scripts/pdodb-completion.bash
echo "source ~/.pdodb-completion.bash" >> ~/.bashrc
```

### Examples

```bash
# 🖥️ Launch interactive TUI Dashboard (real-time monitoring)
vendor/bin/pdodb ui

# 🤖 AI-powered analysis (requires API keys or Ollama)
vendor/bin/pdodb ai analyze "SELECT * FROM users WHERE email = 'user@example.com'" --provider=openai
vendor/bin/pdodb ai query "SELECT * FROM orders" --provider=anthropic --table=orders
vendor/bin/pdodb ai schema --table=users --provider=ollama

# Create database
vendor/bin/pdodb db create myapp

# Dump with compression and rotation
vendor/bin/pdodb dump --auto-name --compress=gzip --rotate=7

# Create migration
vendor/bin/pdodb migrate create create_users_table

# Generate model
vendor/bin/pdodb model make User users app/Models
```

See [CLI Tools Documentation](documentation/05-advanced-features/21-cli-tools.md) for complete guide.

---

## Error Handling

PDOdb provides a comprehensive exception hierarchy for better error handling:

```php
use tommyknocker\pdodb\exceptions\{
    DatabaseException,
    ConnectionException,
    QueryException,
    ConstraintViolationException,
    TransactionException
};

try {
    $users = $db->find()->from('users')->get();
} catch (ConnectionException $e) {
    // Handle connection errors
    if ($e->isRetryable()) {
        // Implement retry logic
    }
} catch (QueryException $e) {
    // Handle query errors
    error_log("Query: " . $e->getQuery());
    error_log("Context: " . $e->getDescription());
} catch (ConstraintViolationException $e) {
    // Handle constraint violations
    error_log("Constraint: " . $e->getConstraintName());
}
```

All exceptions extend `PDOException` for backward compatibility and provide rich context information.

See [Error Handling Documentation](documentation/06-error-handling/) for complete guide.

---

## Performance Tips

### Enable Query Caching

For applications with repeated queries, enable result caching:

```php
$cache = new Psr16Cache(new FilesystemAdapter());
$db = new PdoDb('mysql', $config, [], null, $cache);

$products = $db->find()
    ->from('products')
    ->where('category', 'Electronics')
    ->cache(3600)
    ->get();
```

**Performance Impact:** 65-97% faster for repeated queries with cache hits.

### Use Batch Operations

```php
// ❌ Slow: Multiple single inserts
foreach ($users as $user) {
    $db->find()->table('users')->insert($user);
}

// ✅ Fast: Single batch insert
$db->find()->table('users')->insertMulti($users);
```

### Always Limit Result Sets

```php
// ✅ Safe: Limited results
$users = $db->find()->from('users')->limit(1000)->get();
```

### Use Batch Processing for Large Datasets

```php
// Process in chunks
foreach ($db->find()->from('users')->batch(100) as $batch) {
    processBatch($batch);
}
```

See [Performance Documentation](documentation/08-best-practices/02-performance.md) for more tips.

---

## Helper Functions

PDOdb provides 80+ helper functions for common SQL operations:

**Core Helpers:**
- `Db::raw()` - Raw SQL expressions
- `Db::concat()` - String concatenation
- `Db::now()` - Current timestamp

**String Operations:**
- `Db::upper()`, `Db::lower()`, `Db::trim()`, `Db::substring()`, `Db::replace()`

**Numeric Operations:**
- `Db::inc()`, `Db::dec()`, `Db::abs()`, `Db::round()`, `Db::mod()`

**Date/Time Functions:**
- `Db::now()`, `Db::date()`, `Db::year()`, `Db::month()`, `Db::day()`

**JSON Operations:**
- `Db::jsonObject()`, `Db::jsonArray()`, `Db::jsonGet()`, `Db::jsonPath()`, `Db::jsonContains()`

**Aggregate Functions:**
- `Db::count()`, `Db::sum()`, `Db::avg()`, `Db::min()`, `Db::max()`

**Full Reference:** See [Helper Functions Documentation](documentation/07-helper-functions/) for complete list and examples.

---

## API Reference

### PdoDb Main Class

| Method | Description |
|--------|-------------|
| `find()` | Returns QueryBuilder instance |
| `rawQuery(string, array)` | Execute raw SQL, returns array of rows |
| `rawQueryOne(string, array)` | Execute raw SQL, returns first row |
| `startTransaction()` | Begin transaction |
| `commit()` | Commit transaction |
| `rollBack()` | Roll back transaction |
| `describe(string)` | Get table structure |
| `indexes(string)` | Get all indexes for a table |
| `keys(string)` | Get foreign key constraints |

### QueryBuilder Methods

**Table & Selection:**
- `table(string)` / `from(string)` - Set target table
- `select(array|string)` - Specify columns to select

**Filtering:**
- `where(...)` / `andWhere(...)` / `orWhere(...)` - Add WHERE conditions
- `whereIn(...)` / `whereNotIn(...)` - IN / NOT IN conditions
- `whereNull(...)` / `whereNotNull(...)` - NULL checks
- `whereBetween(...)` - BETWEEN conditions
- `join(...)` / `leftJoin(...)` / `rightJoin(...)` - Add JOIN clauses

**Data Manipulation:**
- `insert(array)` - Insert single row
- `insertMulti(array)` - Insert multiple rows
- `update(array)` - Update rows
- `delete()` - Delete rows

**Execution:**
- `get()` - Execute SELECT, return all rows
- `getOne()` - Execute SELECT, return first row
- `getValue()` - Execute SELECT, return single value

**Full Reference:** See [API Reference Documentation](documentation/09-reference/) for complete method list and signatures.

---

## Dialect Differences

PDOdb handles most differences automatically, but here are some key points:

**UPSERT:**
- **MySQL**: `ON DUPLICATE KEY UPDATE`
- **PostgreSQL/SQLite**: `ON CONFLICT ... DO UPDATE SET`

Use `onDuplicate()` for portable UPSERT:

```php
$db->find()->table('users')->onDuplicate([
    'age' => Db::inc()
])->insert(['email' => 'user@example.com', 'age' => 25]);
```

**JSON Functions:**
- **MySQL**: Uses `JSON_EXTRACT`, `JSON_CONTAINS`
- **PostgreSQL**: Uses `->`, `->>`, `@>` operators
- **SQLite**: Uses `json_extract`, `json_each`

All handled transparently through `Db::json*()` helpers.

**Full Reference:** See [Dialect Differences Documentation](documentation/09-reference/05-dialect-differences.md) for complete guide.

---

## Frequently Asked Questions

### Is PDOdb an ORM?
No, PDOdb is a **query builder** with optional ActiveRecord pattern. It's lighter than full ORMs like Eloquent or Doctrine.

### Can I use raw SQL?
Yes! Use `rawQuery()` for complete control:
```php
$users = $db->rawQuery('SELECT * FROM users WHERE age > :age', ['age' => 18]);
```

### Does it work with frameworks?
Yes! PDOdb is framework-agnostic. Works with Laravel, Symfony, Yii, or no framework at all.

### Is it production-ready?
Yes! 3806+ tests, 12180+ assertions, PHPStan level 8, used in production environments.

### What about security?
All queries use **prepared statements** automatically. SQL injection protection is built-in.

### Can I use it with existing PDO connections?
Yes! Pass your PDO instance:
```php
$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');
$db = new PdoDb('mysql', ['pdo' => $pdo]);
```

### Which database should I use for development?
**SQLite** is perfect for development - no server setup needed:
```php
$db = new PdoDb('sqlite', ['path' => ':memory:']);
```

### Does it support transactions?
Yes! Full transaction support with savepoints:
```php
$db->startTransaction();
try {
    // Your operations
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
}
```

---

## Migration Guide

### From Raw PDO

**Before:**
```php
$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');
$stmt = $pdo->prepare('SELECT * FROM users WHERE age > :age');
$stmt->execute(['age' => 18]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

**After:**
```php
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'username' => 'user',
    'password' => 'pass'
]);
$users = $db->find()->from('users')->where('age', 18, '>')->get();
```

### From Eloquent (Laravel)

**Before:**
```php
User::where('active', 1)
    ->where('age', '>', 18)
    ->orderBy('name')
    ->limit(10)
    ->get();
```

**After:**
```php
$db->find()
    ->from('users')
    ->where('active', 1)
    ->andWhere('age', 18, '>')
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->get();
```

See [Migration Guide Documentation](documentation/10-cookbook/03-migration-guide.md) for more examples.

---

## Troubleshooting

### "Driver not found" Error

**Solution:** Install the required PHP extension:
```bash
sudo apt-get install php8.4-mysql php8.4-pgsql php8.4-sqlite3
```

### "JSON functions not available" (SQLite)

**Solution:** Check if JSON support is available:
```bash
sqlite3 :memory: "SELECT json_valid('{}')"
```

### "SQLSTATE[HY000]: General error: 1 near 'OFFSET'"

**Problem:** Using OFFSET without LIMIT in SQLite.

**Solution:** Always use LIMIT with OFFSET:
```php
// ✅ Works
$db->find()->from('users')->limit(20)->offset(10)->get();
```

### Memory Issues with Large Result Sets

**Solution:** Use batch processing or streaming:
```php
foreach ($db->find()->from('users')->batch(100) as $batch) {
    processBatch($batch);
}
```

See [Troubleshooting Documentation](documentation/10-cookbook/04-troubleshooting.md) for more solutions.

---

## Testing

The project includes comprehensive PHPUnit tests for all supported databases.

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific dialect tests
./vendor/bin/phpunit tests/PdoDbMySQLTest.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage
```

### Test Requirements

- **MySQL/MariaDB**: Running instance on localhost:3306
- **PostgreSQL**: Running instance on localhost:5432
- **SQLite**: No setup required (uses `:memory:`)
- **MSSQL**: Running instance on localhost:1433
- **Oracle**: Running instance on localhost:1521 (requires Oracle Instant Client and `pdo_oci` extension)

---

## Contributing

Contributions are welcome! Please follow these guidelines:

1. **Open an issue** first for new features or bug reports
2. **Include failing tests** that demonstrate the problem
3. **Follow PSR-12** coding standards
4. **Write tests** for all new functionality
5. **Test against all six dialects** (MySQL, MariaDB, PostgreSQL, SQLite, MSSQL, Oracle)

### Pull Request Process

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## License

This project is open source. See [LICENSE](LICENSE) file for details.

---

## Acknowledgments

Inspired by [ThingEngineer/PHP-MySQLi-Database-Class](https://github.com/ThingEngineer/PHP-MySQLi-Database-Class) and [Yii2 framework](https://github.com/yiisoft/yii2-framework)

Built with ❤️ for the PHP community.

