# Upgrading from 2.x to 3.0

PDOdb 3.0 requires the same PHP as 2.14 (8.4 or newer). Most of the major version is a set of safety fixes: places
where 2.x quietly did something unsafe or surprising now refuse, or do the safe thing. Read the sections below in order;
the first two are the ones that can change your application's results without any error.

The one-page checklist is [UPGRADE.md](../../UPGRADE.md). The complete list of changes is the
[CHANGELOG](../../CHANGELOG.md) (entries marked **Breaking**).

## Before you start

1. Update to 2.14 first and make sure your tests pass there.
2. Run your tests against 3.0 with warnings and exceptions visible, then work through the sections that apply.
3. If you use the outbox or the inbox, read [Database schema](#database-schema) before deploying: their tables change.

## Changes that can alter results without an error

### Mass assignment

`setAttributes($values)` now sets only safe attributes by default (`$safeOnly` is `true`). The safe attributes are those
returned by `safeAttributes()` if the model defines it, otherwise those mentioned in `rules()`, minus
`unsafeAttributes()`. Other keys are ignored, and a model with neither `rules()` nor `safeAttributes()` throws
`MassAssignmentException`.

If your code passed trusted data to `setAttributes()`, switch to `forceFill()` (or `setAttribute()`), or pass `false` as
the second argument. A field you assign from request data must be named in `rules()` or `safeAttributes()`. To find
silently ignored keys while migrating, call `Model::preventSilentlyDiscardingAttributes()` (off by default); it turns an
ignored key into an exception. See [Mass assignment](../05-advanced-features/16-active-record.md#4-mass-assignment-and-safe-attributes).

### `firstOrCreate()` and `updateOrCreate()`

They now write through `save()`, so validation (`rules()`), behaviors and model events run, as for any other save. When
validation fails they return the **unsaved** model (`getIsNewRecord()` is true, `getValidationErrors()` is filled)
instead of persisting an invalid row. Check the result before using it. The search columns still need a unique
constraint.

### Query-result cache invalidation

Cached query results are dropped after a write to the table they read (`cache.auto_invalidate`, on by default). Set it to
`false` for the old behavior. For raw SQL, or to invalidate after `COMMIT`, call `CacheManager::invalidateTable()`.

### `Db::concat()`

A string argument is a column name only when it is a bare identifier (`name`, `users.name`); a well-formed quoted literal
(`"' - '"`) is kept as written; any other string is an escaped text literal. Strings containing parentheses or operators
used to be emitted as raw SQL. Pass SQL fragments as `Db::raw()`.

### `->unique()` is enforced

`unique()` on a column in `createTable()` and `addColumn()` was ignored on every database except Oracle. It now creates
the constraint. New tables built from migrations get it, and `addColumn()` of a unique column on a table that already
holds duplicates fails. SQLite cannot add a `UNIQUE` column to an existing table, so a unique index is created after the
column.

### JSON and regular expressions

- `Db::jsonLength()` counts the keys of an object (it returned 0 on SQLite and PostgreSQL and 1 on Oracle); a scalar is 1,
  a missing path is 0.
- `Db::jsonKeys()` returns a JSON array of the keys (it returned the constant `'[keys]'` on PostgreSQL, SQLite and
  Oracle); Oracle throws `UnsupportedOperationException`.
- JSON path segments that are not plain identifiers or indexes (`my-key`, `a b`) are written as quoted path keys
  (`$."my-key"`).
- PostgreSQL `Db::regexpExtract()` group numbers are correct now: the whole match is `groupIndex` 0/null, group N is N.
- On MySQL and MariaDB, regular expressions containing a backslash (`\d`, `a\.c`) matched the wrong rows; they are now
  correct.

### One query builder, several runs

A builder can be executed more than once. Before, its bound values were discarded after the first run, so a second `get()`
on a builder with conditions returned nothing (SQLite) or failed. Code that worked around it by rebuilding the query is
unaffected.

### Time columns written by behaviors

`TimestampBehavior` and `SoftDeleteBehavior` write the database clock (`NOW()` of the dialect) instead of the PHP clock and
reload the stored value after saving, so values follow the connection time zone. Use the `value` option (closure,
`RawValue` or literal) to keep another source.

### ActiveRecord reads and writes

- Models have independent connection overrides. For a connection shared by all models, set the default once with
  `Model::setDb($db)`. `User::setDb($otherDb)` overrides it for `User` and its subclasses only; `User::setDb(null)`
  disables its connection explicitly.
- Global scopes now apply to ActiveQuery terminal operations and to record updates and deletes; terminal methods such as
  `exists()` and `delete()` return their actual results. Raw SQL and `getQueryBuilder()` stay outside scopes (a scope is
  not an authorization boundary).
- `ActiveQuery::each()` yields models; use `getQueryBuilder()->each()` for raw rows.
- Changing a loaded model's primary key and saving or deleting it throws `PrimaryKeyMutationException`.
- The default `Model::tableName()` uses `Inflector::tableize()` (English plural) instead of appending `s`. Override
  `tableName()` if your schema uses another convention.

## Changes that now throw

| Change | What to do |
|---|---|
| Condition operators in `where()`, `having()`, `whereColumn()`, `whereJsonPath()` and `FilterValue::filter()` are checked against an allowlist (`=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `EXISTS`, `NOT EXISTS`, `IS`, `IS NOT`, `LIKE`, `NOT LIKE`) | `InvalidArgumentException` for anything else; use `Db::raw()` for a custom condition |
| An unknown validator name or a malformed entry in `rules()` | `InvalidArgumentException` instead of a silently skipped rule (a typo such as `requried` no longer lets bad data through) |
| `paginate()`, `simplePaginate()` and `cursorPaginate()` no longer read `$_GET['page']` / `$_GET['cursor']` | Pass the request value yourself; `null` means page 1 / first page |
| A sharded query whose shard key is unknown (no `WHERE` value, no value in the inserted rows) | `LogicException` instead of running on the default connection; use `connection($name)` for work that spans shards. Rows of one `insertMulti()` that belong to different shards also throw |
| A query routed to a connection with another SQL dialect than the builder's | `LogicException`; every replica and shard must share the dialect |
| `now($diff)` with an interval containing anything but letters, digits, spaces and `+-:.,_` | `InvalidArgumentException` |
| Connection parameters containing `;`, a NUL or a line break (host, database, port, service name, TLS options) | Rejected, because a DSN cannot escape them |
| SQL Server before 2025: a regular expression that `LIKE` cannot express (`a+`, groups, alternation, `\w`) | `UnsupportedOperationException`; see [Regular Expressions on SQL Server](../07-helper-functions/02-string-helpers.md) |
| SQLite `Db::groupConcat()` with `DISTINCT` and a separator other than `,` | `UnsupportedOperationException` |
| `Db::jsonKeys()` on Oracle | `UnsupportedOperationException` |

## Behavior changes

### Transactions and migrations

- An inner `transaction()` uses a savepoint and does not commit an existing outer transaction. An inner failure rolls back
  to the savepoint when the database can recover; an exception escaping the outer callback rolls back the outer work. Some
  database errors or DDL may invalidate the transaction or commit implicitly.
- Migrations no longer run inside a transaction on MySQL, MariaDB and Oracle, which commit DDL implicitly (the transaction
  used to roll back only the history row and leave the schema half changed). A failing migration there says that its
  statements stay applied and is not recorded. `Migration::transactional()` (null follows the database, true forces, false
  opts out) replaces the unused `safeUp()` / `safeDown()`, which are removed.
- `--pretend`, `MigrationRunner::setPretend()` and `SeedRunner::setPretend()` are removed. Use `--dry-run` and
  `setDryRun()`. A dry run no longer executes migration or seed PHP; see
  [dry-run behavior](../05-advanced-features/15-migrations.md#dry-run).

### Connections

- `RetryableConnection` retries only reads (`SELECT`, `SHOW`, `DESCRIBE`, `EXPLAIN`). A write that failed on a dropped
  connection or a deadlock may already have been applied. Set `retry.retry_writes` to `true` when your writes are
  idempotent.
- The MySQL and MariaDB connection no longer sets `PDO\Mysql::ATTR_LOCAL_INFILE`. To use `LOAD DATA LOCAL INFILE`, pass it
  explicitly: `new PdoDb('mysql', $config, [\PDO\Mysql::ATTR_LOCAL_INFILE => true])`.
- `enableReadWriteSplitting()` registers the connections added before it was called, the default one included. Read/write
  splitting and sharding now change where a query runs (before, components kept the original connection).
- `loadCsv()`, `loadXml()` and `loadJson()` commit and roll back only a transaction they started; they used to commit one
  the caller had started and could leave one open.

### Command line

- A mistyped or unknown option is rejected with exit code 2 and a suggestion (`--dryrun` used to run the real command);
  `--help` works on any subcommand.
- A command that fails exits with 1 and prints `Error: <message>` instead of ending in a PHP fatal. Set `PDODB_DEBUG=1` to
  get the exception and its trace. Code that calls commands as a library catches `CliExitException`; generators throw
  `GenerationCancelledException` when you decline to overwrite a file.
- The CLI no longer changes behavior when the `PHPUNIT` environment variable is set. Interactivity comes from
  `PDODB_NON_INTERACTIVE` or a missing terminal.
- `pdodb user create` / `password` accept `--password-env=NAME` and `--password-stdin`; `--password <pass>` still works but
  warns, because it is visible in the process list.
- The model generator emits `@property` tags instead of a public `$attributes` array.
- Default AI models changed (OpenAI: `gpt-5.6-luna`, which accepts no explicit temperature).

### AI configuration

Provider-specific environment keys and nested provider settings are replaced by settings for the selected provider. For
example, replace `PDODB_AI_OPENAI_KEY` with `PDODB_AI_API_KEY` and use `PDODB_AI_MODEL` for the model; PHP configuration
uses a flat `ai` array. See
[AI configuration and migration](../05-advanced-features/26-ai-reference.md#migrating-existing-ai-settings).

## Database schema

Outbox timestamps (`created_at`, `available_at`, `locked_until`, `published_at`) use the database clock in UTC and native
types: `DATETIME(6)` on MySQL/MariaDB and `TIMESTAMP(6)` on Oracle (previously `VARCHAR`); SQLite keeps UTC text. **Outbox
tables created by an earlier version must be recreated.** The inbox `processed_at` is a native datetime column, and inbox
ids may be up to 190 characters.

`OutboxStorage::claimBatch()` returns an `OutboxClaim` (`messages`, `unreadable`) instead of a list, and a worker batch is
claimed in one transaction. See [Transactional outbox](../05-advanced-features/27-transactional-outbox.md).

## Extending the library

### Custom dialects

A class that extends `DialectAbstract` keeps working with these changes. If you implement `DialectInterface` yourself or
override its methods, check:

- `DialectInterface` is now assembled from role interfaces in `tommyknocker\pdodb\dialects\roles` (quoting, DML, JSON, SQL
  functions, DDL, catalog, planning, transactions, loaders, administration, dump, monitoring, messaging and others).
  Method names and signatures are unchanged, and `DialectAbstract` takes its defaults from one trait per role
  (`tommyknocker\pdodb\dialects\traits`). Code that needs one aspect can type-hint the matching role.
- New required methods: `quoteStringLiteral()`, `executePlanExplain()`, `resolvePlanExplainAvailability()`,
  `buildIdempotentUpsertSql()`, `buildInboxSchemaSql()`, `outboxNowSql()`, `outboxNowPlusSecondsSql()`,
  `outboxClaimStrategy()` and `addsUniqueColumnInline()` (default `true`).
- Removed: `supportsOutboxConcurrentWorkerClaim()`, `buildOutboxClaimOneSql()`, `outboxClaimSqlReturnsClaimedRow()`,
  `buildOutboxLockCandidateSql()`, `buildOutboxPendingIdsSql()` and `formatInboxProcessedAt()`; outbox claiming is an
  `OutboxClaimStrategy`.
- `DialectAbstract` no longer provides an `INSERT ... ON CONFLICT` implementation of `buildIdempotentUpsertSql()`; it throws
  `UnsupportedOperationException`. A dialect with that syntax adds `use OnConflictUpsertTrait;`, as PostgreSQL and SQLite do.
- `DialectAbstract::formatGroupConcat()` throws unless overridden instead of emitting MySQL's `SEPARATOR` syntax.
- Values written into SQL text use `quoteStringLiteral()` instead of `addslashes()` or raw interpolation, so a value with a
  quote can no longer break out of its literal on PostgreSQL, SQLite, SQL Server and Oracle.
- New required method `supportsUpsertClause()` (false when the database has no `INSERT ... ON CONFLICT`/`ON DUPLICATE`
  clause and upserts with `MERGE`).
- New required method `configOptimizerStrategy()` (recommendations for `pdodb optimize`; return null for none).
  `formatTruncate()` and `formatRegexpLike()` no longer have MySQL-style defaults and throw
  `UnsupportedOperationException` until the dialect defines them.
- `transformValueForBinding()` has an optional `$table` argument, and `onDuplicate()` an optional conflict target.
- `dumpData()` / `restoreFromSql()` share one implementation; a dialect customizes `listDumpTables()`,
  `dumpInsertStatement()` and `restoreSplitter()` instead of reimplementing them.

### Other extension points

- Subclasses of the TUI `Dashboard` that overrode scratchpad or fullscreen/detail rendering must target `SqlScratchpad` and
  `DashboardViews`; the command-line behavior is unchanged.
- MySQL and MariaDB share `MySQLFamilyDialect` and its builders (`dialects/mysqlfamily`); `MariaDBDdlQueryBuilder` no longer
  extends `MySQLDdlQueryBuilder`, and `SessionQuoteMode` moved to `dialects\mysqlfamily`. Public class names are unchanged.
- `QueryExecutedEvent::getParams()` returns raw bound values; filter them before logging.
- `CacheManager` takes an optional `CacheBackend` as its third argument; code that subclassed it and used its protected
  Redis/Memcached/APCu helpers (`getRedisConnection()`, `incrementAtomic()`, ...) uses a `CacheBackend` instead.
- AI providers take an optional `AiTransport` (second constructor argument) in place of overriding `makeRequest()` for
  transport changes; overriding `makeRequest()` still works.
- `SqlQueryCollector` and the unused per-driver methods of `MonitorManager` are removed.
