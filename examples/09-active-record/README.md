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

## Usage

```bash
# Run examples
php 01-basics.php
php 02-relationships.php
php 03-scopes.php
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
