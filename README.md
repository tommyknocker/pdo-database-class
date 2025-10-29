# PDOdb

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-blue.svg)](https://php.net)
[![Latest Version](https://img.shields.io/packagist/v/tommyknocker/pdo-database-class.svg)](https://packagist.org/packages/tommyknocker/pdo-database-class)
[![Tests](https://github.com/tommyknocker/pdo-database-class/actions/workflows/tests.yml/badge.svg)](https://github.com/tommyknocker/pdo-database-class/actions)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)
[![Coverage](https://codecov.io/gh/tommyknocker/pdo-database-class/branch/master/graph/badge.svg)](https://codecov.io/gh/tommyknocker/pdo-database-class)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/tommyknocker/pdo-database-class.svg)](https://packagist.org/packages/tommyknocker/pdo-database-class)
[![GitHub Stars](https://img.shields.io/github/stars/tommyknocker/pdo-database-class?style=social)](https://github.com/tommyknocker/pdo-database-class)

**PDOdb** is a lightweight, framework-agnostic PHP database library providing a **unified API** across MySQL, PostgreSQL, and SQLite.

Built on top of PDO with **zero external dependencies**, it offers:
- **Fluent Query Builder** - Intuitive, chainable API for all database operations
- **Cross-Database Compatibility** - Automatic SQL dialect handling (MySQL, PostgreSQL, SQLite)
- **Query Caching** - PSR-16 integration for result caching (10-1000x faster queries)
- **Read/Write Splitting** - Horizontal scaling with master-replica architecture and load balancing
- **Window Functions** - Advanced analytics with ROW_NUMBER, RANK, LAG, LEAD, running totals, moving averages
- **Full-Text Search** - Cross-database FTS with unified API (MySQL FULLTEXT, PostgreSQL tsvector, SQLite FTS5)
- **Schema Introspection** - Query indexes, foreign keys, and constraints programmatically
- **Advanced Pagination** - Full, simple, and cursor-based pagination with metadata
- **JSON Operations** - Native JSON support with consistent API across all databases
- **Bulk Operations** - CSV/XML/JSON loaders, multi-row inserts, UPSERT support
- **Export Helpers** - Export results to JSON, CSV, and XML formats
- **Transactions & Locking** - Full transaction support with table locking
- **Batch Processing** - Memory-efficient generators for large datasets
- **Exception Hierarchy** - Typed exceptions for precise error handling
- **Connection Retry** - Automatic retry with exponential backoff
- **80+ Helper Functions** - SQL helpers for strings, dates, math, JSON, aggregations, and more
- **Fully Tested** - 541 tests, 2469 assertions across all dialects
- **Type-Safe** - PHPStan level 8 validated, PSR-12 compliant

Inspired by [ThingEngineer/PHP-MySQLi-Database-Class](https://github.com/ThingEngineer/PHP-MySQLi-Database-Class)

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [📖 Documentation](#-documentation)
- [📚 Examples](#-examples)
- [Quick Example](#quick-example)
- [Configuration](#configuration)
  - [MySQL Configuration](#mysql-configuration)
  - [PostgreSQL Configuration](#postgresql-configuration)
  - [SQLite Configuration](#sqlite-configuration)
  - [Connection Pooling](#connection-pooling)
  - [Read/Write Splitting](#readwrite-splitting)
  - [Window Functions](#window-functions)
- [Quick Start](#quick-start)
  - [Basic CRUD Operations](#basic-crud-operations)
  - [Filtering and Joining](#filtering-and-joining)
  - [Bulk Operations](#bulk-operations)
  - [Transactions](#transactions)
- [Query Analysis](#query-analysis)
  - [Execution Plan Analysis](#execution-plan-analysis)
  - [Table Structure Analysis](#table-structure-analysis)
  - [SQL Generation](#sql-generation)
  - [Performance Optimization Example](#performance-optimization-example)
- [JSON Operations](#json-operations)
  - [Creating JSON Data](#creating-json-data)
  - [Querying JSON](#querying-json)
  - [Extracting JSON Values](#extracting-json-values)
- [Advanced Usage](#advanced-usage)
  - [Raw Queries](#raw-queries)
  - [Complex Conditions](#complex-conditions)
  - [Subqueries](#subqueries)
  - [Schema Support](#schema-support-postgresql)
  - [Ordering](#ordering)
  - [Pagination](#pagination)
  - [Batch Processing](#batch-processing)
  - [Query Caching](#query-caching)
- [Error Handling](#error-handling)
- [Performance Tips](#performance-tips)
- [Debugging](#debugging)
- [Helper Functions Reference](#helper-functions-reference)
- [Public API Reference](#public-api-reference)
- [Dialect Differences](#dialect-differences)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)
- [Database Error Codes](#database-error-codes)
- [Contributing](#contributing)
- [License](#license)
- [Acknowledgments](#acknowledgments)

---

## Requirements

- **PHP**: 8.4 or higher
- **PDO Extensions**: 
  - `pdo_mysql` for MySQL/MariaDB
  - `pdo_pgsql` for PostgreSQL
  - `pdo_sqlite` for SQLite
- **Supported Databases**:
  - MySQL 5.7+ / MariaDB 10.3+
  - PostgreSQL 9.4+
  - SQLite 3.38+

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

---

## 📖 Documentation

Complete documentation is available in the [`documentation/`](documentation/) directory with 56+ detailed guides covering all features:

- **[Getting Started](documentation/01-getting-started/)** - Installation, configuration, your first connection
- **[Core Concepts](documentation/02-core-concepts/)** - Connection management, query builder, parameter binding, dialects
- **[Query Builder](documentation/03-query-builder/)** - SELECT, DML, filtering, joins, aggregations, subqueries
- **[JSON Operations](documentation/04-json-operations/)** - Working with JSON across all databases
- **[Advanced Features](documentation/05-advanced-features/)** - Transactions, batch processing, bulk operations, UPSERT
- **[Error Handling](documentation/06-error-handling/)** - Exception hierarchy, retry logic, logging, monitoring
- **[Helper Functions](documentation/07-helper-functions/)** - Complete reference for all helper functions
- **[Best Practices](documentation/08-best-practices/)** - Security, performance, memory management, code organization
- **[API Reference](documentation/09-reference/)** - Complete API documentation
- **[Cookbook](documentation/10-cookbook/)** - Common patterns, real-world examples, troubleshooting

Each guide includes working code examples, dialect-specific notes, security considerations, and best practices.

Start here: [Documentation Index](documentation/README.md)

## 📚 Examples

Comprehensive, runnable examples are available in the [`examples/`](examples/) directory:

- **[Basic](examples/01-basic/)** - Connection, CRUD, WHERE conditions
- **[Intermediate](examples/02-intermediate/)** - JOINs, aggregations, pagination, transactions
- **[Advanced](examples/03-advanced/)** - Connection pooling, bulk operations, UPSERT
- **[JSON Operations](examples/04-json/)** - Complete guide to JSON features
- **[Helper Functions](examples/05-helpers/)** - String, math, date/time helpers
- **[Export Helpers](examples/11-export-helpers/)** - Export data to JSON, CSV, XML
- **[Real-World](examples/06-real-world/)** - Blog system, user auth, search, multi-tenant
- **[README Examples](examples/07-readme-examples/)** - Examples extracted from this README
- **[Connection Retry](examples/08-connection-retry/)** - Retry mechanism with logging
- **[Exception Handling](examples/09-exception-handling/)** - Comprehensive error handling

Each example is self-contained with setup instructions. See [`examples/README.md`](examples/README.md) for the full catalog.

**Quick start:**
```bash
cd examples

# SQLite (ready to use, no setup required)
php 01-basic/02-simple-crud.php

# MySQL (update config.mysql.php with your credentials)
PDODB_DRIVER=mysql php 01-basic/02-simple-crud.php

# PostgreSQL (update config.pgsql.php with your credentials)
PDODB_DRIVER=pgsql php 01-basic/02-simple-crud.php

# Test all examples on all available databases
./scripts/test-examples.sh
```

**Environment variable `PDODB_DRIVER`** controls which database to use:
- `sqlite` (default) - uses `config.sqlite.php`
- `mysql` - uses `config.mysql.php`
- `pgsql` - uses `config.pgsql.php`

If config file is missing, falls back to SQLite.

---

## Quick Example

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

// Initialize
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'testdb'
]);

// Simple query
$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->andWhere(Db::jsonContains('tags', 'php'))
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// Insert with JSON
$id = $db->find()->table('users')->insert([
    'name' => 'John',
    'age' => 25,
    'meta' => Db::jsonObject(['city' => 'NYC', 'active' => true])
]);
```

---

## Configuration

### MySQL Configuration

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'pdo'         => null,                 // Optional. Existing PDO object. If specified, all other parameters (except prefix) are ignored.
    'host'        => '127.0.0.1',          // Required. MySQL host (e.g. 'localhost' or IP address).
    'username'    => 'testuser',           // Required. MySQL username.
    'password'    => 'testpass',           // Required. MySQL password.
    'dbname'      => 'testdb',             // Required. Database name.
    'port'        => 3306,                 // Optional. MySQL port (default is 3306).
    'prefix'      => 'my_',                // Optional. Table prefix (e.g. 'wp_').
    'charset'     => 'utf8mb4',            // Optional. Connection charset (recommended: 'utf8mb4').
    'unix_socket' => '/var/run/mysqld/mysqld.sock', // Optional. Path to Unix socket if used.
    'sslca'       => '/path/ca.pem',       // Optional. Path to SSL CA certificate.
    'sslcert'     => '/path/client-cert.pem', // Optional. Path to SSL client certificate.
    'sslkey'      => '/path/client-key.pem',  // Optional. Path to SSL client key.
    'compress'    => true                  // Optional. Enable protocol compression.
]);
```

### PostgreSQL Configuration

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('pgsql', [
    'pdo'              => null,            // Optional. Existing PDO object. If specified, all other parameters (except prefix) are ignored.
    'host'             => '127.0.0.1',     // Required. PostgreSQL host.
    'username'         => 'testuser',      // Required. PostgreSQL username.
    'password'         => 'testpass',      // Required. PostgreSQL password.
    'dbname'           => 'testdb',        // Required. Database name.
    'port'             => 5432,            // Optional. PostgreSQL port (default is 5432).
    'prefix'           => 'pg_',           // Optional. Table prefix.
    'options'          => '--client_encoding=UTF8', // Optional. Extra options (e.g. client encoding).
    'sslmode'          => 'require',       // Optional. SSL mode: disable, allow, prefer, require, verify-ca, verify-full.
    'sslkey'           => '/path/client.key',   // Optional. Path to SSL private key.
    'sslcert'          => '/path/client.crt',   // Optional. Path to SSL client certificate.
    'sslrootcert'      => '/path/ca.crt',       // Optional. Path to SSL root certificate.
    'application_name' => 'MyApp',         // Optional. Application name (visible in pg_stat_activity).
    'connect_timeout'  => 5,               // Optional. Connection timeout in seconds.
    'hostaddr'         => '192.168.1.10',  // Optional. Direct IP address (bypasses DNS).
    'service'          => 'myservice',     // Optional. Service name from pg_service.conf.
    'target_session_attrs' => 'read-write' // Optional. For clusters: any, read-write.
]);
```

### SQLite Configuration

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('sqlite', [
    'pdo'   => null,                       // Optional. Existing PDO object. If specified, all other parameters (except prefix) are ignored.
    'path'  => '/path/to/database.sqlite', // Required. Path to SQLite database file.
                                           // Use ':memory:' for an in-memory database.
    'prefix'=> 'sq_',                      // Optional. Table prefix.
    'mode'  => 'rwc',                      // Optional. Open mode: ro (read-only), rw (read/write), rwc (create if not exists), memory.
    'cache' => 'shared'                    // Optional. Cache mode: shared or private.
]);
```

### Connection Pooling

Manage multiple database connections and switch between them:

```php
use tommyknocker\pdodb\PdoDb;

// Initialize without a default connection
$db = new PdoDb();

// Add multiple connections
$db->addConnection('mysql_main', [
    'driver' => 'mysql',
    'host' => 'mysql.server.com',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'main_db'
]);

$db->addConnection('pgsql_analytics', [
    'driver' => 'pgsql',
    'host' => 'postgres.server.com',
    'username' => 'analyst',
    'password' => 'pass',
    'dbname' => 'analytics'
]);

// Switch between connections
$users = $db->connection('mysql_main')->find()->from('users')->get();
$stats = $db->connection('pgsql_analytics')->find()->from('stats')->get();
```

### Read/Write Splitting

Scale horizontally with master-replica architecture. Automatically route reads to replicas and writes to master:

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\connection\loadbalancer\RoundRobinLoadBalancer;

$db = new PdoDb();

// Enable read/write splitting with load balancer
$db->enableReadWriteSplitting(new RoundRobinLoadBalancer());

// Add write connection (master)
$db->addConnection('master', [
    'driver' => 'mysql',
    'host' => 'master.db.local',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'myapp',
    'type' => 'write',
]);

// Add read connections (replicas)
$db->addConnection('replica-1', [
    'driver' => 'mysql',
    'host' => 'replica1.db.local',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'myapp',
    'type' => 'read',
]);

$db->addConnection('replica-2', [
    'driver' => 'mysql',
    'host' => 'replica2.db.local',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'myapp',
    'type' => 'read',
]);

$db->connection('master');

// SELECT queries automatically go to read replicas
$users = $db->find()->from('users')->get();

// INSERT/UPDATE/DELETE automatically go to write master
$id = $db->find()->table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);

// Force a SELECT to read from master
$user = $db->find()->from('users')->forceWrite()->where('id', $id)->getOne();

// Enable sticky writes (reads go to master for 60s after writes)
$db->enableStickyWrites(60);
```

**Load Balancing Strategies:**
- `RoundRobinLoadBalancer` - Distributes requests evenly in circular order
- `RandomLoadBalancer` - Randomly selects a replica
- `WeightedLoadBalancer` - Distributes proportionally based on weights

**Key Features:**
- Automatic query routing (SELECTs → replicas, DML → master)
- Sticky writes for read-after-write consistency
- Multiple load balancing strategies
- Health checks and automatic failover
- Transaction support (always uses master)

See:
- [Documentation: Read/Write Splitting](documentation/05-advanced-features/read-write-splitting.md)
- [Example: Basic Setup](examples/15-read-write-splitting/01-basic-setup.php)
- [Example: Sticky Writes](examples/15-read-write-splitting/02-sticky-writes.php)
- [Example: Load Balancers](examples/15-read-write-splitting/03-load-balancers.php)

### Window Functions

Perform advanced analytics with window functions (MySQL 8.0+, PostgreSQL 9.4+, SQLite 3.25+):

```php
use tommyknocker\pdodb\helpers\Db;

// ROW_NUMBER - Sequential numbering within partitions
$results = $db->find()
    ->from('sales')
    ->select([
        'product',
        'region',
        'amount',
        'row_num' => Db::rowNumber()
            ->partitionBy('region')
            ->orderBy('amount', 'DESC')
    ])
    ->get();

// RANK - Ranking with gaps for ties
$results = $db->find()
    ->from('students')
    ->select([
        'name',
        'score',
        'student_rank' => Db::rank()->orderBy('score', 'DESC')
    ])
    ->get();

// LAG/LEAD - Access previous/next row data
$results = $db->find()
    ->from('monthly_sales')
    ->select([
        'month',
        'revenue',
        'prev_month' => Db::lag('revenue', 1, 0)->orderBy('month'),
        'next_month' => Db::lead('revenue', 1, 0)->orderBy('month')
    ])
    ->get();

// Running totals
$results = $db->find()
    ->from('transactions')
    ->select([
        'date',
        'amount',
        'running_total' => Db::windowAggregate('SUM', 'amount')
            ->orderBy('date')
            ->rows('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW')
    ])
    ->get();

// Moving averages (7-day)
$results = $db->find()
    ->from('metrics')
    ->select([
        'date',
        'value',
        'moving_avg_7' => Db::windowAggregate('AVG', 'value')
            ->orderBy('date')
            ->rows('ROWS BETWEEN 6 PRECEDING AND CURRENT ROW')
    ])
    ->get();

// NTILE - Divide into quartiles
$results = $db->find()
    ->from('products')
    ->select([
        'name',
        'price',
        'quartile' => Db::ntile(4)->orderBy('price')
    ])
    ->get();
```

**Available Functions:**
- `Db::rowNumber()` - Sequential numbering
- `Db::rank()` - Ranking with gaps
- `Db::denseRank()` - Ranking without gaps
- `Db::ntile(n)` - Divide into n buckets
- `Db::lag()` - Access previous row
- `Db::lead()` - Access next row
- `Db::firstValue()` - First value in window
- `Db::lastValue()` - Last value in window
- `Db::nthValue(n)` - Nth value in window
- `Db::windowAggregate(func, col)` - Aggregate functions (SUM, AVG, MIN, MAX, COUNT)

**Common Use Cases:**
- Rankings and leaderboards
- Running totals and balances
- Moving averages and smoothing
- Period-over-period comparisons (MoM, YoY)
- Percentile and quartile analysis
- Gap detection and trend analysis

See:
- [Documentation: Window Functions](documentation/03-query-builder/window-functions.md)
- [Documentation: Window Helpers](documentation/07-helper-functions/window-helpers.md)
- [Example: Complete Demo](examples/16-window-functions/01-window-functions.php)

---

## Quick Start

**Note**: All query examples start with `$db->find()` which returns a `QueryBuilder` instance. You can chain multiple methods before executing the query with `get()`, `getOne()`, `insert()`, `update()`, or `delete()`.

### Basic CRUD Operations

#### Simple SELECT

```php
// Get one row
$user = $db->find()
    ->from('users')
    ->select(['id', 'name', 'email'])
    ->where('id', 10)
    ->getOne();

// Get multiple rows
$users = $db->find()
    ->from('users')
    ->select(['id', 'name'])
    ->where('age', 18, '>=')
    ->get();
```

#### INSERT

```php
use tommyknocker\pdodb\helpers\Db;

// Single row
$id = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'age'  => 30,
    'created_at' => Db::now()
]);

// Multiple rows
$rows = [
    ['name' => 'Bob', 'age' => 25],
    ['name' => 'Carol', 'age' => 28],
];
$count = $db->find()->table('users')->insertMulti($rows);
```

#### UPDATE

```php
use tommyknocker\pdodb\helpers\Db;

$affected = $db->find()
    ->table('users')
    ->where('id', 5)
    ->update([
        'age' => Db::inc(),  // Increment by 1
        'updated_at' => Db::now()
    ]);
```

#### DELETE

```php
$affected = $db->find()
    ->table('users')
    ->where('age', 18, '<')
    ->delete();
```

### Filtering and Joining

#### WHERE Conditions

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere('age', 18, '>')
    ->andWhere(Db::like('email', '%@example.com'))
    ->get();
```

#### JOIN and GROUP BY

```php
use tommyknocker\pdodb\helpers\Db;

$stats = $db->find()
    ->from('users AS u')
    ->select(['u.id', 'u.name', 'total' => Db::sum('o.amount')])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->groupBy('u.id')
    ->having(Db::sum('o.amount'), 1000, '>')
    ->orderBy('total', 'DESC')
    ->limit(20)
    ->get();
```

### Bulk Operations

#### UPSERT (INSERT or UPDATE)

```php
use tommyknocker\pdodb\helpers\Db;

// Portable across MySQL, PostgreSQL, SQLite
$db->find()->table('users')->onDuplicate([
    'age' => Db::inc(),
    'updated_at' => Db::now()
])->insert([
    'email' => 'alice@example.com',  // Unique key
    'name' => 'Alice',
    'age' => 30
]);
```

#### CSV Loader

```php
// Simple load
$db->find()->table('users')->loadCsv('/path/to/file.csv');

// With options
$db->find()->table('users')->loadCsv('/path/to/file.csv', [
    'fieldChar' => ',',
    'fieldEnclosure' => '"',
    'fields' => ['id', 'name', 'email', 'age'],
    'local' => true,
    'lineChar' => "\n",
    'linesToIgnore' => 1  // Skip header row
]);
```

#### XML Loader

```php
$db->find()->table('users')->loadXml('/path/to/file.xml', [
    'rowTag' => '<user>',
    'linesToIgnore' => 0
]);
```

#### JSON Loader

```php
// Array format
$db->find()->table('products')->loadJson('/path/to/products.json');

// NDJSON format (newline-delimited)
$db->find()->table('products')->loadJson('/path/to/products.ndjson', [
    'format' => 'lines',
]);

// With options
$db->find()->table('products')->loadJson('/path/to/products.json', [
    'columns' => ['name', 'price', 'stock'],
    'batchSize' => 1000,
]);
```

### Transactions

```php
$db->startTransaction();
try {
    $userId = $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->find()->table('orders')->insert(['user_id' => $userId, 'total' => 100]);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    throw $e;
}
```

#### Table Locking

```php
$db->lock(['users', 'orders'])->setLockMethod('WRITE');
try {
    // Perform exclusive operations
    $db->find()->table('users')->where('id', 1)->update(['balance' => 100]);
} finally {
    $db->unlock();
}
```

---

## Query Analysis

PDOdb provides methods to analyze query execution plans and table structures across all supported databases.

### Execution Plan Analysis

#### Basic EXPLAIN

```php
// Analyze query execution plan
$plan = $db->find()
    ->table('users')
    ->where('age', 25, '>')
    ->orderBy('created_at', 'DESC')
    ->explain();

// Returns dialect-specific execution plan
// MySQL: id, select_type, table, type, possible_keys, key, key_len, ref, rows, Extra
// PostgreSQL: QUERY PLAN column with execution details
// SQLite: addr, opcode, p1, p2, p3, p4, p5, comment
```

#### Detailed Analysis with EXPLAIN ANALYZE

```php
// Get detailed execution statistics
$analysis = $db->find()
    ->table('users')
    ->join('orders', 'users.id = orders.user_id')
    ->where('users.age', 25, '>')
    ->explainAnalyze();

// Returns:
// - PostgreSQL: EXPLAIN ANALYZE with actual execution times
// - MySQL: EXPLAIN FORMAT=JSON with detailed cost analysis
// - SQLite: EXPLAIN QUERY PLAN with query optimization details
```

### Table Structure Analysis

```php
// Get table structure information
$structure = $db->find()
    ->table('users')
    ->describe();

// Returns dialect-specific column information:
// MySQL: Field, Type, Null, Key, Default, Extra
// PostgreSQL: column_name, data_type, is_nullable, column_default
// SQLite: cid, name, type, notnull, dflt_value, pk
```

### SQL Generation

```php
// Get SQL query and parameters without execution
$query = $db->find()
    ->table('users')
    ->where('age', 25, '>')
    ->andWhere('status', 'active')
    ->toSQL();

echo $query['sql'];    // "SELECT * FROM users WHERE age > :age AND status = :status"
print_r($query['params']); // ['age' => 25, 'status' => 'active']
```

### Performance Optimization Example

```php
// Analyze a complex query
$complexQuery = $db->find()
    ->table('users')
    ->join('orders', 'users.id = orders.user_id')
    ->join('products', 'orders.product_id = products.id')
    ->where('users.created_at', '2023-01-01', '>')
    ->andWhere('orders.status', 'completed')
    ->groupBy('users.id')
    ->having('COUNT(orders.id)', 5, '>')
    ->orderBy('users.created_at', 'DESC');

// Get execution plan
$plan = $complexQuery->explain();

// Get detailed analysis
$analysis = $complexQuery->explainAnalyze();

// Check table structures
$usersStructure = $db->find()->table('users')->describe();
$ordersStructure = $db->find()->table('orders')->describe();
```

---

## JSON Operations

PDOdb provides a unified JSON API that works consistently across MySQL, PostgreSQL, and SQLite.

### Creating JSON Data

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()->table('users')->insert([
    'name' => 'John',
    'meta' => Db::jsonObject(['city' => 'NYC', 'age' => 30, 'verified' => true]),
    'tags' => Db::jsonArray('php', 'mysql', 'docker')
]);
```

### Querying JSON

#### Filter by JSON path value

```php
use tommyknocker\pdodb\helpers\Db;

// Find users older than 25
$adults = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->get();

// Multiple JSON conditions
$active = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->andWhere(Db::jsonContains('tags', 'php'))
    ->andWhere(Db::jsonExists('meta', ['verified']))
    ->get();
```

#### Check if JSON contains value

```php
use tommyknocker\pdodb\helpers\Db;

// Single value
$phpDevs = $db->find()
    ->from('users')
    ->where(Db::jsonContains('tags', 'php'))
    ->get();

// Multiple values (subset matching)
$fullStack = $db->find()
    ->from('users')
    ->where(Db::jsonContains('tags', ['php', 'mysql']))  // Must have both
    ->get();
```

#### Check if JSON path exists

```php
use tommyknocker\pdodb\helpers\Db;

$withCity = $db->find()
    ->from('users')
    ->where(Db::jsonExists('meta', ['city']))
    ->get();
```

### Extracting JSON Values

#### Select JSON values

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'city' => Db::jsonGet('meta', ['city']),
        'age' => Db::jsonGet('meta', ['age'])
    ])
    ->get();
```

#### Order by JSON value

```php
use tommyknocker\pdodb\helpers\Db;

$sorted = $db->find()
    ->from('users')
    ->orderBy(Db::jsonGet('meta', ['age']), 'DESC')
    ->get();
```

#### Get JSON array/object length

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'tag_count' => Db::jsonLength('tags')
    ])
    ->where(Db::jsonLength('tags'), 3, '>')
    ->get();
```

#### Get JSON type

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'tags_type' => Db::jsonType('tags')  // 'array', 'object', 'string', etc.
    ])
    ->get();
```

#### Update JSON values

```php
use tommyknocker\pdodb\helpers\Db;

// Update JSON field using QueryBuilder method
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'meta' => $db->find()->jsonSet('meta', ['city'], 'London')
    ]);

// Remove JSON field
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'meta' => $db->find()->jsonRemove('meta', ['old_field'])
    ]);
```

---

## Advanced Usage

### Raw Queries

#### Safe parameter binding

```php
use tommyknocker\pdodb\helpers\Db;

// Raw SELECT
$users = $db->rawQuery(
    'SELECT * FROM users WHERE age > :age AND city = :city',
    ['age' => 18, 'city' => 'NYC']
);

// Single row
$user = $db->rawQueryOne(
    'SELECT * FROM users WHERE id = :id',
    ['id' => 10]
);

// Single value
$count = $db->rawQueryValue(
    'SELECT COUNT(*) FROM users WHERE status = :status',
    ['status' => 'active']
);
```

#### Using RawValue in queries

```php
use tommyknocker\pdodb\helpers\Db;

// Using helper functions where possible
$db->find()
    ->table('users')
    ->where('id', 5)
    ->update([
        'age' => Db::raw('age + :inc', ['inc' => 5]), // No helper for arithmetic
        'name' => Db::concat('name', '_updated')      // Using CONCAT helper
    ]);
```

### Complex Conditions

```php
use tommyknocker\pdodb\helpers\Db;

// Nested OR conditions
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->andWhere(function($qb) {
        $qb->where('age', 18, '>')
           ->orWhere('verified', 1);
    })
    ->get();

// IN condition
$users = $db->find()
    ->from('users')
    ->where(Db::in('id', [1, 2, 3, 4, 5]))
    ->get();

// BETWEEN
$users = $db->find()
    ->from('users')
    ->where(Db::between('age', 18, 65))
    ->get();

// IS NULL / IS NOT NULL
$users = $db->find()
    ->from('users')
    ->where(Db::isNull('deleted_at'))
    ->andWhere(Db::isNotNull('email'))
    ->get();
```

### Subqueries

```php
// Method 1: Using callable functions
$users = $db->find()
    ->from('users')
    ->whereIn('id', function($query) {
        $query->from('orders')
            ->select('user_id')
            ->where('total', 1000, '>');
    })
    ->get();

// Method 2: Using QueryBuilder instance directly
$orderSubquery = $db->find()
    ->from('orders')
    ->select('user_id')
    ->where('total', 1000, '>');

$users = $db->find()
    ->from('users')
    ->where('id', $orderSubquery, 'IN')
    ->get();

// Method 3: WHERE EXISTS with callable (automatic external reference detection)
$users = $db->find()
    ->from('users')
    ->whereExists(function($query) {
        $query->from('orders')
            ->where('user_id', 'users.id')  // Automatically detected as external reference
            ->where('status', 'completed');
    })
    ->get();

// Method 4: WHERE EXISTS with QueryBuilder instance (automatic external reference detection)
$orderExistsQuery = $db->find()
    ->from('orders')
    ->where('user_id', 'users.id')  // Automatically detected as external reference
    ->where('status', 'completed');

$users = $db->find()
    ->from('users')
    ->whereExists($orderExistsQuery)
    ->get();

// Method 5: Complex subquery in SELECT (using helper functions)
$users = $db->find()
    ->from('users AS u')
    ->select([
        'u.id',
        'u.name',
        'order_count' => Db::raw('(SELECT ' . Db::count()->getValue() . ' FROM orders o WHERE o.user_id = u.id)')
    ])
    ->get();

// Method 6: Multiple subqueries with different approaches
$highValueOrders = $db->find()
    ->from('orders')
    ->select('user_id')
    ->where('total', 1000, '>');

$bannedUsers = $db->find()
    ->from('bans')
    ->select('user_id')
    ->where('active', 1);

$users = $db->find()
    ->from('users')
    ->where('id', $highValueOrders, 'IN')
    ->whereNotExists(function($query) use ($bannedUsers) {
        $query->from('bans')
            ->where('user_id', Db::raw('users.id'))
            ->where('active', 1);
    })
    ->get();
```

#### Automatic External Reference Detection

The library automatically detects external table references in subqueries and converts them to `RawValue` objects. This means you can write natural SQL without manually wrapping external references:

```php
// ✅ Automatic detection - no need for Db::raw()
$users = $db->find()
    ->from('users')
    ->whereExists(function($query) {
        $query->from('orders')
            ->where('user_id', 'users.id')        // Automatically detected
            ->where('created_at', 'users.created_at', '>')  // Automatically detected
            ->where('status', 'completed');
    })
    ->get();

// ✅ Works in SELECT expressions
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'total_orders' => 'COUNT(orders.id)',     // Automatically detected
        'last_order' => 'MAX(orders.created_at)' // Automatically detected
    ])
    ->leftJoin('orders', 'orders.user_id = users.id')
    ->groupBy('users.id', 'users.name')
    ->get();

// ✅ Works in ORDER BY
$users = $db->find()
    ->from('users')
    ->select(['users.id', 'users.name', 'total' => 'SUM(orders.amount)'])
    ->leftJoin('orders', 'orders.user_id = users.id')
    ->groupBy('users.id', 'users.name')
    ->orderBy('total', 'DESC')  // Automatically detected
    ->get();

// ✅ Works in GROUP BY
$results = $db->find()
    ->from('orders')
    ->select(['user_id', 'total' => 'SUM(amount)'])
    ->groupBy('user_id')  // Internal reference - not converted
    ->get();

// ✅ Works with table aliases
$users = $db->find()
    ->from('users AS u')
    ->whereExists(function($query) {
        $query->from('orders AS o')
            ->where('o.user_id', 'u.id')  // Automatically detected with aliases
            ->where('o.status', 'completed');
    })
    ->get();
```

**Detection Rules:**
- Pattern: `table.column` or `alias.column`
- Only converts if the table/alias is not in the current query's FROM clause
- Works in: `where()`, `select()`, `orderBy()`, `groupBy()`, `having()`
- Internal references (tables in current query) are not converted
- Invalid patterns (like `123.invalid`) are not converted

### Schema Support (PostgreSQL)

```php
// Specify schema explicitly
$users = $db->find()->from('public.users')->get();
$archived = $db->find()->from('archive.old_users')->get();

// Cross-schema JOIN
$data = $db->find()
    ->from('public.users AS u')
    ->leftJoin('archive.orders AS o', 'o.user_id = u.id')
    ->get();
```

### Ordering

PDOdb supports multiple convenient ways to order results:

```php
// Single column
$users = $db->find()->from('users')->orderBy('name', 'ASC')->get();

// Multiple columns (chained)
$users = $db->find()
    ->from('users')
    ->orderBy('status', 'ASC')
    ->orderBy('created_at', 'DESC')
    ->get();

// Array with explicit directions
$users = $db->find()
    ->from('users')
    ->orderBy(['status' => 'ASC', 'created_at' => 'DESC'])
    ->get();

// Array with default direction
$users = $db->find()
    ->from('users')
    ->orderBy(['status', 'name'], 'DESC')
    ->get();

// Comma-separated string
$users = $db->find()
    ->from('users')
    ->orderBy('status ASC, created_at DESC, name ASC')
    ->get();
```

See [Ordering & Pagination Documentation](documentation/03-query-builder/ordering-pagination.md) for more examples.

---

### Pagination

PDOdb offers three pagination styles for different use cases:

#### Full Pagination (with total count)

```php
// Traditional page-number pagination
$result = $db->find()
    ->from('posts')
    ->orderBy('created_at', 'DESC')
    ->paginate(20, 1); // 20 per page, page 1

echo "Page {$result->currentPage()} of {$result->lastPage()}\n";
echo "Total: {$result->total()} items\n";
echo "Showing: {$result->from()}-{$result->to()}\n";

// JSON API response
header('Content-Type: application/json');
echo json_encode($result);
```

#### Simple Pagination (without total count - faster)

```php
// Infinite scroll / "Load More" pattern
$result = $db->find()
    ->from('posts')
    ->orderBy('created_at', 'DESC')
    ->simplePaginate(20, 1);

if ($result->hasMorePages()) {
    echo '<button data-page="' . ($result->currentPage() + 1) . '">Load More</button>';
}

// JSON response (no COUNT query)
echo json_encode($result);
```

#### Cursor Pagination (most efficient for large datasets)

```php
// Stable pagination for millions of rows
$result = $db->find()
    ->from('posts')
    ->orderBy('id', 'DESC')
    ->cursorPaginate(20); // First page

// Next page using cursor
if ($result->hasMorePages()) {
    $result2 = $db->find()
        ->from('posts')
        ->orderBy('id', 'DESC')
        ->cursorPaginate(20, $result->nextCursor());
}

// JSON response with encoded cursors
echo json_encode($result);
```

#### Pagination with URL Options

```php
$result = $db->find()
    ->from('posts')
    ->where('status', 'published')
    ->paginate(20, 2, [
        'path' => '/api/posts',
        'query' => ['status' => 'published']
    ]);

echo $result->nextPageUrl();
// Output: /api/posts?status=published&page=3
```

#### Performance Comparison

| Type | Queries | Performance (1M rows) | Use Case |
|------|---------|----------------------|----------|
| **Full** | 2 (COUNT + SELECT) | ~200ms | Page numbers needed |
| **Simple** | 1 (SELECT +1) | ~50ms | Infinite scroll |
| **Cursor** | 1 (SELECT WHERE) | ~30ms | Large datasets, real-time |

See [Pagination Documentation](documentation/05-advanced-features/pagination.md) for more details.

---

### Batch Processing

For processing large datasets efficiently, PDOdb provides three generator-based methods:

#### Processing in Batches

```php
// Process data in chunks of 100 records
foreach ($db->find()->from('users')->orderBy('id')->batch(100) as $batch) {
    echo "Processing batch of " . count($batch) . " users\n";
    
    foreach ($batch as $user) {
        // Process each user in the batch
        processUser($user);
    }
}
```

#### Processing One Record at a Time

```php
// Process records individually with internal buffering
foreach ($db->find()
    ->from('users')
    ->where('active', 1)
    ->orderBy('id')
    ->each(50) as $user) {
    
    // Process individual user
    sendEmail($user['email']);
}
```

#### Streaming with Cursor

```php
// Most memory-efficient for very large datasets
foreach ($db->find()
    ->from('users')
    ->where('age', 18, '>=')
    ->cursor() as $user) {
    
    // Stream processing with minimal memory usage
    exportUser($user);
}
```

#### Real-world Example: Data Export

```php
function exportUsersToCsv($db, $filename) {
    $file = fopen($filename, 'w');
    fputcsv($file, ['ID', 'Name', 'Email', 'Age']);
    
    foreach ($db->find()
        ->from('users')
        ->orderBy('id')
        ->cursor() as $user) {
        
        fputcsv($file, [
            $user['id'],
            $user['name'],
            $user['email'],
            $user['age']
        ]);
    }
    
    fclose($file);
}

// Export 1M+ users without memory issues
exportUsersToCsv($db, 'users_export.csv');
```

#### Performance Comparison

| Method | Memory Usage | Best For |
|--------|-------------|----------|
| `get()` | High (loads all data) | Small datasets, complex processing |
| `batch()` | Medium (configurable chunks) | Bulk operations, parallel processing |
| `each()` | Medium (internal buffering) | Individual record processing |
| `cursor()` | Low (streaming) | Large datasets, simple processing |

### Query Caching

PDOdb supports PSR-16 (Simple Cache) for caching query results to improve performance.

#### Setup

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use tommyknocker\pdodb\PdoDb;

// Create PSR-16 cache
$adapter = new FilesystemAdapter();
$cache = new Psr16Cache($adapter);

// Pass cache to PdoDb
$db = new PdoDb(
    'mysql',
    [
        'host' => 'localhost',
        'dbname' => 'myapp',
        'username' => 'user',
        'password' => 'pass',
        'cache' => [
            'prefix' => 'myapp_',        // Optional: cache key prefix
            'default_ttl' => 3600,       // Optional: default TTL in seconds
            'enabled' => true,           // Optional: enable/disable
        ],
    ],
    [],
    null,
    $cache  // PSR-16 cache instance
);
```

#### Basic Usage

```php
// Cache for 1 hour (3600 seconds)
$products = $db->find()
    ->from('products')
    ->where('category', 'Electronics')
    ->cache(3600)  // Enable caching
    ->get();

// Custom cache key
$featured = $db->find()
    ->from('products')
    ->where('featured', 1)
    ->cache(3600, 'featured_products')  // Custom key
    ->get();

// Disable caching for specific query
$fresh = $db->find()
    ->from('products')
    ->cache(3600)
    ->noCache()  // Override and disable
    ->get();
```

#### Works with All Fetch Methods

```php
// Cache get() - all rows
$all = $db->find()->from('users')->cache(600)->get();

// Cache getOne() - single row
$user = $db->find()->from('users')->where('id', 1)->cache(600)->getOne();

// Cache getValue() - single value
$count = $db->find()->from('users')->select([Db::count()])->cache(600)->getValue();

// Cache getColumn() - column values
$names = $db->find()->from('users')->select('name')->cache(600)->getColumn();
```

#### Cache Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `prefix` | `string` | `'pdodb_'` | Cache key prefix for namespacing |
| `default_ttl` | `int` | `3600` | Default time-to-live in seconds |
| `enabled` | `bool` | `true` | Global cache enable/disable |

#### Supported PSR-16 Implementations

- **Symfony Cache** (recommended): `symfony/cache`
- **Redis**: Via Symfony Redis adapter
- **APCu**: Via Symfony APCu adapter
- **Memcached**: Via Symfony Memcached adapter
- **Filesystem**: Via Symfony Filesystem adapter

#### Performance Impact

| Operation | Database | Cached | Speedup |
|-----------|----------|--------|---------|
| Simple SELECT | 5-10ms | 0.1-0.5ms | 10-100x |
| Complex JOIN | 50-500ms | 0.1-0.5ms | 100-1000x |
| Aggregation | 100-1000ms | 0.1-0.5ms | 200-2000x |

**See**: [Query Caching Documentation](documentation/05-advanced-features/query-caching.md) and [Examples](examples/14-caching/)

---

## Error Handling

PDOdb provides a comprehensive exception hierarchy for better error handling and debugging. All exceptions extend `PDOException` for backward compatibility.

### Exception Hierarchy

```php
use tommyknocker\pdodb\exceptions\{
    DatabaseException,           // Base exception class
    ConnectionException,         // Connection-related errors
    QueryException,             // Query execution errors
    ConstraintViolationException, // Constraint violations
    TransactionException,       // Transaction errors
    AuthenticationException,     // Authentication errors
    TimeoutException,           // Timeout errors
    ResourceException           // Resource exhaustion
};
```

### Specific Error Handling

#### Connection Errors

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\exceptions\ConnectionException;
use tommyknocker\pdodb\exceptions\AuthenticationException;

try {
    $db = new PdoDb('mysql', [
        'host' => 'localhost',
        'username' => 'user',
        'password' => 'pass',
        'dbname' => 'db'
    ]);
} catch (ConnectionException $e) {
    error_log("Connection failed: " . $e->getMessage());
    // Connection errors are retryable
    if ($e->isRetryable()) {
        // Implement retry logic
    }
} catch (AuthenticationException $e) {
    error_log("Authentication failed: " . $e->getMessage());
    // Authentication errors are not retryable
}
```

#### Query Errors

```php
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ConstraintViolationException;

try {
    $users = $db->find()
        ->from('users')
        ->where('invalid_column', 1)
        ->get();
} catch (QueryException $e) {
    error_log("Query error: " . $e->getMessage());
    error_log("SQL: " . $e->getQuery());
} catch (ConstraintViolationException $e) {
    error_log("Constraint violation: " . $e->getMessage());
    error_log("Constraint: " . $e->getConstraintName());
    error_log("Table: " . $e->getTableName());
    error_log("Column: " . $e->getColumnName());
}
```

#### Transaction Errors

```php
use tommyknocker\pdodb\exceptions\TransactionException;

$db->startTransaction();
try {
    $userId = $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->find()->table('orders')->insert(['user_id' => $userId, 'total' => 100]);
    $db->commit();
} catch (TransactionException $e) {
    $db->rollBack();
    error_log("Transaction failed: " . $e->getMessage());
    
    if ($e->isRetryable()) {
        // Implement retry logic for deadlocks, etc.
    }
    throw $e;
}
```

### Retry Logic with Exception Types

```php
function executeWithRetry(callable $operation, int $maxRetries = 3): mixed
{
    $attempt = 0;
    $lastException = null;
    
    while ($attempt < $maxRetries) {
        try {
            return $operation();
        } catch (ConnectionException $e) {
            $lastException = $e;
            $attempt++;
            
            if ($attempt < $maxRetries) {
                sleep(2 ** $attempt); // Exponential backoff
            }
        } catch (TimeoutException $e) {
            $lastException = $e;
            $attempt++;
            
            if ($attempt < $maxRetries) {
                sleep(2 ** $attempt);
            }
        } catch (ResourceException $e) {
            $lastException = $e;
            $attempt++;
            
            if ($attempt < $maxRetries) {
                sleep(2 ** $attempt);
            }
        } catch (DatabaseException $e) {
            // Non-retryable errors
            throw $e;
        }
    }
    
    throw $lastException;
}

// Usage
$result = executeWithRetry(function() use ($db) {
    return $db->find()->from('users')->get();
});
```

### Error Monitoring and Logging

```php
use tommyknocker\pdodb\exceptions\DatabaseException;

function handleDatabaseError(DatabaseException $e): void
{
    $errorData = $e->toArray();
    
    // Log structured error data
    error_log(json_encode([
        'timestamp' => date('c'),
        'exception_type' => $errorData['exception'],
        'message' => $errorData['message'],
        'code' => $errorData['code'],
        'driver' => $errorData['driver'],
        'category' => $errorData['category'],
        'retryable' => $errorData['retryable'],
        'query' => $errorData['query'],
        'context' => $errorData['context']
    ]));
    
    // Send alerts for critical errors
    if ($e instanceof AuthenticationException || 
        $e instanceof ResourceException) {
        sendCriticalAlert($e);
    }
}

try {
    $users = $db->find()->from('users')->get();
} catch (DatabaseException $e) {
    handleDatabaseError($e);
    throw $e;
}
```

### Exception Properties

All exceptions provide rich context information:

```php
catch (DatabaseException $e) {
    // Basic properties
    echo "Message: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    echo "Driver: " . $e->getDriver() . "\n";
    echo "Query: " . $e->getQuery() . "\n";
    echo "Category: " . $e->getCategory() . "\n";
    echo "Retryable: " . ($e->isRetryable() ? 'Yes' : 'No') . "\n";
    
    // Context information
    $context = $e->getContext();
    echo "Context: " . json_encode($context) . "\n";
    
    // Add custom context
    $e->addContext('user_id', 123);
    
    // Convert to array for logging
    $errorData = $e->toArray();
}
```

### Constraint Violation Details

```php
catch (ConstraintViolationException $e) {
    echo "Constraint: " . $e->getConstraintName() . "\n";
    echo "Table: " . $e->getTableName() . "\n";
    echo "Column: " . $e->getColumnName() . "\n";
    
    // Handle specific constraint violations
    if ($e->getConstraintName() === 'unique_email') {
        // Handle duplicate email
        $existingUser = $db->find()
            ->from('users')
            ->where('email', $email)
            ->getOne();
        
        if ($existingUser) {
            // Update existing user instead
            $db->find()
                ->table('users')
                ->where('email', $email)
                ->update(['last_login' => date('Y-m-d H:i:s')]);
        }
    }
}
```

### Timeout and Resource Details

```php
catch (TimeoutException $e) {
    echo "Timeout: " . $e->getTimeoutSeconds() . "s\n";
    
    // Implement timeout-specific handling
    if ($e->getTimeoutSeconds() > 30) {
        // Long timeout - might be a complex query
        logSlowQuery($e->getQuery());
    }
}

catch (ResourceException $e) {
    echo "Resource Type: " . $e->getResourceType() . "\n";
    
    // Handle resource exhaustion
    if ($e->getResourceType() === 'connections') {
        // Implement connection pooling or queuing
        queueRequest();
    }
}
```

### Backward Compatibility

All new exceptions extend `PDOException`, so existing code continues to work:

```php
// Old way (still works)
try {
    $result = $db->query('SELECT * FROM users');
} catch (PDOException $e) {
    // Generic error handling
}

// New way (recommended)
try {
    $result = $db->query('SELECT * FROM users');
} catch (ConnectionException $e) {
    // Handle connection issues specifically
} catch (QueryException $e) {
    // Handle query issues specifically
} catch (DatabaseException $e) {
    // Handle any other database issues
}
```

---

## Performance Tips

### 1. Use Batch Operations

```php
// ❌ Slow: Multiple single inserts
foreach ($users as $user) {
    $db->find()->table('users')->insert($user);
}

// ✅ Fast: Single batch insert
$db->find()->table('users')->insertMulti($users);
```

### 2. Always Limit Result Sets

```php
// ❌ Dangerous: Can load millions of rows
$users = $db->find()->from('users')->get();

// ✅ Safe: Limited results
$users = $db->find()->from('users')->limit(1000)->get();
```

### 3. Use Indexes for JSON Queries

For frequently queried JSON paths, create indexes (MySQL 5.7+):

```sql
-- Create virtual column + index
ALTER TABLE users 
ADD COLUMN meta_age INT AS (JSON_EXTRACT(meta, '$.age'));

CREATE INDEX idx_meta_age ON users(meta_age);
```

### 4. Connection Reuse

```php
// ✅ Good: Reuse connection for multiple operations
$db->addConnection('main', $config);

$users = $db->connection('main')->find()->from('users')->get();
$orders = $db->connection('main')->find()->from('orders')->get();
```

### 5. Query Timeouts

Set appropriate timeouts for different operations:

```php
// Set timeout for long-running queries
$db->setTimeout(60); // 60 seconds

// Check current timeout
$currentTimeout = $db->getTimeout();

// Different timeouts for different connections
$db->addConnection('fast', $fastConfig);
$db->addConnection('slow', $slowConfig);

$db->connection('fast')->setTimeout(5);   // Quick queries
$db->connection('slow')->setTimeout(300); // Long-running reports
```

### 6. Prepared Statements (Automatic)

All queries automatically use prepared statements - no action needed!

```php
// Automatically uses prepared statements
$users = $db->find()
    ->from('users')
    ->where('age', 18, '>')
    ->get();
```

---

## Debugging

### Enable Query Logging

```php
use tommyknocker\pdodb\PdoDb;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Create logger
$logger = new Logger('database');
$logger->pushHandler(new StreamHandler('php://stdout'));

// Initialize with logger
$db = new PdoDb('mysql', $config, [], $logger);

// All queries will be logged with parameters
$users = $db->find()->from('users')->get();
```

### Inspect Generated SQL

```php
// Build query but don't execute
$query = $db->find()
    ->from('users')
    ->where('age', 18, '>');

// Get generated SQL
$sql = $query->getLastQuery();
echo "SQL: " . $sql . "\n";

// Get bound parameters
$params = $query->getLastParams();
print_r($params);
```

### Check Query Results

```php
$users = $db->find()->from('users')->where('age', 18, '>')->get();

echo "Found " . count($users) . " users\n";
echo "Memory used: " . memory_get_usage(true) / 1024 / 1024 . " MB\n";
```

---

## Helper Functions Reference

### Core Helpers

```php
use tommyknocker\pdodb\helpers\Db;

// Raw SQL expressions (when no helper available)
Db::raw('age + :years', ['years' => 5])

// External table references in subqueries (manual)
Db::ref('users.id')  // Equivalent to Db::raw('users.id')

// Using helper functions when available
Db::concat('first_name', ' ', 'last_name')

// Escape strings
Db::escape("O'Reilly")

// Configuration
Db::config('FOREIGN_KEY_CHECKS', 1)
```

### NULL Handling

```php
use tommyknocker\pdodb\helpers\Db;

Db::null()                              // NULL
Db::isNull('column')                    // column IS NULL
Db::isNotNull('column')                 // column IS NOT NULL
Db::ifNull('column', 'default')         // IFNULL(column, 'default')
Db::coalesce('col1', 'col2', 'default') // COALESCE(col1, col2, 'default')
Db::nullIf('col1', 'col2')              // NULLIF(col1, col2)
```

### Boolean Values

```php
use tommyknocker\pdodb\helpers\Db;

Db::true()    // TRUE (1)
Db::false()   // FALSE (0)
Db::default() // DEFAULT
```

### Numeric Operations

```php
use tommyknocker\pdodb\helpers\Db;

Db::inc()              // age + 1
Db::dec(5)             // age - 5
Db::abs('column')      // ABS(column)
Db::round('column', 2) // ROUND(column, 2)
Db::mod('a', 'b')      // MOD(a, b) or a % b
```

### String Operations

```php
use tommyknocker\pdodb\helpers\Db;

Db::concat('first_name', ' ', 'last_name') // CONCAT(...)
Db::upper('name')                          // UPPER(name)
Db::lower('email')                         // LOWER(email)
Db::trim('text')                           // TRIM(text)
Db::ltrim('text')                          // LTRIM(text)
Db::rtrim('text')                          // RTRIM(text)
Db::length('text')                         // LENGTH(text)
Db::substring('text', 1, 5)                // SUBSTRING(text, 1, 5)
Db::replace('text', 'old', 'new')          // REPLACE(text, 'old', 'new')
```

### Comparison Operators

```php
use tommyknocker\pdodb\helpers\Db;

Db::like('email', '%@example.com')      // email LIKE '%@example.com'
Db::ilike('name', 'john%')              // Case-insensitive LIKE
Db::not(Db::like('email', '%@spam.com')) // NOT LIKE
Db::between('age', 18, 65)              // age BETWEEN 18 AND 65
Db::notBetween('age', 0, 17)            // age NOT BETWEEN 0 AND 17
Db::in('id', [1, 2, 3])                 // id IN (1, 2, 3)
Db::notIn('status', ['deleted', 'banned']) // status NOT IN (...)
```

### Conditional Logic

```php
use tommyknocker\pdodb\helpers\Db;

Db::case([
    ['age < 18', "'minor'"],
    ['age < 65', "'adult'"]
], "'senior'")
// CASE WHEN age < 18 THEN 'minor' WHEN age < 65 THEN 'adult' ELSE 'senior' END
```

### Date/Time Functions

```php
use tommyknocker\pdodb\helpers\Db;

Db::now()                    // NOW() or CURRENT_TIMESTAMP
Db::now('1 DAY')             // NOW() + INTERVAL 1 DAY
Db::ts()                     // UNIX_TIMESTAMP()
Db::curDate()                // CURDATE()
Db::curTime()                // CURTIME()
Db::date('created_at')       // DATE(created_at)
Db::time('created_at')       // TIME(created_at)
Db::year('created_at')       // YEAR(created_at)
Db::month('created_at')      // MONTH(created_at)
Db::day('created_at')        // DAY(created_at)
Db::hour('created_at')       // HOUR(created_at)
Db::minute('created_at')     // MINUTE(created_at)
Db::second('created_at')     // SECOND(created_at)
```

### Aggregate Functions

```php
use tommyknocker\pdodb\helpers\Db;

Db::count()             // COUNT(*)
Db::count('DISTINCT id') // COUNT(DISTINCT id)
Db::sum('amount')       // SUM(amount)
Db::avg('rating')       // AVG(rating)
Db::min('price')        // MIN(price)
Db::max('price')        // MAX(price)
```

### Type Conversion & Comparison

```php
use tommyknocker\pdodb\helpers\Db;

Db::cast('123', 'INTEGER')       // CAST('123' AS INTEGER)
Db::greatest('a', 'b', 'c')      // GREATEST(a, b, c)
Db::least('a', 'b', 'c')         // LEAST(a, b, c)
```

### JSON Helper Functions

```php
use tommyknocker\pdodb\helpers\Db;

// Create JSON
Db::jsonObject(['key' => 'value'])     // '{"key":"value"}'
Db::jsonArray('a', 'b', 'c')           // '["a","b","c"]'

// Query JSON
Db::jsonPath('meta', ['age'], '>', 18)      // JSON path comparison
Db::jsonContains('tags', 'php')             // Check if contains value
Db::jsonContains('tags', ['php', 'mysql'])  // Check if contains all values
Db::jsonExists('meta', ['city'])            // Check if path exists

// Extract JSON
Db::jsonGet('meta', ['city'])               // Extract value at path
Db::jsonExtract('meta', ['city'])           // Alias for jsonGet
Db::jsonLength('tags')                      // Array/object length
Db::jsonKeys('meta')                        // Object keys
Db::jsonType('tags')                        // Value type
```

### Export Helpers

```php
use tommyknocker\pdodb\helpers\Db;

// Export to JSON
$data = $db->find()->from('users')->get();
$json = Db::toJson($data);

// Export to CSV
$csv = Db::toCsv($data);

// Export to XML
$xml = Db::toXml($data);

// Custom options
$json = Db::toJson($data, JSON_UNESCAPED_SLASHES);
$csv = Db::toCsv($data, ';');              // Semicolon delimiter
$xml = Db::toXml($data, 'users', 'user');   // Custom elements
```

### Full-Text Search

```php
use tommyknocker\pdodb\helpers\Db;

// Full-text search (requires FTS indexes)
$results = $db->find()
    ->from('articles')
    ->where(Db::fulltextMatch('title, content', 'database tutorial'))
    ->get();

// Single column search
$results = $db->find()
    ->from('articles')
    ->where(Db::fulltextMatch('title', 'PHP'))
    ->get();
```

### Schema Introspection

```php
// Get table structure
$structure = $db->describe('users');

// Get indexes via QueryBuilder
$indexes = $db->find()->from('users')->indexes();

// Get indexes via direct call
$indexes = $db->indexes('users');

// Get foreign keys via QueryBuilder
$foreignKeys = $db->find()->from('orders')->keys();

// Get foreign keys via direct call
$foreignKeys = $db->keys('orders');

// Get constraints via QueryBuilder
$constraints = $db->find()->from('users')->constraints();

// Get constraints via direct call
$constraints = $db->constraints('users');
```

---

## Public API Reference

### PdoDb Main Class

| Method | Description |
|--------|-------------|
| `find()` | Returns QueryBuilder instance |
| `rawQuery(string\|RawValue, array)` | Execute raw SQL, returns array of rows |
| `rawQueryOne(string\|RawValue, array)` | Execute raw SQL, returns first row |
| `rawQueryValue(string\|RawValue, array)` | Execute raw SQL, returns single value |
| `startTransaction()` | Begin transaction |
| `commit()` | Commit transaction |
| `rollBack()` | Roll back transaction |
| `lock(array\|string)` | Lock tables |
| `unlock()` | Unlock tables |
| `setLockMethod(string)` | Set lock method (READ/WRITE) |
| `describe(string)` | Get table structure |
| `indexes(string)` | Get all indexes for a table |
| `keys(string)` | Get foreign key constraints |
| `constraints(string)` | Get all constraints (PK, UNIQUE, FK, CHECK) |
| `explain(string, array)` | Analyze query execution plan |
| `explainAnalyze(string, array)` | Analyze query with execution |
| `ping()` | Check database connection |
| `disconnect()` | Close connection |
| `setTimeout(int)` | Set query timeout in seconds |
| `getTimeout()` | Get current query timeout |
| `addConnection(name, config, options, logger)` | Add connection to pool |
| `connection(name)` | Switch to named connection |

### QueryBuilder Methods

#### Table & Selection

| Method | Description |
|--------|-------------|
| `table(string)` / `from(string)` | Set target table (supports `schema.table` and aliases) |
| `prefix(string)` | Set table prefix for this query |
| `select(array\|string\|RawValue)` | Specify columns to select |

#### Filtering & Joining

| Method | Description |
|--------|-------------|
| `where(...)` / `andWhere(...)` / `orWhere(...)` | Add WHERE conditions |
| `whereIn(column, callable\|QueryBuilder)` / `whereNotIn(column, callable\|QueryBuilder)` | Add WHERE IN/NOT IN with subquery |
| `whereExists(callable\|QueryBuilder)` / `whereNotExists(callable\|QueryBuilder)` | Add WHERE EXISTS/NOT EXISTS with subquery |
| `where(column, QueryBuilder, operator)` | Add WHERE condition with QueryBuilder subquery |
| `join(...)` / `leftJoin(...)` / `rightJoin(...)` / `innerJoin(...)` | Add JOIN clauses |
| `groupBy(...)` | Add GROUP BY clause |
| `having(...)` / `orHaving(...)` | Add HAVING conditions |

#### Ordering & Limiting

| Method | Description |
|--------|-------------|
| `orderBy(string\|array\|RawValue, direction = 'ASC')` | Add ORDER BY clause. Supports: single column, array of columns, comma-separated string |
| `limit(int)` | Set LIMIT |
| `offset(int)` | Set OFFSET |
| `option(string\|array)` | Add query options (e.g., DISTINCT, SQL_CALC_FOUND_ROWS) |

#### Data Manipulation

| Method | Description |
|--------|-------------|
| `insert(array)` | Insert single row, returns inserted ID |
| `insertMulti(array)` | Insert multiple rows, returns count |
| `update(array)` | Update rows, returns affected count |
| `delete()` | Delete rows, returns affected count |
| `truncate()` | Truncate table |
| `replace(array)` / `replaceMulti(array)` | MySQL REPLACE operations |
| `onDuplicate(array)` | Build UPSERT clause (dialect-specific) |

#### Bulk Loading

| Method | Description |
|--------|-------------|
| `loadCsv(file, options)` | CSV loader (uses COPY/LOAD DATA when available) |
| `loadXml(file, options)` | XML loader |
| `loadJson(file, options)` | JSON loader (supports array and NDJSON formats) |

#### Execution

| Method | Description |
|--------|-------------|
| `get()` | Execute SELECT, return all rows |
| `getOne()` | Execute SELECT, return first row |
| `getColumn()` | Execute SELECT, return single column values |
| `getValue()` | Execute SELECT, return single value |
| `exists()` | Check if any rows match conditions |
| `notExists()` | Check if no rows match conditions |
| `tableExists(string)` | Check if table exists |

#### Batch Processing

| Method | Description |
|--------|-------------|
| `batch(int $batchSize = 100)` | Process data in batches using Generator |
| `each(int $batchSize = 100)` | Process one record at a time using Generator |
| `cursor()` | Stream results with minimal memory usage using Generator |

#### Query Analysis

| Method | Description |
|--------|-------------|
| `explain()` | Execute EXPLAIN query to analyze execution plan |
| `explainAnalyze()` | Execute EXPLAIN ANALYZE (PostgreSQL) or EXPLAIN FORMAT=JSON (MySQL) |
| `describe()` | Execute DESCRIBE to get table structure |
| `toSQL()` | Convert query to SQL string and parameters |

#### JSON Operations

| Method | Description |
|--------|-------------|
| `selectJson(col, path, alias, asText)` | Select JSON column or path |
| `whereJsonPath(col, path, operator, value, cond)` | Add JSON path condition |
| `whereJsonContains(col, value, path, cond)` | Add JSON contains condition |
| `whereJsonExists(col, path, cond)` | Add JSON path existence condition |
| `jsonSet(col, path, value)` | Set JSON value |
| `jsonRemove(col, path)` | Remove JSON path |
| `orderByJson(col, path, direction)` | Order by JSON path |

#### Fetch Modes

| Method | Description |
|--------|-------------|
| `asObject()` | Set fetch mode to objects instead of arrays |

---

## Dialect Differences

PDOdb handles most differences automatically, but here are some key points:

### Identifier Quoting

- **MySQL**: Backticks `` `column` ``
- **PostgreSQL**: Double quotes `"column"`
- **SQLite**: Double quotes `"column"`

Automatically handled by the library.

### UPSERT

- **MySQL**: `ON DUPLICATE KEY UPDATE`
- **PostgreSQL**: `ON CONFLICT ... DO UPDATE SET`
- **SQLite**: `ON CONFLICT ... DO UPDATE SET`

Use `onDuplicate()` for portable UPSERT:

```php
$db->find()->table('users')->onDuplicate([
    'age' => Db::inc()
])->insert(['email' => 'user@example.com', 'age' => 25]);
```

### REPLACE

- **MySQL**: Native `REPLACE` statement
- **PostgreSQL**: Emulated via UPSERT
- **SQLite**: Native `REPLACE` statement

```php
$db->find()->table('users')->replace(['id' => 1, 'name' => 'Alice']);
```

### TRUNCATE

- **MySQL/PostgreSQL**: Native `TRUNCATE TABLE`
- **SQLite**: Emulated via `DELETE FROM` + reset AUTOINCREMENT

```php
$db->find()->table('users')->truncate();
```

### Table Locking

- **MySQL**: `LOCK TABLES ... READ/WRITE`
- **PostgreSQL**: `LOCK TABLE ... IN ... MODE`
- **SQLite**: `BEGIN IMMEDIATE`

```php
$db->lock(['users'])->setLockMethod('WRITE');
```

### JSON Functions

- **MySQL**: Uses `JSON_EXTRACT`, `JSON_CONTAINS`, etc.
- **PostgreSQL**: Uses `->`, `->>`, `@>` operators
- **SQLite**: Uses `json_extract`, `json_each`, etc.

All handled transparently through `Db::json*()` helpers.

### Bulk Loaders

- **MySQL**: `LOAD DATA [LOCAL] INFILE`
- **PostgreSQL**: `COPY FROM`
- **SQLite**: Row-by-row inserts in a transaction

```php
$db->find()->table('users')->loadCsv('/path/to/file.csv');
```

### Multi-row Inserts

All dialects support efficient multi-row inserts. The library generates unique placeholders (`:name_0`, `:name_1`) to avoid PDO binding conflicts:

```php
$db->find()->table('users')->insertMulti([
    ['name' => 'Alice', 'age' => 30],
    ['name' => 'Bob', 'age' => 25]
]);
```

---

## Troubleshooting

### "Driver not found" Error

**Problem**: PDO extension not installed.

**Solution**: Install the required PHP extension:

```bash
# Ubuntu/Debian
sudo apt-get install php8.4-mysql php8.4-pgsql php8.4-sqlite3

# macOS
brew install php

# Check installed extensions
php -m | grep pdo
```

### "JSON functions not available" (SQLite)

**Problem**: SQLite compiled without JSON1 extension.

**Solution**: Check if JSON support is available:

```bash
sqlite3 :memory: "SELECT json_valid('{}')"
```

If it returns an error, you need to recompile SQLite with JSON1 or use a pre-built version with JSON support.

### "SQLSTATE[HY000]: General error: 1 near 'OFFSET'"

**Problem**: Using OFFSET without LIMIT in SQLite.

**Solution**: Always use LIMIT with OFFSET in SQLite:

```php
// ❌ Doesn't work in SQLite
$db->find()->from('users')->offset(10)->get();

// ✅ Works
$db->find()->from('users')->limit(20)->offset(10)->get();
```

### Slow JSON Operations

**Problem**: JSON operations can be slow on large datasets without indexes.

**Solutions**:

1. **Add indexes** (MySQL 5.7+):
   ```sql
   ALTER TABLE users ADD COLUMN meta_age INT AS (JSON_EXTRACT(meta, '$.age'));
   CREATE INDEX idx_meta_age ON users(meta_age);
   ```

2. **Denormalize frequently accessed fields**:
   ```sql
   ALTER TABLE users ADD COLUMN age INT;
   -- Copy from JSON
   UPDATE users SET age = JSON_EXTRACT(meta, '$.age');
   ```

3. **Use virtual columns with indexes** (PostgreSQL):
   ```sql
   CREATE INDEX idx_meta_age ON users((meta->>'age'));
   ```

### "Too many connections" Error

**Problem**: Connection pool not properly managed.

**Solution**: Reuse connections and disconnect when done:

```php
// ✅ Good: Reuse connection
$db->addConnection('main', $config);
$users = $db->connection('main')->find()->from('users')->get();
$orders = $db->connection('main')->find()->from('orders')->get();

// Disconnect when completely done
$db->disconnect();
```

### Memory Issues with Large Result Sets

**Problem**: Loading millions of rows into memory.

**Solution**: Use LIMIT or process in chunks:

```php
// Process in chunks
$offset = 0;
$limit = 1000;

while (true) {
    $users = $db->find()
        ->from('users')
        ->limit($limit)
        ->offset($offset)
        ->get();
    
    if (empty($users)) break;
    
    // Process $users...
    
    $offset += $limit;
}
```

---

## Testing

The project includes comprehensive PHPUnit tests for MySQL, PostgreSQL, and SQLite.

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific dialect tests
./vendor/bin/phpunit tests/PdoDbMySQLTest.php
./vendor/bin/phpunit tests/PdoDbPostgreSQLTest.php
./vendor/bin/phpunit tests/PdoDbSqliteTest.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage
```

### Test Requirements

- **MySQL**: Running instance on localhost:3306
- **PostgreSQL**: Running instance on localhost:5432
- **SQLite**: No setup required (uses `:memory:`)

### CI/CD

Tests are designed to run in containers or against local instances. Recommended CI workflow:

```yaml
# GitHub Actions example
- name: Run tests
  run: ./vendor/bin/phpunit
  env:
    MYSQL_HOST: 127.0.0.1
    MYSQL_PORT: 3306
    PGSQL_HOST: 127.0.0.1
    PGSQL_PORT: 5432
```

---

## Database Error Codes

The library provides standardized error codes for all supported database dialects through the `DbError` class:

```php
use tommyknocker\pdodb\helpers\DbError;

// MySQL error codes
DbError::MYSQL_CONNECTION_LOST        // 2006
DbError::MYSQL_CANNOT_CONNECT         // 2002
DbError::MYSQL_CONNECTION_KILLED      // 2013   
DbError::MYSQL_DUPLICATE_KEY          // 1062
DbError::MYSQL_TABLE_EXISTS           // 1050

// PostgreSQL error codes (SQLSTATE)
DbError::POSTGRESQL_CONNECTION_FAILURE        // '08006'
DbError::POSTGRESQL_CONNECTION_DOES_NOT_EXIST  // '08003'
DbError::POSTGRESQL_UNIQUE_VIOLATION          // '23505'
DbError::POSTGRESQL_UNDEFINED_TABLE           // '42P01'

// SQLite error codes
DbError::SQLITE_ERROR      // 1
DbError::SQLITE_BUSY       // 5
DbError::SQLITE_LOCKED     // 6
DbError::SQLITE_CONSTRAINT // 19
DbError::SQLITE_ROW        // 100
DbError::SQLITE_DONE       // 101
```

### Helper Methods

```php
// Get retryable error codes for specific driver
$mysqlErrors = DbError::getMysqlRetryableErrors();
$postgresqlErrors = DbError::getPostgresqlRetryableErrors();
$sqliteErrors = DbError::getSqliteRetryableErrors();

// Get retryable errors for any driver
$errors = DbError::getRetryableErrors('mysql');

// Check if error is retryable
$isRetryable = DbError::isRetryable(2006, 'mysql'); // true

// Get human-readable error description
$description = DbError::getDescription(2006, 'mysql');
// Returns: "MySQL server has gone away"
```

### Usage in Connection Retry

```php
use tommyknocker\pdodb\helpers\DbError;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'delay_ms' => 1000,
        'retryable_errors' => DbError::getRetryableErrors('mysql'), // Use helper method
    ]
]);

// Or specify individual error codes
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'retry' => [
        'enabled' => true,
        'retryable_errors' => [
            DbError::MYSQL_CONNECTION_LOST,
            DbError::MYSQL_CANNOT_CONNECT,
            DbError::MYSQL_CONNECTION_KILLED,
        ]
    ]
]);
```

### Configuration Validation

The retry mechanism includes comprehensive validation to prevent invalid configurations:

```php
// Valid configuration
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,        // Must be 1-100
        'delay_ms' => 1000,         // Must be 0-300000ms (5 minutes)
        'backoff_multiplier' => 2.0, // Must be 1.0-10.0
        'max_delay_ms' => 10000,    // Must be >= delay_ms, max 300000ms
        'retryable_errors' => [2006, '08006'] // Must be array of int/string
    ]
]);

// Invalid configurations will throw InvalidArgumentException:
try {
    $db = new PdoDb('mysql', [
        'retry' => [
            'enabled' => 'true',     // ❌ Must be boolean
            'max_attempts' => 0,     // ❌ Must be >= 1
            'delay_ms' => -100,      // ❌ Must be >= 0
            'backoff_multiplier' => 0.5, // ❌ Must be >= 1.0
            'max_delay_ms' => 500,   // ❌ Must be >= delay_ms
        ]
    ]);
} catch (InvalidArgumentException $e) {
    echo "Configuration error: " . $e->getMessage();
}
```

**Validation Rules:**
- `enabled`: Must be boolean
- `max_attempts`: Must be integer 1-100
- `delay_ms`: Must be integer 0-300000ms (5 minutes)
- `backoff_multiplier`: Must be number 1.0-10.0
- `max_delay_ms`: Must be integer 0-300000ms, >= delay_ms
- `retryable_errors`: Must be array of integers or strings

### Retry Logging

The retry mechanism provides comprehensive logging for monitoring connection attempts in production:

```php
use Monolog\Handler\TestHandler;
use Monolog\Logger;

// Create a logger (e.g., Monolog)
$testHandler = new TestHandler();
$logger = new Logger('database');
$logger->pushHandler($testHandler);

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'delay_ms' => 1000,
    ]
], [], $logger);

// Set logger on the connection
$connection = $db->connection;
if ($connection instanceof \tommyknocker\pdodb\connection\RetryableConnection) {
    $reflection = new \ReflectionClass($connection);
    $loggerProperty = $reflection->getProperty('logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($connection, $logger);
}

// Execute queries - logs will be captured
$result = $db->connection->query('SELECT 1 as test');

// Access captured logs
$records = $testHandler->getRecords();
foreach ($records as $record) {
    echo "[{$record['level_name']}] {$record['message']}\n";
}
```

**Log Messages:**

- `connection.retry.start` - Retry operation begins
- `connection.retry.attempt` - Individual attempt starts
- `connection.retry.success` - Successful operation
- `connection.retry.attempt_failed` - Attempt failed (retryable)
- `connection.retry.not_retryable` - Error not in retryable list
- `connection.retry.exhausted` - All retry attempts failed
- `connection.retry.retrying` - Decision to retry
- `connection.retry.wait` - Wait delay calculation details

**Log Context Includes:**
- Method name (`query`, `execute`, `prepare`, `transaction`)
- Attempt number and max attempts
- Error codes and messages
- Driver information
- Delay calculations and backoff details

---

## Contributing

Contributions are welcome! Please follow these guidelines:

1. **Open an issue** first for new features or bug reports
2. **Include failing tests** that demonstrate the problem
3. **Provide details**: 
   - Expected SQL vs. actual SQL
   - Environment details (PHP version, database version, driver)
   - Steps to reproduce
4. **Follow PSR-12** coding standards
5. **Write tests** for all new functionality
6. **Test against all three dialects** (MySQL, PostgreSQL, SQLite)

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

Inspired by [ThingEngineer/PHP-MySQLi-Database-Class](https://github.com/ThingEngineer/PHP-MySQLi-Database-Class)

Built with ❤️ for the PHP community.
