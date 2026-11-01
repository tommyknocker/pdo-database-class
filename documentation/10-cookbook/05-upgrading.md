# Upgrading from 2.14

These notes describe changes planned for the next major version. Review the
sections that apply to your application before updating.

## ActiveRecord connections

Models now have independent connection overrides. For a connection shared by
all models, set the default once:

```php
use tommyknocker\pdodb\orm\Model;

Model::setDb($db);
```

Use `User::setDb($otherDb)` to override the connection for `User` and its
subclasses without changing unrelated models. `User::setDb(null)` explicitly
disables its connection, even if there is a shared default. Previously, setting
one model's inherited connection could affect other models.

## Scopes and writes

Global scopes now apply to ActiveQuery terminal operations and record updates
and deletes. Terminal methods such as `exists()` and `delete()` return their
actual results. Queries deliberately using raw SQL or `getQueryBuilder()` remain
outside these protections. A scope is not an authorization boundary.

Changing a loaded model's primary key and then saving or deleting it throws
`PrimaryKeyMutationException`.

## Mass assignment

`setAttributes($values)` now sets only safe attributes by default (`$safeOnly`
is `true`). The safe attributes are those returned by `safeAttributes()` if the
model defines it, otherwise those mentioned in `rules()`, minus
`unsafeAttributes()`. Other keys are ignored, and a model with neither
`rules()` nor `safeAttributes()` throws `MassAssignmentException`.

If your code passed trusted data to `setAttributes()`, switch to `forceFill()`
(or `setAttribute()`), or pass `false` as the second argument. Models whose
rules do not mention a field you assign from request data need that field in
`rules()` or `safeAttributes()`.

## Nested transaction callbacks

An inner `transaction()` uses a savepoint and does not commit an existing outer
transaction. An inner failure rolls back to the savepoint when the database can
recover; an exception escaping the outer callback rolls back the outer work.
Some database errors or DDL may invalidate the transaction or commit implicitly.

## AI configuration

Provider-specific environment keys and nested provider settings have been
replaced by settings for the selected provider. For example, replace
`PDODB_AI_OPENAI_KEY` with `PDODB_AI_API_KEY` and use `PDODB_AI_MODEL` for the
model. See [AI configuration and migration](../05-advanced-features/26-ai-reference.md#migrating-existing-ai-settings).

## Custom dialects and dry runs

Custom `DialectInterface` implementations must implement `executePlanExplain()`
and `resolvePlanExplainAvailability()` (or extend `DialectAbstract` defaults).
Migration and seed dry runs no longer execute arbitrary PHP or display SQL
produced by executing it. See [dry-run behavior](../05-advanced-features/15-migrations.md#dry-run).
