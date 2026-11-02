# Upgrading to 3.0

PDOdb 3.0 needs PHP 8.4 or newer, the same as 2.14. The full guide, with the reason for each change and what to do about
it, is [Upgrading from 2.x to 3.0](documentation/10-cookbook/05-upgrading.md). This page is the checklist.

## Check these first: results can change without an error

- [ ] **Mass assignment.** `setAttributes()` sets only safe attributes (`safeAttributes()`, else the fields in `rules()`).
      Use `forceFill()` for trusted data.
- [ ] **`firstOrCreate()` / `updateOrCreate()`** validate and run behaviors; on a validation failure they return an unsaved
      model.
- [ ] **Query-result cache** is dropped after a write to the table (`cache.auto_invalidate`).
- [ ] **`Db::concat()`**: only a bare identifier is a column name; other strings are text literals.
- [ ] **`->unique()` on a column** now creates the constraint on every database (it was ignored except on Oracle).
- [ ] **`Db::jsonLength()`** counts object keys; **`Db::jsonKeys()`** returns real keys.
- [ ] **Timestamp and soft-delete behaviors** write the database clock.
- [ ] **ActiveRecord**: per-class connections (`Model::setDb()` for a shared one), global scopes apply to writes,
      `ActiveQuery::each()` yields models, `Model::tableName()` pluralizes with `Inflector`.

## Now throws instead of working

- [ ] Condition operators outside the allowlist, unknown validator names, a shard key that cannot be found, a query routed
      to a connection with another dialect, unsafe characters in connection parameters or `now()` intervals.
- [ ] `paginate()` and friends no longer read `$_GET`; pass the page or cursor.
- [ ] SQL Server before 2025: regular expressions that `LIKE` cannot express. SQL Server 2025 uses native regular
      expressions.

## Behavior changes

- [ ] Nested `transaction()` uses savepoints and never commits the outer transaction.
- [ ] Migrations on MySQL, MariaDB and Oracle no longer run in a transaction; `safeUp()` / `safeDown()` are replaced by
      `Migration::transactional()`; `--pretend` is gone (use `--dry-run`).
- [ ] `RetryableConnection` retries only reads (`retry.retry_writes` to change).
- [ ] MySQL `LOCAL INFILE` is off unless you pass `PDO\Mysql::ATTR_LOCAL_INFILE`.
- [ ] CLI: unknown options exit with 2, failures print `Error: ...` and exit with 1 (`PDODB_DEBUG=1` for traces).
- [ ] AI settings: one selected provider (`PDODB_AI_API_KEY`, `PDODB_AI_MODEL`).

## Database

- [ ] **Recreate outbox tables** created by 2.x (native datetime types, database clock). Inbox `processed_at` is a native
      datetime column.
- [ ] `OutboxStorage::claimBatch()` returns an `OutboxClaim`.

## If you implement `DialectInterface` or extend the dashboard

- [ ] New required methods, removed outbox claim methods, and `OnConflictUpsertTrait` for `ON CONFLICT` upserts: see
      [Custom dialects](documentation/10-cookbook/05-upgrading.md#custom-dialects).
- [ ] Subclasses of the TUI `Dashboard` target `SqlScratchpad` and `DashboardViews`.

Every change is listed in the [CHANGELOG](CHANGELOG.md) under 3.0.0 (entries marked **Breaking**).
