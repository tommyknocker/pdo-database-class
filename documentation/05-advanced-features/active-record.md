# ActiveRecord Pattern

PDOdb provides an optional lightweight ActiveRecord pattern implementation that allows you to work with database records as objects rather than arrays.

## Overview

ActiveRecord is an ORM pattern that maps database tables to classes and rows to objects. PDOdb's implementation is lightweight and optional - you can use it alongside or instead of the QueryBuilder API.

## Key Features

- **Model Classes**: Extend `Model` base class to create model classes
- **Magic Accessors**: Access attributes via `$model->attribute` syntax
- **Automatic CRUD**: Save, update, delete methods
- **Query Building**: Full QueryBuilder API through `ActiveQuery`
- **Dirty Tracking**: Automatically tracks changed attributes
- **Flexible Finding**: Find by ID, condition, or composite keys

## Basic Usage

### Defining a Model

```php
use tommyknocker\pdodb\orm\Model;

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

### Setting Database Connection

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb'
]);

User::setDb($db);
```

## Creating Records

### Creating a New Record

```php
$user = new User();
$user->name = 'Alice';
$user->email = 'alice@example.com';
$user->age = 30;
$user->save();

echo "Created user with ID: {$user->id}\n";
```

### Populating from Array

```php
$user = new User();
$user->populate([
    'name' => 'Bob',
    'email' => 'bob@example.com',
    'age' => 25
]);
$user->save();
```

## Finding Records

### Find One by ID

```php
$user = User::findOne(1);
if ($user !== null) {
    echo $user->name;
}
```

### Find One by Condition

```php
$user = User::findOne(['email' => 'alice@example.com']);
```

### Find All

```php
// All records
$users = User::findAll([]);

// With condition
$activeUsers = User::findAll(['status' => 'active']);
```

### Using ActiveQuery

```php
// Chainable query builder
$users = User::find()
    ->where('status', 'active')
    ->where('age', 18, '>=')
    ->orderBy('age', 'DESC')
    ->limit(10)
    ->all();

// Get raw data (array of arrays)
$rawData = User::find()
    ->where('status', 'active')
    ->get();

// Get single value
$count = User::find()
    ->select('COUNT(*)')
    ->getValue();
```

## Updating Records

### Updating Attributes

```php
$user = User::findOne(1);
$user->name = 'Updated Name';
$user->age = 31;
$user->save();
```

### Checking for Changes

```php
$user = User::findOne(1);

// Check if model has unsaved changes
if ($user->getIsDirty()) {
    $dirty = $user->getDirtyAttributes();
    echo "Changed attributes: " . implode(', ', array_keys($dirty));
    $user->save();
}
```

### Reloading from Database

```php
$user = User::findOne(1);
$user->name = 'Modified';

// Reload to get latest data from database
$user->refresh();
// $user->name now contains database value
```

## Deleting Records

```php
$user = User::findOne(1);
$user->delete();

// After delete, model becomes new record
echo $user->getIsNewRecord(); // true
```

## Advanced Features

### Composite Primary Keys

```php
class UserRole extends Model
{
    public static function tableName(): string
    {
        return 'user_roles';
    }

    public static function primaryKey(): array
    {
        return ['user_id', 'role_id'];
    }
}

// Find by composite key
$userRole = UserRole::findOne(['user_id' => 1, 'role_id' => 2]);
```

### Custom Table Names

```php
class Order extends Model
{
    // Auto-detects as 'orders' (plural of class name)
    // Override if needed:
    public static function tableName(): string
    {
        return 'user_orders';
    }
}
```

### Attribute Access

```php
$user = new User();

// Set attribute
$user->name = 'Alice';
$user->email = 'alice@example.com';

// Get attribute
echo $user->name;

// Check if set
if (isset($user->email)) {
    echo $user->email;
}

// Unset attribute
unset($user->email);
```

### Working with Attributes

```php
// Get all attributes as array
$attributes = $user->getAttributes();

// Set multiple attributes
$user->setAttributes([
    'name' => 'Bob',
    'email' => 'bob@example.com'
]);

// Convert model to array
$array = $user->toArray();
```

### Full QueryBuilder Access

All QueryBuilder methods are available through `ActiveQuery`:

```php
$users = User::find()
    ->select(['name', 'email'])
    ->where('status', 'active')
    ->orWhere('age', 18, '>=')
    ->join('profiles', 'users.id = profiles.user_id')
    ->groupBy('users.id')
    ->having('COUNT(profiles.id)', 1, '>=')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->offset(20)
    ->all();
```

### Accessing QueryBuilder Directly

```php
$query = User::find()->getQueryBuilder();

// Use QueryBuilder methods directly
$sql = $query->toSQL();
```

## Lifecycle Events

ActiveRecord supports PSR-14 event dispatcher integration for lifecycle events. You can listen to events that fire before and after save, insert, update, and delete operations.

### Available Events

- **ModelBeforeSaveEvent** - Fired before save() (insert or update)
- **ModelAfterSaveEvent** - Fired after successful save()
- **ModelBeforeInsertEvent** - Fired before insert operation
- **ModelAfterInsertEvent** - Fired after successful insert
- **ModelBeforeUpdateEvent** - Fired before update operation
- **ModelAfterUpdateEvent** - Fired after successful update
- **ModelBeforeDeleteEvent** - Fired before delete operation
- **ModelAfterDeleteEvent** - Fired after successful delete

### Setting Up Event Dispatcher

```php
use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\events\ModelBeforeSaveEvent;
use tommyknocker\pdodb\events\ModelAfterSaveEvent;

// Create your event dispatcher (e.g., Symfony EventDispatcher)
$dispatcher = new YourEventDispatcher();

// Set dispatcher on connection
$db = new PdoDb('mysql', $config);
$queryBuilder = $db->find();
$connection = $queryBuilder->getConnection();
$connection->setEventDispatcher($dispatcher);

// Or use PdoDb method if available
// $db->setEventDispatcher($dispatcher);
```

### Listening to Events

```php
use tommyknocker\pdodb\events\ModelBeforeSaveEvent;
use tommyknocker\pdodb\events\ModelAfterSaveEvent;
use tommyknocker\pdodb\events\ModelBeforeDeleteEvent;

// Listen to beforeSave events
$dispatcher->addListener(ModelBeforeSaveEvent::class, function (ModelBeforeSaveEvent $event) {
    $model = $event->getModel();
    echo "Saving model: {$model->name}\n";
    
    // Access model attributes
    if ($event->isNewRecord()) {
        echo "This is a new record (insert)\n";
    } else {
        echo "This is an update\n";
    }
});

// Listen to afterSave events
$dispatcher->addListener(ModelAfterSaveEvent::class, function (ModelAfterSaveEvent $event) {
    $model = $event->getModel();
    echo "Model saved: {$model->name}\n";
});

// Listen to beforeDelete events
$dispatcher->addListener(ModelBeforeDeleteEvent::class, function (ModelBeforeDeleteEvent $event) {
    $model = $event->getModel();
    echo "Deleting model: {$model->name}\n";
});
```

### Stopping Event Propagation

Before-events support stopping propagation to prevent the operation:

```php
$dispatcher->addListener(ModelBeforeSaveEvent::class, function (ModelBeforeSaveEvent $event) {
    $model = $event->getModel();
    
    // Prevent save if validation fails
    if (empty($model->name)) {
        $event->stopPropagation();
    }
});

$user = new User();
$user->email = 'test@example.com';
$result = $user->save(); // Returns false, save was prevented
```

### Event Information

Events provide access to model data and operation context:

```php
// BeforeUpdateEvent provides dirty attributes
$dispatcher->addListener(ModelBeforeUpdateEvent::class, function (ModelBeforeUpdateEvent $event) {
    $dirty = $event->getDirtyAttributes();
    echo "Changed attributes: " . implode(', ', array_keys($dirty)) . "\n";
});

// AfterInsertEvent provides inserted ID
$dispatcher->addListener(ModelAfterInsertEvent::class, function (ModelAfterInsertEvent $event) {
    $insertId = $event->getInsertId();
    echo "Inserted with ID: {$insertId}\n";
});

// AfterUpdateEvent provides rows affected
$dispatcher->addListener(ModelAfterUpdateEvent::class, function (ModelAfterUpdateEvent $event) {
    $rowsAffected = $event->getRowsAffected();
    echo "Updated {$rowsAffected} row(s)\n";
});

// AfterDeleteEvent provides rows affected
$dispatcher->addListener(ModelAfterDeleteEvent::class, function (ModelAfterDeleteEvent $event) {
    $rowsAffected = $event->getRowsAffected();
    echo "Deleted {$rowsAffected} row(s)\n";
});
```

### Complete Example

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\events\ModelBeforeSaveEvent;
use tommyknocker\pdodb\events\ModelAfterSaveEvent;

class User extends Model
{
    public static function tableName(): string
    {
        return 'users';
    }
}

$db = new PdoDb('mysql', $config);
User::setDb($db);

// Simple event dispatcher
class SimpleDispatcher implements \Psr\EventDispatcher\EventDispatcherInterface
{
    protected array $listeners = [];
    
    public function addListener(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }
    
    public function dispatch(object $event): object
    {
        $eventClass = $event::class;
        if (isset($this->listeners[$eventClass])) {
            foreach ($this->listeners[$eventClass] as $listener) {
                $listener($event);
            }
        }
        return $event;
    }
}

$dispatcher = new SimpleDispatcher();

// Log all saves
$dispatcher->addListener(ModelBeforeSaveEvent::class, function (ModelBeforeSaveEvent $event) {
    error_log("Before save: " . $event->getModel()->name);
});

$dispatcher->addListener(ModelAfterSaveEvent::class, function (ModelAfterSaveEvent $event) {
    error_log("After save: " . $event->getModel()->name);
});

// Set dispatcher
$queryBuilder = $db->find();
$connection = $queryBuilder->getConnection();
$connection->setEventDispatcher($dispatcher);

// Now all save operations will trigger events
$user = new User();
$user->name = 'Alice';
$user->email = 'alice@example.com';
$user->save(); // Events will fire
```

## Best Practices

### 1. Model Organization

```php
// app/Models/User.php
namespace App\Models;

use tommyknocker\pdodb\orm\Model;

class User extends Model
{
    public static function tableName(): string
    {
        return 'users';
    }
}
```

### 2. Database Setup

```php
// bootstrap.php
use tommyknocker\pdodb\PdoDb;
use App\Models\User;

$db = new PdoDb('mysql', $config);
User::setDb($db);
```

### 3. Validation

ActiveRecord supports declarative validation using rules. Define validation rules in the `rules()` method:

```php
class User extends Model
{
    public static function rules(): array
    {
        return [
            [['name', 'email'], 'required'],
            ['email', 'email'],
            ['age', 'integer', 'min' => 1, 'max' => 150],
            ['name', 'string', 'min' => 2, 'max' => 100],
        ];
    }
}

$user = new User();
$user->email = 'invalid-email';
$user->age = 200;

if (!$user->save()) {
    $errors = $user->getValidationErrors();
    foreach ($errors as $attribute => $messages) {
        foreach ($messages as $message) {
            echo "{$attribute}: {$message}\n";
        }
    }
}
```

#### Built-in Validators

- **`required`**: Attribute must not be empty
- **`email`**: Attribute must be a valid email address
- **`integer`**: Attribute must be an integer (supports `min`, `max` params)
- **`string`**: Attribute must be a string (supports `min`, `max`, `length` params)

#### Validation Rules Format

```php
public static function rules(): array
{
    return [
        // Multiple attributes with same validator
        [['name', 'email'], 'required'],
        
        // Single attribute with validator
        ['email', 'email'],
        
        // Validator with parameters
        ['age', 'integer', 'min' => 1, 'max' => 150],
        ['name', 'string', 'min' => 2, 'max' => 100],
        
        // Custom error message
        ['name', 'required', 'message' => 'Name is mandatory'],
    ];
}
```

#### Getting Validation Errors

```php
$user = new User();
if (!$user->validate()) {
    // Get all errors
    $errors = $user->getValidationErrors();
    
    // Get errors for specific attribute
    $nameErrors = $user->getValidationErrorsForAttribute('name');
    
    // Check if has errors
    if ($user->hasValidationErrors()) {
        // Handle errors
    }
    
    // Clear errors
    $user->clearValidationErrors();
}
```

#### Custom Validators

You can create custom validators by implementing `ValidatorInterface`:

```php
use tommyknocker\pdodb\orm\validators\AbstractValidator;
use tommyknocker\pdodb\orm\validators\ValidatorFactory;

class CustomValidator extends AbstractValidator
{
    public function validate(Model $model, string $attribute, mixed $value, array $params = []): bool
    {
        // Your validation logic
        return true; // or false
    }

    protected function getDefaultMessage(string $attribute, array $params): string
    {
        return "Attribute '{$attribute}' validation failed.";
    }
}

// Register custom validator
ValidatorFactory::register('custom', CustomValidator::class);

// Use in rules
class User extends Model
{
    public static function rules(): array
    {
        return [
            ['field', 'custom'],
        ];
    }
}
```

#### Skipping Validation

You can skip validation when saving:

```php
$user = new User();
// Skip validation
$user->save(false);
```

### 4. Safe Attributes

```php
class User extends Model
{
    public static function safeAttributes(): array
    {
        return ['name', 'email', 'age'];
    }
}

// Only safe attributes will be set
$user = new User();
$user->setAttributes($data, true); // safeOnly = true
```

## Comparison with QueryBuilder

### QueryBuilder (Array-Based)

```php
$users = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->get(); // Returns array of arrays

foreach ($users as $user) {
    echo $user['name'];
}
```

### ActiveRecord (Object-Based)

```php
$users = User::find()
    ->where('status', 'active')
    ->all(); // Returns array of User objects

foreach ($users as $user) {
    echo $user->name;
}
```

## When to Use ActiveRecord

**Use ActiveRecord when:**
- You prefer working with objects over arrays
- You want automatic dirty tracking
- You need simple CRUD operations
- You're building an MVC-style application

**Use QueryBuilder when:**
- You need maximum performance
- You're building complex queries
- You prefer array-based results
- You need more control over SQL generation

## Database Support

ActiveRecord works with all supported databases:
- ✅ MySQL
- ✅ PostgreSQL
- ✅ SQLite

## Limitations

- No automatic relationships (1-to-1, 1-to-many, etc.)
- No automatic schema introspection
- No migrations
- No automatic validation rules
- Lightweight implementation focused on core functionality

These limitations are intentional to keep the library lightweight and optional. You can extend models to add relationships and validation as needed.

## Examples

See the [ActiveRecord examples](../../examples/23-active-record/) for complete working examples.

