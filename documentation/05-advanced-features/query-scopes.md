# Query Scopes

Query Scopes allow you to encapsulate query logic into reusable, named functions that can be applied to queries. This feature helps reduce code duplication and makes queries more readable and maintainable.

## Table of Contents

- [Overview](#overview)
- [Global Scopes](#global-scopes)
- [Local Scopes](#local-scopes)
- [Using Scopes](#using-scopes)
- [Disabling Global Scopes](#disabling-global-scopes)
- [Examples](#examples)

## Overview

Scopes are reusable query logic that can be applied to queries. There are two types:

1. **Global Scopes**: Automatically applied to all queries for a model
2. **Local Scopes**: Applied only when explicitly called

Scopes are defined in model classes using the `globalScopes()` and `scopes()` methods.

## Global Scopes

Global scopes are automatically applied to all queries for a model. They are useful for:

- **Soft deletes**: Filtering out deleted records
- **Multi-tenant isolation**: Ensuring data is filtered by tenant
- **Default filters**: Applying common filters automatically

### Defining Global Scopes

Global scopes are defined in the `globalScopes()` method:

```php
class Post extends Model
{
    public static function globalScopes(): array
    {
        return [
            'notDeleted' => function ($query) {
                $query->whereRaw('deleted_at IS NULL');
                return $query;
            },
            'active' => function ($query) {
                $query->where('is_active', 1);
                return $query;
            },
        ];
    }
}
```

### How Global Scopes Work

Global scopes are automatically applied when you use `Model::find()`. For example:

```php
// This query automatically includes WHERE deleted_at IS NULL
$posts = Post::find()->all();
```

All queries through the model will have the global scope applied, including:

- `Post::find()->all()`
- `Post::findOne($id)`
- `Post::findAll($condition)`

## Local Scopes

Local scopes are applied only when explicitly called. They are useful for:

- Common query patterns (e.g., `published()`, `popular()`)
- Parameterized filters (e.g., `recent($days)`)
- Query combinations

### Defining Local Scopes

Local scopes are defined in the `scopes()` method:

```php
class Post extends Model
{
    public static function scopes(): array
    {
        return [
            'published' => function ($query) {
                $query->where('status', 'published');
                return $query;
            },
            'popular' => function ($query) {
                $query->where('view_count', 1000, '>');
                return $query;
            },
            'recent' => function ($query, $days = 7) {
                $date = date('Y-m-d H:i:s', strtotime("-$days days"));
                $query->where('created_at', $date, '>=');
                return $query;
            },
            'byAuthor' => function ($query, $authorId) {
                $query->where('author_id', $authorId);
                return $query;
            },
        ];
    }
}
```

## Using Scopes

### Applying Local Scopes

Local scopes are applied using the `scope()` method:

```php
// Apply a single scope
$publishedPosts = Post::find()->scope('published')->all();

// Chain multiple scopes
$posts = Post::find()
    ->scope('published')
    ->scope('popular')
    ->limit(10)
    ->all();

// Use scope with parameters
$recentPosts = Post::find()
    ->scope('recent', 30) // last 30 days
    ->all();
```

### Using Scopes with QueryBuilder

Scopes can also be used directly with QueryBuilder (without models):

```php
$db->find()
    ->from('posts')
    ->scope(function ($query) {
        return $query->where('status', 'published')
                    ->where('view_count', 1000, '>');
    })
    ->limit(5)
    ->get();
```

### Combining Global and Local Scopes

You can combine global and local scopes:

```php
// Global scope (notDeleted) is automatically applied
// Local scope (published) is explicitly applied
$posts = Post::find()
    ->scope('published')
    ->all();
```

## Disabling Global Scopes

Sometimes you need to temporarily disable global scopes. Use `withoutGlobalScope()` or `withoutGlobalScopes()`:

```php
// Disable a single global scope
$allPostsIncludingDeleted = Post::find()
    ->withoutGlobalScope('notDeleted')
    ->all();

// Disable multiple global scopes
$allUsers = User::find()
    ->withoutGlobalScopes(['active', 'verified'])
    ->all();
```

## Examples

### Example 1: Soft Deletes

A common use case for global scopes is implementing soft deletes:

```php
class Post extends Model
{
    public static function globalScopes(): array
    {
        return [
            'notDeleted' => function ($query) {
                $query->whereRaw('deleted_at IS NULL');
                return $query;
            },
        ];
    }
}

// Automatically filters deleted posts
$posts = Post::find()->all();

// Get all posts including deleted
$allPosts = Post::find()
    ->withoutGlobalScope('notDeleted')
    ->all();
```

### Example 2: Multi-Tenant Isolation

Global scopes are perfect for multi-tenant applications:

```php
class Document extends Model
{
    public static function globalScopes(): array
    {
        return [
            'tenant' => function ($query) {
                $tenantId = $_SESSION['tenant_id'] ?? null;
                if ($tenantId) {
                    $query->where('tenant_id', $tenantId);
                }
                return $query;
            },
        ];
    }
}

// All queries automatically filter by tenant_id
$documents = Document::find()->all();
```

### Example 3: Complex Query Patterns

Local scopes help organize complex query logic:

```php
class Post extends Model
{
    public static function scopes(): array
    {
        return [
            'published' => function ($query) {
                $query->where('status', 'published')
                      ->whereRaw('published_at <= NOW()');
                return $query;
            },
            'withTags' => function ($query, array $tagIds) {
                $query->innerJoin('post_tags', 'post_tags.post_id = posts.id')
                      ->where('post_tags.tag_id', $tagIds, 'IN')
                      ->distinct();
                return $query;
            },
            'popular' => function ($query) {
                $query->where('view_count', 1000, '>')
                      ->orderBy('view_count', 'DESC');
                return $query;
            },
        ];
    }
}

// Use multiple scopes together
$posts = Post::find()
    ->scope('published')
    ->scope('popular')
    ->scope('withTags', [1, 5, 10])
    ->limit(20)
    ->all();
```

### Example 4: Parameterized Scopes

Scopes can accept parameters for flexible filtering:

```php
class Post extends Model
{
    public static function scopes(): array
    {
        return [
            'byAuthor' => function ($query, $authorId) {
                $query->where('author_id', $authorId);
                return $query;
            },
            'createdBetween' => function ($query, $startDate, $endDate) {
                $query->where('created_at', $startDate, '>=')
                      ->where('created_at', $endDate, '<=');
                return $query;
            },
        ];
    }
}

// Use scopes with parameters
$authorPosts = Post::find()
    ->scope('byAuthor', 123)
    ->scope('createdBetween', '2025-01-01', '2025-12-31')
    ->all();
```

## Best Practices

1. **Use global scopes sparingly**: Only for logic that should apply to ALL queries (soft deletes, multi-tenant, etc.)
2. **Keep scopes simple**: Each scope should do one thing well
3. **Return the query**: Always return `$query` from scope callables for chaining
4. **Use parameters**: Make scopes flexible with parameters when needed
5. **Document scope behavior**: Comment complex scopes to explain their purpose

## Scopes at PdoDb Level

You can also define scopes directly on the `PdoDb` instance. These scopes will be automatically applied to all `QueryBuilder` instances created via `find()`:

```php
$db = new PdoDb('mysql', $config);

// Add scopes
$db->addScope('active', function ($query) {
    return $query->where('is_active', 1);
});

$db->addScope('tenant', function ($query) {
    $tenantId = $_SESSION['tenant_id'] ?? null;
    if ($tenantId) {
        return $query->where('tenant_id', $tenantId);
    }
    return $query;
});

// All queries automatically apply these scopes
$users = $db->find()->from('users')->get();
$posts = $db->find()->from('posts')->get();

// Temporarily disable a scope
$allUsers = $db->find()
    ->from('users')
    ->withoutGlobalScope('active')
    ->get();

// Remove a scope completely
$db->removeScope('tenant');
```

### Use Cases

- **Multi-tenant isolation**: Ensure all queries filter by tenant automatically
- **Soft deletes**: Filter deleted records globally without Model classes
- **Application-wide filters**: Apply business rules to all queries

## See Also

- [ActiveRecord Pattern](active-record.md)
- [ActiveRecord Relationships](active-record-relationships.md)
- [Query Builder](query-builder.md)
