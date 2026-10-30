# PDOdb

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-blue.svg)](https://php.net)
[![Latest Version](https://img.shields.io/packagist/v/tommyknocker/pdo-database-class.svg)](https://packagist.org/packages/tommyknocker/pdo-database-class)
[![Tests](https://github.com/tommyknocker/pdo-database-class/actions/workflows/tests.yml/badge.svg)](https://github.com/tommyknocker/pdo-database-class/actions)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)
[![AI query advice](https://img.shields.io/badge/AI-query%20advice-blueviolet)](#understand-a-slow-query-with-your-model)
[![Coverage](https://codecov.io/gh/tommyknocker/pdo-database-class/branch/master/graph/badge.svg)](https://codecov.io/gh/tommyknocker/pdo-database-class)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/tommyknocker/pdo-database-class.svg)](https://packagist.org/packages/tommyknocker/pdo-database-class)
[![GitHub Stars](https://img.shields.io/github/stars/tommyknocker/pdo-database-class?style=social)](https://github.com/tommyknocker/pdo-database-class)

**PDOdb** is a PHP database library for MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, and Oracle. You write queries once with a fluent builder. When a query is slow, one call sends the available execution plan, columns, and indexes to a provider you choose, so you can review a suggestion without pasting the schema into chat.

Built on PDO, with no framework dependency. The package requires three PSR interfaces: `psr/log`, `psr/simple-cache`, and `psr/event-dispatcher`.

To try it with SQLite, install PHP 8.4+ with `pdo_sqlite`, then run:

```bash
composer require tommyknocker/pdo-database-class
```

```php
<?php
require 'vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('sqlite', ['path' => ':memory:']);
$db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$id = $db->find()->table('users')->insert(['name' => 'Alice']);
$user = $db->find()->from('users')->where('id', $id)->getOne();
if ($user !== false) {
    echo $user['name'];
}
```

Rows are associative arrays by default. `asObject()` returns `stdClass` objects instead:

```php
// get() returns an array of objects (or an empty array if no rows match)
$users = $db->find()->from('users')->asObject()->get();
foreach ($users as $user) {
    echo $user->name;
}

// getOne() returns an object, or false if no row matches
$user = $db->find()->from('users')->where('id', $id)->asObject()->getOne();
if ($user !== false) {
    echo $user->name;
}
```

## Why PDOdb

**Compared with raw PDO.** Queries are method calls, parameters are bound for you, and helpers cover dates, JSON, aggregates, and strings. The SQL that reaches the server still follows that database's dialect.

**Compared with Eloquent or Doctrine.** There is no framework to adopt and no ORM unless you want the optional ActiveRecord layer. You can drop to raw SQL. Two things those libraries do not include: query advice grounded in the database plan, and a terminal dashboard for a connection you have already configured.

**Several databases in one project.** The same builder calls target MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, and Oracle. Where the SQL itself differs, the [dialect guide](documentation/09-reference/05-dialect-differences.md) says so.

## Capabilities

Each item below is implemented in the library and documented on its own page.

**Queries**

- [Fluent query builder](documentation/02-core-concepts/03-query-builder-basics.md) for select, insert, update, and delete
- [Filtering, joins, and aggregates](documentation/03-query-builder/03-filtering-conditions.md), including [subqueries](documentation/03-query-builder/07-subqueries.md)
- [80+ SQL helpers](documentation/07-helper-functions/) for strings, dates, math, JSON, and aggregates
- [Window functions](documentation/03-query-builder/09-window-functions.md): `ROW_NUMBER`, `RANK`, `LAG`, `LEAD`
- [Common table expressions](documentation/03-query-builder/08-cte.md), including recursive CTEs
- [LATERAL joins](documentation/03-query-builder/04-joins.md#lateral-joins) on PostgreSQL, MySQL, and SQL Server (`CROSS APPLY` / `OUTER APPLY`)
- [UNION, INTERSECT, and EXCEPT](documentation/03-query-builder/15-set-operations.md)
- [Full-text search](documentation/03-query-builder/10-fulltext-search.md) with one API over each dialect's native FTS
- [DISTINCT and PostgreSQL DISTINCT ON](documentation/03-query-builder/13-distinct.md)
- [FILTER aggregates](documentation/03-query-builder/14-filter-clause.md), with a MySQL fallback
- [Offset and cursor pagination](documentation/05-advanced-features/12-pagination.md)
- [Raw SQL](documentation/03-query-builder/16-raw-queries.md) when you want the statement yourself

**Writes and schema**

- [INSERT ... SELECT](documentation/03-query-builder/02-data-manipulation.md), upserts, and [MERGE](documentation/03-query-builder/02-data-manipulation.md)
- [Bulk inserts and file loaders](documentation/05-advanced-features/04-bulk-operations.md) for CSV, XML, and JSON
- [DDL builder](documentation/03-query-builder/12-ddl-operations.md) for tables, columns, indexes, and foreign keys
- [Dialect-specific types](documentation/05-advanced-features/20-dialect-specific-schema.md): MySQL `ENUM`/`SET`, PostgreSQL `UUID`/`JSONB`, SQL Server `UNIQUEIDENTIFIER`, SQLite type affinity
- [Migrations](documentation/05-advanced-features/15-migrations.md) and [seeds](documentation/05-advanced-features/21-cli-tools.md#seed-management) with rollback
- [Schema introspection](documentation/03-query-builder/11-schema-introspection.md) for indexes, foreign keys, and constraints

**Speed and scale**

- [PSR-16 result cache](documentation/05-advanced-features/09-query-caching.md) for repeated queries
- [Compiled-SQL cache](documentation/05-advanced-features/10-query-compilation-cache.md) and a [prepared-statement pool](documentation/01-getting-started/04-configuration.md#prepared-statement-pool)
- [Query profiler](documentation/05-advanced-features/11-query-profiling.md) and [EXPLAIN analysis](documentation/05-advanced-features/08-query-analysis.md)
- [Read/write splitting](documentation/05-advanced-features/13-read-write-splitting.md) and [sharding](documentation/05-advanced-features/14-sharding.md)
- [Batch and streaming reads](documentation/05-advanced-features/03-batch-processing.md) for large result sets
- [Connection retry](documentation/05-advanced-features/07-connection-retry.md) with exponential backoff
- [Transactions, savepoints, and table locks](documentation/05-advanced-features/01-transactions.md)

**Application code**

- Optional [ActiveRecord](documentation/05-advanced-features/16-active-record.md) with [relationships](documentation/05-advanced-features/17-active-record-relationships.md), eager and lazy loading
- [Query scopes](documentation/05-advanced-features/19-query-scopes.md), [macros](documentation/05-advanced-features/18-query-macros.md), and [plugins](documentation/05-advanced-features/20-plugins.md)
- [PSR-14 events](documentation/05-advanced-features/22-events.md)
- [Typed exceptions](documentation/06-error-handling/01-exception-hierarchy.md) and [query diagnostics](documentation/06-error-handling/03-error-diagnostics.md) with sanitized parameters
- [JSON, CSV, and XML export](documentation/07-helper-functions/10-export-helpers.md) of result sets

**Tools**

- [AI query advice](#understand-a-slow-query-with-your-model) from the PHP call, plus CLI reports
- Optional [MCP server](documentation/01-getting-started/08-ai-editor.md) so an editor can read the development schema
- [Terminal dashboard](documentation/05-advanced-features/21-cli-tools.md#tui-dashboard) (`pdodb ui`) for a database configured for the CLI
- [CLI](documentation/05-advanced-features/21-cli-tools.md) for migrations, seeds, dumps, users, models, and a SQL REPL

## Work with JSON the same way on every database

The helper calls stay the same. Each dialect receives its own JSON SQL.

```php
<?php
require 'vendor/autoload.php';

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('sqlite', ['path' => ':memory:']);
$db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, meta TEXT)');

$db->find()->table('users')->insert([
    'name' => 'John',
    'meta' => Db::jsonObject(['city' => 'NYC', 'age' => 30]),
]);

$users = $db->find()
    ->from('users')
    ->select([
        'name',
        'city' => Db::jsonGet('meta', ['city']),
    ])
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->get();

foreach ($users as $user) {
    echo $user['name'] . ' ' . $user['city'] . PHP_EOL;
}
```

More JSON operations: [create and query JSON](documentation/04-json-operations/01-json-basics.md).

## Understand a slow query with your model

You already know which query is slow. The open question is whether to change a filter, a join, an index, or nothing.

```php
$advice = $db->find()
    ->from('orders')
    ->where('status', 'pending')
    ->orderBy('created_at', 'DESC')
    ->limit(50)
    ->explainAiAdvice();

echo $advice->aiAnalysis;
```

PDOdb collects the available plan, the columns, and the existing indexes, then makes one request to the provider configured for your application. You get an explanation and a hypothesis. PDOdb does not create the index or run the model's SQL. On SQL Server, and for parameterized Oracle `EXPLAIN`, a verified plan is unavailable. Those calls can still discuss the SQL and schema, and they say that the plan is missing. Details: [query-advice limitations](documentation/05-advanced-features/24-ai-query-advice.md#4-read-status-and-limitations-before-using-the-advice).

On the included SQLite fixture the plan before a matching index looked like this:

```text
SCAN orders
USE TEMP B-TREE FOR ORDER BY
```

After the index was applied in that fixture, the same ordered page used the index:

```text
SEARCH orders USING INDEX idx_orders_status_created (status=?)
```

The median `SELECT` on that synthetic database moved from 4.6860 ms to 0.0200 ms. That is a local measurement of the fixture, not a production promise. A third case in the same demo needs no new index: the existing one already matches the filter and the order. Walkthrough, setup, and the three cases: [Understand and improve a query with AI](documentation/05-advanced-features/23-ai-analysis.md).

**In an application.** Call `explainAiAdvice()` on the `PdoDb` instance you already use. Put the provider, key, and model in that process environment. [Configuration reference](documentation/05-advanced-features/26-ai-reference.md#configuration).

**Offline demo, no API key.** The `examples/` directory is part of a repository checkout, not of a `composer require` install. From the repository root:

```bash
composer install
php examples/07-performance/05-ai-query-advice.php
```

The command builds 100,000 synthetic orders in memory, prints real SQLite plans, and checks that each reviewed index change preserves the result.

## Requirements

- PHP 8.4 or newer
- PDO, plus the extension for each database you connect to: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `pdo_sqlsrv` (Microsoft ODBC Driver), or `pdo_oci`
- Compatibility targets: MySQL 5.7+ / MariaDB 10.3+, PostgreSQL 9.4+, SQLite 3.38+, SQL Server 2019+ / Azure SQL, Oracle 12c+. Individual features may need a newer server. These targets are not a promise that every historical version is still covered or still supported by its vendor.

## Database test coverage

CI runs the database suites against:

| Database | CI version |
| --- | --- |
| MySQL | 8.0 |
| MariaDB | 11.2 |
| PostgreSQL | 16 |
| SQLite | Runner's bundled version |
| SQL Server | 2022 |
| Oracle | XE 21 |

SQLite JSON support:

```bash
sqlite3 :memory: "SELECT json_valid('{}')"
```

`1` means JSON is available.

## Installation

```bash
composer require tommyknocker/pdo-database-class
```

```bash
composer require tommyknocker/pdo-database-class:^2.0
composer require tommyknocker/pdo-database-class:^1.0
composer require tommyknocker/pdo-database-class:dev-master
```

`vendor/bin/pdodb init` writes a connection config (`.env` or `config/db.php`) and can create migration and model directories. Full option list: [CLI tools](documentation/05-advanced-features/21-cli-tools.md#project-initialization). Connection arrays, pooling, and read/write splitting: [configuration](documentation/01-getting-started/04-configuration.md).

## Documentation and examples

Start with the [documentation index](documentation/README.md). Guides are grouped into getting started, the query builder, JSON, advanced features, errors, helpers, and the API reference. A short path for a first connection: [installation](documentation/01-getting-started/01-installation.md), [first connection](documentation/01-getting-started/05-first-connection.md), [hello world](documentation/01-getting-started/06-hello-world.md).

Coming from raw PDO or Eloquent: [migration guide](documentation/10-cookbook/03-migration-guide.md). Common failures: [troubleshooting](documentation/10-cookbook/04-troubleshooting.md).

Runnable examples live in this repository, not in an application that only installed the package. [Example catalog](examples/README.md).

```bash
# From the examples/ directory
cd examples
php 01-basic/02-simple-crud.php

# MySQL (update config.mysql.php with your credentials)
PDODB_DRIVER=mysql php 01-basic/02-simple-crud.php

# From the repository root: verify all examples on available databases
./scripts/test-examples.sh
```

## Testing

```bash
./vendor/bin/phpunit
./vendor/bin/phpunit --testsuite "MySQL Tests"
./vendor/bin/phpunit --coverage-html coverage
```

MySQL and MariaDB expect a server on `localhost:3306`, PostgreSQL on `5432`, SQL Server on `1433`, and Oracle on `1521` (`pdo_oci` and Oracle Instant Client). SQLite uses `:memory:`.

## Contributing

Open an issue before a new feature, follow PSR-12, and add tests. Dialect-specific behavior belongs in that dialect's test directory. See [CONTRIBUTING.md](CONTRIBUTING.md).

Development and CI use `composer install` so PHPStan and the other development tools follow `composer.lock`. The lock file does not constrain applications that require PDOdb as a package. Update dependencies with `composer update` and include the lock file in that change.

## License

MIT. See [LICENSE](LICENSE).

## Acknowledgments

Inspired by [ThingEngineer/PHP-MySQLi-Database-Class](https://github.com/ThingEngineer/PHP-MySQLi-Database-Class) and the [Yii2 framework](https://github.com/yiisoft/yii2-framework).
