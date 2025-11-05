# Query Builder Macros Examples

This directory contains examples demonstrating the Query Builder Macros feature.

## What are Macros?

Macros allow you to extend QueryBuilder with custom methods. They provide a way to register reusable query logic that can be called as methods on QueryBuilder instances.

## Key Features

- **Custom Methods**: Register custom methods that can be called on QueryBuilder
- **Method Chaining**: Macros can return QueryBuilder instances for fluent chaining
- **Arguments Support**: Macros accept parameters just like regular methods
- **Flexible Returns**: Macros can return QueryBuilder instances or any other value

## Basic Usage

### Registering a Macro

```php
use tommyknocker\pdodb\query\QueryBuilder;

QueryBuilder::macro('active', function (QueryBuilder $query) {
    return $query->where('status', 'active');
});
```

### Using a Macro

```php
$activeProducts = $db->find()
    ->table('products')
    ->active()
    ->get();
```

### Checking if Macro Exists

```php
if (QueryBuilder::hasMacro('active')) {
    // Macro exists
}
```

## Examples

### Example 1: Basic Macro

Register a simple macro that filters by status:

```php
QueryBuilder::macro('active', function (QueryBuilder $query) {
    return $query->where('status', 'active');
});

$products = $db->find()->table('products')->active()->get();
```

### Example 2: Macro with Arguments

Macros can accept parameters:

```php
QueryBuilder::macro('wherePrice', function (QueryBuilder $query, string $operator, float $price) {
    return $query->where('price', $price, $operator);
});

$expensive = $db->find()
    ->table('products')
    ->wherePrice('>', 100.00)
    ->get();
```

### Example 3: Complex Macro

Macros can combine multiple conditions:

```php
QueryBuilder::macro('available', function (QueryBuilder $query) {
    return $query
        ->where('status', 'active')
        ->where('price', 0, '>');
});

$products = $db->find()->table('products')->available()->get();
```

### Example 4: Macro with Default Parameters

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

### Example 5: Chaining Macros

Macros can be chained together:

```php
$result = $db->find()
    ->table('products')
    ->active()
    ->inCategory(1)
    ->wherePrice('>', 100.00)
    ->orderBy('price', 'DESC')
    ->get();
```

### Example 6: Macro Returning Non-QueryBuilder Value

Macros can return any value:

```php
QueryBuilder::macro('countActive', function (QueryBuilder $query) {
    return $query->where('status', 'active')->getValue('COUNT(*)');
});

$count = $db->find()->table('products')->countActive();
```

## Running Examples

```bash
# SQLite (default)
php examples/29-macros/01-macro-examples.php

# MySQL
PDODB_DRIVER=mysql php examples/29-macros/01-macro-examples.php

# PostgreSQL
PDODB_DRIVER=pgsql php examples/29-macros/01-macro-examples.php

# MariaDB
PDODB_DRIVER=mariadb php examples/29-macros/01-macro-examples.php
```

## Files

- `01-macro-examples.php` - Comprehensive examples of macro usage

## Best Practices

1. **Naming**: Use descriptive names that clearly indicate what the macro does
2. **Return Values**: Return QueryBuilder instance for chaining, or specific values when needed
3. **Documentation**: Document macro parameters and behavior
4. **Reusability**: Create macros for common query patterns used across your application
5. **Testing**: Test macros thoroughly, especially when they contain complex logic

## Differences from Scopes

- **Macros**: Registered globally, can be called as methods on any QueryBuilder instance
- **Scopes**: Can be global (automatic) or local (explicit), model-specific, support disabling

Choose macros when you need:
- Custom method names that read naturally
- Methods that can be called on any QueryBuilder instance
- Reusable query patterns across different models

Choose scopes when you need:
- Model-specific query logic
- Automatic application (global scopes)
- Ability to temporarily disable scopes

