# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.1.0] - 2026-11-05

Read the section "Upgrading from 3.0 to 3.1" of [UPGRADE.md](UPGRADE.md): several results that were wrong in 3.0.0 are
correct now, and documents written by `jsonSet()` on MariaDB, SQL Server and Oracle may need rewriting.

### Added
- A step-by-step guide to reliable events between services (outbox, worker, inbox), an end-to-end example that runs on every database, and a signed webhook publisher with a matching receiver.

### Changed
- On SQLite an invalid regular expression in `REGEXP`, `regexp_replace()` or `regexp_extract()` throws a `QueryException` (`Invalid regular expression '(': Compilation failed: ...`), as MySQL, MariaDB, PostgreSQL and Oracle do. It used to emit a PHP warning and act as "no match": `NOT REGEXP` selected every row and `regexp_replace()` returned an empty string, which an `UPDATE` wrote into the column. A pattern that is valid elsewhere but not in PCRE now fails instead of matching nothing.
- `insert()` and `replace()` return `null` when no key can be reported (a composite key, an unreportable generated key, a skipped `INSERT IGNORE`). Since 2.10 they returned `1` there, which looked like a real id and let code attach child rows to the wrong parent; before 2.10 they returned `0`. A failed insert still throws. `ActiveRecord` no longer treats the id as the success flag, and `Seed::insert()`, `Migration::insert()` and the generated repository `create()` return `int|string|null`.
- `insert()` no longer casts an id to `int` blindly: PDO reports it as a string, and a `BIGINT UNSIGNED` id above `PHP_INT_MAX` was returned as `PHP_INT_MAX`. It is an `int` when it fits and the numeric string otherwise, so the return type is `int|string|null`.
- `insertFrom()` and `replaceMulti()` return the number of affected rows (they returned the last insert id, which only matched the row count by coincidence).

### Fixed
- Comparing a JSON value with a number or a boolean (`whereJsonPath()`, `Db::jsonPath()`) compared text: `age > 27` selected the row with 5 (`'5' > '27'`), and `ok = true` matched the number 1 and `ok = false` nothing, on every database. A number is compared as a number and a boolean as a boolean now; a JSON value of another type does not match and never fails the statement, a string is still compared as text. Numbers are exact for a 64-bit integer and a fraction down to `1e-30` (`1e-18` on SQL Server); a number outside `1e-30 .. 1e35` (SQL Server: beyond `1e20`) matches no numeric condition. A JSON string that holds a number or a boolean (`"30"`, `"true"`) is not one on MySQL, MariaDB, PostgreSQL and SQLite; on SQL Server and Oracle it is, because the database converts the string.
- On MariaDB `jsonSet()` and `jsonReplace()` stored every value as a JSON string: `999` as `"999"`, `true` as `"true"`, an object as a string of JSON text. The value keeps its type now (`JSON_COMPACT`). Documents written before are not repaired; see UPGRADE.md.
- On SQL Server `jsonSet()` and `jsonReplace()` stored every value as a JSON string: `999` as `"999"`, `true` as `"true"`, a list as the string `"[1,2]"`, text with its own quotes (`"\"abc\""`) and `null` as the string `"null"`. Numbers, booleans, text, objects and lists are written as their JSON types now, and `null` as JSON `null`. Documents already written keep their strings.
- On Oracle `jsonSet()` stored every value as a JSON string the same way, and `jsonReplace()` did not replace anything: it added a key named like the path (`"$.a.b"`). Both use `JSON_TRANSFORM` now and write the JSON type of the value; `jsonReplace()` changes only an existing key. Documents already written keep their strings and stray keys.
- On SQLite `REGEXP`, `regexp_replace()` and `regexp_extract()` fail on a pattern containing `/` (`'path/to' REGEXP '^path/'` returned 0 with a PHP warning). The slash is a literal, as on the other databases; an already escaped `\/` keeps working.
- SQL Server created `DECIMAL(10)` for `decimal(10, 2)` (in `createTable()`, `addColumn()` and `alterColumn()`), so every value was rounded to a whole number (`45.67` was stored as `46`). The scale is kept now. Columns created earlier are not repaired; see UPGRADE.md.
- Insert keys stay correct after MySQL/MariaDB table changes and TEMP shadowing: metadata is read from the table the statement resolves to and remembered for the connection (no extra queries per insert); it is dropped when the connection runs DDL, `GRANT`/`REVOKE` or `USE`, or rolls back, but a change made by another session is not seen until then or until the connection is opened again. MySQL uses the connection's answer for an AUTO_INCREMENT key changed by a trigger; a supplied non-auto-increment key with a BEFORE INSERT trigger is unknown (`null`) instead of guessed from the input. SQL Server no longer reports an identity column outside a single-column primary key as the id. A real zero key is preserved, including keys read by `RETURNING` / `OUTPUT`; the dialect hook uses `false` for no reportable key and retains `null` for the connection fallback.
- Query parameters are bound with their own type instead of all as text (`PDOStatement::execute($params)` sent `1` as `'1'` and `false` as `''`). `where('active', false)` or an insert of `false` failed on PostgreSQL (`invalid input syntax for type boolean`) and MariaDB (`Incorrect integer value: ''`), and on Oracle `false` was stored as `NULL` or matched nothing; on SQLite a whole-number parameter compared with a column without a declared type (a CTE or derived table, an expression) matched nothing, so `ReadOnly` pagination over a CTE returned 0 rows. `null`, `int`, `bool` and strings keep their type; a float is still sent as text, so SQLite compares it with an untyped column as text. Oracle binds a boolean as the integer 0 or 1 (the `pdo_oci` build for PHP 8.4 binds `false` as `NULL`). SQL Server keeps sending everything except `null` as text, because it takes one type for a column of a multi-row `VALUES` list from all rows (`0` as an integer next to `45.67` is rejected); a custom dialect can choose the type with `DialectParameterTypesInterface`. JSON path comparisons (`whereJsonPath()`, `Db::jsonPath()`) send a string as text, because the extracted value is compared as text; a number or a boolean is compared as that type (see below).
- On PHP 8.4 a SQLite connection the library creates is a `Pdo\Sqlite` object, as it already was on 8.5, so the REGEXP functions are registered with `createFunction()`. A plain `PDO` passed in the `pdo` option still works; PHP 8.5 reports `PDO::sqliteCreateFunction()` as deprecated for it, so create the handle with `PDO::connect()`.
- `insert()` no longer reports an id that belongs to another row, and returns the key of the written row wherever the database can tell. PostgreSQL, SQLite, MariaDB 10.5+ (for a key that is not `AUTO_INCREMENT`, and for upserts) and SQL Server (for a non-identity key, `OUTPUT INSERTED.<key>`) read the key from the statement itself: an upsert names the row it inserted or updated (PostgreSQL returned the failed attempt's sequence value, SQLite the previous insert's rowid, MariaDB nothing after an update that changed no value), a skipped `ON CONFLICT DO NOTHING` / `INSERT OR IGNORE` / `INSERT IGNORE` returns `null`, and a text key or a UUID the database generated is returned as a string. MySQL and Oracle return the key you supplied for the table's single-column primary key, of any type. Where none of this applies, PostgreSQL reads its sequence by relation OID, so a changed `search_path` is a different table, and a secondary `SERIAL` column is not mistaken for the id. A custom dialect can opt in with `DialectInsertReturningInterface`. On MySQL an upsert returns the id of the row it inserted or updated (it returned the key the statement carried, which can belong to another row when a conflict on another unique column updates one), `null` when the update changed nothing, whatever `MYSQL_ATTR_FOUND_ROWS` makes `rowCount()` say; PostgreSQL reads the primary key's name on every insert, so a renamed column cannot break one; Oracle returns `null` for a composite key and ignores identity columns outside the primary key; SQLite looks a table up again when a TEMP table takes its name.
- Oracle returns an id beyond `PHP_INT_MAX` exactly (as a string), for generated and supplied keys; the dialect hook `extractInsertId()` may return `int|string|false|null`.
- Shard routing is exact for keys beyond `PHP_INT_MAX`: `ModuloShardStrategy` and `RangeShardStrategy` no longer cast them to `int` (which chose the wrong shard, or put them inside an `int` range). A raw numeric literal keeps its digits too.
- `getValue()`, `getColumn()` and `index()` work after `asObject()`; they returned `null`, `[]` or threw.
- Generated code uses the real API: the repository accepts string keys, returns `null` for a missing row (it returned `false` and failed the return type) and counts rows on Oracle; the API controller and the integration test called `insert()`, `update()`, `delete()`, `all()`, `one()` and `execute()` on objects that have none.
- The compiled-query cache ignored the values of `limit()` and `offset()`: with a PSR-16 cache configured, the second run of a query that differed only in its limit or offset returned the rows of the first (for example `paginate(3, 3)` returned page 1). The values are now part of the cache key.
- Cursor pagination (`cursorPaginate()`) and `batch()` / `each()` with a `key` threw on columns qualified with a table alias such as `o.created_at`; they now page through every row.
- A write that joins another table (`UPDATE ... JOIN`, `DELETE ... JOIN`) now also drops the cached reads of the joined tables; before, they stayed stale until they expired.
- `paginate()` threw `Cannot use object of type stdClass as array` after `asObject()`.
- `limit()` and `offset()` are restored when building a `UNION` query throws.
- `insert()` returned a wrong id in two cases. On Oracle it always returned `1`, because the id was looked up in a sequence named `<table>_seq` while the schema builder creates `<table>_<column>_seq` (and the lookup quoted the name in the wrong case); it now returns the real id (from the table's trigger, identity column or sequence default), or the key the insert supplied. On PostgreSQL it used `lastval()`, the last sequence value of the whole session, so an insert that supplied its own key returned the sequence's last value and an insert into a table without a serial key returned the id of an earlier insert into another table; it now reads the sequence of the inserted table only, including a hand-made `DEFAULT nextval()`.
- `limit()` and `offset()` throw `InvalidArgumentException` for a negative number. Before, the result depended on the database: MySQL, MariaDB, PostgreSQL and SQL Server failed with a syntax error, SQLite treated `limit(-1)` as no limit, and Oracle returned no rows for it and ignored a negative offset.

## [3.0.0] - 2026-11-02

This is a major release. Read [UPGRADE.md](UPGRADE.md) before updating: it lists every change that can alter results or
throw where 2.x worked, and the changes needed by custom dialects.

### Added
- ORM: a model remembers which key a mapped attribute name resolved to (Oracle returns upper-case column names, so `$model->user_name` has to find `USER_NAME`), instead of scanning the attributes on every read.
- AI: requests are repeated when they fail in a way that is likely to pass (HTTP 429, 408, 5xx, or no connection) with exponential waits; `retries` (`PDODB_AI_RETRIES`, default 2, 0 to 10) sets how often, and `RetryingAiTransport` wraps any `AiTransport`. Other failures are thrown at once. Token usage: `AiAnalysisService::getLastUsage()` and `getTotalUsage()` return an `AiUsage` (input, output and total tokens; null when the provider does not report them) for OpenAI-style APIs, Anthropic, Google, Ollama and Yandex.
- Outbox: `add()` takes `headers` (message information kept apart from the payload, read as `$message->headers`) and a `partitionKey`: messages with the same key are delivered one at a time in the order they were added (a message waiting for a retry holds its key back; a failed one does not). Message ids are now time-ordered UUID v7 (`Uuid::v7()`, strictly increasing within a process) so the order is defined. `Outbox` accepts an `OutboxSerializer` for the payload (output must be valid JSON), and the worker sends `OutboxMessageAddedEvent`, `OutboxMessagePublishedEvent`, `OutboxMessageRetryEvent` and `OutboxMessageFailedEvent` to the connection's event dispatcher. The outbox table gets the columns `headers` and `partition_key` and an index on `(partition_key, status, id)`; `ensureSchema()` adds the two columns to a table made by a pre-release build and keeps its rows.
- Polymorphic relations: `morphMany` and `morphOne` (`['morphMany', 'modelClass' => Comment::class, 'morphName' => 'commentable']`) and the owning side `morphTo`. They work lazily, with `with()` (one query per type for `morphTo`) and with `withCount()`. `MorphMap::register()` stores short aliases instead of class names in the type column, and `MorphMap::enforce()` accepts only registered aliases; an unknown type throws instead of returning null.
- `ActiveQuery::withCount('posts')` adds `posts_count` to each model with one grouped `COUNT(*)` query per has-many or has-one relation, and `withCount(['posts' => fn ($q) => $q->where('published', 1)])` counts only some rows. Other relation kinds are counted from the loaded rows. The count is readable like an attribute and is never saved or reported as a change.
- `Model::init()` runs when a model object is created, and `Model::afterFind()` plus `ModelAfterFindEvent` when a model is filled from a database row (queries, lazy and eager relations, pagination); what `afterFind()` sets does not make the model dirty. A subclass with its own constructor calls `parent::__construct()` to keep `init()`. All row-to-model conversions go through one method, `hydrate()`, instead of eight copies of the same three calls.
- `QueryExecutedEvent::toSafeArray()` returns what can be logged (the statement with literals removed, the number of bound values, timing, row count, driver, cache flag); `getSql()` and `getParams()` stay raw.
- `DialectInterface::supportsUpsertClause()` says whether `onDuplicate()` on an insert (`ON CONFLICT` / `ON DUPLICATE KEY UPDATE`) is available; it is false on SQL Server and Oracle, which upsert with `merge()`. Building the clause there throws `UnsupportedOperationException` (a `RuntimeException`, as before) instead of a bare `RuntimeException`. **Third-party dialects** that do not extend `DialectAbstract` must implement the method.
- `TableFormatter` (`cli`) renders the text tables of `pdodb table` and `pdodb monitor`; widths are measured in characters, so accented and CJK text lines up, and an over-wide table loses width from its widest columns first.
- `pdodb mcp` completion in the bash script, and tests that fail when the script's command list differs from the registered commands.
- `QueryBuilder::batch()` and `each()` (and `ActiveQuery::each()`) take a `key`: with it they page by a unique key instead of an OFFSET, so loops that update or delete the rows they read do not skip rows and deep pages cost the same.
- `Outbox::add()` accepts a `$delaySeconds` argument and `pdodb outbox work` a `--max-runtime=N` option; `MigrationRunner::setMigrationNamespace()` sets the namespace of application migration classes.
- `Command::knownOptions()`: `pdodb` now rejects an option a command does not declare (exit code 2, with a suggestion) instead of ignoring it; global `--connection`, `--config` and `--env` no longer stay in the process environment after the command.
- `DriverBranchingGuardTest` to forbid driver-name branching in shared code under `src/` (outside dialects).
- Dialect catalog query traits for AI/MCP metadata, `PlanExplainAvailability`, `ConnectionInitHandler`, and CLI/bootstrap hooks on `DialectInterface` (env completeness, init wizard, PDO extension, agent capabilities).
- Opt-in relation query diagnostics in profiler reports, with a possible N+1 warning and a runnable lazy-versus-eager loading example.
- Bind ActiveRecord queries and models to an explicit `PdoDb` with `Model::on()` and propagate the context through relations.
- Opt-in ActiveRecord optimistic locking using an integer version column; stale updates and deletes throw `OptimisticLockException`.
- Opt-in ActiveRecord casts for JSON arrays, backed enums and immutable UTC datetimes; explicit API presentations and model pagination with eager-loaded relations.
- In-process `profileReport()` for the current connection's profiler, local plan diagnosis, and a JSON export. Another process does not see that memory.
- `pdodb profile summary` reads a profile exported by the application, and `pdodb profile diagnose` inspects caller-supplied SQL. Use `--text` on summary/import for a readable report.
- `Model::preventLazyLoading()` to opt out of lazy-loading relations.
- `PlanComparison` for two plans the caller collected. It does not execute DDL or model SQL. PostgreSQL `EXPLAIN ANALYZE` timings mean the statement ran.
- `pdodb ai doctor` JSON summary of provider, model, key presence, endpoint, and database connection without calling a model. `--probe` makes one short provider request and may incur a charge. The key and response body are not printed.
- Init wizard can write multiple named connections into `config/db.php`.
- Model generator emits `belongsTo` relations from foreign keys and accepts explicit table-to-model mappings for custom names.
- Transactional outbox (`Outbox`, `OutboxWorker`): atomic `add()` inside a transaction, worker claim/retry with user-defined publish callback, and dialect-specific claim SQL for PostgreSQL, MySQL/MariaDB, SQL Server, and single-worker mode elsewhere.
- `pdodb outbox work` and `pdodb outbox purge` CLI commands with `--publisher`, retention `--older-than`, and `--dry-run` purge.
- `pdodb outbox status`, `list`, and `retry` for queue inspection and requeueing failed messages; programmatic `Outbox::countByStatus()`, `listMessages()`, and `requeueFailed()` / `requeueAllFailed()`.
- Outbox integration tests with multiple independent database connections per dialect (MySQL, MariaDB, PostgreSQL, SQL Server, Oracle) to assert each pending message is published exactly once.
- ActiveRecord behaviors for timestamps, soft deletes, and UUID/ULID keys; `transactional()` saves that roll back in-memory state; `afterCommit()`; `firstOrCreate()` / `updateOrCreate()`; atomic `increment()` / `decrement()`; `updateAll()` / `deleteAll()`; model `each()` / `chunk()` / `cursor()`; richer validators; `hiddenAttributes()` / `visibleAttributes()`; and many-to-many `attach()` / `detach()` / `sync()`.
- Consumer `Inbox` for idempotent handling of at-least-once messages, plus `PdoDb::afterCommit()` / `afterRollback()`.
- Shared `Inflector` helper for English table and class names (`tableize`, `classify`, default foreign-key stems).
- Inbox claims insert `processed_at` using the dialect clock format so strict SQL modes and existing tables stay consistent.
- Documentation for a read-only database account for the MCP `diagnostics` connection (PostgreSQL, MySQL/MariaDB, SQL Server, Oracle, SQLite), including why `inspect_query`'s SELECT/WITH check is not a security boundary.
- `unique` validator option `withoutTrashed` to ignore soft-deleted rows (they count as taken by default).

### Changed
- `QueryBuilder` hands connection choice (shard, read replica, write connection) to `QueryRouting` and global-scope bookkeeping to `GlobalScopes`; its public methods are unchanged.
- MySQL 8.0.19 and newer can read the inserted row in `INSERT ... VALUES ... ON DUPLICATE KEY UPDATE` through a row alias (`AS pdodb_new (pdodb_c0, pdodb_c1) ... col = pdodb_new.pdodb_c1`) instead of `VALUES(col)`, which MySQL deprecates since 8.0.20 and answers with a warning. It is **off by default**: set `use_upsert_alias` to `true` in the connection configuration. The alias has column names of its own, so an unqualified `col` in an expression (`Db::raw('age + 5')`, `Db::inc()`) still means the table's column. MySQL before 8.0.19, MariaDB (also behind the `mysql` driver) and `INSERT ... SELECT`, which has no alias, keep `VALUES(col)` whatever the setting. Applies to `onDuplicate()` with `insert()` and `insertMulti()` and to `firstOrCreate()` / `updateOrCreate()`. `DialectInterface::applyConfig()` hands connection settings to the dialect (no-op by default), and **third-party dialects** that do not extend `DialectAbstract` must implement it and `buildUpsertClauseForValues()`.
- **Breaking for third-party dialects only:** `DialectInterface` requires `configOptimizerStrategy()` (`?ConfigOptimizerStrategy`; `DialectAbstract` returns null, which gives an empty `pdodb optimize` report). The optimizer engine no longer switches on driver names: MySQL/MariaDB, PostgreSQL, Oracle, SQL Server and SQLite recommendations are `ConfigOptimizerStrategy` classes (`dialects/config`) supplied by the dialect, and `DatabaseConfigOptimizerEngine` takes a strategy as an optional second argument. `DialectAbstract` no longer emits MySQL's `TRUNCATE(x, n)` and `x REGEXP 'p'` by default; `formatTruncate()` and `formatRegexpLike()` throw `UnsupportedOperationException` unless the dialect defines them (all built-in dialects do).
- `CacheManager` (1300 → 500 lines) depends on a `CacheBackend` port (`cache/backend`) for what PSR-16 lacks: atomic statistics counters and key lookup by pattern. `RedisBackend` (ext-redis or Predis), `MemcachedBackend` and `ApcuBackend` replace the reflection code inside the manager; `CacheBackendDetector` still finds the connection behind a PSR-16 wrapper, and an application can pass its own backend as the third constructor argument. Statistics live in `CacheStatistics`; without a backend they are written to the cache every ten operations, and `CacheManager::flushStats()` writes them at once. With a backend, counts are atomic on the server and no longer counted twice until the next flush. Cache type detection no longer reports every PSR-16 cache as Redis because it has `get()` and `set()` methods. Table names with a quoted schema (`` `app`.`users` ``) are normalized to `users` when entries are stored and invalidated.
- AI requests go through an `AiTransport` (`ai`): `StreamAiTransport` sends them with PHP streams as before, and a provider constructor or `AiAnalysisService` accepts another implementation (a PSR-18 client, a proxy, a fake in tests).
- `ExplainAnalyzer`, `RecommendationGenerator` and `JsonQueryBuilder` require only the dialect roles they use (`DialectDdlInterface`, `DialectQuotingInterface`, `DialectCatalogInterface`, `DialectJsonInterface`) instead of the whole `DialectInterface`.
- Removed code nothing called: `SqlQueryCollector`, and the per-driver `MonitorManager::*MySQL/PostgreSQL/MSSQL/SQLite` methods and conversion helpers (the manager delegates to the dialect, as before).
- The CLI can be driven by supplied input: `CliOutput` takes an optional input stream that answers prompts instead of the terminal (used by the tests of the interactive `init` wizard).
- TUI dashboard: `Dashboard` (4500 → 1300 lines) is split into `DashboardState` (what is shown), `DashboardViews` (fullscreen and detail screens) and `SqlScratchpad` (editor, autocomplete, history, execution, export). The eight copies of the pane-switch code are one method. Subclasses of `Dashboard` that overrode the scratchpad or fullscreen/detail rendering internals must now override or replace those classes; the command-line behavior is unchanged.
- `PdoDb` delegates transactions, savepoints and after-commit callbacks to `TransactionManager` (1540 → 1240 lines); the public methods are unchanged. `MigrationRunner` and `SeedRunner` share `AbstractFileRunner` (dry-run, collected queries, history-table check, event dispatcher, directory check). `QueryBuilder` uses one helper for JSON selections and orders instead of five copies.
- Oracle: SQL rewriting moved from `OracleDialect` (3600 → 3200 lines) to `OracleSqlNormalizer`; `dumpData()` and `restoreFromSql()` of every dialect share one implementation with small per-dialect hooks (`listDumpTables()`, `dumpInsertStatement()`, `restoreSplitter()`).
- `DialectInterface` is built from role interfaces (`dialects/roles`), and `DialectAbstract` takes its default implementations from one trait per role (`dialects/traits`, 2200 → 600 lines). Method signatures are unchanged. Outbox and inbox storage depend on the narrow messaging and quoting roles. **Breaking for third-party dialects only:** `buildIdempotentUpsertSql()` no longer defaults to `ON CONFLICT`; add `OnConflictUpsertTrait` where the syntax applies.
- **Breaking:** `ActiveRecord::setAttributes()` sets only safe attributes by default (`$safeOnly = true`): the ones in `safeAttributes()` if defined, otherwise those mentioned in `rules()`, minus the new `Model::unsafeAttributes()`. Other keys are ignored; a model with neither `rules()` nor `safeAttributes()` throws `MassAssignmentException`. Use `forceFill()`, `setAttribute()` or `setAttributes($values, false)` for trusted data. `Model::preventSilentlyDiscardingAttributes()` turns ignored keys into a `MassAssignmentException` (off by default).
- The default OpenAI model is now `gpt-5.6-luna`. This model family does not accept an explicit temperature; set `ai.model` to a `gpt-5.2`/`gpt-5.4` model to keep using one.
- The CLI no longer changes its behaviour when the `PHPUNIT` environment variable is set. Interactivity comes from `PDODB_NON_INTERACTIVE` or a missing terminal (`CliEnvironment`), and a failing command now throws `CliExitException`, which `Application::run()` turns into the exit code. Test runs set `PDODB_NON_INTERACTIVE=1` in `phpunit.xml`.
- Duplicate numbering removed: `examples/02-intermediate/08-pagination-advanced.php`, `examples/11-schema/09-advanced-ddl.php`, `examples/12-reliability/06-error-diagnostics.php` and `documentation/05-advanced-features/29-dialect-specific-schema.md` were renumbered (links updated).
- **Breaking:** model connection overrides are isolated by class; use `Model::setDb($db)` for a shared default. Scoped record writes now honor global scopes, and terminal ActiveQuery methods return their delegated results.
- **Breaking:** `ActiveQuery::each()` yields models. Use `getQueryBuilder()->each()` for raw rows. `DialectInterface` now requires `buildIdempotentUpsertSql()`, `buildInboxSchemaSql()`, `outboxNowSql()` and `outboxNowPlusSecondsSql()`, and `buildOutboxClaimOneSql()` takes the lock duration in seconds instead of bound time parameters. A third-party dialect must implement them.
- Outbox timestamps (`created_at`, `available_at`, `locked_until`, `published_at`) and lease, backoff and `purge` comparisons use the database clock in UTC, stored in native types: `DATETIME(6)` on MySQL/MariaDB and `TIMESTAMP(6)` on Oracle (previously `VARCHAR`); SQLite keeps UTC text. The outbox is new in 3.0, so only tables made by pre-release builds are affected: recreate them (`ensureSchema()` adds missing `headers` and `partition_key` columns, but cannot convert column types).
- **Breaking:** `TimestampBehavior` and `SoftDeleteBehavior` write the database clock (`NOW()` on the dialect) instead of the PHP clock and reload the stored value after saving; they follow the connection time zone. A `value` option (closure, `RawValue` or literal) overrides it. Idempotent upsert rows (`firstOrCreate()` / `updateOrCreate()`) accept `RawValue` expressions. `DialectInterface::transformValueForBinding()` gets an optional `$table` argument.
- **Breaking:** condition operators passed to `where()`, `having()`, `whereColumn()`, `whereJsonPath()` and `FilterValue::filter()` are validated against an allowlist (`=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `EXISTS`, `NOT EXISTS`, `IS`, `IS NOT`, `LIKE`, `NOT LIKE`; case and spacing are normalized) and anything else throws `InvalidArgumentException`.
- **Breaking:** `DialectInterface` requires `quoteStringLiteral()`. Values that must appear in SQL text (aggregate separators, pad strings, `DEFAULT` values, `COMMENT`s, ENUM/SET members, introspection table names) are now quoted with it instead of `addslashes()` or raw interpolation. `addslashes()` doubled the quote only on MySQL, so on PostgreSQL, SQLite, SQL Server and Oracle a value containing a quote could break out of its literal.
- **Breaking:** the MySQL/MariaDB PDO connection no longer sets `PDO\Mysql::ATTR_LOCAL_INFILE` by default. Pass it as the PDO options argument (`new PdoDb('mysql', $config, [\PDO\Mysql::ATTR_LOCAL_INFILE => true])`) to use `LOAD DATA LOCAL INFILE`.
- **Breaking:** JSON path segments that are not plain identifiers or indexes (for example `my-key` or `a b`) are written as quoted JSON path keys (`$."my-key"`), and the path is escaped for the SQL literal. `Db::replace()` binds its search and replacement strings. `now($diff)` throws `InvalidArgumentException` for an interval expression containing anything other than letters, digits, spaces and `+-:.,_`.
- **Breaking:** `OutboxStorage::claimBatch()` returns an `OutboxClaim` (`messages`, `unreadable`) instead of a list.
- **Breaking:** outbox claiming is a per-dialect `OutboxClaimStrategy` returned by `DialectInterface::outboxClaimStrategy()`. It replaces `supportsOutboxConcurrentWorkerClaim()`, `buildOutboxClaimOneSql()`, `outboxClaimSqlReturnsClaimedRow()`, `buildOutboxLockCandidateSql()` and `buildOutboxPendingIdsSql()`. A worker batch is now claimed in one transaction and one query round (PostgreSQL, MySQL/MariaDB, SQL Server, SQLite) instead of one transaction per message, and all rows of a batch share one `locked_by` token. `formatInboxProcessedAt()` is removed; the inbox `processed_at` is filled by the database clock again.
- Inbox ids may be up to 190 characters, and `processed_at` is a native datetime column filled by the database clock.
- **Breaking:** the model generator emits `@property` tags instead of a public `$attributes` array.
- **Breaking:** default `Model::tableName()` uses `Inflector::tableize()` (English pluralization) instead of appending `s` only. Override `tableName()` when your schema uses a different convention.
- **Breaking:** `DialectInterface` requires `formatInboxProcessedAt()` for inbox claim timestamps.
- **Breaking:** nested `transaction()` calls no longer commit an existing transaction; they use savepoints when supported.
- **Breaking:** `setAttributes(..., safeOnly: true)` throws `MassAssignmentException` when the model does not declare `safeAttributes()`.
- **Breaking:** `DialectInterface` requires `executePlanExplain()`. A third-party dialect must implement it. `getSlowestQueries()` sorts by total time and includes `count` and `possible_n_plus_one`.
- **Breaking:** Removed `--pretend` and `MigrationRunner::setPretend()` / `SeedRunner::setPretend()`. Use `--dry-run` and `setDryRun()` only.
- **Breaking:** AI configuration now uses one selected provider with `PDODB_AI_API_KEY`, `PDODB_AI_MODEL` and, when needed, `PDODB_AI_BASE_URL`; PHP uses a flat `ai` array. Provider-specific keys and nested provider settings must be migrated as described in the AI configuration reference.
- MySQL and MariaDB now share `MySQLFamilyDialect`, `MySQLFamilyDdlBuilder`, `MySQLFamilyDmlBuilder`, `MySQLFamilySqlFormatter` and `MySQLFamilyDdlQueryBuilder` (`dialects/mysqlfamily`) instead of duplicating about 200 methods; `MariaDBDdlQueryBuilder` no longer extends `MySQLDdlQueryBuilder`, and `SessionQuoteMode` moved to `dialects\mysqlfamily`. The public class names and behavior of `MySQLDialect` and `MariaDBDialect` are unchanged, except that MariaDB now also reads `PDODB_UNIX_SOCKET`, `PDODB_SSLCA`, `PDODB_SSLCERT`, `PDODB_SSLKEY` and `PDODB_COMPRESS`.
- Move database server tuning recommendations into `dialects/config/DatabaseConfigOptimizerEngine`; CLI `DatabaseConfigOptimizer` is a thin facade.
- `DialectRegistry::normalizeDriverName()` accepts common aliases (`postgresql`, `mssql`, `oracle`, …).
- `ErrorUtilityTrait` and exception detection strategies resolve retryable codes and descriptions through dialects only.
- CLI command suggestions (`Did you mean`) are written to stderr. `pdodb migrate` / `pdodb seed` honor `--help` on any subcommand (e.g. `pdodb migrate up --help`).
- Share AI prompt and analysis flow across providers, and use the OpenAI-compatible transport for DeepSeek.
- Update default OpenAI, Anthropic, Google and DeepSeek models, and simplify the AI setup wizard to configure only the chosen provider.

### Fixed
- The error-log redaction also covers values in the first line of driver messages: PostgreSQL `invalid input syntax for type integer: "..."`, `value "..." is out of range`, enum and array literals, and `CONTEXT`/`STATEMENT` lines; MySQL `Truncated incorrect DOUBLE value: '...'`; SQL Server `Conversion failed when converting the nvarchar value '...'`, overflow and `Truncated value: '...'`. Names of tables, columns and constraints stay.
- `Outbox::ensureSchema()` also creates the partition index on an older MySQL/MariaDB outbox table (those databases declare it inside `CREATE TABLE`, so the idempotent index statement other databases use does not exist); new `DialectMessagingInterface::outboxMissingIndexSql()`.
- `pdodb optimize analyze` on Oracle asks for the foreign keys of all tables in one statement instead of one per table (each `USER_CONSTRAINTS` query costs about a second, so 99 tables took minutes); new `DialectCatalogInterface::buildShowAllForeignKeysSql()`, null where per-table queries are fine. The per-table foreign key query uses joins and a table filter that can use the dictionary index.
- `composer.lock` is in sync with `composer.json` (`ext-mbstring`).
- `->unique()` on a TEXT column no longer fails with a raw server error on MySQL (`1170 BLOB/TEXT column used in key specification without a key length`) and SQL Server (`NVARCHAR(MAX)` cannot be an index key): `createTable()`/`addColumn()` throw an `UnsupportedOperationException` that says to use `string(190)->unique()`. MariaDB, PostgreSQL, SQLite and Oracle enforce it as before. The examples use `string(190)->unique()`.
- `Outbox::ensureSchema()` adds the `headers` and `partition_key` columns to an outbox table made by an earlier build instead of leaving it to fail on the first insert.
- The string-helpers example skips its regular-expression part on SQL Server before 2025.
- Global scopes registered with `PdoDb::addScope()` now hold for `batch()`, `each()`, `stream()`, `exists()`, `notExists()`, `toSQL()`, `update()` and `delete()`, not only for `get()` and friends. `ActiveQuery::getScopedQueryBuilder()` returns the builder with the model's scopes applied; `withCount()` uses it, so rows hidden by the related model's scope are no longer counted.
- `stream()`, `batch()` and `each()` read on the connection a `get()` would use (replica or shard) for as long as they run; `stream()` used to switch back before the first row was read.
- Queries inside a transaction bypass the shared query cache, so uncommitted rows are never served to or left behind for other connections.
- A custom `OutboxSerializer` that throws while reading a payload fails that message only; it no longer rolls back the claim and blocks the queue.
- The library's error logs no longer contain the exception object or data values from driver messages (PostgreSQL `DETAIL`, MySQL `Duplicate entry`, SQL Server duplicate key value); the context has `exception_class`, `exception_code` and `exception_message` with values redacted.
- A driver's native error code is consulted before the words of the message, so `ORA-00001` on a table named `TRANSACTION_ID_UQ` is a constraint violation, not a retryable transaction error.
- Optimizer commands keep the recommended memory exactly (`shared_buffers = '1536MB'`, not `'1GB'`).
- `ext-mbstring` is declared in `composer.json` and the README; driver names no longer need it to be normalized.
- `QueryExecutedEvent::toSafeArray()` takes the quoting rules from the dialect instead of a list of drivers. `SqlContextTokenizer` moved from `tommyknocker\pdodb\ai` to `tommyknocker\pdodb\helpers`, and the SQLite-only inbox and outbox SQL moved from the shared defaults into `SqliteDialect`.
- Eager loading a has-many-through relation with `viaTable` returned no related models on Oracle: the junction rows came back with upper-case column names and were skipped.
- `QueryBuilder::explainAnalyze()` on Oracle failed with ORA-01036 as soon as the query had a bound value, and on SQL Server returned the query's own rows instead of a plan. The builder now hands the statement to the dialect (`executeExplainAnalyze()`), as `PdoDb::explainAnalyze()` and `explain()` already did.
- `pdodb optimize` wrote every number as bytes: PostgreSQL `max_connections = 200` became `'200B'`, `random_page_cost = 1.5` became `'1B'`, MySQL `max_connections = 2048` became `2K` and Oracle `processes = 300` became `0M`. Only memory settings get a unit now; counts and ratios are written as they are, and a quote in a PostgreSQL value is doubled.
- `simplePaginate()` and `paginate()` leave the builder's own `LIMIT` and `OFFSET` in place when the query fails; before, a failing `simplePaginate(1, 1)` left `LIMIT 2 OFFSET 0` on a builder that had `LIMIT 7 OFFSET 1`.
- `cursorPaginate()` left its cursor condition on the query builder, so every further call on the same builder stacked another one (it worked only because the placeholders had equal names). The builder's conditions are restored after each page.
- SQL Server and Oracle errors are classified by their native error number as well as by message text: the SQLSTATE is too coarse there (`23000` for every integrity violation, `HY000` for everything in Oracle), so a reworded or localized message sent a unique violation, deadlock or login failure to a generic `QueryException`. `ErrorCodeRegistry::getNativeErrorCodes()` lists the numbers per driver, and a number is only compared with the codes of the driver that raised it.
- `MigrationRunner::migrateDown()`, `migrateUp()` and `migrateTo()` send the same `MigrationStartedEvent` / `MigrationCompletedEvent` / `MigrationRolledBackEvent` as `migrate()`; before, only `migrate()` (and the rollback by version) did. Rolling back a seed sends the new `SeedRolledBackEvent`.
- Migration and seed history is ordered by batch and name after the time, so two migrations applied in the same second are rolled back newest first instead of in database order.
- `DialectInterface::supportsTransactionalDdl()` belongs to the transaction role (`DialectTransactionInterface`), not the quoting one; the method itself is unchanged.
- SQL Server 2025 and later (server version 17) now run `Db::regexpMatch()`, `regexpReplace()` and `regexpExtract()` with the native `REGEXP_LIKE` / `REGEXP_REPLACE` / `REGEXP_SUBSTR`, chosen from the server version; older servers keep the `LIKE` translation. The native path is tested against SQL Server 2025 (17.0); the MSSQL suite runs against any server selected with `PDODB_MSSQL_TEST_HOST` / `PDODB_MSSQL_TEST_PORT`.
- `Db::jsonLength()` on SQL Server failed with a JSON error for a scalar member and on SQLite for a string member; both now return 1, like MySQL.
- `->unique()` on a column in `createTable()` / `addColumn()` was silently ignored on SQLite, MySQL, MariaDB, PostgreSQL and SQL Server (only Oracle wrote `UNIQUE`), so the column accepted duplicates. It now produces the constraint on every dialect; SQLite, which cannot add a `UNIQUE` column to an existing table, gets a unique index right after the column.
- `loadCsv()`, `loadXml()` and `loadJson()` left the connection inside an open transaction when the file could not be read, and committed a transaction the caller had started. They now commit and roll back only a transaction they started themselves, and always roll back on failure.
- A CLI command that failed with a library exception (for example `table columns alter` on SQLite) ended in a PHP fatal error with a stack trace. The CLI now prints `Error: <message>` and exits with 1; set `PDODB_DEBUG=1` to get the exception and its trace. Commands also no longer print the error prefix twice (`Error: Error: ...`).
- `QueryCompilationCache::clear()` did nothing; it now forgets the compiled queries it stored.
- SQLite full-text search with several columns (`Db::match(['title', 'body'], ...)`) produced invalid SQL (`a, b MATCH x`); it now matches when any of the columns does, like MySQL's `MATCH (a, b)`.
- `pdodb init` with `PDODB_CACHE_TYPE=FILESYSTEM` kept the upper-case name in the generated configuration; the cache type is now lower-cased.
- `Db::regexpMatch()`, `regexpReplace()` and `regexpExtract()` with a backslash in the pattern (`a\.c`, `\d`) matched the wrong rows on MySQL and MariaDB, because the pattern was written into a string literal without escaping the backslash (it also let a pattern ending in `\'` break out of the literal). They now quote the pattern like any other value.
- SQL Server: regular expressions were a `PATINDEX` approximation that returned wrong rows (`.` and `*` were rewritten, `^` and `$` dropped) and `regexpExtract()` returned the rest of the string instead of the match. Patterns are now translated exactly to `LIKE`, or rejected with `UnsupportedOperationException` when `LIKE` cannot express them; no helper functions are created in the database any more. See [Regular Expressions on SQL Server](documentation/07-helper-functions/02-string-helpers.md).
- `Db::jsonLength()` returned 0 for a JSON object on SQLite and PostgreSQL and 1 on Oracle; it now returns the number of keys, like MySQL (arrays: elements, scalars: 1, missing: 0).
- TUI dashboard: Esc closed the SQL autocomplete list only after leaving fullscreen (it left fullscreen instead); the query-history keys were unreachable and are now PgUp/PgDn in the editor; two debug writes to `/tmp/pdodb_autocomplete.log` on every keystroke are gone.
- TUI dashboard: the SQL scratchpad always reported `Affected: 0` for INSERT, UPDATE and DELETE; it now shows the real row count (and for a script, the count of its last statement). The help screen said panes `1-4`; there are eight.
- Restoring a dump (`pdodb dump restore`, `DumpManager::restore()`) removed the line breaks from strings that span several lines, skipped blank lines and lines starting with `--` inside them, and (SQLite) cut statements at the wrong semicolon. Dumps of PostgreSQL, SQL Server and Oracle data also doubled every backslash and wrote non-ASCII text to SQL Server without the `N` prefix. All dialects now share one statement splitter (`SqlScriptSplitter`, which understands quotes, comments, `$tag$` bodies and `[identifiers]`) and write literals with the dialect's own `quoteStringLiteral()`.
- A query builder can be executed more than once. Its bound values were discarded after the first run, so a second `get()` on a builder with conditions silently returned nothing on SQLite (and failed elsewhere). Parameters now stay with the builder, and each run sends only those the SQL contains (`ParameterManager::getParamsFor()`).
- `indexExists()` always returned false on PostgreSQL, SQL Server and Oracle, and `uniqueExists()` on SQLite and Oracle: catalog column names, letter case and constraint type codes differ between dialects. `indexExists()`, `uniqueExists()`, `foreignKeyExists()` and `checkExists()` now compare names case-insensitively and read each dialect's catalog correctly.
- SQL Server `whereJsonContains()` never matched a string element because the value was compared as quoted JSON text.
- `Db::not(Db::regexpMatch(...))` produced `REGEXP` on PostgreSQL, SQL Server and Oracle, where that syntax does not exist; the negation is now applied after the dialect has rendered the match.
- `Db::jsonKeys()` returned the constant `'[keys]'` on PostgreSQL, SQLite and Oracle. It now returns a JSON array of the object's keys (NULL for a non-object) on PostgreSQL, SQLite, SQL Server and MySQL/MariaDB; Oracle throws `UnsupportedOperationException` because SQL cannot list JSON keys there.
- PostgreSQL `Db::regexpExtract()` returned the wrong group (or NULL) for patterns with capture groups; the whole match is now `groupIndex` 0/null and group N is N.
- SQLite `Db::truncate()` rounded negative numbers away from zero, and `Db::groupConcat()` with `DISTINCT` and a separator was rejected by SQLite; the latter now works with the default `,` and throws `UnsupportedOperationException` for any other separator.
- File imports (`loadXml()` on SQLite, SQL Server and Oracle) dropped every other `<row>` element. Oracle imports (`loadCsv()`, `loadXml()`, `loadJson()`) failed because Oracle has no multi-row `VALUES`; they now use `INSERT ALL`.
- Oracle `lock()` produced invalid SQL (`IN WRITE MODE`); `WRITE` now locks in `EXCLUSIVE` mode and `READ` in `SHARE` mode. Oracle `explainAnalyze()` failed with ORA-00905, and bound values are now replaced by whole parameter name.
- Oracle `pdodb db create` created a user with the password `password`; it now creates a schema-only account (`NO AUTHENTICATION`, Oracle 18c+).
- `ActiveRecord` skips the per-read attribute-name lookup for dialects that do not rename columns; `SelectQueryBuilder` builds its AI helper `PdoDb` once per connection.
- Move MSSQL CASE/WHEN reserved-word quoting, nested transactions/savepoints, INSERT…SELECT expansion, MCP statement timeouts, ORM Oracle hooks, and AI metadata collection into dialect implementations so shared layers delegate instead of branching on driver names.
- `QueryInspectionService` treats synthetic MSSQL plans as unavailable while `explainAdvice()` still receives estimated plan rows.
- Align dialect retryable error codes and descriptions with `DbError` after centralizing error helpers on dialects.
- Move MSSQL and Oracle plan-inspection limits into `DialectInterface::resolvePlanExplainAvailability()` so shared AI services no longer branch on driver names.
- Resolve unambiguous Oracle column names for ActiveRecord attributes while preserving exact-case matches.
- Apply ActiveRecord global scopes to query and model writes, preserve terminal method return values, and isolate model connections with an explicit shared default.
- Nested `transaction()` callbacks use database-specific savepoints instead of committing the outer transaction.
- Read/write splitting and sharding now change where a query runs. Before, the builder switched its own connection but its select, DML, join and JSON components kept the original one, so reads never reached a replica and sharded rows were written to the default connection. Components, the execution engine and the value resolver are rebound in place, which also keeps the profiler, and the builder returns to its own connection after the query even without a read/write router. Rows written with `insert()`, `insertMulti()` and `replace()` are routed by their shard key; rows of one `insertMulti()` that belong to different shards throw `LogicException`.
- **Breaking:** migrations no longer run inside a transaction on MySQL, MariaDB and Oracle (`DialectInterface::supportsTransactionalDdl()` is false there). Those databases commit DDL implicitly, so the transaction used to roll back only the history row and leave the schema half changed. Such a migration that fails now says that its statements stay applied and is not recorded. `Migration::transactional()` (null follows the database, true forces, false opts out) replaces the unused `safeUp()`/`safeDown()`, which are removed.
- **Breaking:** an unknown validator name or a malformed entry in `rules()` throws `InvalidArgumentException` instead of silently skipping the rule, so a typo such as `requried` can no longer let invalid data be saved.
- **Breaking:** `RetryableConnection` retries only reads (`SELECT`, `SHOW`, `DESCRIBE`, `EXPLAIN`) by default. A write that fails on a dropped connection or a deadlock may already have been applied, so repeating it could duplicate or double-apply it; set `retry.retry_writes` to `true` when your writes are idempotent.
- The retry strategy now recognizes the converted `DatabaseException` (whose `getCode()` is always 0), so retries based on the driver code or the exception's retry verdict work on the same path the connection actually throws.
- Error classification recognizes SQL Server and Oracle errors: unique, primary key, foreign key and NOT NULL violations, login failures, unreachable servers and lost connections, deadlocks and lock timeouts. Before, SQL Server error 2627 and every Oracle error other than a few constraint codes fell through to a generic `QueryException`, so they were never retried or categorized.
- **Breaking:** `paginate()`, `simplePaginate()` and `cursorPaginate()` no longer read `$_GET['page']` or `$_GET['cursor']`. A null page means page 1 and a null cursor means the first page; pass the request value explicitly. A library that reads a superglobal makes results depend on the caller's environment, and an array in `?cursor[]=` crashed the decoder.
- `Connection::prepare()` no longer hands its `$params` to `PDO::prepare()` as driver options, where positional values were interpreted as PDO attribute numbers; the values are kept and used by `execute()` when it is called without its own.
- Document that `QueryExecutedEvent::getParams()` returns raw bound values and must be filtered before logging.
- **Breaking:** a query on a sharded table whose shard key is unknown (no `WHERE` value, or no value in the inserted rows) throws `LogicException` instead of silently running on the default connection, which read or wrote only part of the table. A value that falls into no shard range, or a shard with no connection, now surfaces its error instead of being written to the default connection. Use `connection($name)` for a task that spans shards.
- `enableReadWriteSplitting()` registers the connections added before it was called, the default one included; before, only later `addConnection()` calls took part, so a read/write setup started from `new PdoDb(...)` had no write connection.
- `onDuplicate()` takes an optional conflict target (`onDuplicate($update, ['tenant', 'code'])`) for PostgreSQL and SQLite; before, the target was always `id` or the first inserted column, which fails on tables whose unique key is composite or is not `id`. `QueryBuilderInterface::onDuplicate()` and `DmlQueryBuilderInterface::onDuplicate()` gain the optional parameter.
- Connection parameters that are written into a DSN (host, database, port, service name, TLS and other options) are rejected when they contain `;`, a NUL or a line break, because a DSN has no escaping and `app;host=elsewhere` would redirect the connection.
- The MariaDB dialect uses `MariaDBExplainParser`, which was never wired in. `DialectAbstract::formatGroupConcat()` throws `UnsupportedOperationException` instead of emitting MySQL's `SEPARATOR` syntax for any dialect that forgot to override it.
- CLI: the generators (`model`, `repository`, `service`, `dto`, `enum`, `docs`, `api`, `tests`) throw `GenerationCancelledException` when the user declines to overwrite a file instead of calling `exit(0)`, so they can be used as a library and the command still exits with 0. `pdodb query test` without a query fails with a clear message when there is no terminal instead of spinning at 100% CPU. A mistyped `--dry-run` (`--dryrun`, `--dry_run`) is rejected with exit code 2 instead of being ignored, which ran the real command. `pdodb user create|password` accept `--password-env=NAME` and `--password-stdin`; `--password <pass>` still works but warns that it is visible in the process list. The CLI version falls back to `dev` instead of a hard-coded `2.12.0`.
- **Breaking:** `Model::firstOrCreate()` and `Model::updateOrCreate()` write through `save()` instead of a raw upsert. Validation (`rules()`), behaviors and model events now run for them, as for any other save. When validation fails they return the unsaved model (`getIsNewRecord()` is true, `getValidationErrors()` is filled) instead of persisting an invalid row; callers that assumed a saved model must check that. A lost race on the unique key is caught: the row the competing request inserted is returned (and updated by `updateOrCreate()`). The search columns still need a unique constraint. `Model::normalizeUpsertValue()` and `upsertBySearch()` (protected) are removed.
- **Breaking:** query-result cache entries are dropped after a write to the table they read (`cache.auto_invalidate`, on by default). Before, `insert`/`update`/`delete`/`truncate`/`merge` left stale rows in the cache until the TTL ran out. `CacheManager::invalidateTable()` is public for raw SQL and for invalidating after `COMMIT`; set `auto_invalidate` to `false` for the old behavior.
- **Breaking:** `Db::concat()` treats a string argument as a column name only when it is a bare identifier (`name`, `users.name`) and keeps a well-formed quoted literal (`"' - '"`) as written; any other string is now an escaped text literal. Before, strings containing parentheses or operators, and any string starting and ending with a quote, were emitted as raw SQL, so user text could inject. Pass SQL fragments as `Db::raw()`.
- `cursorPaginate()` builds a correct keyset condition: `(a > x) OR (a = x AND b > y)`, with `<` for `DESC` columns. Before, each column was compared on its own with `>`, which skipped or repeated rows when several rows shared the first column and ignored descending order. A cursor that lacks a value for an ORDER BY column throws `InvalidArgumentException`. `simplePaginate()` and `cursorPaginate()` restore the builder's limit/offset.
- Eager loading of a `HasManyThrough` relation runs a fixed number of queries; before, it queried the junction table once per owner model (and loaded the intermediate relation once per owner for `via`).
- `bin/pdodb` no longer sets `PHPUNIT=1` when `PDODB_NON_INTERACTIVE` is set, which turned every failing command in a CI job into an uncaught exception and exit code 255. The TUI dashboard restores the terminal in a `finally` block, and without `ext-pcntl` it reads Ctrl+C as a key instead of leaving the terminal in raw mode.
- AI: `base_url` of the Microsoft provider and the Ollama URL are validated like the OpenAI-family ones (absolute http(s), no credentials, query or fragment); the Yandex provider no longer copies the provider's response body into exception messages and no longer discards answers that resemble a prompt; the structured advice prompt contains a real newline instead of the characters `\n`; the MCP server reports the package version instead of a fixed `1.1.0`; `AiAnalysisContext` and `AgentContextService` no longer depend on the CLI layer; a disabled `allow_url_fopen` gives a precise error.
- `pdodb inbox purge --older-than=30d [--dry-run] [--table=NAME]` (also bash completion) calls `Inbox::purge()`, the inbox counterpart of `pdodb outbox purge`.
- `Inbox::purge()` deletes processed ids older than a given age in batches; `Outbox` purge deletes in batches too (it was a single `DELETE`). A cache hit no longer reads a per-key metadata entry that was never written.
- `quoteIdentifier()` passes a `RawValue` through unchanged on every dialect. It did so on MySQL, MariaDB and SQL Server only, while SQLite, PostgreSQL and Oracle threw a `TypeError`. The Oracle error for `onDuplicate()` now points to `QueryBuilder::merge()`.
- **Breaking:** a query routed to a connection with a different SQL dialect than the builder's throws `LogicException`; every replica and shard must use the dialect of the connection the query was built for.
- A claimed outbox row with an undecodable payload is marked `failed` instead of rolling back the claim and blocking the queue. A transient error thrown by the publisher schedules a retry rather than leaving the row locked, and the worker fails loudly if the publisher leaves a transaction open. Shared `Inbox` tests cover duplicate delivery, handler rollback, consumer isolation and nested transactions.
- Oracle `createUser()` / `changeUserPassword()` wrote the password into the statement unquoted; it is now double-quoted, and a password containing a double quote or NUL byte is rejected. Other dialects fall back to `quoteStringLiteral()`.
- `quoteIdentifier()` doubles the closing quote on PostgreSQL, SQLite, MySQL/MariaDB and SQL Server (it already did on Oracle), so an identifier can no longer break out of its quotes.
- Outbox inserts no longer call `lastInsertId()` (PostgreSQL aborted the surrounding transaction). The worker rejects an already open transaction, SQLite compares lock timestamps in a single format, and MySQL, MariaDB, SQL Server, and Oracle use dialect-specific outbox schema and claim SQL.
- Oracle outbox claim locks the pending row with `FOR UPDATE SKIP LOCKED` before updating it. Retryable deadlocks are retried by the worker and by `pdodb outbox work`. A publish whose lock update does not match is reported as unconfirmed and left for a later at-least-once delivery.
- A message claimed again after its last allowed attempt ended without confirmation is marked `failed` instead of being redelivered forever; stale claims are released once per batch.
- The SQL Server outbox schema can be applied repeatedly.
- The MCP statement-timeout note reflects whether the dialect supports a limit.
- Oracle no longer converts UUID strings to hex in `VARCHAR2` columns; only `RAW` columns (looked up in the catalog) are converted.
- The `unique` and `exists` validators query through the model's bound connection (`Model::on()`), and `unique` no longer passes for a slug held by a soft-deleted row.
- Migration and seed `--dry-run` no longer execute migration or seed PHP (avoids side effects; SQL from arbitrary PHP is not shown).
- ActiveRecord `update()` / `delete()` target the original primary key from when the row was loaded; changing a primary key in memory throws `PrimaryKeyMutationException`.
- ActiveRecord `save()` / `update()` treat zero affected rows as success when the row still matches the intended values (e.g. MySQL no-op updates).
- ActiveRecord insert preserves non-numeric primary key values returned from the driver.
- Keep distinct profiler ids when queries overlap, rank slow queries by total time, and omit parameter values from the slow-query log unless `setLogParameters(true)` is enabled.
- Recommend a filter-then-sort index from `explainAdvice()`, and recognize when that index already exists, including SQLite temporary sorts and PostgreSQL sorts.
- Reject a non-numeric `--temperature` and a non-positive `--max-tokens` or `--timeout` before they are cast.
- Look up one Oracle object by its exact name, and use the folded case only when that object is absent, so catalog columns and `NUM_ROWS` are not mixed across similarly named tables.
- Keep logging a slow query after the profiler stops storing new SQL shapes. Remove recognized string literals, numeric literals, and comments from profiler SQL.
- Remove double-quoted profiler text only when the connection treats double quotes as strings. Fold each unquoted part of an Oracle or PostgreSQL name on its own, so a quoted schema does not keep an unquoted table's case.
- Avoid exposing provider response bodies and API keys in AI transport errors; send Gemini credentials in the request header.
- Reject invalid numeric AI settings and explicitly configured temperature where the model profile omits sampling parameters.
- Correct database feature version requirements and broken documentation links; check local Markdown links in CI.

## [2.14.0] - 2026-10-29

### Added
- Reproducible AI query-advice showcase with 100,000 synthetic orders, three live-answer use cases, recorded SQLite plans and verified before/after results; shorten the README example and link the hands-on guide.
- Query-advice context preview, bounded FROM/JOIN catalog collection, database versions, normalized index metadata, optional catalog estimates, task goals and privacy controls. Add optional validated structured AI findings while retaining existing text/base-analysis results.
- Dedicated PHP query-advice walkthrough and deterministic/native database regression coverage; `composer pdodb:test:ai:db` checks six adapters without an LLM request.
- MCP visibility policies for tables/schemas, explicit diagnostic connection selection, scoped PostgreSQL/MariaDB statement limits, and MCP-specific diagnostics. Restricted visibility exposes metadata tools only.
- MCP discovery of columns and schemas, schema-scoped metadata and paginated resource listings; hide foreign-key references to objects outside the configured view.
- OpenRouter and configurable OpenAI-compatible Chat Completions endpoints, including base URLs, extra headers and endpoint-specific request options. Existing OpenAI configuration remains supported.
- Agent tools for environment context, local documentation search, paginated table discovery and estimated EXPLAIN without an additional LLM API key.
- AI setup/doctor/context/docs commands, MCP launch configuration preview, and a bundled PDOdb QueryBuilder skill.
- Separate offline AI tests and an explicitly invoked live OpenRouter/compatible API smoke test with private local credentials, synthetic SQLite data and bounded requests.

### Fixed
- Check installed model availability before running optional Ollama tests, rather than treating any listening server as ready.
- Resolve AI metadata for aliased/prefixed tables; distinguish missing metadata and unavailable plans. Query advice uses real SQLite QUERY PLAN evidence and refuses synthetic MSSQL plans or Oracle parameter interpolation.
- Share evidence-oriented query prompts across providers, preserve partial metadata and report truncation. Correct PostgreSQL index/JOIN parsing and stop treating SQLite bytecode fields as index names or row estimates.
- Report output-budget exhaustion separately from invalid response format when a compatible reasoning model produces no visible text; allow explicit reasoning effort in the local OpenRouter smoke test.
- Preserve AI configuration through PdoDb, QueryBuilder and analysis services; retain configured Ollama URLs and isolate per-request provider options.
- Include table schema in AI query prompts and report the actual provider/model. Default AI metadata collection no longer performs an exact COUNT(*) scan.
- Correct MCP notification handling, capability serialization, prompt argument resolution, input validation and tool errors; add bounded structured results and explicit protocol-version negotiation.
- Accept bound SQL parameters in AI CLI/MCP query analysis, report unavailable plans explicitly, and avoid treating the MSSQL synthetic plan as database evidence in the inspection service.
- Resolve Composer autoloading for the installed MCP executable and share the CLI database configuration loader.

## [2.13.0] - 2026-10-29

### Fixed
- Preserve NULL and DISTINCT semantics in filtered COUNT fallbacks and handle complex nested WHERE conditions.
- Align the condition-builder interface with Closure-based WHERE and HAVING methods.
- Use a selected column as the fallback sort for MSSQL DISTINCT pagination, including queries with CTEs.
- Clear temporary cache state after scalar reads so a reused builder cannot mix scalar and row results.
- Preserve parameterized CTE bindings during pagination and bypass compilation caching for CTEs, whose SQL and parameter state are assembled during compilation.
- Count plain-column JOIN results without constructing a derived table with duplicate column names on MySQL/MariaDB.
- Avoid PHP 8.5 deprecations in AI HTTP headers, eager loading with null keys, and Reflection-based tests and examples; PHP 8.4 remains supported. Eager-loaded belongs-to relations are now assigned to their original models.
- Automatically generated result cache keys distinguish connections and result shapes; old automatic entries become cache misses. Explicit cache keys retain their existing behavior and must be unique per database and result shape.
- Apply QueryBuilder global scopes to pagination and retain UNION and DISTINCT options for single-row, column and scalar reads. Existing queries that depended on omitted scopes or options may return different results.
- Count grouped and distinct result rows in pagination and return pagination results from ActiveQuery with model scopes applied.
- Avoid deprecated SQLite function registration and reflection calls in library-created SQLite connections on PHP 8.5.

### Added
- `DatabaseException::toSafeArray()` for logging metadata without SQL, parameters, driver messages or stack traces. Existing detailed diagnostic methods can contain sensitive data.
- PHP 8.5 SQLite and shared tests in CI and regression tests for result cache isolation, scopes, pagination and diagnostics.

### Changed
- Lock development dependencies in `composer.lock` for reproducible static analysis and quality checks.

## [2.12.1] - 2026-03-22

### Fixed
- **PHP 8.5**: `PDO::MYSQL_ATTR_LOCAL_INFILE` is deprecated; use `Pdo\Mysql::ATTR_LOCAL_INFILE` when available for MySQL local-infile handling.

### Changed
- **MySQL / MariaDB**: `password` is no longer a required connection parameter, so empty passwords are supported as allowed by the servers.
- **MSSQLDialect**: normalized `listTables()` SQL string formatting.

### Tests
- Expanded shared test coverage (Db helpers, `ParameterManager::setParams`, result cache and `generateCacheKey`, `PdoDb`, `QueryCompilationCache`, `QueryBuilder`, `CteManager`, `SelectQueryBuilder`, `QueryProfiler`, `FulltextMatchValue`, `ExecutionEngine`, `CacheManager`, `SeedDataGenerator`, `MigrationRunner`, and related CLI/migration paths).

## [2.12.0] - 2025-12-12

### Added
- **Optimize Command** (`pdodb optimize`) - Comprehensive database optimization tool:
  - `pdodb optimize analyze` - Analyze database schema for optimization opportunities
  - `pdodb optimize structure` - Analyze specific table structure
  - `pdodb optimize logs` - Analyze slow query logs
  - `pdodb optimize query` - Analyze and optimize specific SQL queries
  - Detects missing indexes, redundant indexes, missing foreign key indexes
  - Suggests indexes for common patterns (soft delete, status columns, timestamps)
  - Identifies large tables that may benefit from partitioning
  - Provides detailed statistics and recommendations
  - Support for JSON, table, and YAML output formats

- **AI-Powered Database Analysis** (`pdodb ai`) - Intelligent database optimization with AI:
  - `pdodb ai query <sql>` - Get AI-powered query analysis and optimization suggestions
  - `pdodb ai schema [table]` - Analyze database schema with AI recommendations
  - `pdodb ai optimize` - AI-powered database optimization suggestions
  - Automatic EXPLAIN plan integration for better AI analysis
  - Support for multiple AI providers:
    - **OpenAI** (GPT-4, GPT-3.5-turbo, GPT-4-turbo)
    - **Anthropic** (Claude 3 Opus, Sonnet, Haiku)
    - **Google** (Gemini Pro, Gemini Pro Vision)
    - **Microsoft Azure OpenAI** (GPT-4, GPT-3.5-turbo)
    - **DeepSeek** (DeepSeek Chat, DeepSeek Coder)
    - **Yandex Cloud GPT** (YandexGPT, YandexGPT Lite)
  - Configurable temperature, max_tokens, and timeout settings
  - Progress indicators for long-running AI requests
  - Integration with `explainAiAdvice()` method in QueryBuilder

- **AI Provider Configuration**:
  - Support for temperature, max_tokens, and timeout configuration for all AI providers
  - Improved error handling and response parsing
  - Enhanced markdown formatting in AI responses
  - AI configuration options in `pdodb init` command

### Changed
- **Performance Optimizations**:
  - Optimized `SchemaAnalyzer` to reduce duplicate database queries (~25% faster)
  - Eliminated redundant `TableManager::info()` and `getForeignKeys()` calls
  - Optimized `getTableRowCount()` to only execute for tables with detected issues
  - Removed unnecessary `tableExists()` checks
  - Added filtering for Oracle system tables (`BIN$%`, `SYS_%`, `DUAL`)
  - Added filtering for MSSQL system schemas (`sys`, `INFORMATION_SCHEMA`)
  - Improved test execution time for Oracle optimize tests (32s → 24s)

- **Code Quality**:
  - Removed `#[RunInSeparateProcess]` attributes from tests
  - Improved test reliability by throwing exceptions instead of `exit()` in test environment
  - Better handling of non-interactive mode in CLI commands
  - Extracted magic numbers to constants in AI providers

### Fixed
- **Test Reliability**:
  - Fixed blocking input issues in non-interactive test environments
  - Fixed "risky tests" warnings by properly managing output buffers
  - Fixed MSSQL test failures in GitHub Actions (environment variable handling)
  - Fixed Oracle test timeouts and hanging issues
  - Preserved critical environment variables in test tearDown methods
  - Improved non-interactive mode detection across all CLI commands

- **AI Command**:
  - Removed redundant `ai analyze` command (consolidated into `ai query`)
  - Fixed AI command output formatting
  - Fixed schema analysis in AI command
  - Improved Yandex AI provider response handling

- **Database Dialect Fixes**:
  - Fixed MSSQL `listTables()` to filter system schemas for better performance
  - Fixed Oracle system table filtering in `listTables()`

### Documentation
- Updated `composer.json` with AI integration keywords and description
- Removed references to deprecated `ai analyze` command
- Updated bash completion script to remove `ai analyze` command

## [2.11.2] - 2025-12-06

### Added
- **Extended TUI Dashboard** - Added 4 new panes with dual-screen support:
  - **Schema Browser pane** - Browse database tables, view columns, indexes, and foreign keys
  - **Migration Manager pane** - List migrations with status, view file content with syntax highlighting, apply/rollback actions
  - **Server Variables pane** - View and search server configuration variables across all dialects
  - **SQL Scratchpad pane** - Interactive SQL query editor with autocomplete and query history
  - Dual-screen navigation: keys 1-8 work globally, tab/arrows switch between screens
  - Global search filter for Schema Browser and Server Variables panes (activate with '/' key)
  - PageUp/PageDown support for paginated navigation in fullscreen mode
  - Migration actions: 'a' key to apply pending migrations, 'r' key to rollback last applied migration
  - Improved migration detail view with structured display and syntax highlighting

- **Table Search Command** (`pdodb table search <table>`):
  - Search values across all columns in a table
  - Support for JSON and array column search across all dialects
  - Options: `--column=COL`, `--limit=N`, `--json=0|1`, `--format=table|json|yaml`
  - Automatic column type detection and appropriate search condition building
  - Comprehensive unit tests and CLI tests

- **Seed Generate Command** (`pdodb seed generate <table>`):
  - Extract data from database and generate seed files
  - Options: `--limit`, `--where`, `--order-by`, `--exclude`, `--include`, `--format`, `--chunk-size`, `--preserve-ids`, `--skip-timestamps`
  - Support for filtering, sorting, and selective column export

- **IDE Autocompletion for Migrations**:
  - Enhanced IDE autocompletion for `schema()` method in migration classes
  - Full support for all DdlQueryBuilder methods with proper type hints
  - Improved developer experience with better code completion

- **Environment Config Loader**:
  - New `EnvConfigLoader` class to centralize environment variable parsing
  - Improved isolation of database and cache configuration loading
  - Prevents environment variable pollution between tests

### Changed
- **TUI Dashboard UI Improvements**:
  - Centered pane titles horizontally in border rendering
  - Compact header and footer to fit 80x24 terminal
  - Changed pending migration icon from ✗ to ⏳ (hourglass) with yellow color
  - Updated README with current test count: 3806 tests, 12180 assertions

- **Code Quality**:
  - Optimized imports by converting fully qualified names to use statements (74 files)
  - Added `fully_qualified_strict_types` rule to PHP CS Fixer config

### Fixed
- **TUI Dashboard Fixes**:
  - Fixed connection count discrepancy in Connection Pool pane (summary vs. list)
  - Fixed Server Metrics pane showing incorrect Uptime (0s) and connection metrics
  - Fixed flickering in Active Queries pane when in fullscreen mode
  - Fixed right border being overwritten by content in Active Queries pane
  - Fixed visible height calculation in ConnectionPoolPane to account for all header rows
  - Fixed dashboard pane borders rendering (Status/Type duplication, border visibility)
  - Fixed arrow key navigation in TUI Dashboard (2-character escape sequences)
  - Fixed SQL Scratchpad autocomplete positioning and immediate screen refresh
  - Fixed SQL Scratchpad editor navigation and multi-statement query execution
  - Fixed ESC key handling to exit fullscreen mode

- **Database Dialect Fixes**:
  - Fixed MySQL/MariaDB `getActiveConnections` to use actual visible connections count
  - Fixed MySQL/MariaDB `getServerMetrics` to properly extract Uptime value from SHOW VARIABLES
  - Fixed Oracle connection details display (added support for 'sid' and 'schema' keys)
  - Fixed SchemaBrowserPane to handle array table data correctly
  - Improved ServerMetricsPane metric labels for clarity

- **Code Fixes**:
  - Fixed PHPStan errors in SeedCommand and SeedDataGenerator
  - Fixed unreachable code after `showError()` calls
  - Fixed event dispatcher state leakage in tests (added tearDown methods)
  - Fixed Oracle tests failing due to environment variable pollution
  - Fixed CacheManager reflection issues with typed properties

### Tests
- **Significant Test Coverage Increase**:
  - Added comprehensive tests for Oracle classes (OracleDdlBuilder, OracleDdlQueryBuilder, OracleSqlFormatter)
  - Added tests for all UI panes (ActiveQueriesPane, CacheStatsPane, ConnectionPoolPane, ServerMetricsPane, SchemaBrowserPane, MigrationManagerPane, ServerVariablesPane, SqlScratchpadPane)
  - Added tests for InputHandler, Terminal, MonitorManager, TestGenerator classes
  - Added tests for UserCommand, KillConnectionAction, KillQueryAction
  - Added tests for TableSearcher and seed generate command
  - Code coverage increased from ~65% to ~67% (Lines: 18519/27596)
  - Total: 3806 tests, 12180 assertions

## [2.11.1] - 2025-12-02

### Added
- **Interactive TUI Dashboard** - Real-time database monitoring with full-screen terminal interface:
  - Monitor active queries, connection pool, cache statistics, and server metrics
  - View query details with full query text, execution time, user, and database
  - Kill long-running queries with a single keystroke
  - Keyboard navigation with pane switching, scrolling, and fullscreen mode
  - Real-time refresh with configurable intervals (realtime, 0.5s, 1s, 2s, 5s)
  - Color-coded metrics for performance visualization
  - Launch with `pdodb ui` command

- **Code Generation Command** (`pdodb generate`):
  - `pdodb generate api` - Generate API controller classes
  - `pdodb generate tests` - Generate test files
  - `pdodb generate dto` - Generate Data Transfer Object classes
  - `pdodb generate enum` - Generate enum classes
  - `pdodb generate docs` - Generate documentation files
  - `pdodb generate model` - Generate ActiveRecord model classes
  - `pdodb generate repository` - Generate repository classes
  - `pdodb generate service` - Generate service classes
  - All generated namespaces are automatically converted to lowercase

- **Benchmark Command** (`pdodb benchmark`):
  - `pdodb benchmark query` - Benchmark SQL query performance
  - `pdodb benchmark crud` - Benchmark CRUD operations
  - `pdodb benchmark load` - Load testing with multiple connections
  - `pdodb benchmark profile` - Query profiling with detailed statistics
  - `pdodb benchmark compare` - Compare query performance

- **Version Command** (`pdodb version`):
  - Show application version information
  - `--version` / `-v` flag support for all commands

- **Index Suggestion Command** (`pdodb table suggest-indexes <table>`):
  - Analyze table queries and suggest optimal indexes
  - Help optimize database performance

- **Oracle-Specific Helper Functions**:
  - `Db::toTs()` - Convert value to Oracle TIMESTAMP
  - `Db::toDate()` - Convert value to Oracle DATE
  - `Db::toChar()` - Convert value to Oracle CHAR/VARCHAR2

- **IDE Autocompletion Support**:
  - Enhanced IDE autocompletion for `Model::find()` method
  - Improved type hints and documentation

- **Event System Enhancements**:
  - Added missing events to event system for better monitoring and auditing

- **CLI Improvements**:
  - "Did you mean?" command suggestions for typos
  - Help messages for generate subcommands when required parameters are missing

### Changed
- **Code Generation**:
  - All generated namespaces are now automatically converted to lowercase for consistency

### Fixed
- Fixed loading .env file before checking PDODB_DRIVER in connection list command
- Fixed parsing command line options without equals sign (e.g., `--option=value` and `--option value`)
- Fixed Oracle type helpers example and PHPStan errors
- Fixed benchmark command tests to use correct path to pdodb binary
- Added warning when --query is not specified for benchmark compare command
- Improved test reliability: skip user creation tests when privileges are missing

### Documentation
- Improved IDE autocompletion documentation
- Clarified PHPStan ignore comments in PdoDb::fromEnv method

### Tests
- Added comprehensive tests for Oracle-specific helpers and exception handling
- Improved benchmark command tests with better error handling

## [2.11.0] - 2025-11-28

### Added
- **Oracle Database Support** - Full support for Oracle Database (OCI driver):
  - Complete Oracle dialect implementation with all query builder features
  - Support for Oracle-specific data types and functions
  - CLOB handling for large text fields in WHERE, ORDER BY, and DISTINCT clauses
  - Oracle-specific SQL formatting (INSERT ALL, FILTER clause, etc.)
  - Support for recursive CTEs, window functions, and advanced Oracle features
  - `normalize_row_keys` option for automatic column name normalization
  - Oracle examples and comprehensive test coverage
  - Oracle CI integration in GitHub Actions with Oracle Instant Client installation

- **PdoDb::fromEnv() Method** - Unified .env file support:
  - Static method `PdoDb::fromEnv(?string $envPath = null)` for easy database connection from .env files
  - Automatic .env file detection (respects `PDODB_ENV_PATH` environment variable)
  - Support for all database dialects and configuration options
  - Reusable `EnvLoader` class for .env parsing logic

- **PHP Extension Validation** - Automatic validation of required PHP extensions:
  - `ExtensionChecker` class validates required PDO extensions before connection
  - Helpful error messages with installation suggestions for missing extensions
  - Support for all database dialects (MySQL, PostgreSQL, SQLite, MSSQL, Oracle)
  - Integration with `DialectRegistry` for automatic extension checking

- **Extended .env File Support** - Support for all dialect-specific options in .env files:
  - Oracle-specific options (`PDODB_SERVICE_NAME`, `PDODB_SID`)
  - All database-specific configuration options can be set via environment variables
  - Unified .env parsing across CLI tools and application code

- **Connection Management CLI Commands** (`pdodb connection`):
  - `pdodb connection list` - List all configured database connections
  - `pdodb connection test [name]` - Test database connection(s)
  - `pdodb connection info [name]` - Show detailed connection information
  - Support for multiple named connections

- **Database Seeds Functionality** (`pdodb seed`):
  - `pdodb seed create <name>` - Create seed file
  - `pdodb seed run [name]` - Run seed files
  - Support for database seeding in development and testing

- **Dump Command Backup Features** (`pdodb dump`):
  - Compression support (gzip, bzip2)
  - Auto-naming with timestamps
  - Backup rotation with configurable retention
  - Enhanced backup management capabilities

- **Table Management Commands** (`pdodb table`):
  - `pdodb table count [table]` - Count rows in table(s) with improved formatting
  - `pdodb table sample <table> [limit]` - Get random sample rows from table

- **Comprehensive Test Coverage**:
  - Extended test coverage to 85%+
  - Tests for CLI commands (MigrateCommand, ModelCommand, DbCommand, UserCommand)
  - Tests for DDL builders (MySQL, PostgreSQL, MSSQL, SQLite)
  - Tests for RepositoryGenerator, ServiceGenerator, MigrationGenerator
  - Tests for CacheManager, ConnectionCommand, MonitorManager
  - Tests for edge cases and error handling

### Changed
- **Oracle Compatibility Improvements**:
  - Improved Oracle support for TRUE/FALSE, CAST, GREATEST, and LEAST functions
  - Enhanced REGEXP_MATCH, COALESCE, and NULLIF support for Oracle
  - Improved CLOB handling in formatIfNull, formatRepeat, and formatReverse
  - Added formatUnionOrderBy() for Oracle ORDER BY in UNION queries
  - Better date handling in INSERT ALL statements
  - Cursor pagination support for Oracle

- **Refactoring**:
  - Removed dialect-specific logic from general classes (ConditionBuilder, concat() method)
  - Moved dialect-specific logic to dialect implementations
  - Improved code organization and maintainability

### Fixed
- **Oracle CI in GitHub Actions**:
  - Fixed Oracle Instant Client installation (version 23.26.0.0.0)
  - Fixed apt-get exit code 8 handling
  - Fixed pdo_oci and oci8 extension compilation
  - Fixed Oracle Instant Client SDK installation for headers
  - Improved error handling and diagnostics

- **Oracle CLI Examples**:
  - Fixed BaseCliCommand::buildConfigFromEnv() to check PDODB_SERVICE_NAME/PDODB_SID for Oracle
  - Added setEnvFromConfig() helper function for CLI examples
  - Fixed environment variable propagation for Oracle examples
  - All Oracle CLI examples now work correctly in GitHub Actions

- **Oracle Examples**:
  - Fixed recursive CTE examples
  - Fixed type helpers and JSON basics
  - Fixed window functions and CTEs
  - Fixed INSERT ALL dates and FILTER clause CLOB handling
  - Fixed cursor pagination

- **Other Fixes**:
  - Fixed Memcached adapter creation in CacheFactory
  - Fixed foreign key constraint handling in DDL examples for MySQL/MariaDB
  - Fixed PHPStan errors
  - Fixed test assertions and improved test reliability

### Documentation
- Added Oracle Database installation instructions
- Fixed all documentation links to use correct file names with numeric prefixes
- Created bidirectional links between documentation and examples
- Optimized README.md for better readability
- Updated TOC with missing sections
- Added CLI Tools section to table of contents
- Added bash completion installation instructions

## [2.10.3] - 2025-11-21

### Added
- **Project Initialization** (`pdodb init`) - Interactive wizard for quick project setup:
  - Interactive configuration wizard for database connection settings
  - Support for MySQL, PostgreSQL, SQLite, and MSSQL (SQLSRV) drivers
  - Configuration file generation (`.env` or `config/db.php`)
  - Automatic directory structure creation (migrations, models, repositories, services)
  - Connection testing before saving configuration
  - Advanced options (table prefix, caching, performance settings, multiple connections)
  - Cache dependency validation with helpful warnings if dependencies are missing
  - Fallback to array cache if selected cache type dependencies are unavailable

- **Repository and Service Generators** (`pdodb repository`, `pdodb service`):
  - `pdodb repository make <name>` - Generate repository classes with auto-detected model and table names
  - `pdodb service make <name>` - Generate service classes with dependency injection
  - Automatic primary key detection
  - Support for `--namespace`, `--force`, and `--connection` options

- **Cache Management Enhancements** (`pdodb cache`):
  - `pdodb cache invalidate <pattern>` - Invalidate cache entries by pattern:
    - `table:users` - Invalidate all cache entries for a specific table
    - `table:users_*` - Invalidate cache entries for tables with prefix
    - `pdodb_table_users_*` - Invalidate cache entries matching key pattern
  - Cache type detection in `pdodb cache stats` output (Redis, APCu, Memcached, Filesystem, Array, Unknown)
  - Universal cache type detection for all PSR-16 implementations (not just Symfony Cache)
  - Persistent cache statistics with atomic operations (Redis INCR, Memcached increment, APCu apcu_inc)
  - Optimistic locking with retry for non-atomic caches (Filesystem, Array)
  - Cache metadata storage linking cache keys to table names for efficient invalidation

- **Database Monitoring** (`pdodb monitor`):
  - `pdodb monitor queries` - Show active queries
  - `pdodb monitor connections` - Show active connections
  - `pdodb monitor slow [threshold]` - Show slow queries (default threshold: 1.0 seconds)
  - Cross-dialect support (MySQL, MariaDB, PostgreSQL, MSSQL, SQLite)

- **Table Management Enhancements** (`pdodb table`):
  - `pdodb table foreign-keys list <table>` - List foreign keys
  - `pdodb table foreign-keys add <table>` - Add foreign key
  - `pdodb table foreign-keys drop <table> <name>` - Drop foreign key

- **Migration Dry-Run Improvements** (`pdodb migrate`):
  - `pdodb migrate up --dry-run` now shows actual SQL queries that would be executed
  - SQL queries are collected via event dispatcher during migration execution in transaction
  - Transaction is rolled back after collecting SQL to prevent actual changes
  - SQL queries are formatted with parameter substitution for readable output

- **Bash Completion Script** (`scripts/pdodb-completion.bash`):
  - Tab completion for all pdodb CLI commands and subcommands
  - Completion for command options (`--force`, `--format`, `--connection`, etc.)
  - Completion for cache subcommands (`clear`, `stats`, `invalidate`)
  - Completion for migration, model, repository, service, and init commands

- **Dialect-Specific DDL Query Builders**:
  - MySQL-specific types: `enum()`, `set()`, `tinyInteger()`, `mediumInteger()`, `longText()`, `mediumText()`, `binary()`, `varbinary()`, `blob()` variants, `year()`, optimized `uuid()` (CHAR(36)), `boolean()` (TINYINT(1))
  - PostgreSQL-specific types: `uuid()`, `jsonb()`, `serial()`, `bigSerial()`, `inet()`, `cidr()`, `macaddr()`, `tsvector()`, `tsquery()`, `bytea()`, `money()`, `interval()`, `array()`, geometric types (`point()`, `line()`, `polygon()`, `circle()`, etc.)
  - MSSQL-specific types: `uniqueidentifier()`, `nvarchar()`, `nchar()`, `ntext()`, `money()`, `smallMoney()`, `datetime2()`, `smallDatetime()`, `datetimeOffset()`, `time()`, `binary()`, `varbinary()`, `image()`, `real()`, `xml()`, `geography()`, `geometry()`, `hierarchyid()`, `sqlVariant()`
  - SQLite-specific type mappings for all types
  - MariaDB inherits MySQL types with potential for MariaDB-specific extensions

- **CLI Version Auto-Detection**:
  - CLI version now automatically detected from git tags using `Composer\InstalledVersions` or `git describe`
  - Falls back to `composer.json` version if git is not available
  - Removes `-dev` suffix for clean version display

### Changed
- **Architecture Refactoring** - Moved dialect-specific logic from common classes to dialect implementations:
  - Monitoring methods (`getActiveQueries`, `getActiveConnections`, `getSlowQueries`) moved to `DialectInterface`
  - Table listing (`listTables`) moved to `DialectInterface`
  - Error handling methods (`getRetryableErrorCodes`, `getErrorDescription`) moved to `DialectInterface`
  - DML operations (`getInsertSelectColumns`) moved to `DialectInterface`
  - Configuration methods (`buildConfigFromEnv`, `normalizeConfigParams`) moved to `DialectInterface`
  - Concatenation formatting (`formatConcatExpression`) moved to `DialectInterface`
  - Removed `match/case` and `if` statements based on specific dialects from common classes
  - Follows Open/Closed Principle - new dialects can be added without modifying common classes

- **MSSQL Driver Standardization**:
  - Removed `mssql` alias, use only `sqlsrv` driver name everywhere
  - Renamed `config.mssql.php` to `config.sqlsrv.php`
  - Updated all examples, documentation, and tests to use `sqlsrv` instead of `mssql`
  - Updated `DialectRegistry` to remove `mssql` alias

- **CLI Command Organization**:
  - Commands sorted by logical groups with `init` at the top
  - Grouped commands: Project initialization, Migrations, Code generation, Database management, Development tools, Monitoring and performance, Utilities

- **Environment Variables Standardization**:
  - Standardized all environment variables from `DB_*` to `PDODB_*` in CI/CD workflows
  - Updated GitHub Actions workflow (`tests.yml`) to use `PDODB_*` variables
  - Removed fallback to `DB_*` variables in `BaseCliCommand` and `InitWizard`

- **Migration Dry-Run Behavior**:
  - `pdodb migrate up --dry-run` now executes migrations in transaction to collect actual SQL
  - Previously only showed comments like "Would execute migration.up()"
  - Now shows real SQL queries (CREATE TABLE, INSERT, etc.) that would be executed

- **README Updates**:
  - Added `pdodb init` as the fastest way to get started
  - Updated quick start guide to highlight interactive wizard
  - Updated examples to use `sqlsrv` instead of `mssql`

### Fixed
- **Cache Factory**:
  - Handle Redis connection errors gracefully (return null instead of throwing exception)
  - Improved error handling for missing Redis/Memcached extensions

- **MSSQL Monitoring Example**:
  - Fixed MSSQL monitoring example failing in GitHub Actions
  - Standardized environment variable names to `PDODB_*`

- **Configuration Loading**:
  - Fixed `database` vs `dbname` parameter normalization for PostgreSQL and MSSQL
  - Ensured `dbname` is required everywhere (not `database`)
  - Fixed `InitWizard` connection test to properly normalize parameters

- **CLI Prompts**:
  - Fixed duplicate default values in prompts (e.g., `Select [1] [1]:` → `Select [1]:`)
  - Removed hardcoded default values from prompt strings

### Technical Details
- **All tests passing**: 2491 tests, 8468 assertions across all dialects
- **Test coverage**: Added tests for SQL collection in dry-run mode, cache dependency validation, dialect-specific schema builders
- **Code quality**: PHPStan level 9, PHP-CS-Fixer compliant
- **Full backward compatibility**: 100% maintained (except removed `mssql` alias)

## [2.10.2] - 2025-11-18

### Added
- **Database Dump and Restore** (`pdodb dump`) - Export and import database schema and data:
  - `pdodb dump` - Dump entire database to SQL
  - `pdodb dump <table>` - Dump specific table
  - `pdodb dump --schema-only` - Dump only schema (CREATE TABLE, indexes, etc.)
  - `pdodb dump --data-only` - Dump only data (INSERT statements)
  - `pdodb dump --output=<file>` - Write dump to file instead of stdout
  - `pdodb dump --no-drop-tables` - Exclude DROP TABLE IF EXISTS statements (included by default)
  - `pdodb dump restore <file>` - Restore database from SQL dump file
  - `pdodb dump restore <file> --force` - Restore without confirmation
  - Cross-dialect support for all database types
  - Automatic DROP TABLE IF EXISTS before CREATE TABLE (configurable)

- **Table Management** (`pdodb table`) - Comprehensive table operations:
  - `pdodb table list` - List all tables
  - `pdodb table info <table>` - Show detailed table information
  - `pdodb table exists <table>` - Check if table exists
  - `pdodb table create <table>` - Create new table with interactive column prompts
  - `pdodb table drop <table>` - Drop table (with confirmation)
  - `pdodb table rename <table> <new_name>` - Rename table
  - `pdodb table truncate <table>` - Truncate table data
  - `pdodb table describe <table>` - Show table structure
  - `pdodb table columns list <table>` - List all columns
  - `pdodb table columns add <table>` - Add new column
  - `pdodb table columns alter <table> <column>` - Modify column
  - `pdodb table columns drop <table> <column>` - Drop column
  - `pdodb table indexes list <table>` - List all indexes
  - `pdodb table indexes add <table>` - Create new index
  - `pdodb table indexes drop <table> <index>` - Drop index
  - Support for `--format` option (table, json, yaml)
  - Support for `--if-exists` and `--if-not-exists` flags

- **Database Management** (`pdodb db`) - Database operations:
  - `pdodb db create <name>` - Create new database
  - `pdodb db drop <name>` - Drop database (with confirmation)
  - `pdodb db exists <name>` - Check if database exists
  - `pdodb db list` - List all databases
  - `pdodb db info <name>` - Show database information
  - Interactive prompts for database name input
  - Database name display in command header

- **User Management** (`pdodb user`) - Database user operations:
  - `pdodb user create <username>` - Create new database user
  - `pdodb user drop <username>` - Drop user (with confirmation)
  - `pdodb user exists <username>` - Check if user exists
  - `pdodb user list` - List all users
  - `pdodb user info <username>` - Show user information
  - `pdodb user grant <username>` - Grant privileges to user
  - `pdodb user revoke <username>` - Revoke privileges from user
  - `pdodb user password <username>` - Change user password
  - Cross-dialect support (MySQL, MariaDB, PostgreSQL, MSSQL)

- **Query Command Enhancements** (`pdodb query`):
  - `pdodb query explain <sql>` - Show EXPLAIN plan for query
  - `pdodb query format <sql>` - Pretty-print and format SQL
  - `pdodb query validate <sql>` - Validate SQL syntax

- **Model Generator Enhancements** (`pdodb model`):
  - `--force` option - Overwrite existing models without confirmation
  - `--namespace=<ns>` option - Specify namespace for generated models
  - `--connection=<name>` - Select named connection (global option)

- **Global CLI Options**:
  - `--help` - Show help message (available globally and per-command)
  - `--connection=<name>` - Use named connection from config/db.php
  - `--config=<path>` - Path to db.php configuration file
  - `--env=<path>` - Path to .env file

- **Migration Command Enhancements** (`pdodb migrate`):
  - `--dry-run` option - Show SQL without executing
  - `--pretend` option - Show what would be executed
  - `--force` option - Skip confirmation prompts
  - Confirmation prompts for `migrate up` command

### Changed
- **SQL Formatter Improvements**:
  - Enhanced tokenization with proper whitespace handling
  - Multi-word keyword recognition (LEFT/RIGHT/FULL [OUTER] JOIN, GROUP BY, ORDER BY)
  - Consistent keyword uppercasing
  - Improved formatting with proper line breaks for major clauses
  - Better handling of quoted strings and parentheses

- **Dialect Architecture Refactoring**:
  - Split dialect classes into separate components (SqlFormatter, DmlBuilder, DdlBuilder)
  - Reorganized dialects into subdirectories (sqlite/, mysql/, mariadb/, postgresql/, mssql/)
  - Extracted FeatureSupport classes for better organization
  - Replaced magic strings with QueryConstants class

- **CLI Command Behavior**:
  - Commands now show help when no arguments provided (consistent behavior)
  - Improved error messages with helpful suggestions (e.g., typo detection for 'restore')
  - Better table existence checking before operations

### Fixed
- **Error Handling**:
  - Improved error handling in `pdodb dump` command (replaces fatal errors with user-friendly messages)
  - Typo detection for 'restore' command with helpful suggestions
  - Table existence check before dumping (prevents fatal errors)
  - Better exception handling with try/catch for QueryException and general Exception

- **Configuration Loading**:
  - Fixed configuration loading order in BaseCliCommand
  - Corrected environment variable checks for CI environments
  - Improved MSSQL configuration loading (sqlsrv driver alias support)
  - Fixed MySQL/MariaDB CI environment variable support in examples

- **CI/CD**:
  - Added CI environment variable support to MariaDB config and workflow
  - Fixed MariaDB examples failing in GitHub Actions
  - Improved configuration file detection for examples

- **Window Functions**:
  - Restored MariaDB LAG/LEAD default value handling in formatWindowFunction

- **CLI Tools**:
  - Fixed CLI prompts display before waiting for input
  - Prevented CLI tools from hanging in tests by adding non-interactive mode
  - Improved MySQL/MariaDB table listing robustness

### Technical Details
- **All tests passing**: 2390 tests, 8016 assertions across all dialects
- **Test coverage**: Increased CLI integration test coverage
- **Code quality**: PHPStan level 8, PHP-CS-Fixer compliant
- **Full backward compatibility**: 100% maintained

## [2.10.1] - 2025-11-11

### Added
- **CLI Tools - Unified Command Structure** - Complete command-line interface for developer productivity:
  - **Migration Management** (`pdodb migrate`) - Create, run, rollback, and manage database migrations:
    - `pdodb migrate create <name>` - Generate new migration files
    - `pdodb migrate up` - Apply pending migrations
    - `pdodb migrate down` - Rollback last migration
    - `pdodb migrate to <version>` - Migrate to specific version
    - `pdodb migrate history` - View migration history
    - `pdodb migrate new` - List pending migrations
  - **Model Generator** (`pdodb model make`) - Auto-generate ActiveRecord models from database tables:
    - Automatic table name detection from model name
    - Primary key detection
    - Foreign key relationship detection
    - Attribute generation with types
    - Relationship definitions generation
    - Support for custom output paths via `PDODB_MODEL_PATH` environment variable
  - **Schema Inspector** (`pdodb schema inspect`) - Inspect database structure:
    - List all tables
    - Detailed table inspection (columns, indexes, foreign keys, constraints)
    - Multiple output formats (table, JSON, YAML)
    - Row count information
  - **Interactive Query Tester** (`pdodb query test`) - REPL for testing SQL queries:
    - Interactive SQL execution
    - Single query execution mode
    - Formatted result display
    - Help commands and query history

- **Enhanced Developer Experience**:
  - **PHPDoc Improvements** - Comprehensive inline documentation with examples:
    - `@example` tags with usage examples for 10+ key methods
    - `@warning` tags for dialect-specific differences and important considerations
    - `@note` tags for additional context and best practices
    - `@see` tags linking to full documentation
    - Enhanced documentation for `insertFrom()`, `merge()`, `getColumn()`, `paginate()`, `withRecursive()`, `createIndex()`, `groupBy()`, `case()`, `relations()`, and more
  - **Migration Path Resolution** - Improved migration file location detection:
    - Automatic detection of project root directory
    - Support for `.env` file configuration
    - Fallback to library paths for development
    - Better error messages for missing directories
  - **getColumn() Enhancement** - Added optional column name parameter:
    - `getColumn()` - Returns first selected column (backward compatible)
    - `getColumn('name')` - Returns specific column by name
    - Preserves array keys when used with `index()` method
    - Automatic detection of first selected column from SELECT clause

- **New Helper Methods**:
  - `Db::as()` - Alias helper to reduce `Db::raw()` usage for simple aliases
  - `Db::add()` - Addition helper for mathematical operations
  - `ColumnSchema::char()` - CHAR type support for fixed-length strings

### Changed
- **CLI Tools Architecture** - Reorganized into unified command structure:
  - Single entry point: `vendor/bin/pdodb` with subcommands
  - Yii2-inspired command structure (`pdodb migrate create`, `pdodb model make`, etc.)
  - Consistent error handling and output formatting
  - Better integration with Composer installation paths
  - Improved autoload.php path resolution for both development and production

- **Migration System Improvements**:
  - Fixed `migrateTo()` to use migration order instead of `apply_time` for correct rollback sequence
  - Improved rollback logic to ensure migrations are rolled back in reverse order
  - Better handling of migration version detection
  - Migration files excluded from Git tracking (added to `.gitignore`)

- **Code Quality**:
  - Increased test coverage from 79.79% to 80.25%
  - Comprehensive test coverage for CLI tools (MigrationGenerator, ModelGenerator, SchemaInspector, QueryTester)
  - Improved test coverage for RangeShardStrategy, RawValueResolver, MSSQLExplainParser, FileLoader, MigrationRunner
  - Enhanced test coverage for SelectQueryBuilder, DmlQueryBuilder, ExternalReferenceProcessingTrait

### Fixed
- **Cross-Dialect Compatibility**:
  - Improved identifier quoting in `CASE` statements for MSSQL reserved words
  - Fixed `GROUP BY` with qualified identifiers (table.column) and JOIN aliases
  - Better handling of MSSQL reserved words like 'plan', 'order', 'group'
  - Improved cross-dialect DDL operations

- **Migration System**:
  - Fixed migration path resolution to prioritize project root over library paths
  - Corrected autoload.php path detection for Composer installations
  - Fixed migration version detection after rollback operations

- **Type Safety**:
  - Added explicit type checks for PHPStan compatibility
  - Fixed type hints in various methods
  - Improved parameter type validation

### Technical Details
- **All tests passing**: 2052+ tests across all dialects
- **CLI Tools**: Fully tested with comprehensive test coverage
- **Code Coverage**: Improved from 79.79% to 80.25%
- **PHPStan Level 8**: Zero errors maintained
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained

## [2.10.0] - 2025-11-09

### Added
- **MSSQL (Microsoft SQL Server) Support** - Full support for MSSQL as a fifth database dialect:
  - **New MSSQLDialect class** - Complete SQL dialect implementation for Microsoft SQL Server
  - **Comprehensive test coverage** - 28 test suites, 732+ tests covering all MSSQL-specific functionality
  - **GitHub Actions integration** - Dedicated CI job for MSSQL with coverage reporting
  - **Example compatibility** - All examples now support MSSQL (64/64 passing)
  - **REGEXP support** - Custom REGEXP implementation using `PATINDEX` for pattern matching
  - **Cross-dialect compatibility** - All helper functions work consistently across all five dialects
  - **Complete documentation** - MSSQL configuration and usage examples added to documentation

- **MSSQL-Specific Features**:
  - **LATERAL JOIN support** - Automatic conversion to `CROSS APPLY`/`OUTER APPLY` syntax
  - **MERGE statement support** - Native MERGE operations for UPSERT patterns
  - **Window functions** - Full support for SQL Server window functions
  - **JSON operations** - Complete JSON support using SQL Server JSON functions
  - **Type casting** - Safe type conversion using `TRY_CAST` for error-free casting
  - **String functions** - Dialect-specific implementations (`LEN` vs `LENGTH`, `CEILING` vs `CEIL`)
  - **Date/time functions** - SQL Server-specific date/time handling
  - **Boolean types** - `BIT` type support with proper 0/1 handling
  - **Identity columns** - `IDENTITY(1,1)` support for auto-incrementing primary keys

### Changed
- **Architecture Improvements** - Major refactoring following SOLID principles:
  - **Dialect-specific logic migration** - Moved all dialect-specific checks from general classes to dialect implementations
  - **New DialectInterface methods** - Added 20+ new methods to `DialectInterface` for better abstraction:
    - `formatLateralJoin()` - Handle LATERAL JOIN conversion
    - `needsColumnQualificationInUpdateSet()` - PostgreSQL-specific UPDATE behavior
    - `executeExplainAnalyze()` - Dialect-specific EXPLAIN ANALYZE execution
    - `getBooleanType()`, `getTimestampType()`, `getDatetimeType()` - Type system abstraction
    - `isNoFieldsError()` - MSSQL-specific error detection
    - `appendLimitOffset()` - Dialect-specific LIMIT/OFFSET handling
    - `getPrimaryKeyType()`, `getBigPrimaryKeyType()`, `getStringType()` - Schema type abstraction
    - `formatMaterializedCte()` - Materialized CTE support
    - `registerRegexpFunctions()` - REGEXP function registration
    - `normalizeDefaultValue()` - Default value normalization
    - `buildMigrationTableSql()`, `buildMigrationInsertSql()` - Migration infrastructure
    - `extractErrorCode()` - Error code extraction
    - `getExplainParser()` - EXPLAIN parser abstraction
    - `getRecursiveCteKeyword()` - Recursive CTE keyword handling
    - `formatGreatest()`, `formatLeast()` - Type-safe GREATEST/LEAST functions
    - `buildDropTableIfExistsSql()` - DROP TABLE IF EXISTS support
  - **Removed dialect checks from general classes** - No more `if ($driver === 'sqlsrv')` in general classes
  - **Better separation of concerns** - Each dialect handles its own specific requirements

- **Example Improvements**:
  - **Refactored examples** - Removed `Db::raw()` calls with dialect-specific SQL from examples
  - **Library helpers usage** - Examples now use library helpers exclusively (`Db::length()`, `Db::position()`, `Db::greatest()`, etc.)
  - **Schema Builder usage** - Examples use Schema Builder for DDL operations where possible
  - **Better educational value** - Examples demonstrate proper library usage patterns

- **CI/CD Improvements**:
  - **MSSQL GitHub Actions job** - Complete CI setup for MSSQL testing
  - **Environment variable support** - Examples and tests can use environment variables for configuration
  - **Simplified user management** - Use SA user directly in CI for better reliability

### Fixed
- **PostgreSQL DROP TABLE CASCADE** - Fixed foreign key constraint issues when dropping tables
- **MSSQL type compatibility** - Fixed GREATEST/LEAST functions to handle type compatibility issues
- **MSSQL boolean handling** - Proper conversion of TRUE/FALSE to 1/0 for BIT type
- **MSSQL string functions** - Fixed LENGTH() -> LEN() conversion, SUBSTRING syntax
- **MSSQL REGEXP support** - Custom PATINDEX-based implementation for pattern matching
- **MSSQL LIMIT/OFFSET** - Proper OFFSET ... FETCH NEXT ... ROWS ONLY syntax
- **MSSQL error handling** - Proper handling of "contains no fields" errors
- **Example compatibility** - All examples now work correctly on all five dialects

### Technical Details
- **All tests passing**: 2052 tests, 7097 assertions (+732 tests, +2239 assertions from 2.9.3)
- **All examples passing**: 320/320 examples (64 files × 5 dialects each)
  - SQLite: 64/64 ✅
  - MySQL: 64/64 ✅
  - MariaDB: 64/64 ✅
  - PostgreSQL: 64/64 ✅
  - MSSQL: 64/64 ✅
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **MSSQL integration**: Complete dialect support with comprehensive testing and CI integration

## [2.9.3] - 2025-11-09

### Added
- **Regular Expression Helpers** - Cross-database regex support with unified API:
  - **REGEXP_LIKE** (`Db::regexpMatch()`) - Pattern matching for all dialects
  - **REGEXP_REPLACE** (`Db::regexpReplace()`) - Pattern-based string replacement
  - **REGEXP_EXTRACT** (`Db::regexpExtract()`) - Extract matched substrings with capture group support
  - **Automatic SQLite registration** - REGEXP functions automatically registered via `PDO::sqliteCreateFunction()` if not available
  - **Configuration option** - `enable_regexp` config option for SQLite (default: `true`) to control automatic registration
  - **Cross-dialect support** - MySQL/MariaDB (`REGEXP`, `REGEXP_REPLACE`, `REGEXP_SUBSTR`), PostgreSQL (`~`, `regexp_replace`, `regexp_match`), SQLite (via extension or PHP emulation)
  - **Complete documentation** - `documentation/07-helper-functions/string-helpers.md` with regex examples
  - **Comprehensive examples** - `examples/05-helpers/01-string-helpers.php` demonstrates all regex operations
  - **Full test coverage** - Tests for all dialects including SQLite emulation

- **JSON Modification Helpers** - Enhanced JSON manipulation capabilities:
  - **`Db::jsonSet()`** - Set JSON values at specific paths (supports nested paths)
  - **`Db::jsonRemove()`** - Remove JSON keys or array elements
  - **`Db::jsonReplace()`** - Replace existing JSON values
  - **MariaDB compatibility** - Proper handling of MariaDB's JSON function differences
  - **Cross-dialect support** - Works consistently across MySQL, MariaDB, PostgreSQL, and SQLite
  - **Complete test coverage** - Comprehensive tests for all JSON modification operations

- **DEFAULT Helper Support for SQLite** - `Db::default()` now works on SQLite:
  - **Automatic translation** - `DEFAULT` keyword automatically converted to `NULL` for SQLite UPDATE statements
  - **Consistent behavior** - Provides consistent API across all dialects
  - **UPSERT support** - Works correctly in UPSERT operations for SQLite

- **Savepoints and Nested Transactions** - Advanced transaction management:
  - **Savepoint support** - Create named savepoints within transactions
  - **Nested transactions** - Support for nested transaction patterns
  - **Rollback to savepoint** - Rollback to specific savepoints without affecting outer transactions
  - **Complete documentation** - Transaction management guide with savepoint examples
  - **Examples** - `examples/34-savepoints/` demonstrating savepoint usage

- **SQLite String Function Emulation** - Additional string functions for SQLite:
  - **`REPEAT()`** - Repeat string N times (emulated via PHP)
  - **`REVERSE()`** - Reverse string characters (emulated via PHP)
  - **`LPAD()`** - Left pad string to specified length (emulated via PHP)
  - **`RPAD()`** - Right pad string to specified length (emulated via PHP)
  - **Automatic registration** - Functions automatically registered via `PDO::sqliteCreateFunction()`
  - **Cross-dialect compatibility** - Provides consistent API across all dialects

- **JOIN Support for UPDATE and DELETE** - Advanced DML operations:
  - **UPDATE with JOIN** - Update tables based on JOIN conditions
  - **DELETE with JOIN** - Delete rows based on JOIN conditions
  - **Cross-dialect support** - Works on MySQL, MariaDB, PostgreSQL, and SQLite
  - **Complete documentation** - DML operations guide with JOIN examples
  - **Examples** - `examples/33-update-delete-join/` demonstrating JOIN in UPDATE/DELETE

- **INSERT ... SELECT Support** - Bulk insert from query results:
  - **`QueryBuilder::insertFrom()`** - Insert rows from SELECT query results
  - **Column mapping** - Map columns from source to target table
  - **Cross-dialect support** - Works consistently across all dialects
  - **Complete documentation** - INSERT operations guide with INSERT ... SELECT examples
  - **Examples** - `examples/32-insert-select/` demonstrating INSERT ... SELECT patterns

- **Enhanced EXPLAIN Analysis** - Advanced performance metrics:
  - **Performance metrics** - Additional performance indicators in EXPLAIN output
  - **Better recommendations** - Improved query optimization suggestions
  - **Cross-dialect parsing** - Enhanced parsing for MySQL, PostgreSQL, and SQLite EXPLAIN output

- **Plugin System** - Extend PdoDb with custom functionality:
  - **`AbstractPlugin` class** - Base class for creating plugins
  - **`PdoDb::registerPlugin()`** - Register plugins to extend functionality
  - **Macro registration** - Plugins can register QueryBuilder macros
  - **Scope registration** - Plugins can register global scopes
  - **Event listener registration** - Plugins can register event listeners
  - **Complete documentation** - `documentation/05-advanced-features/plugins.md` with plugin development guide
  - **Examples** - `examples/31-plugins/` demonstrating plugin creation and usage

### Changed
- **Examples Directory Reorganization** - Improved structure for better navigation:
  - Reorganized example files into logical groups
  - Better organization of advanced features examples
  - Improved README files in example directories

- **Code Quality Improvements**:
  - **Namespace refactoring** - Replaced full namespace paths with `use` statements throughout codebase
  - **Better code organization** - Improved maintainability and readability
  - **PSR-12 compliance** - All code follows PSR-12 standards

### Fixed
- **Composer Description** - Shortened description for better Packagist display

### Documentation
- **README Improvements** - Major enhancements for better onboarding:
  - **Reorganized feature list** - Grouped features into logical categories (Core, Performance, Advanced, Developer Experience)
  - **Added "Why PDOdb?" section** - Value proposition and comparisons vs Raw PDO, Eloquent, and Doctrine
  - **Simplified Quick Example** - Replaced complex JSON example with basic CRUD using SQLite
  - **Added "5-Minute Tutorial"** - Step-by-step guide for quick onboarding
  - **Added FAQ section** - 10 common questions and answers
  - **Added Migration Guide** - Examples for migrating from Raw PDO, Eloquent, Doctrine, and Yii2
  - **Improved structure** - Better navigation and readability
  - **Lower entry barrier** - Easier for new users to understand and adopt

- **Examples Section Updates**:
  - Updated Examples section in README.md with current example structure
  - Added Sharding section to README.md
  - Replaced chained `where()` with `andWhere()` in examples and documentation for better clarity

### Technical Details
- **All tests passing**: Comprehensive test coverage including new regex, JSON, and SQLite emulation tests
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **Documentation**: Updated with new features including regex helpers, JSON modification, savepoints, and plugin system
- **Examples**: All examples updated and verified on all dialects (MySQL, MariaDB, PostgreSQL, SQLite)

## [2.9.2] - 2025-11-06

### Added
- **Sharding Support** - Horizontal partitioning across multiple databases with automatic query routing:
  - **Three sharding strategies** - Range, Hash, and Modulo strategies for data distribution
    - **Range Strategy** - Distributes data based on numeric ranges (e.g., 0-1000, 1001-2000)
    - **Hash Strategy** - Distributes data based on hash of shard key value (CRC32)
    - **Modulo Strategy** - Distributes data based on modulo operation (`value % shard_count`)
  - **Unified connection pool** - Uses existing connections from `PdoDb` connection pool via `useConnections()`
  - **Automatic query routing** - Queries automatically routed to appropriate shard based on shard key in WHERE conditions
  - **ShardConfig, ShardRouter, ShardConfigBuilder** - Complete infrastructure for sharding configuration
  - **ShardStrategyInterface** - Extensible interface for custom sharding strategies
  - **Fluent API** - `$db->shard('table')->shardKey('id')->strategy('range')->useConnections([...])->register()`
  - **Comprehensive examples** - `examples/30-sharding/` with all strategies and use cases
  - **Complete documentation** - `documentation/05-advanced-features/sharding.md` with detailed guides
  - **Full test coverage** - 15 tests covering all sharding strategies and edge cases

- **QueryBuilder Macros** - Extend QueryBuilder with custom methods:
  - **MacroRegistry class** - Central registry for managing macro registration and storage
  - **QueryBuilder::macro()** - Register custom query methods as macros
  - **QueryBuilder::hasMacro()** - Check if a macro exists
  - **Dynamic execution** - `__call()` implementation for automatic macro execution
  - **Comprehensive examples** - `examples/29-macros/` demonstrating macro usage patterns
  - **Complete documentation** - `documentation/05-advanced-features/query-macros.md` with examples
  - **Full test coverage** - Comprehensive tests for macro functionality

- **Enhanced WHERE Condition Methods** - Fluent methods for common WHERE patterns:
  - **Null checks**: `whereNull()`, `whereNotNull()`, `andWhereNull()`, `orWhereNull()`, etc.
  - **Range checks**: `whereBetween()`, `whereNotBetween()`, `andWhereBetween()`, `orWhereBetween()`, etc.
  - **Column comparisons**: `whereColumn()`, `andWhereColumn()`, `orWhereColumn()`
  - **Enhanced IN clauses**: `whereIn()` and `whereNotIn()` now support arrays in addition to subqueries
  - **AND/OR variants** - All new methods have `and*` and `or*` variants for fluent chaining
  - **27 new tests** - Comprehensive test coverage (66 assertions) in `WhereConditionMethodsTests`
  - **Updated examples** - `examples/01-basic/03-where-conditions.php` demonstrates all new methods
  - Improves developer experience by eliminating need for helper functions in common WHERE patterns

- **Connection Management**:
  - **PdoDb::getConnection()** - Retrieve connection from pool by name
  - Enables sharding to reuse existing connections from connection pool

### Changed
- **Fluent Interface Return Types** - Replaced `self` with `static` in all interfaces:
  - `ConditionBuilderInterface`, `DmlQueryBuilderInterface`, `ExecutionEngineInterface`
  - `FileLoaderInterface`, `JoinBuilderInterface`, `JsonQueryBuilderInterface`
  - `ParameterManagerInterface`, `QueryBuilderInterface`, `SelectQueryBuilderInterface`
  - Better support for extending QueryBuilder classes and improved IDE autocomplete

- **Composer Metadata**:
  - **Updated description** - Full description (487 chars) with all major features including sharding, migrations, query macros
  - **Added keywords** - 17 new keywords: `sharding`, `migrations`, `query-macros`, `macro-system`, `ddl`, `schema-builder`, `lateral-join`, `query-profiling`, `query-compilation-cache`, `batch-processing`, `merge-statements`, `sql-formatter`, `sql-pretty-printer`, `connection-retry`, `psr-14`, `event-dispatcher`, `explain-analysis`
  - Improves discoverability on Packagist and GitHub

### Technical Details
- **All tests passing**: Comprehensive test coverage with new sharding, macro, and WHERE condition tests
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **Documentation**: Added 3 new comprehensive documentation files:
  - `documentation/05-advanced-features/sharding.md` (241 lines)
  - `documentation/05-advanced-features/query-macros.md` (345 lines)
  - Updated `documentation/03-query-builder/filtering-conditions.md` with enhanced WHERE methods
- **Examples**: Added 2 new example directories:
  - `examples/30-sharding/` (3 files, 352 lines total)
  - `examples/29-macros/` (2 files, 470 lines total)

## [2.9.1] - 2025-11-04

### Added
- **Enhanced Error Diagnostics** - Query context and debug information in exceptions:
  - **QueryDebugger class** - Helper class for parameter sanitization and context formatting
  - **QueryException enhancements** - `QueryException` now includes `queryContext` property with detailed query builder state
  - **getDebugInfo() methods** - Added to `QueryBuilder` and all component builders (`ConditionBuilder`, `JoinBuilder`, `SelectQueryBuilder`, `DmlQueryBuilder`)
  - **Automatic context capture** - Query context automatically captured and included in exceptions when errors occur
  - **Parameter sanitization** - Sensitive parameters (passwords, tokens, API keys) automatically masked in error messages
  - **Comprehensive documentation** - New `error-diagnostics.md` documentation page with examples
  - **Example** - `02-error-diagnostics.php` demonstrating new error diagnostics features
  - Implements roadmap item 1.1: Enhanced Error Diagnostics

- **ActiveRecord Relationship Enhancements**:
  - **Many-to-many relationships** - `viaTable()` and `via()` methods for junction table relationships
  - **Yii2-like syntax** - Support for Yii2-style relationship definitions for easier migration
  - Enhanced relationship documentation and examples

- **QueryBuilder Convenience Methods**:
  - **first()** - Get first row from query result
  - **last()** - Get last row from query result
  - **index()** - Index query results by a specific column
  - Improved developer experience with commonly used query patterns

- **CLI Migration Tool** - Yii2-style migration command-line tool:
  - Create, run, and rollback migrations via command line
  - Yii2-compatible migration workflow
  - Enhanced migration management capabilities

- **Documentation Improvements**:
  - **MariaDB configuration examples** - Added MariaDB configuration section to Getting Started documentation
  - **MariaDB connection examples** - Added MariaDB connection example to first-connection.md
  - Complete MariaDB setup guide for new users

### Changed
- **Fulltext search helper** - Renamed `Db::fulltextMatch()` to `Db::match()` for clarity and consistency
- **Composer script namespacing** - All composer scripts now use `pdodb:` prefix for better organization
- **PdoDb scope methods** - Renamed for better readability and consistency

### Fixed
- **LoadBalancerTests** - Fixed fatal error by implementing missing `setTempQueryContext()` method in test stub
- **PHPStan type errors** - Fixed all type-related static analysis issues
- **PHP-CS-Fixer issues** - Resolved all code style issues

### Technical Details
- **All tests passing**: Comprehensive test coverage with improved ActiveQuery, EagerLoader, and CacheManager tests
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles

## [2.9.0] - 2025-11-01

### Added
- **MariaDB Support** - Full support for MariaDB as a fourth database dialect:
  - **New MariaDBDialect class** - Complete SQL dialect implementation for MariaDB
  - **Comprehensive test coverage** - 171 tests, 783 assertions covering all MariaDB-specific functionality
  - **GitHub Actions integration** - Dedicated CI job for MariaDB with coverage reporting
  - **Example compatibility** - All 51 examples now support MariaDB (51/51 passing)
  - **Documentation updates** - All README files and documentation updated to reflect 4-dialect support
  - **Helper function support** - MariaDB support added to `examples/helpers.php` for CREATE TABLE normalization
  - **Explain analysis support** - MariaDB EXPLAIN parsing uses MySQL-compatible parser

- **MERGE Statement Support** - Standard SQL MERGE operations:
  - **PostgreSQL**: Native MERGE statement with `MATCHED`/`NOT MATCHED` clauses
  - **MySQL 8.0+**: Enhanced UPSERT via `INSERT ... ON DUPLICATE KEY UPDATE`
  - **SQLite**: Enhanced UPSERT support
  - **QueryBuilder::merge()** method for MERGE operations
  - **Complex data synchronization** - Merge source data into target tables with conditional logic
  - Examples in `examples/04-json/` and comprehensive documentation

- **SQL Formatter/Pretty Printer** - Human-readable SQL output for debugging:
  - **QueryBuilder::toSQL(bool $isFormatted = false)** - Optional formatted SQL output
  - **Proper indentation** - Nested queries, JOINs, and subqueries properly indented
  - **Line breaks** - Complex queries formatted across multiple lines for readability
  - **Keyword highlighting** - Optional SQL keyword highlighting for better visualization
  - **SqlFormatter class** - Standalone formatter for SQL queries
  - Enhanced debugging experience for complex queries

- **Query Builder IDE Autocomplete Enhancement**:
  - **@template annotations** - Better type inference for IDE autocomplete
  - **Improved return types** - More precise PHPDoc annotations for fluent interface methods
  - **Better inheritance support** - Replaced `self` with `static` for fluent interface methods

### Changed
- **Fluent Interface Return Types** - Replaced `self` with `static` for better inheritance support:
  - All fluent interface methods now return `static` instead of `self`
  - Better support for extending QueryBuilder classes
  - Improved IDE autocomplete in subclass contexts
  - Applied to `QueryBuilder`, `SelectQueryBuilder`, `DmlQueryBuilder`, and all helper traits

- **Documentation Improvements**:
  - Replaced `Db::raw()` calls with QueryBuilder helpers where possible in documentation
  - Better examples demonstrating proper helper usage
  - Cleaner, more maintainable documentation examples

### Fixed
- **MariaDB Window Functions** - LAG/LEAD functions with default values:
  - MariaDB doesn't support third parameter (default value) in LAG/LEAD
  - Automatic COALESCE wrapper added for MariaDB when default value is provided
  - Maintains API compatibility across all dialects

- **MariaDB Type Casting** - CAST operations for MariaDB:
  - Replaced `CAST(... AS REAL)` with `CAST(... AS DECIMAL(10,2))` for MariaDB/MySQL
  - MariaDB doesn't support REAL type in CAST operations
  - All type helper examples now work correctly on MariaDB

- **MariaDB JSON Operations**:
  - Fixed `JSON_SET` for nested paths - string values handled correctly without double-escaping
  - Fixed `JSON_REMOVE` for array indices - uses proper JSON_REMOVE instead of JSON_SET with null
  - MariaDB-specific JSON function handling

- **MariaDB Health Check in GitHub Actions**:
  - Fixed health check command to use built-in MariaDB healthcheck script
  - Added `--health-start-period=40s` to allow MariaDB time to initialize
  - Increased retries to 20 for better reliability

- **Example Compatibility**:
  - All examples updated to support MariaDB alongside MySQL, PostgreSQL, and SQLite
  - CREATE TABLE statements normalized for MariaDB compatibility
  - Dialect-specific logic updated in all example files

### Technical Details
- **All tests passing**: 1200 tests, 4856 assertions (+209 tests, +905 assertions from 2.8.0)
- **All examples passing**: 204/204 examples (51 files × 4 dialects each)
  - SQLite: 51/51 ✅
  - MySQL: 51/51 ✅
  - MariaDB: 51/51 ✅
  - PostgreSQL: 51/51 ✅
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **MariaDB integration**: Complete dialect support with comprehensive testing

## [2.8.0] - 2025-11-01

### Added
- **ActiveRecord Pattern** - Optional lightweight ORM for object-based database operations:
  - **Magic attribute access** - `$user->name`, `$user->email`
  - **Automatic CRUD** - `save()`, `delete()`, `refresh()`
  - **Dirty tracking** - Track changed attributes automatically
  - **Declarative validation** - Rules-based validation with extensible validators (required, email, integer, string, custom)
  - **Lifecycle events** - PSR-14 events for save, insert, update, delete operations
  - **ActiveQuery builder** - Full QueryBuilder API through `find()` method
  - `Model` base class with complete ActiveRecord implementation
  - `ValidatorInterface` for custom validation rules
  - Comprehensive examples in `examples/23-active-record/`
  - Complete documentation in `documentation/05-advanced-features/active-record.md`
  - Comprehensive test coverage with edge case testing

- **Query Performance Profiling** - Built-in profiler for performance analysis:
  - Automatic tracking of all query executions
  - Execution time measurement (total, average, min, max)
  - Memory usage tracking per query
  - Slow query detection with configurable threshold
  - Query grouping by SQL structure (same query pattern grouped together)
  - PSR-3 logger integration for slow query logging
  - Statistics reset for new measurement periods
  - `enableProfiling()`, `disableProfiling()`, `getProfilerStats()`, `getSlowestQueries()` methods
  - Complete documentation in `documentation/05-advanced-features/query-profiling.md`
  - Examples in `examples/21-query-profiling/`

- **Materialized CTE Support** - Performance optimization for expensive queries:
  - `QueryBuilder::withMaterialized()` method for PostgreSQL and MySQL
  - PostgreSQL: Uses `MATERIALIZED` keyword
  - MySQL: Uses optimizer hints
  - Automatically cached expensive CTE computations
  - Cross-database support (PostgreSQL 12+, MySQL 8.0+)
  - Examples in `examples/17-cte/03-materialized-cte.php`
  - Documentation updates in `documentation/03-query-builder/cte.md`

- **PSR-14 Event Dispatcher Integration** - Event-driven architecture:
  - `ConnectionOpenedEvent` - Fired when connection is opened
  - `QueryExecutedEvent` - Fired after successful query execution
  - `QueryErrorEvent` - Fired when query error occurs
  - `TransactionStartedEvent`, `TransactionCommittedEvent`, `TransactionRolledBackEvent`
  - Works with any PSR-14 compatible event dispatcher (Symfony, League, custom)
  - Examples in `examples/19-events/`
  - Complete integration guide in documentation

- **Enhanced EXPLAIN with Recommendations** - Automatic query optimization analysis:
  - `QueryBuilder::explainAdvice()` method (renamed from `explainWithRecommendations`)
  - Automatic detection of full table scans
  - Missing index identification with SQL suggestions
  - Filesort and temporary table warnings
  - Dialect-aware parsing (MySQL, PostgreSQL, SQLite)
  - Structured recommendations with severity levels
  - Enhanced documentation and examples

- **Comprehensive Exception Testing** - Full test coverage for exception handling:
  - `ExceptionTests` - 33 tests covering all exception classes and ExceptionFactory
  - `ErrorDetectionStrategyTests` - 36 tests for all error detection strategies
  - `ConstraintParserTests` - 14 tests for error message parsing
  - `ErrorCodeRegistryTests` - 30 tests for error code registry
  - Improved `ConstraintParser` with better pattern matching:
    - Enhanced `CONSTRAINT \`name\`` pattern matching for MySQL
    - Improved `FOREIGN KEY (\`name\`)` pattern for column extraction
    - Better `schema.table` format handling
    - Filtering of invalid constraint names (e.g., "fails", "failed")
  - All exception classes now fully tested with edge cases

- **Infection Mutation Testing** - Code quality assurance:
  - Integrated Infection mutation testing framework
  - Configuration file `infection.json` with comprehensive settings
  - Mutation testing scripts in `composer.json`:
    - `composer pdodb:infection` - Full mutation test run
    - `composer pdodb:infection:quick` - Quick mutation test
    - `composer pdodb:infection:ci` - CI-optimized mutation test
  - `composer pdodb:check-all-with-infection` script for complete quality checks
  - Minimum MSI (Mutation Score Indicator) requirements configured

### Changed
- **External Reference Detection Simplification** - KISS/YAGNI refactoring:
  - Removed `externalTables` array and `setExternalTables()` method
  - Simplified `isTableInCurrentQuery()` to only check against current query table
  - External references automatically detected without manual configuration
  - Cleaner, more maintainable code following KISS principles

- **Query Compilation Cache Improvements**:
  - Fixed compilation cache normalization issues
  - Prevented double caching in `getValue()` method
  - Optimized result cache by avoiding double SQL compilation
  - Renamed `21-query-compilation-cache` directory to `20-query-compilation-cache`
  - Updated all documentation references

- **Memory Management Enhancements**:
  - Fixed memory leaks by properly closing PDOStatement cursors
  - All fetch methods (`get()`, `getOne()`, `fetch()`, `fetchColumn()`) automatically close cursors
  - Exception-safe cleanup using `try/finally` blocks
  - Production-tested with 50,000+ queries without memory accumulation
  - Added memory leak prevention documentation to README

- **Method Renaming for Clarity**:
  - `QueryBuilder::cursor()` renamed to `QueryBuilder::stream()` for better API clarity
  - `QueryBuilder::explainWithRecommendations()` renamed to `QueryBuilder::explainAdvice()`
  - Updated all documentation and examples

- **Test Organization Improvements**:
  - Split tests into organized groups for better CI workflow
  - Improved PHPUnit configuration for directory-based test execution
  - Better test isolation and structure

### Fixed
- **SQLite Cache Parameter Validation** - Prevent invalid DSN parameters:
  - Added validation for SQLite `cache` parameter in `SqliteDialect::buildDsn()`
  - Valid values: `shared`, `private`
  - Throws `InvalidArgumentException` for invalid values
  - Prevents creation of files like `:memory:;cache=Array` or `:memory:;cache=invalid`
  - Added comprehensive tests for cache and mode parameter validation

- **SQL Identifier Quoting in FileLoader** - Correct quoting for different dialects:
  - Fixed SQL identifier quoting in `FileLoader` for MySQL, PostgreSQL, and SQLite
  - Ensures proper quoting based on dialect-specific requirements
  - Improved cross-dialect compatibility

- **LATERAL JOIN Improvements**:
  - Enhanced automatic external reference detection in LATERAL JOIN subqueries
  - Removed need for `Db::raw()` in most LATERAL JOIN scenarios
  - Better alias handling and SQL generation
  - Updated all examples and documentation

- **Removed All Skipped Tests** - Zero skipped tests policy:
  - Moved all dialect-specific tests from shared to dialect-specific test files
  - No more `markTestSkipped()` calls in shared tests
  - All tests actively run and verify functionality

- **Markdown EOF Formatting** - Consistent file formatting:
  - Added `fix-markdown-eof.sh` script to ensure exactly one empty line at EOF
  - Integrated into `composer pdodb:check-all` for automatic fixing
  - All markdown files now have consistent formatting

### Technical Details
- **All tests passing**: 991 tests, 3951 assertions (+417 tests, +1425 assertions from 2.7.1)
- **All examples passing**: 147/147 examples (49 files × 3 dialects each)
  - SQLite: 49/49 ✅
  - MySQL: 49/49 ✅
  - PostgreSQL: 49/49 ✅
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **Memory safety**: Zero memory leaks verified with 50,000+ query tests
- **SHA-256 hashing**: Replaced all MD5 usage with SHA-256 for better security:
  - Cache key generation uses SHA-256
  - Parameter name generation uses SHA-256 with 16-character truncation
  - LATERAL JOIN alias generation uses SHA-256
- **Infection mutation testing**: Integrated for code quality assurance
- **Code statistics**: Significant additions across exception handling, ActiveRecord, and profiling features

---

## [2.7.1] - 2025-10-29

### Added
- **Window Functions Support** - Advanced analytics with SQL window functions:
  - **Ranking functions**: `Db::rowNumber()`, `Db::rank()`, `Db::denseRank()`, `Db::ntile()`
  - **Value access functions**: `Db::lag()`, `Db::lead()`, `Db::firstValue()`, `Db::lastValue()`, `Db::nthValue()`
  - **Window aggregates**: `Db::windowAggregate()` for running totals, moving averages
  - Support for `PARTITION BY`, `ORDER BY`, and frame clauses (`ROWS BETWEEN`)
  - Cross-database support (MySQL 8.0+, PostgreSQL 9.4+, SQLite 3.25+)
  - `WindowFunctionValue` class and `WindowHelpersTrait` with 11 helper methods
  - Complete implementation of `formatWindowFunction()` in all dialects
  - Comprehensive examples in `examples/16-window-functions/` (10 use cases)
  - Full documentation:
    - `documentation/03-query-builder/window-functions.md` (500+ lines)
    - `documentation/07-helper-functions/window-helpers.md` (400+ lines)
  - 8 comprehensive tests with 44 assertions covering all window functions
  - Use cases: rankings, leaderboards, period-over-period analysis, trends, quartiles

- **Common Table Expressions (CTEs) Support** - WITH clauses for complex queries:
  - `QueryBuilder::with()` and `withRecursive()` methods for CTE definitions
  - Support for basic CTEs using `Closure`, `QueryBuilder`, or raw SQL
  - Recursive CTEs for hierarchical data processing (tree structures, organizational charts)
  - Multiple CTEs with unique parameter scoping to avoid conflicts
  - Explicit column lists for CTEs (recommended for recursive CTEs)
  - Proper parameter passing from CTEs to main query
  - Cross-database support (MySQL 8.0+, PostgreSQL 8.4+, SQLite 3.8.3+)
  - `CteManager` and `CteDefinition` classes for CTE management
  - Comprehensive test coverage (9 new tests in `SharedCoverageTest`)
  - 2 runnable examples in `examples/17-cte/` (basic and recursive CTEs, 10 scenarios total)
  - Complete documentation in `documentation/03-query-builder/cte.md` with use cases and best practices

- **Set Operations** - UNION, INTERSECT, and EXCEPT support:
  - `QueryBuilder::union()`, `unionAll()`, `intersect()`, `except()` methods
  - Support for both `Closure` and `QueryBuilder` instances in set operations
  - Proper `ORDER BY`/`LIMIT`/`OFFSET` placement after set operations (SQL standard compliance)
  - Cross-database: MySQL 8.0+, PostgreSQL, SQLite 3.8.3+
  - `UnionQuery` class for operation management
  - 6 comprehensive examples in `examples/18-set-operations/`
  - Complete documentation in `documentation/03-query-builder/set-operations.md`

- **DISTINCT and DISTINCT ON** - Remove duplicates from result sets:
  - `QueryBuilder::distinct()` method for all databases
  - `QueryBuilder::distinctOn()` method with PostgreSQL-only support
  - Runtime dialect validation with `RuntimeException` for unsupported databases
  - `DialectInterface::supportsDistinctOn()` method for feature detection
  - Examples added to `examples/01-basic/05-ordering.php`
  - Complete documentation in `documentation/03-query-builder/distinct.md`

- **FILTER Clause for Conditional Aggregates** - SQL:2003 standard compliance:
  - `filter()` method chainable after all aggregate functions (`COUNT`, `SUM`, `AVG`, `MIN`, `MAX`)
  - `FilterValue` class replaces `RawValue` for aggregate function returns
  - Native `FILTER (WHERE ...)` clause for PostgreSQL and SQLite 3.30+
  - Automatic `CASE WHEN` fallback for MySQL (which doesn't support FILTER clause)
  - `DialectInterface::supportsFilterClause()` method for feature detection
  - Examples added to `examples/02-intermediate/02-aggregations.php`
  - Complete documentation in `documentation/03-query-builder/filter-clause.md`
  - SQL:2003 standard compliance with automatic dialect-specific translation

- **Comprehensive Edge-Case Testing** - 20 new edge-case tests covering critical scenarios:
  - **CTEs**: Empty results, NULL values, recursive CTEs with complex data
  - **UNION/INTERSECT/EXCEPT**: Empty tables, NULL handling, no common values
  - **FILTER Clause**: No matches, all NULLs, empty tables
  - **DISTINCT**: Empty tables, all NULLs, mixed NULL/non-NULL values
  - **Window Functions**: Empty tables, NULL partitions, single row scenarios
  - Added 18 new tests in `SharedCoverageTest`
  - Added 2 dialect-specific tests (MySQL, SQLite) for DISTINCT ON validation
  - Edge cases validate: empty result sets, NULL value handling, boundary conditions, unsupported feature detection

### Changed
- **Aggregate helpers return type**: All aggregate functions (`Db::count()`, `Db::sum()`, `Db::avg()`, `Db::min()`, `Db::max()`) now return `FilterValue` instead of `RawValue` to support chaining with `filter()` method
- **SelectQueryBuilder enhancements**: Added methods `setUnions()`, `setDistinct()`, `setDistinctOn()` to `SelectQueryBuilderInterface`
- **QueryBuilder parameter management**: Enhanced parameter merging for UNION subqueries to prevent conflicts

### Fixed
- **MySQL recursive CTE string concatenation**: Fixed `examples/17-cte/02-recursive-cte.php` test failure on MySQL
  - MySQL doesn't support `||` operator for string concatenation by default
  - Added dialect-specific handling using `CONCAT()` function for MySQL
  - PostgreSQL and SQLite continue using `||` operator
  - Example now works correctly on all three database dialects

### Technical Details
- **All tests passing**: 574 tests, 2526 assertions (+41 tests, +129 assertions from 2.7.0)
- **All examples passing**: 108/108 examples (36 files × 3 dialects each)
  - SQLite: 36/36 ✅
  - MySQL: 36/36 ✅
  - PostgreSQL: 36/36 ✅
- **PHPStan Level 9**: Zero errors across entire codebase (upgraded from Level 8)
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **Documentation**: Added 6 new comprehensive documentation files:
  - `documentation/03-query-builder/window-functions.md` (473 lines)
  - `documentation/07-helper-functions/window-helpers.md` (550 lines)
  - `documentation/03-query-builder/cte.md` (409 lines)
  - `documentation/03-query-builder/set-operations.md` (204 lines)
  - `documentation/03-query-builder/distinct.md` (320 lines)
  - `documentation/03-query-builder/filter-clause.md` (349 lines)
- **Examples**: Added 4 new example directories with comprehensive demos:
  - `examples/16-window-functions/` (360 lines, 10 use cases)
  - `examples/17-cte/` (521 lines total, 10 scenarios)
  - `examples/18-set-operations/` (138 lines, 6 set operation examples)
  - Extended existing examples with DISTINCT and FILTER clause demonstrations
- **Code statistics**: 39 files changed, 6507 insertions(+), 80 deletions(-)

---

## [2.7.0] - 2025-10-28

### Added
- **Full-Text Search** - Cross-database full-text search with `Db::match()` helper:
  - **MySQL**: `MATCH AGAINST` with FULLTEXT indexes
  - **PostgreSQL**: `@@` operator with `to_tsquery()` and text search vectors
  - **SQLite**: FTS5 virtual tables support
  - Support for multiple columns, search modes (natural language, boolean), and query expansion
  - `FulltextMatchValue` class and `FulltextSearchHelpersTrait` for implementation
  - Complete documentation in `documentation/03-query-builder/fulltext-search.md`
  - Examples in `examples/12-fulltext-search/` working on all three dialects
  - 12 new tests across MySQL, PostgreSQL, and SQLite

- **Schema Introspection** - Query database structure programmatically:
  - `PdoDb::getIndexes(string $table)` - Get all indexes for a table
  - `PdoDb::getForeignKeys(string $table)` - Get foreign key constraints
  - `PdoDb::getConstraints(string $table)` - Get all constraints (PRIMARY KEY, UNIQUE, FOREIGN KEY, CHECK)
  - `QueryBuilder::getIndexes()`, `getForeignKeys()`, `getConstraints()` - Same methods via QueryBuilder
  - Cross-database compatible across MySQL, PostgreSQL, and SQLite
  - Complete documentation in `documentation/03-query-builder/schema-introspection.md`
  - Tests in all dialect-specific test files

- **Query Result Caching** - PSR-16 (Simple Cache) integration for dramatic performance improvements:
  - **10-1000x faster** for repeated queries with caching enabled
  - `QueryBuilder::cache(?int $ttl = null, ?string $key = null)` - Enable caching for query
  - `QueryBuilder::noCache()` - Disable caching for specific query
  - `PdoDb::enableCache(CacheInterface $cache, ?CacheConfig $config = null)` - Enable caching globally
  - `PdoDb::disableCache()` - Disable caching globally
  - Support for all PSR-16 compatible cache implementations:
    - Symfony Cache, Redis, APCu, Memcached, Filesystem adapters
    - Built-in `ArrayCache` for testing
  - Automatic cache key generation based on SQL and parameters
  - Custom cache keys and TTL support
  - `CacheManager`, `CacheConfig`, `QueryCacheKey` infrastructure classes
  - Complete documentation in `documentation/05-advanced-features/query-caching.md`
  - Examples in `examples/14-caching/` demonstrating real-world usage
  - Comprehensive test coverage in `SharedCoverageTest`

- **Advanced Pagination** - Three pagination types for different use cases:
  - **Full pagination** (`paginate(int $page, int $perPage)`): Complete pagination with total count and page numbers
    - Returns `PaginationResult` with items, total, current page, last page, per page
    - Best for UI with page numbers and "showing X of Y results"
  - **Simple pagination** (`simplePaginate(int $page, int $perPage)`): Fast pagination without total count
    - Returns `SimplePaginationResult` with items, has more pages flag
    - No COUNT query overhead - ideal for large datasets
  - **Cursor-based pagination** (`cursorPaginate(?string $cursor, int $perPage, string $column = 'id')`): Scalable pagination for real-time feeds
    - Returns `CursorPaginationResult` with items, next cursor, previous cursor
    - Consistent results even with new data
    - Perfect for infinite scroll and API endpoints
  - All results implement `JsonSerializable` for easy API responses
  - URL generation support with query parameters
  - Complete documentation in `documentation/05-advanced-features/pagination.md`
  - Examples in `examples/13-pagination/` working on all dialects
  - Comprehensive test coverage for all pagination types

- **Export Helpers** - Export query results to various formats:
  - `Db::toJson($data, int $options = 0)` - Export to JSON with customizable encoding options
    - Support for pretty printing, unescaped unicode, unescaped slashes
  - `Db::toCsv($data, string $delimiter = ',', string $enclosure = '"')` - Export to CSV format
    - Custom delimiter and enclosure character support
  - `Db::toXml($data, string $rootElement = 'root', string $itemElement = 'item')` - Export to XML format
    - Customizable root and item element names
    - Support for nested arrays and complex data structures
  - SOLID architecture with separate exporter classes (`JsonExporter`, `CsvExporter`, `XmlExporter`)
  - Complete documentation in `documentation/07-helper-functions/export-helpers.md`
  - Examples in `examples/11-export-helpers/` demonstrating all export formats
  - Comprehensive test coverage in `SharedCoverageTest`

- **JSON File Loading** - Bulk load JSON data directly into database tables:
  - `QueryBuilder::loadJson(string $filename, bool $update = false, ?array $updateFields = null)` - Load JSON array of objects
  - `QueryBuilder::loadJsonLines(string $filename, bool $update = false, ?array $updateFields = null)` - Load newline-delimited JSON (JSONL/NDJSON)
  - Support for nested JSON with automatic encoding
  - Memory-efficient batch processing for large files
  - Works across all three database dialects (MySQL, PostgreSQL, SQLite)
  - UPSERT support with optional update fields
  - Complete documentation in `documentation/05-advanced-features/file-loading.md`
  - Examples in `examples/05-file-loading/` with practical scenarios

- **Enhanced orderBy()** - Flexible sorting with multiple syntax options:
  - **Array syntax with directions**: `orderBy(['name' => 'ASC', 'created_at' => 'DESC'])`
  - **Array with default direction**: `orderBy(['name', 'email'], 'DESC')`
  - **Comma-separated string**: `orderBy('name ASC, email DESC, id')`
  - **Original syntax still works**: `orderBy('name', 'DESC')`
  - Full backward compatibility with existing code
  - Complete documentation and examples in `documentation/03-query-builder/ordering-pagination.md`
  - New example file `examples/01-basic/05-ordering.php` demonstrating all syntax variants
  - Comprehensive test coverage in `SharedCoverageTest`

- **Read/Write Connection Splitting** - Master-replica architecture for horizontal scaling:
  - **Automatic query routing**: SELECT queries → read replicas, DML operations → write master
  - **Three load balancing strategies**:
    - `RoundRobinLoadBalancer` - Even distribution across replicas
    - `RandomLoadBalancer` - Random replica selection
    - `WeightedLoadBalancer` - Weight-based distribution (e.g., 70% replica-1, 30% replica-2)
  - **Sticky writes**: Read-after-write consistency (reads from master for N seconds after write)
  - **Force write mode**: `QueryBuilder::forceWrite()` to explicitly read from master
  - **Transaction support**: All operations in transactions use write connection
  - **Health checks and failover**: Automatic exclusion of failed connections
  - Type-safe with `ConnectionType` enum (`READ`, `WRITE`)
  - New classes: `ConnectionRouter`, `LoadBalancerInterface` + 3 implementations
  - New methods: `enableReadWriteSplitting()`, `disableReadWriteSplitting()`, `enableStickyWrites()`, `disableStickyWrites()`, `getConnectionRouter()`
  - Complete documentation in `documentation/05-advanced-features/read-write-splitting.md`
  - Three examples in `examples/15-read-write-splitting/` demonstrating all features
  - 13 new tests with 24 assertions in `SharedCoverageTest`

- **Comprehensive Documentation** - 57 new documentation files (~12,600 lines):
  - **Getting Started**: Installation, configuration, first connection, hello world
  - **Core Concepts**: Connection management, dialect support, parameter binding, query builder basics
  - **Query Builder**: All operations (SELECT, INSERT, UPDATE, DELETE, JOINs, aggregations, subqueries, etc.)
  - **JSON Operations**: Complete JSON support across all dialects
  - **Advanced Features**: Transactions, bulk operations, batch processing, file loading, pagination, query caching, connection retry, read/write splitting
  - **Error Handling**: Exception hierarchy, error codes, logging, monitoring, retry logic
  - **Helper Functions**: 80+ helpers organized by category (string, math, date, JSON, aggregate, comparison, etc.)
  - **Best Practices**: Security, performance, memory management, common pitfalls, code organization
  - **Reference**: Complete API reference, dialect differences, method documentation
  - **Cookbook**: Real-world examples, migration guide, troubleshooting, common patterns
  - README.md files added to all example subdirectories for better navigation

### Changed
- **Memory-efficient file loading**: Refactored `loadCsv()` and `loadXml()` to use PHP generators
  - Dramatically reduced memory consumption for large files
  - Added `loadFromCsvGenerator()` and `loadFromXmlGenerator()` in `FileLoader` class
  - Batched processing to handle files larger than available RAM
  - MySQL and PostgreSQL keep native commands but wrapped as generators
  - All 478+ tests passing with improved performance

- **Code architecture improvements**:
  - Removed `ParameterManager` class (functionality integrated into other components)
  - Changed method/property visibility from `private` to `protected` for better extensibility
  - Renamed schema introspection methods for consistency
  - Updated return types in `TableManagementTrait` from `static` to `self`

- **Test refactoring for best practices**:
  - Replaced direct `$db->connection` calls with public API methods
  - Use `$db->rawQuery()` instead of `$db->connection->query()`
  - Use `$db->startTransaction()`, `$db->commit()`, `$db->rollback()` instead of direct connection calls
  - Direct connection access only in tests specifically testing `ConnectionInterface`

- **Enhanced README.md**:
  - Restructured feature list with bold categories and detailed descriptions
  - Added all new features with performance metrics (e.g., "10-1000x faster queries")
  - More scannable and impressive presentation
  - Complete table of contents with all sections

- **QueryBuilder enhancements**:
  - Dynamic connection switching for read/write splitting
  - Cache-aware query execution
  - Enhanced ordering with multiple syntax options

### Fixed
- **Invalid release date**: Fixed incorrect date in CHANGELOG.md for v2.6.1
- **Cross-dialect compatibility**: Exception handling examples now work correctly on MySQL, PostgreSQL, and SQLite
  - Added dialect-specific CREATE TABLE statements
  - Added DROP TABLE IF EXISTS for idempotency
  - All examples pass on all three dialects

### Technical Details
- **All tests passing**: 533 tests, 2397 assertions (+55 tests from 2.6.2)
- **All examples passing**: 93/93 examples (31 files × 3 dialects each)
  - SQLite: 31/31 ✅
  - MySQL: 31/31 ✅
  - PostgreSQL: 31/31 ✅
- **PHPStan Level 8**: Zero errors across entire codebase
- **PHP-CS-Fixer**: All code complies with PSR-12 standards
- **Full backward compatibility**: 100% maintained - all existing code continues to work
- **Code quality**: Follows KISS, SOLID, DRY, YAGNI principles
- **Documentation**: 57 comprehensive documentation files covering all features
- **Performance**: Memory-efficient generators, optional query caching for 10-1000x speedup

---

## [2.6.2] - 2025-10-25

### Added
- **Comprehensive examples expansion**: Added 5 new comprehensive example files demonstrating all library functionality:
  - `05-comparison-helpers.php` - LIKE, BETWEEN, IN, NOT operations with practical scenarios
  - `06-conditional-helpers.php` - CASE statements with complex conditional logic
  - `07-boolean-helpers.php` - TRUE/FALSE/DEFAULT values and boolean operations
  - `08-type-helpers.php` - CAST, GREATEST, LEAST type conversion and comparison functions
  - `04-subqueries.php` - Complete subquery examples (EXISTS, NOT EXISTS, scalar subqueries)
- **Enhanced existing examples**: Significantly expanded all helper function examples with real-world scenarios
- **Complete CONTRIBUTING.md**: Added comprehensive development guidelines (413 lines) covering:
  - Development setup and testing procedures
  - Code style requirements and commit conventions
  - Pull request guidelines and templates
  - Release process and maintainer information
  - Security reporting and communication channels

### Changed
- **Major architectural refactoring** following SOLID principles:
  - **Connection architecture**: Extracted ConnectionLogger, ConnectionState, DialectRegistry, RetryConfigValidator
  - **Strategy pattern for exception handling**: Replaced monolithic ExceptionFactory with 7 specialized strategies
  - **Dialect refactoring**: Extracted common functionality into reusable traits (JsonPathBuilderTrait, UpsertBuilderTrait, FileLoader)
  - **Helper system refactoring**: Converted all helper classes to traits organized by functionality
  - **QueryBuilder refactoring**: Split monolithic QueryBuilder into focused components with proper interfaces
- **Code organization improvements**:
  - Moved helper classes to `src/helpers/traits/` directory
  - Moved query interfaces to `src/query/interfaces/` directory
  - Split DbError into dialect-specific traits (MysqlErrorTrait, PostgresqlErrorTrait, SqliteErrorTrait)
  - Created utility classes: ParameterManager, FileLoader, ConstraintParser, ErrorCodeRegistry
- **Enhanced examples**: Replaced `Db::raw()` calls with helper functions where possible for better educational value

### Fixed
- **Cross-database compatibility**: Fixed all examples to work consistently on SQLite, MySQL, and PostgreSQL
- **Code duplication elimination**: Removed ~1000+ lines of duplicate code through trait extraction
- **Type safety improvements**: Added proper PHPDoc annotations and null safety checks

### Technical Details
- **All tests passing**: 478 tests, 2198 assertions
- **All examples passing**: 90/90 examples (30 files × 3 dialects each)
- **PHPStan Level 8**: Zero errors across entire codebase
- **Backward compatibility**: 100% maintained - no breaking changes to public API
- **Performance**: Improved through reduced code duplication and better architecture
- **Maintainability**: Significantly improved through SOLID principles and trait-based organization

---

## [2.6.1] - 2025-10-23

### Added
- **Automatic external reference detection in subqueries**: QueryBuilder now automatically detects and converts external table references (`table.column`) to `RawValue` objects
  - Works in `where()`, `select()`, `orderBy()`, `groupBy()`, `having()` methods
  - Only converts references to tables not in current query's FROM clause
  - Supports table aliases (e.g., `u.id` where `u` is an alias)
  - Pattern detection: `table.column` or `alias.column`
  - Invalid patterns (like `123.invalid`) are not converted
- **Db::ref() helper**: Manual external reference helper (though now mostly unnecessary due to automatic detection)
  - Equivalent to `Db::raw('table.column')` but more semantic
  - Still useful for complex expressions or when automatic detection doesn't work
- **Comprehensive test coverage** for external reference detection across all dialects:
  - 13 new tests in each dialect-specific test file (MySQL, PostgreSQL, SQLite)
  - Tests cover `whereExists`, `whereNotExists`, `select`, `orderBy`, `groupBy`, `having`
  - Tests verify internal references are not converted
  - Tests verify aliased table references work correctly
  - Tests verify complex external references and edge cases

### Changed
- **Enhanced QueryBuilder methods**: `addCondition()`, `select()`, `orderBy()`, `groupBy()` now automatically process external references
- **Improved subquery examples**: Updated README.md examples to demonstrate automatic detection
- **Better developer experience**: No more need for `Db::raw('users.id')` in most subquery scenarios

### Fixed
- **QueryBuilder external reference handling**: Fixed parsing of table names and aliases from JOIN clauses
- **Cross-dialect compatibility**: External reference detection works consistently across MySQL, PostgreSQL, and SQLite

### Technical Details
- **All tests passing**: 429+ tests, 2044+ assertions
- **PHPStan Level 8**: Zero errors across entire codebase
- **All examples passing**: 24/24 examples on all database dialects
- **Backward compatibility**: Fully maintained - existing code continues to work unchanged
- **Performance**: Minimal overhead - only processes string values matching `table.column` pattern

---

## [2.6.0] - 2025-10-23

### ⚠️ Breaking Changes
- **Exception handling system**: `loadCsv()` and `loadXml()` now throw specialized exceptions instead of returning `false`
  - **Impact**: Code that checks `if (!$ok)` for these methods will need to be updated to use try-catch blocks
  - **Migration**: Wrap calls in try-catch blocks and handle `DatabaseException` types
  - **Example**:
    ```php
    // Before (2.5.x)
    $ok = $db->find()->table('users')->loadCsv($file);
    if (!$ok) {
        // handle error
    }

    // After (2.6.0)
    try {
        $db->find()->table('users')->loadCsv($file);
    } catch (DatabaseException $e) {
        // handle specific exception type
    }
    ```

### Added
- **Comprehensive exception hierarchy** for better error handling:
  - `DatabaseException` - Base exception class extending `PDOException`
  - `ConnectionException` - Connection-related errors (timeouts, refused connections)
  - `QueryException` - General query execution errors
  - `ConstraintViolationException` - Constraint violations (unique keys, foreign keys)
    - Additional properties: `constraintName`, `tableName`, `columnName`
  - `TransactionException` - Transaction-related errors (deadlocks, rollbacks)
  - `AuthenticationException` - Authentication failures
  - `TimeoutException` - Query or connection timeouts
    - Additional property: `timeoutSeconds`
  - `ResourceException` - Resource exhaustion (too many connections)
    - Additional property: `resourceType`
- **ExceptionFactory** for intelligent exception classification:
  - Analyzes PDO error codes and messages to determine appropriate exception type
  - Supports MySQL, PostgreSQL, and SQLite error codes
  - Extracts detailed context (constraint names, table names, etc.)
- **Enhanced error context** in all exceptions:
  - `getDriver()` - Database driver name
  - `getOriginalCode()` - Original error code (handles PostgreSQL SQLSTATE strings)
  - `getQuery()` - SQL query that caused the error
  - `getContext()` - Additional context information
  - `getCategory()` - Error category for logging/monitoring
  - `isRetryable()` - Whether the error is retryable
  - `toArray()` - Convert to array for logging
- **Exception handling examples** (`examples/09-exception-handling/`):
  - Comprehensive examples showing how to handle different exception types
  - Retry logic patterns with exception-aware logic
  - Error monitoring and alerting examples
  - Real-world error handling scenarios
- **test-examples script** added to composer check:
  - `composer pdodb:check` now runs: PHPStan + PHPUnit + Examples
  - Ensures all examples work on all database dialects
  - 24/24 examples passing on SQLite, MySQL, PostgreSQL

### Changed
- **loadCsv() and loadXml() behavior**: Now throw exceptions instead of returning `false`
  - Provides more detailed error information
  - Enables specific error handling based on exception type
  - Maintains backward compatibility through exception inheritance from `PDOException`
- **Error handling in Connection class**: All database operations now throw specialized exceptions
- **Error handling in RetryableConnection**: Retry logic now works with exception types
- **Error handling in QueryBuilder**: Query execution errors now throw appropriate exceptions

### Fixed
- **PHPStan compliance**: Added PHPDoc generic array types (`array<string, mixed>`) for better static analysis
- **PostgreSQL COPY permission errors**: Now correctly classified as `AuthenticationException`
- **Test compatibility**: Updated all loadCsv/loadXml tests to handle new exception behavior
- **Exception parsing**: Improved constraint name extraction from error messages
- **Error code handling**: Proper handling of PostgreSQL SQLSTATE string codes vs integer codes

### Technical Details
- **All tests passing**: 429 tests, 2044 assertions
- **PHPStan Level 8**: Zero errors across entire codebase
- **All examples passing**: 24/24 examples on all database dialects
- **Exception coverage**: Comprehensive error handling for all database operations
- **Backward compatibility**: Exceptions extend `PDOException` for compatibility with existing catch blocks

---

## [2.5.1] - 2025-10-22

### Added
- **Db::inc() and Db::dec() support in onDuplicate()**: Now you can use convenient helpers for UPSERT increments
  - Works across all dialects: MySQL, PostgreSQL, SQLite
  - Example: `->onDuplicate(['counter' => Db::inc(5)])`
  - Automatically generates dialect-specific SQL (e.g., `counter = counter + 5`)
- **CI testing for examples**: All 21 examples now tested in GitHub Actions
  - SQLite: 21/21 examples verified
  - MySQL: 20/20 examples verified (if database available)
  - PostgreSQL: 20/20 examples verified (if database available)
- **New tests** for UPSERT functionality:
  - `testUpsertWithRawIncrement()` - tests `Db::raw('age + 5')` in onDuplicate
  - `testUpsertWithIncHelper()` - tests `Db::inc(5)` in onDuplicate
  - `testGroupByWithQualifiedNames()` - verifies qualified column names in GROUP BY

### Changed
- **All examples migrated to QueryBuilder API**:
  - Replaced 15+ `rawQuery()` calls with fluent QueryBuilder methods
  - `rawQuery()` now used ONLY for DDL (CREATE/ALTER/DROP TABLE)
  - Examples demonstrate best practices with `Db::` helpers
- **Enhanced buildUpsertClause() signature** (backwards compatible):
  - Added optional `$tableName` parameter for PostgreSQL ambiguous column resolution
  - Enables proper `Db::inc()` and `Db::raw()` support in UPSERT operations
- **Improved test-examples.sh script**:
  - Fixed PostgreSQL port detection in connection checks
  - Now correctly detects all 3 database dialects when available
- **JSON column handling**: Examples automatically use JSONB for PostgreSQL, TEXT for MySQL/SQLite

### Fixed
- **CRITICAL: groupBy() and orderBy() with qualified column names** (`u.id`, `t.name`):
  - **Bug**: Qualified names quoted as single identifier `` `u.id` `` instead of `` `u`.`id` ``
  - **Impact**: Broke on MySQL/PostgreSQL with "Unknown column 'u.id'" error
  - **Fix**: Changed to use `quoteQualifiedIdentifier()` instead of `quoteIdentifier()`
  - **Tests**: Added `testGroupByWithQualifiedNames()` for all 3 dialects
- **CRITICAL: Db::inc()/Db::dec() ignored in onDuplicate()**:
  - **Bug**: Dialects didn't handle `['__op' => 'inc']` array format in UPSERT
  - **Impact**: UPSERT replaced value instead of incrementing
  - **Fix**: Added `is_array($expr) && isset($expr['__op'])` handling in all dialects
- **PostgreSQL UPSERT "ambiguous column" errors**:
  - **Bug**: `Db::raw('age + 5')` in onDuplicate caused "column reference ambiguous"
  - **Impact**: PostgreSQL couldn't distinguish old vs new column values
  - **Fix**: Auto-qualify column references with table name (`user_stats."age" + 5`)
- **PostgreSQL lastInsertId() exception for non-SERIAL tables**:
  - **Bug**: Crash when inserting into tables without auto-increment
  - **Fix**: Added try-catch in `executeInsert()` to gracefully handle missing sequence
- **String literal quoting in SQL expressions**:
  - Fixed PostgreSQL compatibility (uses `'string'` not `"string"`)
  - Fixed CASE WHEN conditions to use single quotes for string literals
  - Examples now properly escape string values in Db::raw() expressions
- **Examples multi-dialect compatibility**:
  - JSON columns: Use JSONB for PostgreSQL, TEXT for MySQL/SQLite
  - String concatenation: Dialect-aware (`||` vs `CONCAT()`)
  - Date/time functions: Dialect-specific interval syntax
  - All 21 examples verified on all 3 dialects

### Technical Details
- **All tests passing**: 343 tests, 1544 assertions (up from 334 tests)
  - Added 9 new tests across MySQL, PostgreSQL, SQLite
  - Zero failures, zero errors, zero warnings
- **All examples passing**: 61/61 total runs (21 examples × ~3 dialects each)
  - SQLite: 21/21 ✅
  - MySQL: 20/20 ✅ (01-connection.php uses SQLite only)
  - PostgreSQL: 20/20 ✅
- **PHPStan Level 8**: Zero errors across entire codebase
- **Full backwards compatibility**: Optional parameter with default value

---

## [2.5.0] - 2025-10-19

### Added
- **Connection pooling**: Support for initialization without default connection via `new PdoDb()`
- **17 new SQL helper functions** with full dialect support:
  - NULL handling: `Db::ifNull()`, `Db::coalesce()`, `Db::nullIf()`
  - Math operations: `Db::abs()`, `Db::round()`, `Db::mod()`, `Db::greatest()`, `Db::least()`
  - String operations: `Db::upper()`, `Db::lower()`, `Db::trim()`, `Db::length()`, `Db::substring()`, `Db::replace()`
  - Date/Time extraction: `Db::curDate()`, `Db::curTime()`, `Db::date()`, `Db::time()`, `Db::year()`, `Db::month()`, `Db::day()`, `Db::hour()`, `Db::minute()`, `Db::second()`
- **Complete JSON operations API**:
  - `Db::jsonGet()`, `Db::jsonLength()`, `Db::jsonKeys()`, `Db::jsonType()`
  - Unified API across MySQL, PostgreSQL, and SQLite
  - Edge-case testing for JSON operations
- **Comprehensive examples directory** (`examples/`) with 21 runnable examples:
  - Basic operations (connection, CRUD, WHERE conditions)
  - Intermediate patterns (JOINs, aggregations, pagination, transactions)
  - Advanced features (connection pooling, bulk operations, UPSERT)
  - JSON operations (complete guide with real-world usage)
  - Helper functions (string, math, date/time, NULL handling)
  - Real-world applications:
    - Blog system with posts, comments, tags, analytics
    - User authentication with sessions, RBAC, password hashing
    - Advanced search & filters with facets, sorting, pagination
    - Multi-tenant SaaS with resource tracking and quota management
- **Dialect coverage tests** for better test coverage (300 total tests):
  - `buildLoadCsvSql()` - CSV loading SQL generation with temp file handling
  - `buildLoadXML()` - XML loading SQL generation with temp file handling
  - `formatSelectOptions()` - SELECT statement options formatting
  - `buildExplainSql()` - EXPLAIN query generation and variations
  - Exception tests for unsupported operations (SQLite lock/unlock)
- **Utility scripts** in `scripts/` directory:
  - `release.sh` - Release preparation and tagging automation
  - `test-examples.sh` - Example verification and syntax checking
- **Comprehensive edge-case test coverage** for new helpers and dialect-specific behaviors
- **44 new tests in SharedCoverageTest** for dialect-independent code coverage
- **Professional README documentation** (1400+ lines) with:
  - Table of contents with navigation
  - Error handling examples
  - Performance tips
  - Debugging guide
  - Troubleshooting section
  - PHP 8.4+ requirement clearly documented
  - Examples directory section
- **Complete CHANGELOG.md** documenting all changes from v1.0.3 to present

### Changed
- **Optimized QueryBuilder**: Refactored duplicated code with new helper methods (`addParam()`, `normalizeOperator()`, `processValueForSql()`)
- **Improved error messages**: Property hooks now provide clearer guidance for uninitialized connections
- **Updated .gitignore**: Cleaned up and added examples-related ignores, coverage reports
- **README.md improvements**: Removed `ALL_TESTS=1` requirement - tests now run without environment variables
- **Enhanced examples** (14 files updated): Maximized use of `Db::` helpers over `Db::raw()` for better code clarity:
  - Replaced 30+ raw SQL expressions with helper functions
  - `Db::inc()`/`Db::dec()` for increments/decrements
  - `Db::count()`, `Db::sum()`, `Db::avg()`, `Db::coalesce()` for aggregations
  - `Db::case()` for conditional logic
  - `Db::concat()` with automatic string literal quoting
- **Improved test organization**: Added `setUp()` method in `SharedCoverageTest` for automatic table cleanup before each test
  - Removed 26+ redundant cleanup statements
  - Better test isolation and reliability

### Removed
- **Deprecated helper methods from PdoDb** (~130 lines removed):
  - `inc()`, `dec()`, `not()` - Use `Db::` equivalents or raw SQL
  - `escape()` - Use prepared statements (PDO handles escaping)
  - `tableExists()` - Use `QueryBuilder::tableExists()` instead
  - `now()` - Use `Db::now()` instead
  - `loadData()`, `loadXml()` - Use `QueryBuilder::loadCsv()`, `QueryBuilder::loadXml()` instead

### Fixed
- **CRITICAL: insertMulti() conflict target detection bug**: Fixed automatic conflict target determination for PostgreSQL/SQLite ON CONFLICT
  - `buildInsertMultiSql()` now correctly uses first column when `id` not present (matches `insert()` behavior)
  - Enables proper bulk UPSERT operations across all dialects
  - Without this fix, bulk inserts with `onDuplicate` parameter would fail on PostgreSQL/SQLite
- **CRITICAL: Db::concat() helper bugs** (2 major issues fixed):
  - **Bug #1**: `ConcatValue` not initializing parent `RawValue` class causing "Typed property not initialized" error
    - Added `parent::__construct('')` call in `ConcatValue` constructor
    - Added protective `getValue()` override with clear error message to prevent misuse
  - **Bug #2**: String literals (spaces, special chars) not auto-quoted, treated as column names
    - Enhanced `DialectAbstract::concat()` logic to auto-detect and quote string literals
    - Supports spaces, colons, pipes, dashes, emoji, and unicode characters
    - Examples: `Db::concat('first_name', ' ', 'last_name')` now works without `Db::raw()`
  - Added 8 comprehensive edge-case tests in `SharedCoverageTest`:
    - `testConcatWithStringLiterals()` - spaces and simple literals
    - `testConcatWithSpecialCharacters()` - colon, pipe, dash
    - `testConcatWithNestedHelpers()` - `Db::upper/lower` inside concat
    - `testConcatNestedInHelperThrowsException()` - protection from incorrect usage
    - `testConcatWithQuotedLiterals()` - already-quoted strings
    - `testConcatWithNumericValues()` - number handling
    - `testConcatWithEmptyString()` - empty string edge case
    - `testConcatWithMixedTypes()` - mixed type concatenation
- Restored `RawValue` union type support in `rawQuery()`, `rawQueryOne()`, `rawQueryValue()` methods
- Corrected method calls in `lock()`, `unlock()`, `loadData()`, `loadXml()` to use `prepare()->execute()` pattern
- SQLite JSON support fixes for edge cases (array indexing, value encoding, numeric sorting)
- **MySQL EXPLAIN compatibility**: Reverted EXPLAIN ANALYZE support to maintain table format compatibility
  - MySQL 8.0+ EXPLAIN ANALYZE returns tree/JSON format incompatible with traditional table format
  - Existing tests expect table format with `select_type` column
- **PostgreSQL formatSelectOptions test**: Fixed to test actual supported features (FOR UPDATE/FOR SHARE)

### Technical Details
- **All tests passing**: 334 tests, 1499 assertions across MySQL, PostgreSQL, and SQLite (3 skipped for live testing)
  - **68 tests** in SharedCoverageTest (dialect-independent code)
  - **8 new edge-case tests** for `Db::concat()` bug fixes
  - Added `setUp()` method for automatic table cleanup before each test
- **Test coverage**: 90%+ with comprehensive dialect-specific and edge-case testing
- **Full backward compatibility maintained**: Zero breaking changes (deprecated methods removal is non-breaking)
- Examples tested and verified on PHP 8.4.13
- **Performance**: Optimized QueryBuilder reduces code duplication and improves maintainability

---

## [2.4.3] - 2025-10-18

### Added
- `Db::concat()`, `Db::true()`, `Db::false()` helper functions
- `QueryBuilder::notExists()` method
- `Db::between()`, `Db::notBetween()`, `Db::in()`, `Db::notIn()` helpers
- `Db::isNull()`, `Db::isNotNull()` helpers
- `Db::case()` helper for CASE statements
- `Db::default()` helper
- `Db::null()`, `Db::like()`, `Db::ilike()`, `Db::not()` helpers
- `Db::config()` helper for SET statements
- `Db::escape()` method for string escaping
- `QueryBuilder::tableExists()` method
- `QueryBuilder::loadXml()` and `QueryBuilder::loadCsv()` methods moved from PdoDb
- JSON methods: `selectJson()`, `whereJsonPath()`, `whereJsonContains()`, `jsonSet()`, `jsonRemove()`, `orderByJson()`, `whereJsonExists()`
- `Db::ts()` helper for Unix timestamps
- `QueryBuilderInterface` and `DialectInterface` for better type safety
- Comprehensive LIMIT/OFFSET tests

### Changed
- Code refactoring for better maintainability
- Improved README.md documentation
- Updated composer.json description and keywords

### Fixed
- Removed unused 'json' keyword from composer.json

---

## [2.4.2] - 2025-10-17

### Added
- RawValue parameters binding support
- Normalized parameter binding and condition tokens
- Robust `addCondition()` and `update()` handling

### Changed
- Moved DB helpers (`inc()`, `now()`, `dec()`) to `helpers\Db.php`
- Split `execute()` method into `prepare()` and `execute()` in Connection class
- Pass PDO to dialect on dialect initialization
- Code refactoring for better structure

### Fixed
- Test fix: Exception handling differences between MySQL, SQLite, and PostgreSQL
- MySQL and SQLite throw exceptions on `prepare()`, PostgreSQL on `execute()`

---

## [2.4.1] - 2025-10-15

### Added
- CSV and XML fallback loaders to `DialectAbstract`
- Structured logging via `LoggerInterface`

### Changed
- Centralized all PDO operations in Connection class
- Refactoring for better code organization
- Updated README.md with new information

### Fixed
- `truncate()` method fix for SQLite

---

## [2.4.0] - 2025-10-13

### Added
- Unified QueryBuilder and cross-dialect SQL generation
- SQLite fixes and loaders
- GitHub Actions tests workflow

### Changed
- Major refactoring of QueryBuilder
- Improved cross-dialect compatibility

### Fixed
- Skip loadData/loadXML tests in GitHub Actions due to permission issues

---

## [2.3.0] - 2025-10-12

### Added
- `EXPLAIN` and `EXPLAIN ANALYZE` methods
- `DESCRIBE` method for table introspection
- `getColumn()` method for fetching single column values
- `linesToIgnore` parameter for `loadXml()` method

### Changed
- Dialects and PdoDb refactoring
- Updated README.md with new methods
- Updated LICENCE information

### Fixed
- Changed licence type in composer.json to MIT

---

## [2.2.0] - 2025-10-11

### Added
- **PostgreSQL support** 🎉
- Refactored DSN parameters for each supported database
- PHPDoc comments throughout the codebase

### Changed
- Updated README.md with PostgreSQL configuration examples
- Improved database configuration flexibility

---

## [2.1.1] - 2025-10-10

### Added
- Port support in DSN configuration

### Changed
- Updated README.md documentation

### Fixed
- `loadXML()` log trace issue

---

## [2.1.0] - 2025-10-10

### Added
- **SQLite support** 🎉
- Refactored construction parameters for better flexibility

### Changed
- Updated README.md with SQLite configuration examples
- Load data fixes
- Added LOCAL parameter support for LOAD DATA

---

## [2.0.0] - 2025-10-10

### ⚠️ Breaking Changes
- **Minimum PHP version raised to 8.4+**
- Complete rewrite using PHP 8.4 features (property hooks, etc.)

### Changed
- Huge refactoring of entire codebase
- Modern PHP 8.4+ syntax and features
- Removed outdated information from documentation

### Technical Details
This is a major breaking release that modernizes the entire codebase for PHP 8.4+. Migration from 1.x requires PHP 8.4+ and may require code changes.

---

## [1.1.1] - 2021-12-20

### Added
- `getParams()` method to return query parameters
- `useGenerator()` method information in README

### Changed
- Fixed package name in composer.json
- Updated keywords in composer.json

### Fixed
- Implode legacy signature
- README fixes: heading spacing and table of contents
- Various typo fixes in documentation

---

## [1.1.0] - 2016-06-12

### Added
- `setPageLimit()` method for pagination
- Tests for: having, groupBy, getValue with limit, rawQueryOne, rawQueryValue, named placeholders, pagination, subquery on insert
- PDODb usage documentation

### Fixed
- `escape()` method typo fix
- Subquery on `getOne()` fix

---

## [1.0.3] - 2016-05-27

Initial tagged release with basic PDO database abstraction functionality.

---

[Unreleased]: https://github.com/tommyknocker/pdo-database-class/compare/v3.0.0...HEAD
[3.0.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.14.0...v3.0.0
[2.14.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.13.0...v2.14.0
[2.13.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.12.1...v2.13.0
[2.12.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.12.0...v2.12.1
[2.12.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.11.2...v2.12.0
[2.11.2]: https://github.com/tommyknocker/pdo-database-class/compare/v2.11.1...v2.11.2
[2.11.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.11.0...v2.11.1
[2.11.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.10.3...v2.11.0
[2.10.3]: https://github.com/tommyknocker/pdo-database-class/compare/v2.10.2...v2.10.3
[2.10.2]: https://github.com/tommyknocker/pdo-database-class/compare/v2.10.1...v2.10.2
[2.10.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.10.0...v2.10.1
[2.10.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.9.3...v2.10.0
[2.9.3]: https://github.com/tommyknocker/pdo-database-class/compare/v2.9.2...v2.9.3
[2.9.2]: https://github.com/tommyknocker/pdo-database-class/compare/v2.9.1...v2.9.2
[2.9.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.9.0...v2.9.1
[2.9.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.8.0...v2.9.0
[2.8.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.7.1...v2.8.0
[2.7.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.7.0...v2.7.1
[2.7.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.6.2...v2.7.0
[2.6.2]: https://github.com/tommyknocker/pdo-database-class/compare/v2.6.1...v2.6.2
[2.6.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.6.0...v2.6.1
[2.6.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.5.1...v2.6.0
[2.5.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.5.0...v2.5.1
[2.5.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.4.3...v2.5.0
[2.4.3]: https://github.com/tommyknocker/pdo-database-class/compare/v2.4.2...v2.4.3
[2.4.2]: https://github.com/tommyknocker/pdo-database-class/compare/v2.4.1...v2.4.2
[2.4.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.4.0...v2.4.1
[2.4.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.3.0...v2.4.0
[2.3.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.1.1...v2.2.0
[2.1.1]: https://github.com/tommyknocker/pdo-database-class/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/tommyknocker/pdo-database-class/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/tommyknocker/pdo-database-class/compare/v1.1.1...v2.0.0
[1.1.1]: https://github.com/tommyknocker/pdo-database-class/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/tommyknocker/pdo-database-class/compare/v1.0.3...v1.1.0

