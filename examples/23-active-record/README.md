# ActiveRecord Examples

Examples demonstrating the ActiveRecord pattern implementation in PDOdb.

## Overview

ActiveRecord provides an ORM-like interface for database operations, allowing you to work with database records as objects rather than arrays.

## Examples

### 01-active-record-examples.php

Demonstrates:
- Creating and saving new records
- Finding records by ID and conditions
- Updating records
- Deleting records
- Using ActiveQuery builder
- Tracking dirty attributes
- Populating models from arrays
- Converting models to arrays
- Lifecycle events with PSR-14 event dispatcher

## Running Examples

### SQLite (default)
```bash
php examples/23-active-record/01-active-record-examples.php
```

### MySQL
```bash
PDODB_DRIVER=mysql php examples/23-active-record/01-active-record-examples.php
```

### PostgreSQL
```bash
PDODB_DRIVER=pgsql php examples/23-active-record/01-active-record-examples.php
```

## Key Concepts

### Model Definition

```php
class User extends Model
{
    public static function tableName(): string
    {
        return 'users';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }
}
```

### Basic Operations

```php
// Create
$user = new User();
$user->name = 'Alice';
$user->email = 'alice@example.com';
$user->save();

// Find
$user = User::findOne(1);
$users = User::findAll(['status' => 'active']);

// Update
$user->name = 'Bob';
$user->save();

// Delete
$user->delete();
```

### ActiveQuery

```php
// Query builder
$users = User::find()
    ->where('status', 'active')
    ->where('age', 25, '>=')
    ->orderBy('age', 'DESC')
    ->all();

// Get raw data
$rawData = User::find()->get();
```

## Features

- **Attribute Access**: Magic getters and setters
- **Dirty Tracking**: Automatically tracks changed attributes
- **Query Building**: Full QueryBuilder API through ActiveQuery
- **Flexible Finding**: Find by ID, condition, or composite keys
- **Lifecycle Events**: PSR-14 event dispatcher integration for before/after hooks
- **Database Agnostic**: Works with MySQL, PostgreSQL, and SQLite

