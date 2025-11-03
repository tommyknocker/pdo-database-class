# Query Scopes Examples

This directory contains examples demonstrating the Query Scopes feature.

## What are Query Scopes?

Query Scopes allow you to encapsulate query logic into reusable, named functions that can be applied to queries. There are two types:

### Global Scopes
Automatically applied to all queries for a model. Useful for:
- Soft deletes (filtering deleted records)
- Multi-tenant isolation
- Default filters

### Local Scopes
Applied only when explicitly called. Useful for:
- Common query patterns (e.g., `published()`, `popular()`)
- Parameterized filters (e.g., `recent($days)`)
- Query combinations

## Examples

### Example 1: Basic Local Scopes
```php
$publishedPosts = Post::find()->scope('published')->all();
```

### Example 2: Chaining Scopes
```php
$posts = Post::find()
    ->scope('published')
    ->scope('popular')
    ->limit(10)
    ->all();
```

### Example 3: Scope with Parameters
```php
$recentPosts = Post::find()
    ->scope('recent', 30) // last 30 days
    ->all();
```

### Example 4: Global Scope (Automatic)
```php
// Global scope automatically filters deleted posts
$allPosts = Post::find()->all(); // Only non-deleted posts
```

### Example 5: Disable Global Scope
```php
// Temporarily disable global scope
$allPostsIncludingDeleted = Post::find()
    ->withoutGlobalScope('notDeleted')
    ->all();
```

### Example 6: Scopes at PdoDb Level
```php
// Add scopes to PdoDb (applies to all QueryBuilder instances)
$db->addScope('active', function ($query) {
    return $query->where('is_active', 1);
});

$db->addScope('tenant', function ($query) {
    return $query->where('tenant_id', $_SESSION['tenant_id']);
});

// All queries automatically apply scopes
$items = $db->find()->from('items')->get();

// Temporarily disable a scope
$allItems = $db->find()
    ->from('items')
    ->withoutGlobalScope('active')
    ->get();

// Remove scope completely
$db->removeScope('tenant');
```

## Running Examples

```bash
# SQLite (default)
php examples/28-query-scopes/01-scopes-examples.php

# MySQL
PDODB_DRIVER=mysql php examples/28-query-scopes/01-scopes-examples.php

# PostgreSQL
PDODB_DRIVER=pgsql php examples/28-query-scopes/01-scopes-examples.php

# MariaDB
PDODB_DRIVER=mariadb php examples/28-query-scopes/01-scopes-examples.php
```

## Files

- `01-scopes-examples.php` - Comprehensive examples of scopes usage

