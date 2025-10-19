# PDOdb

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-blue.svg)](https://php.net)
[![Latest Version](https://img.shields.io/packagist/v/tommyknocker/pdo-database-class.svg)](https://packagist.org/packages/tommyknocker/pdo-database-class)
[![Tests](https://github.com/tommyknocker/pdo-database-class/workflows/Tests/badge.svg)](https://github.com/tommyknocker/pdo-database-class/actions)
[![Coverage](https://codecov.io/gh/tommyknocker/pdo-database-class/branch/master/graph/badge.svg)](https://codecov.io/gh/tommyknocker/pdo-database-class)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/tommyknocker/pdo-database-class.svg)](https://packagist.org/packages/tommyknocker/pdo-database-class)
[![GitHub Stars](https://img.shields.io/github/stars/tommyknocker/pdo-database-class?style=social)](https://github.com/tommyknocker/pdo-database-class)

**PDOdb** is a lightweight, framework-agnostic PHP database library providing a **unified API** across MySQL, PostgreSQL, and SQLite.

Built on top of PDO with **zero external dependencies**, it offers:
- Fluent query builder with intuitive syntax
- Automatic SQL dialect handling for cross-database compatibility
- Native JSON support with consistent API across all databases
- Production-ready features: transactions, bulk operations, connection pooling
- Fully tested with comprehensive test coverage

Inspired by [ThingEngineer/PHP-MySQLi-Database-Class](https://github.com/ThingEngineer/PHP-MySQLi-Database-Class)

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Example](#quick-example)
- [Configuration](#configuration)
  - [MySQL Configuration](#mysql-configuration)
  - [PostgreSQL Configuration](#postgresql-configuration)
  - [SQLite Configuration](#sqlite-configuration)
  - [Connection Pooling](#connection-pooling)
- [Quick Start](#quick-start)
  - [Basic CRUD Operations](#basic-crud-operations)
  - [Filtering and Joining](#filtering-and-joining)
  - [Bulk Operations](#bulk-operations)
  - [Transactions](#transactions)
- [JSON Operations](#json-operations)
  - [Creating JSON Data](#creating-json-data)
  - [Querying JSON](#querying-json)
  - [Extracting JSON Values](#extracting-json-values)
- [Advanced Usage](#advanced-usage)
  - [Raw Queries](#raw-queries)
  - [Complex Conditions](#complex-conditions)
  - [Subqueries](#subqueries)
  - [Schema Support](#schema-support-postgresql)
- [Error Handling](#error-handling)
- [Performance Tips](#performance-tips)
- [Debugging](#debugging)
- [Helper Functions Reference](#helper-functions-reference)
- [Public API Reference](#public-api-reference)
- [Dialect Differences](#dialect-differences)
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
- **Supported Databases**:
  - MySQL 5.7+ / MariaDB 10.3+
  - PostgreSQL 12+
  - SQLite 3.32+

**For JSON support:**
- MySQL 5.7+ / MariaDB 10.2+ (JSON functions available)
- PostgreSQL 9.4+ (JSONB available)
- SQLite 3.38+ (compiled with JSON1 extension)

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
# Latest 1.x version
composer require tommyknocker/pdo-database-class:^1.0

# Development version
composer require tommyknocker/pdo-database-class:dev-master
```

---

## 📚 Examples

Comprehensive, runnable examples are available in the [`examples/`](examples/) directory:

- **[Basic](examples/01-basic/)** - Connection, CRUD, WHERE conditions
- **[Intermediate](examples/02-intermediate/)** - JOINs, aggregations, pagination, transactions
- **[Advanced](examples/03-advanced/)** - Connection pooling, bulk operations, UPSERT
- **[JSON Operations](examples/04-json/)** - Complete guide to JSON features
- **[Helper Functions](examples/05-helpers/)** - String, math, date/time helpers
- **[Real-World](examples/06-real-world/)** - Blog system, user auth, search, multi-tenant

Each example is self-contained with setup instructions. See [`examples/README.md`](examples/README.md) for the full catalog.

**Quick start:**
```bash
cd examples
cp config.example.php config.php
# Update config.php with your credentials
php 01-basic/01-connection.php
```

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
    ->where(Db::jsonContains('tags', 'php'))
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
    ->where('age', 18, '>')
    ->where(Db::like('email', '%@example.com'))
    ->get();
```

#### JOIN and GROUP BY

```php
use tommyknocker\pdodb\helpers\Db;

$stats = $db->find()
    ->from('users AS u')
    ->select(['u.id', 'u.name', Db::raw('SUM(o.amount) AS total')])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->groupBy('u.id')
    ->having(Db::raw('SUM(o.amount)'), 1000, '>')
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
    ->where(Db::jsonContains('tags', 'php'))
    ->where(Db::jsonExists('meta', ['verified']))
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

// Raw SQL fragments
$db->find()
    ->table('users')
    ->where('id', 5)
    ->update([
        'age' => Db::raw('age + :inc', ['inc' => 5]),
        'name' => Db::raw('CONCAT(name, :suffix)', ['suffix' => '_updated'])
    ]);
```

### Complex Conditions

```php
use tommyknocker\pdodb\helpers\Db;

// Nested OR conditions
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->where(function($qb) {
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
    ->where(Db::isNotNull('email'))
    ->get();
```

### Subqueries

```php
use tommyknocker\pdodb\helpers\Db;

// Subquery in WHERE
$users = $db->find()
    ->from('users')
    ->where(Db::raw('id IN (SELECT user_id FROM orders WHERE total > 1000)'))
    ->get();

// Subquery in SELECT
$users = $db->find()
    ->from('users AS u')
    ->select([
        'u.id',
        'u.name',
        'order_count' => Db::raw('(SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id)')
    ])
    ->get();
```

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

---

## Error Handling

### Connection Errors

```php
use tommyknocker\pdodb\PdoDb;

try {
    $db = new PdoDb('mysql', [
        'host' => 'localhost',
        'username' => 'user',
        'password' => 'pass',
        'dbname' => 'db'
    ]);
} catch (\PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    // Handle error appropriately
}
```

### Query Errors

```php
try {
    $users = $db->find()
        ->from('users')
        ->where('invalid_column', 1)
        ->get();
} catch (\PDOException $e) {
    // Check error code for specific errors
    if ($e->getCode() === '42S22') {
        // Unknown column error
        error_log("Column not found: " . $e->getMessage());
    }
}
```

### Transaction with Proper Rollback

```php
$db->startTransaction();
try {
    $userId = $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->find()->table('orders')->insert(['user_id' => $userId, 'total' => 100]);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    error_log("Transaction failed: " . $e->getMessage());
    throw $e;
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

### 5. Prepared Statements (Automatic)

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

// Raw SQL expressions
Db::raw('CONCAT(first_name, " ", last_name)')
Db::raw('age + :years', ['years' => 5])

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
| `explain(string, array)` | Analyze query execution plan |
| `explainAnalyze(string, array)` | Analyze query with execution |
| `ping()` | Check database connection |
| `disconnect()` | Close connection |
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
| `join(...)` / `leftJoin(...)` / `rightJoin(...)` / `innerJoin(...)` | Add JOIN clauses |
| `groupBy(...)` | Add GROUP BY clause |
| `having(...)` / `orHaving(...)` | Add HAVING conditions |

#### Ordering & Limiting

| Method | Description |
|--------|-------------|
| `orderBy(...)` | Add ORDER BY clause |
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
ALL_TESTS=1 ./vendor/bin/phpunit

# Run specific dialect tests
./vendor/bin/phpunit tests/PdoDbMySQLTest.php
./vendor/bin/phpunit tests/PdoDbPostgreSQLTest.php
./vendor/bin/phpunit tests/PdoDbSqliteTest.php

# Run with coverage
ALL_TESTS=1 ./vendor/bin/phpunit --coverage-html coverage
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
  run: ALL_TESTS=1 ./vendor/bin/phpunit
  env:
    MYSQL_HOST: 127.0.0.1
    MYSQL_PORT: 3306
    PGSQL_HOST: 127.0.0.1
    PGSQL_PORT: 5432
```

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
