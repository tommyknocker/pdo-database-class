# Query Builder Macros

Query Builder Macros allow you to extend QueryBuilder with custom methods. This feature enables you to create reusable query logic that can be called as methods on QueryBuilder instances, making your code more expressive and maintainable.

## Table of Contents

- [Overview](#overview)
- [Registering Macros](#registering-macros)
- [Using Macros](#using-macros)
- [Macro Parameters](#macro-parameters)
- [Return Values](#return-values)
- [Examples](#examples)
- [Best Practices](#best-practices)
- [Differences from Scopes](#differences-from-scopes)

## Overview

Macros provide a way to register custom methods that can be called on QueryBuilder instances. They are registered globally and can be used with any QueryBuilder instance, making them ideal for:

- **Custom query methods**: Create domain-specific query methods (e.g., `active()`, `published()`)
- **Reusable query patterns**: Encapsulate common query logic
- **Method chaining**: Macros can return QueryBuilder instances for fluent chaining
- **Flexible returns**: Macros can return QueryBuilder instances or any other value

## Registering Macros

Macros are registered using the static `macro()` method on QueryBuilder:

```php
use tommyknocker\pdodb\query\QueryBuilder;

QueryBuilder::macro('active', function (QueryBuilder $query) {
    return $query->where('status', 'active');
});
```

The macro callable receives the QueryBuilder instance as the first argument, followed by any additional arguments passed when calling the macro.

## Using Macros

Once registered, macros can be called as methods on QueryBuilder instances:

```php
$activeProducts = $db->find()
    ->table('products')
    ->active()
    ->get();
```

Macros can be chained with other QueryBuilder methods:

```php
$result = $db->find()
    ->table('products')
    ->active()
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();
```

## Checking if Macro Exists

You can check if a macro is registered using `hasMacro()`:

```php
if (QueryBuilder::hasMacro('active')) {
    // Macro exists
}
```

## Macro Parameters

Macros can accept parameters, just like regular methods:

```php
QueryBuilder::macro('wherePrice', function (QueryBuilder $query, string $operator, float $price) {
    return $query->where('price', $price, $operator);
});

// Use the macro with parameters
$expensive = $db->find()
    ->table('products')
    ->wherePrice('>', 100.00)
    ->get();
```

### Default Parameters

Macros support default parameters:

```php
QueryBuilder::macro('recent', function (QueryBuilder $query, int $days = 7) {
    $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    return $query->where('created_at', $date, '>=');
});

// Use default (7 days)
$recent = $db->find()->table('products')->recent()->get();

// Use custom parameter
$veryRecent = $db->find()->table('products')->recent(1)->get();
```

## Return Values

Macros can return different types of values:

### Returning QueryBuilder (for Chaining)

When a macro returns a QueryBuilder instance, it can be chained with other methods:

```php
QueryBuilder::macro('active', function (QueryBuilder $query) {
    return $query->where('status', 'active');
});

$result = $db->find()
    ->table('products')
    ->active()
    ->orderBy('name')
    ->get();
```

### Returning Other Values

Macros can return any value:

```php
QueryBuilder::macro('countActive', function (QueryBuilder $query) {
    return $query->where('status', 'active')->getValue('COUNT(*)');
});

$count = $db->find()->table('products')->countActive();
```

## Examples

### Example 1: Simple Status Filter

```php
QueryBuilder::macro('active', function (QueryBuilder $query) {
    return $query->where('status', 'active');
});

QueryBuilder::macro('inactive', function (QueryBuilder $query) {
    return $query->where('status', 'inactive');
});

$activeProducts = $db->find()->table('products')->active()->get();
$inactiveProducts = $db->find()->table('products')->inactive()->get();
```

### Example 2: Parameterized Macros

```php
QueryBuilder::macro('wherePrice', function (QueryBuilder $query, string $operator, float $price) {
    return $query->where('price', $price, $operator);
});

QueryBuilder::macro('inCategory', function (QueryBuilder $query, int $categoryId) {
    return $query->where('category_id', $categoryId);
});

$result = $db->find()
    ->table('products')
    ->wherePrice('>', 100.00)
    ->inCategory(1)
    ->get();
```

### Example 3: Complex Macro

```php
QueryBuilder::macro('available', function (QueryBuilder $query) {
    return $query
        ->where('status', 'active')
        ->andWhere('price', 0, '>')
        ->andWhereNotNull('stock');
});

$availableProducts = $db->find()->table('products')->available()->get();
```

### Example 4: Date-Based Macros

```php
QueryBuilder::macro('recent', function (QueryBuilder $query, int $days = 7) {
    $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    return $query->where('created_at', $date, '>=');
});

QueryBuilder::macro('thisMonth', function (QueryBuilder $query) {
    $startOfMonth = date('Y-m-01 00:00:00');
    $endOfMonth = date('Y-m-t 23:59:59');
    return $query
        ->where('created_at', $startOfMonth, '>=')
        ->andWhere('created_at', $endOfMonth, '<=');
});

$recentProducts = $db->find()->table('products')->recent(30)->get();
$monthProducts = $db->find()->table('products')->thisMonth()->get();
```

### Example 5: Macro Returning Count

```php
QueryBuilder::macro('countActive', function (QueryBuilder $query) {
    return $query->where('status', 'active')->getValue('COUNT(*)');
});

$activeCount = $db->find()->table('products')->countActive();
```

### Example 6: Chaining Multiple Macros

```php
QueryBuilder::macro('active', function (QueryBuilder $query) {
    return $query->where('status', 'active');
});

QueryBuilder::macro('inStock', function (QueryBuilder $query) {
    return $query->where('stock', 0, '>');
});

QueryBuilder::macro('featured', function (QueryBuilder $query) {
    return $query->where('featured', 1);
});

$result = $db->find()
    ->table('products')
    ->active()
    ->inStock()
    ->featured()
    ->orderBy('price', 'ASC')
    ->limit(10)
    ->get();
```

## Error Handling

If you call a non-existent macro, a `RuntimeException` is thrown:

```php
try {
    $db->find()->table('products')->nonExistentMacro();
} catch (\RuntimeException $e) {
    echo "Macro not found: " . $e->getMessage();
}
```

## Best Practices

1. **Use descriptive names**: Choose macro names that clearly indicate their purpose
   ```php
   // Good
   QueryBuilder::macro('active', ...);
   QueryBuilder::macro('published', ...);
   
   // Avoid
   QueryBuilder::macro('filter1', ...);
   QueryBuilder::macro('q', ...);
   ```

2. **Return QueryBuilder for chaining**: When creating query-building macros, return the QueryBuilder instance
   ```php
   QueryBuilder::macro('active', function (QueryBuilder $query) {
       return $query->where('status', 'active'); // Return $query
   });
   ```

3. **Keep macros focused**: Each macro should do one thing well
   ```php
   // Good - focused
   QueryBuilder::macro('active', function (QueryBuilder $query) {
       return $query->where('status', 'active');
   });
   
   // Avoid - too many responsibilities
   QueryBuilder::macro('activeAndFeaturedAndInStock', function (QueryBuilder $query) {
       // Too many things
   });
   ```

4. **Document parameters**: If macros accept parameters, document their purpose
   ```php
   /**
    * Filter products by price range.
    *
    * @param QueryBuilder $query
    * @param string $operator Comparison operator (>, <, >=, <=, =)
    * @param float $price Price threshold
    */
   QueryBuilder::macro('wherePrice', function (QueryBuilder $query, string $operator, float $price) {
       return $query->where('price', $price, $operator);
   });
   ```

5. **Use default parameters**: Make macros flexible with default values when appropriate
   ```php
   QueryBuilder::macro('recent', function (QueryBuilder $query, int $days = 7) {
       // Default to 7 days if not specified
   });
   ```

6. **Register macros early**: Register macros during application bootstrap or in service providers

## Differences from Scopes

Macros and scopes serve similar purposes but have different use cases:

### Macros
- **Global registration**: Registered once, available everywhere
- **Method-like syntax**: Called as methods on QueryBuilder
- **No model dependency**: Work with any QueryBuilder instance
- **No automatic application**: Must be called explicitly
- **Can return any value**: Not limited to QueryBuilder instances

### Scopes
- **Model-specific**: Defined in Model classes
- **Global scopes**: Automatically applied to all queries
- **Local scopes**: Applied via `scope()` method
- **Can be disabled**: `withoutGlobalScope()` to temporarily disable
- **Model context**: Better for model-specific logic

### When to Use Macros

Use macros when you need:
- Custom method names that read naturally (`active()`, `published()`)
- Methods that work across different models/tables
- Reusable query patterns used throughout your application
- Methods that can return values other than QueryBuilder

### When to Use Scopes

Use scopes when you need:
- Model-specific query logic
- Automatic application (global scopes)
- Ability to temporarily disable scopes
- Logic that belongs to a specific model

## See Also

- [Query Scopes](19-query-scopes.md) - Model-based reusable query logic
- [Query Builder Basics](../02-core-concepts/03-query-builder-basics.md) - QueryBuilder fundamentals
- [Filtering Conditions](../03-query-builder/03-filtering-conditions.md) - WHERE clause patterns
