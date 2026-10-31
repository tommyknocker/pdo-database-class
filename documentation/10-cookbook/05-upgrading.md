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

`setAttributes($values, safeOnly: true)` requires the model to define
`safeAttributes()`; otherwise it throws `MassAssignmentException`. Changing a
loaded model's primary key and then saving or deleting it throws
`PrimaryKeyMutationException`.

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

Custom `DialectInterface` implementations must implement `executePlanExplain()`.
Migration and seed dry runs no longer execute arbitrary PHP or display SQL
produced by executing it. See [dry-run behavior](../05-advanced-features/15-migrations.md#dry-run).
