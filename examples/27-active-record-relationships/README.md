# ActiveRecord Relationships Examples

This directory contains examples demonstrating ActiveRecord relationships functionality.

## Files

- **01-relationships.php** - Comprehensive examples of hasOne, hasMany, belongsTo relationships with lazy and eager loading

## Features Demonstrated

### Lazy Loading
- Access relationships on-demand when accessed
- Example: `$user->profile` triggers a query when first accessed

### Eager Loading
- Load relationships in advance using `with()` method
- Prevents N+1 query problems
- Example: `User::find()->with('profile')->all()`

### Relationship Types

1. **hasOne** - One-to-one relationship
   - User has one Profile
   - Foreign key in Profile table

2. **hasMany** - One-to-many relationship
   - User has many Posts
   - Foreign key in Posts table

3. **belongsTo** - Many-to-one relationship
   - Post belongs to User
   - Foreign key in Post table

### Nested Eager Loading
- Load related relationships of related models
- Example: `User::find()->with(['posts' => ['comments']])->all()`

### Yii2-like Syntax (Calling Relationships as Methods)
- Call relationships as methods to get ActiveQuery for modification
- Example: `$user->posts()->where('published', 1)->orderBy('created_at', 'DESC')->all()`
- Allows adding conditions, ordering, limiting, etc. to relationship queries

## Running Examples

```bash
# Default (SQLite)
php examples/27-active-record-relationships/01-relationships.php

# MySQL
PDODB_DRIVER=mysql php examples/27-active-record-relationships/01-relationships.php

# MariaDB
PDODB_DRIVER=mariadb php examples/27-active-record-relationships/01-relationships.php

# PostgreSQL
PDODB_DRIVER=pgsql php examples/27-active-record-relationships/01-relationships.php
```

## Relationship Configuration

Relationships are defined in the model's `relations()` method:

```php
public static function relations(): array
{
    return [
        'profile' => ['hasOne', 'modelClass' => Profile::class],
        'posts' => ['hasMany', 'modelClass' => Post::class],
        'user' => ['belongsTo', 'modelClass' => User::class],
    ];
}
```

### Custom Foreign Keys

You can specify custom foreign keys:

```php
'profile' => [
    'hasOne',
    'modelClass' => Profile::class,
    'foreignKey' => 'owner_id',  // Custom foreign key
    'localKey' => 'id',          // Custom local key
],
```

