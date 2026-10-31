# ActiveRecord Examples

Examples demonstrating the ActiveRecord pattern: model definitions, relationships, and query scopes.

## Examples

### 01-basics.php
ActiveRecord basics: model definition and CRUD operations.

**Topics covered:**
- Model definition extending Model base class
- CRUD operations (create, read, update, delete)
- Validation with rules-based validation
- ActiveQuery - Full QueryBuilder API through find()
- Lifecycle events (PSR-14 events for model operations)

### 02-relationships.php
ActiveRecord relationships: hasOne, hasMany, belongsTo, and hasManyThrough.

**Topics covered:**
- **hasOne** - One-to-one relationships
- **hasMany** - One-to-many relationships
- **belongsTo** - Inverse relationships
- **hasManyThrough** - Many-to-many via junction table
- **Lazy Loading** - Load relationships on access
- **Eager Loading** - Prevent N+1 queries

### 03-scopes.php
Query scopes: global and local scopes for reusable query logic.

**Topics covered:**
- **Global Scopes** - Automatically applied to all queries (soft deletes, multi-tenant)
- **Local Scopes** - Applied on-demand (published, popular, recent)
- **Scope Chaining** - Combine multiple scopes
- **Scope Parameters** - Parameterized scopes for flexible filtering
- **Disable Global Scopes** - Temporarily bypass global scopes when needed
- **QueryBuilder Scopes** - Use scopes directly with QueryBuilder (without models)

### 04-api-contract.php
A SQLite-backed order API flow: an optional `tommyknocker/struct` request DTO,
transactional update, eager-loaded model pagination, and an explicit response
DTO. Install the optional package with `composer require tommyknocker/struct` to
run it. The example uses only an in-memory database.

### 05-diagnose-relations.php
Compare lazy and eager relationship loading, then print local EXPLAIN output and an AI context preview. The script uses SQLite and does not call an AI provider.

### 06-model-services.php
Behaviors, transactional save, upsert helpers, soft delete, batch model reads, many-to-many writes, outbox, and inbox.

**Topics covered:**
- Timestamps, soft deletes, and ULID keys
- `firstOrCreate` / `updateOrCreate` and atomic `increment`
- `afterCommit` and rollback of the pre-save model snapshot
- `each()` yielding models, hidden attributes, and a typed property hook
- `HasManyThrough::sync()` / `detach()`
- `Inbox::once()` together with `Outbox::add()`

## Usage

```bash
# Run examples
php 01-basics.php
php 02-relationships.php
php 03-scopes.php
php 04-api-contract.php
php 05-diagnose-relations.php
php 06-model-services.php
```

## ActiveRecord Features

- **Model Definition** - Extend Model base class for object-based database operations
- **Relationships** - Define relationships between models
- **Scopes** - Reusable query logic
- **Validation** - Rules-based validation
- **Events** - Lifecycle events for model operations

## Related Examples

- [Query Builder](../01-basic/) - Basic QueryBuilder operations
- [Extensibility](../10-extensibility/) - Extending QueryBuilder with macros and plugins

## Diagnosing relationship queries

Run `php examples/09-active-record/05-diagnose-relations.php` for a self-contained SQLite comparison of lazy and eager loading, followed by local EXPLAIN and an AI context preview. No AI provider or network call is needed. See [relationship diagnostics](../../documentation/05-advanced-features/16-active-record.md#diagnosing-relationship-queries).
