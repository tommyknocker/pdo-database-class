# PDOdb

Lightweight PHP database library with unified API for MySQL, PostgreSQL & SQLite. Features fluent QueryBuilder, 
connection pooling, UPSERT support, bulk inserts, CSV/XML loaders, and comprehensive testing.

Inspired by https://github.com/ThingEngineer/PHP-MySQLi-Database-Class

---

## Key features

* **Unified API** across MySQL, PostgreSQL and SQLite.
* **QueryBuilder**: fluent, expressive construction for `SELECT`/`INSERT`/`UPDATE`/`DELETE`, `GROUP`/`HAVING`, `JOIN`, etc.
* **Safe parameter binding**: automatic placeholders and unique names for multi-row inserts.
* **Multi-row inserts** with efficient parameter generation.
* **UPSERT support**: generates dialect-appropriate UPSERT (`ON CONFLICT` for SQLite/Postgres, `ON DUPLICATE KEY UPDATE` for MySQL).
* **RawValue support** for injecting safe SQL expressions where needed.
* **Bulk loaders**: CSV loader (`COPY`/`LOAD DATA` when available) and XML loader.
* **Dialect helpers**: identifier quoting, type mapping, and small semantics (`REPLACE` vs `UPSERT`) handled per driver.
* **Transaction helpers** and table locking primitives adapted to each engine.
* **Connection pooling** support for multiple database connections.
* **Helper functions** for common operations (`inc()`, `dec()`, `now()`).
* **Comprehensive** tests across three dialects ensuring consistent behavior.

---

## Installation

Install via Composer:

```bash
composer require tommyknocker/pdo-database-class
```

---

## Initialization

```new PdoDb(string $driver, array $config, array $pdoOptions = [], ?LoggerInterface $logger = null);```

```php
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

```php
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
    'application_name' => 'MyApp',         // Optional. Application name(visible in pg_stat_activity).
    'connect_timeout'  => 5,               // Optional. Connection timeout in seconds.
    'hostaddr'         => '192.168.1.10',  // Optional. Direct IP address (bypasses DNS).
    'service'          => 'myservice',     // Optional. Service name from pg_service.conf.
    'target_session_attrs' => 'read-write' // Optional. For clusters: any, read-write.
]);
```

```php
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

```php
$db = new PdoDb();

// Add multiple connections
$db->addConnection('mysql', ['driver' => 'mysql', 'host' => 'localhost', /* ... */]);
$db->addConnection('pgsql', ['driver' => 'pgsql', 'host' => 'localhost', /* ... */]);

// Switch between connections
$db->connection('mysql')->find()->from('users')->get();
$db->connection('pgsql')->find()->from('posts')->get();
```

---

## Quick start

All QueryBuilder examples start with `$db->find()` which returns a QueryBuilder instance.

### Simple select

```php
$row = $db->find()
    ->from('users')
    ->select(['id', 'name'])
    ->where('id', 10)
    ->getOne();
```

### Select with grouping and having

```php
use tommyknocker\pdodb\helpers\RawValue;

$rows = $db->find()
    ->from('orders')
    ->select(['user_id', new RawValue('SUM(amount) AS total')])
    ->groupBy('user_id')
    ->having(new RawValue('SUM(amount)'), 300, '=')
    ->orHaving(new RawValue('SUM(amount)'), 500, '=')
    ->get();
```

### Joins and pagination

```php
use tommyknocker\pdodb\helpers\RawValue;

$rows = $db->find()
    ->from('users AS u')
    ->select(['u.id', 'u.name', new RawValue('SUM(o.amount) AS total')])
    ->leftJoin('orders AS o', 'o.user_id = u.id')
    ->groupBy('u.id')
    ->orderBy('total', 'DESC')
    ->limit(20)
    ->offset(0)
    ->get();
```

### Insert single row

```php
$id = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'age'  => 30,
]);
```

### Insert multiple rows

```php
$rows = [
    ['name'=>'multi1','age'=>10],
    ['name'=>'multi2','age'=>11],
];

$count = $db->find()->table('users')->insertMulti($rows);
```

### Insert with RawValue expression

```php
use tommyknocker\pdodb\helpers\RawValue;

$id = $db->find()->table('users')->insert([
    'name' => 'Alex',
    'age' => 21,
    'created_at' => new RawValue('NOW()'),
]);
```

### Portable UPSERT

```php
use tommyknocker\pdodb\helpers\RawValue;

$db->find()->table('users')->onDuplicate([
    'age' => new RawValue('age + 1')
])->insert([
    'name' => 'Alice',
    'age'  => 30
]);
```

### CSV loader loadData

```php
$db->loadData('users', '/tmp/users.csv', [
    'fieldChar' => ',',
    'fieldEnclosure' => '"',
    'fields' => ['id','name','status','age'],
    'header' => false,
    'local' => true,
]);
```

### XML loader

```php
$db->find()->table('users')->insertXml('/path/to/file.xml');
```

### Transactions and locking

```php
$db->startTransaction();
try {
    // actions
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    throw $e;
}

// Table locking
$db->lock(['users', 'orders'])->setLockMethod('WRITE');
try {
    // perform operations
} finally {
    $db->unlock();
}
```

### Helper functions

```php
// Increment/decrement operations
$db->find()->table('users')->where('id', 1)->update(['age' => $db->inc()]);
$db->find()->table('users')->where('id', 1)->update(['age' => $db->dec(5)]);

// NOT operation (PostgreSQL requires boolean types)
$db->find()->table('users')->where('id', 1)->update(['is_active' => $db->not(true)]);

// Current timestamp
$db->find()->table('users')->insert([
    'name' => 'John',
    'created_at' => $db->now(),
    'expires_at' => $db->now('1 YEAR')
]);
```

---

## Public API overview

### PdoDb Main Class

* **find()**: returns QueryBuilder instance.
* **rawQuery(string, array)**: execute raw SQL, returns array of rows.
* **rawQueryOne(string, array)**: execute raw SQL, returns first row.
* **rawQueryValue(string, array)**: execute raw SQL, returns single value.
* **startTransaction() / commit() / rollBack()**: transaction helpers.
* **lock(array|string) / unlock()**: table locking helpers.
* **setLockMethod(string)**: set lock method (READ/WRITE).
* **loadData(table, file, options)**: CSV loader; COPY/LOAD DATA when available.
* **loadXml(table, file, tag, options)**: XML loader.
* **describe(string)**: get table structure.
* **explain(string, array) / explainAnalyze(string, array)**: query analysis.
* **tableExists(string)**: check if table exists.
* **ping()**: check database connection.
* **escape(string)**: escape string for SQL.
* **disconnect()**: close connection.
* **addConnection(name, config, options, logger)**: add connection to pool.
* **connection(name)**: switch to named connection.

### Helper Functions

* **inc(int|float)**: returns increment operation array.
* **dec(int|float)**: returns decrement operation array.
* **not(mixed)**: returns NOT operation array.
* **now(string)**: returns current timestamp with optional interval.

### QueryBuilder Methods

* **table(string) / from(string)**: set target table (supports `schema.table` and simple aliasing).
* **prefix(string)**: set table prefix for this query.
* **select(array|string)**: specify columns to select.
* **where(...) / andWhere(...) / orWhere(...)**: add WHERE conditions.
* **join(...) / leftJoin(...) / rightJoin(...) / innerJoin(...)**: add JOIN clauses.
* **groupBy(...)**: add GROUP BY clause.
* **having(...) / orHaving(...)**: add HAVING conditions.
* **orderBy(...)**: add ORDER BY clause.
* **limit(int) / offset(int)**: add LIMIT and OFFSET.
* **option(string|array)**: add query options.
* **insert(array)**: insert single row, returns inserted primary key when available.
* **insertMulti(array)**: insert multiple rows; generates unique named placeholders and returns inserted row count.
* **onDuplicate(array)**: build UPSERT clause; dialect-specific generation.
* **replace(array) / replaceMulti(array)**: MySQL-specific REPLACE operations.
* **update(array)**: update rows, returns affected count.
* **delete()**: delete rows, returns affected count.
* **truncate()**: truncate table (DELETE FROM for SQLite).
* **get()**: execute SELECT and return all rows.
* **getOne()**: execute SELECT and return first row.
* **getColumn()**: execute SELECT and return single column values.
* **getValue()**: execute SELECT and return single value.
* **exists()**: check if any rows match conditions.
* **asObject()**: set fetch mode to objects instead of arrays.

Use RawValue for SQL fragments that must bypass parameter binding.

---

## Dialect specifics and behavioral notes

* **Identifier quoting**: automatic per driver — double quotes for PostgreSQL/SQLite, backticks for MySQL by default. Functions and expressions (containing parentheses/operators) are preserved and not quoted. Use RawValue to inject explicit SQL fragments.
* **UPSERT: SQLite/PostgreSQL**: `ON CONFLICT` syntax (uses excluded.<col> semantics). **MySQL**: `ON DUPLICATE KEY UPDATE`. Library chooses the correct form automatically.
* **REPLACE**: MySQL-specific operation. Other dialects use UPSERT equivalents.
* **TRUNCATE**: SQLite does not support TRUNCATE. For SQLite library uses `DELETE FROM table`; reset AUTOINCREMENT via sqlite_sequence.
* **Table locking**: MySQL uses `LOCK TABLES`, PostgreSQL uses `LOCK TABLE`, SQLite uses `BEGIN IMMEDIATE`.
* **NOT operation**: PostgreSQL requires boolean types for NOT operations, MySQL and SQLite support various types.
* **Bulk loaders**: PostgreSQL use COPY when permissions allow; MySQL use LOAD DATA LOCAL INFILE when allowed.
* **Multi-row inserts**: placeholders are generated uniquely per row/column (e.g., :name_0, :name_1) to avoid binding conflicts in PDO. RawValue elements are embedded verbatim into the `VALUES` tuples.

---

## Conventions and return values

* **insert** returns the inserted primary key where applicable.
* **insertMulti** returns the number of inserted rows.
* **replace/upsert** returns affected row count when deterministic; semantics follow dialect best practices.
* **RawValue** entries are embedded verbatim into SQL tuples and not bound as parameters.
* **Multi-row inserts** generate unique named placeholders like `:name_0`, `:name_1` to avoid PDO binding conflicts.
* **Helper functions** (`inc`, `dec`, `not`) return arrays that are processed during SQL generation.

---

## Testing and CI

* The project includes PHPUnit tests that run against MySQL, PostgreSQL and SQLite. Tests are designed to run in containers or against local instances.
* Recommended CI workflow runs the test matrix on GitHub Actions with containerized MySQL and PostgreSQL and native SQLite.

Run the test suite with:

```bash
vendor/bin/phpunit
```

---

## Contributing

* Open issues with failing queries, expected SQL, actual SQL, and environment details (driver and PHP versions).
* Include unit tests for new dialect behavior.
* Follow PSR-12 formatting and include tests with pull requests.

---

## Licence

This project is open source. See [LICENCE](LICENSE) file for details.