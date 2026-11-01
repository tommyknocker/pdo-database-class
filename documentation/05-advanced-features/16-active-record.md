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

### Table and class names

If you omit `tableName()`, PDOdb derives the table from the short class name with `Inflector::tableize()` (CamelCase to snake_case, then English plural on the last segment). `User` maps to `users`, `OrderItem` to `order_items`, `Person` to `people`. The CLI model generator and default `belongsTo` / `hasMany` foreign keys use the same helper. Override `tableName()` or set an explicit `foreignKey` when your database uses another convention.

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
    ->andWhere('age', 18, '>=')
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

If `safeOnly` is `true`, the model **must** implement `safeAttributes()`. Otherwise `MassAssignmentException` is thrown.

See [Active Record relationships](17-active-record-relationships.md) for `hasManyThrough` and other relation types across supported dialects.

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
- ✅ MySQL / MariaDB
- ✅ PostgreSQL
- ✅ SQL Server
- ✅ Oracle
- ✅ SQLite

## Relationships

ActiveRecord supports relationships between models. See [ActiveRecord Relationships](17-active-record-relationships.md) for detailed documentation.

Supported relationship types:
- **hasOne** - One-to-one relationship
- **hasMany** - One-to-many relationship
- **belongsTo** - Many-to-one relationship
- **hasManyThrough** - Many-to-many via a junction table

Both lazy loading and eager loading are supported to prevent N+1 query problems. Call `Post::preventLazyLoading(true)` to throw `LazyRelationException` when a relation on `Post` is accessed without eager loading (useful in development). The setting applies to the selected model class only; call `Post::preventLazyLoading(false)` to restore lazy loading.

## Limitations

- Schema introspection for code generation is CLI-driven (`pdodb generate model`), not automatic at runtime
- Schema changes use the [migration system](15-migrations.md); ActiveRecord does not apply migrations for you
- Lightweight implementation focused on core functionality

These limitations are intentional to keep the library lightweight and optional. You can extend models to add custom functionality as needed.

## Examples

See the [ActiveRecord examples](../../examples/09-active-record/01-basics.php) for complete working examples.

## Connections and global scopes

`Model::setDb($db)` sets the default connection for models without their own
connection. `User::setDb($db)` sets an override for `User` and its subclasses;
it does not change unrelated models. Passing `null` explicitly disables the
connection for that model class. This differs from older versions where setting
one model's inherited static connection could affect unrelated models.

Global scopes apply to ActiveQuery reads, updates and deletes, and to updates
and deletes on model instances. Operations such as `truncate()` and `replace()`
that cannot honor a WHERE scope require explicitly disabling it. Raw SQL and
`getQueryBuilder()` remain direct access: global scopes are not a substitute
for authorization or database-level tenant isolation.

## Typed attributes and API responses

Models can opt into conversions for JSON arrays, backed enums and immutable
datetimes. Existing models without `casts()` keep their previous behavior.

```php
use DateTimeImmutable;
use tommyknocker\pdodb\orm\Model;

enum OrderStatus: string { case Pending = 'pending'; case Paid = 'paid'; }

final class Order extends Model
{
    public static function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'created_at' => DateTimeImmutable::class,
            'metadata' => 'json',
        ];
    }

    public static function safeAttributes(): array
    {
        return ['status', 'metadata'];
    }
}
```

`$order->status` is an enum case, `$order->created_at` is a `DateTimeImmutable`,
and `$order->metadata` is an array. Assign enum cases, `DateTimeInterface`
objects and arrays respectively; `null` remains `null`. JSON must decode to an
array or object, and invalid input throws. Datetimes are written as UTC without a
timezone, preserving microseconds; use a column that can represent that format.
Dates read without a timezone are interpreted as UTC. `getAttributes()` and
`toArray()` retain their existing raw database-value behavior.

On Oracle, the default session timestamp format drops fractional seconds.
Before using `DateTimeImmutable` casts, explicitly configure the connection:

```php
$db->rawQuery("ALTER SESSION SET NLS_TIMESTAMP_FORMAT='YYYY-MM-DD HH24:MI:SS.FF6'");
```

The model checks that setting before loading or saving cast datetimes and throws
instead of silently losing precision. This check adds a database query to those
Oracle operations. This does not change the format for applications that do not
opt in to datetime casts. Oracle attributes use exact-case names first; when no
exact name exists, an unambiguous case-insensitive match handles normal uppercase
column names. Ambiguous quoted names require their exact case.

For an API, explicitly select the fields and eager-loaded relationships to expose:

```php
$page = Order::find()->with('items')->paginateModels(20, 1);
$data = array_map(
    fn (Model $order) => $order->present(['id', 'status'], ['items' => ['sku']]),
    $page->items()
);
$total = $page->total();
```

`paginateModels()` returns models and loads the relations requested by `with()`;
`paginate()` continues to return raw rows. `present()` never lazy-loads a
relation: request it with `with()` first. It exports only the named attributes,
turning backed enums and dates into JSON-ready values. Do not select private
columns for public responses. For separate request/response DTOs with optional
`tommyknocker/struct`, see the [API contract example](../../examples/09-active-record/04-api-contract.php).

## Optimistic locking

For records that can be edited concurrently, opt in with an integer version
column. Existing models are unaffected. The column must be present in the
schema and included in every model query that will later update or delete it.

```php
final class Order extends Model
{
    public static function optimisticLock(): ?string
    {
        return 'version';
    }
}

$first = Order::findOne(1);
$second = Order::findOne(1);
$first->status = 'paid';
$first->save(); // UPDATE includes WHERE version = the loaded version
$second->status = 'cancelled';
$second->save(); // throws OptimisticLockException
```

New models default to version `1` when the version is unset or null. Each
successful update atomically increments it. A stale update or delete throws
`OptimisticLockException`; the record may also have been deleted or become
inaccessible. Manually changing the version attribute is rejected. A model with
no dirty attributes does not issue an update or check for a conflict.

For changes submitted in a later HTTP request, send the version originally read
with the response and compare it to the loaded model before saving. Loading a
fresh model and ignoring the client's version cannot detect changes that occurred
before that load. Still handle the exception: another writer can update the row
between your comparison and the save.

## Explicit connection contexts

For a worker that needs the same model on two independent databases, pass the
connection to the query instead of changing global state:

```php
$first = Order::on($firstDb)->where('id', 1)->one();
$second = Order::on($secondDb)->with('items')->where('id', 1)->one();

$first->status = OrderStatus::Paid;
$first->save(); // writes to $firstDb
```

The explicit connection propagates to lazy and eager relationships, including
model pagination. Loaded models remain bound to the connection from which they
were read, even if a model's static default is changed later. A new model can be
bound before saving with `$order->bindConnection($db)`.

Use separate `PdoDb` instances for simultaneous contexts. If the active
connection inside a bound `PdoDb` is switched after creating a query or loading
a model, PDOdb throws instead of silently writing to the new connection. An
already loaded model cannot be rebound to a different connection. Direct access
through `getQueryBuilder()` is an advanced escape hatch; it bypasses model
scope and context protections.

Without `on()`, a related model continues using its own configured connection.
With `on()`, relationships use the explicitly supplied connection and retain
related-model global scopes. Do not combine models from different explicit
contexts in one manual eager-load call.

## Behaviors, transactions, and API shape

These features are opt-in. A model that does not override them keeps the previous `save()` and `delete()` behavior.

### Behaviors

Return behavior classes from `behaviors()`. A class name uses the defaults. `ClassName::class => ['column' => 'removed_at']` passes configuration. An instance is also accepted.

- `TimestampBehavior` sets `created_at` on insert when it is empty and `updated_at` on insert and update. Both names can be changed or set to null. The value is the database clock (`NOW()` on the dialect), so all writers share one time source; the model reloads those columns after the write, so `$model->created_at` holds the stored value. The database clock follows the connection time zone, so set the session or server zone to UTC if you store UTC. Pass `'value' => fn (Model $model, string $column) => ...`, a `RawValue` expression or a literal to override it, for example `TimestampBehavior::class => ['value' => fn () => gmdate('Y-m-d H:i:s')]`.
- `SoftDeleteBehavior` takes the same `value` option for the deletion time and adds a `softDelete` global scope (`deleted_at IS NULL`) and turns `delete()` into an update. `restore()` clears the timestamp. `forceDelete()` and `forceDeleteAll()` remove rows. `deleteAll()` soft-deletes when the behavior is enabled. Disable the scope with `withoutGlobalScope(SoftDeleteBehavior::SCOPE)`.
- `PrimaryKeyBehavior` assigns a UUID or ULID before insert when the key is empty. Use `incrementing(): false` when the primary key itself is generated in PHP; otherwise a null key is still treated as auto-increment. The behavior can also fill another column, such as `public_id`.

Generated models document columns with `@property` tags so static analysis can see them. For a real typed property, declare a PHP 8.4 hook that reads and writes through `getAttribute()` and `setAttribute()`:

```php
public string $headline {
    get => (string) $this->getAttribute('title');
    set {
        $this->setAttribute('title', $value);
    }
}
```

Use a set block. A short `set =>` expression is stored in the property, and `setAttribute()` returns void.

### Transactions and afterCommit

`transactional()` lists `insert`, `update`, `delete`, or `['*']`. The default is empty. Validation still runs before a transaction starts, and a failed validation does not roll anything back.

Inside a transactional operation, `beforeSave` and `afterSave` run in the same transaction as the write. If the driver can nest transactions, an operation joins the outer one with a savepoint. If it cannot, the operation runs on the open transaction and does not commit or roll it back. A stopped `beforeSave`, a failed write, or an exception rolls back the database work started by that operation and restores the model to the snapshot taken when `save()` or `delete()` started. Assignments made before that call stay in memory, so a rolled-back update is dirty and can be saved again. Generated keys, behavior values, and old-attribute bookkeeping applied during the write are reverted. The same snapshot is restored when a save succeeded inside an outer transaction that later rolls back. The database row returns to the values committed before that outer transaction; reload the model if another query changed the row after this instance was loaded.

`afterCommit()` registers work that runs only after the outermost commit. Call it from an after-save listener while the transaction is still open. It is the right place for mail or HTTP. It is the wrong place for `Outbox::add()`: the outbox row must be inserted in the same transaction as the business write, from `afterSave` or from the surrounding `transaction()` callback. See [Transactional outbox](27-transactional-outbox.md) and [Inbox](28-inbox.md).

Callbacks run after the commit has succeeded. If a callback throws, the exception propagates to the caller of `transaction()` or `save()`, but the data stays committed and the remaining callbacks of that commit are not run. Catch inside the callback for best-effort work, and use the outbox for anything that must not be lost.

### Bulk writes and race-free create

`updateAll()` and `deleteAll()` apply global scopes and do not fire model events. `firstOrCreate()` and `updateOrCreate()` use one dialect-specific upsert. The search columns need a unique constraint; the first conflict is the whole search key, not only the primary key. `increment()` and `decrement()` use a single SQL addition so two requests cannot lose an update. They participate in optimistic locking when `optimisticLock()` is set.

`ActiveQuery::each()`, `chunk()`, and `cursor()` yield models. `each()` and `chunk()` load `with()` relations once per batch. `getQueryBuilder()->each()` still yields raw rows. `stream()` on an active query remains a raw stream; use `cursor()` for models.

### Validation and serialization

Built-in rules now include `number`, `in`, `date`, `regex`, `unique`, `exist` / `exists`, and `callback`. A closure may be the validator. A `when` callable skips that attribute when it does not return true. `validationErrorPayload()` returns `message` and `errors` for an HTTP response.

`unique` and `exists` run a query before the write, so they are a friendly early error, not a guarantee: two concurrent requests can both pass `unique` and the second insert then fails. Keep a unique index in the database as the real constraint and handle `ConstraintViolationException` for the race. `unique` counts soft-deleted rows as taken because the database index does; pass `'withoutTrashed' => true` to ignore them. Both rules query through the connection bound with `Model::on()`.

`toArray()` omits `hiddenAttributes()`. When `visibleAttributes()` is non-empty, only those stored attributes are returned. `appendedAttributes()` adds computed keys and honors the same visibility lists. `present()` is unchanged: it is an explicit field list and does not read hidden attributes.

### Two ways to write

`$model->save()` and `$model->delete()` run behaviors, validation, events, and the transactional wrapper. `Model::find()->update()` and `updateAll()` / `deleteAll()` are set-based writes: they honor global scopes and skip model events. Use the model instance when a row must notify listeners or fill timestamps. Use the query when many rows change and events would be the wrong tool.

### Long-lived workers

`Model::setDb()` stores the connection on that class, not on one shared static property for every model. In RoadRunner, Swoole, or FrankenPHP, call `setDb($db)` at the start of each request with that request's connection. Prefer `Model::on($db)` when a query must not read process-wide state. Do not keep a request's connection on the class after the request finishes if the next request uses a different database. Loaded models stay bound to the connection that read them.

Query and transaction events (`QueryExecutedEvent`, `TransactionCommittedEvent`) are the integration point for PSR-3 logs and OpenTelemetry. Register a PSR-14 dispatcher on the connection; PDOdb does not ship a framework container binding.

## Diagnosing relationship queries

An order list can accidentally load a customer separately for every order. Enable profiling on the connection to see how many queries each relation performs:

```php
$db->enableProfiling(); // Enable before running the code you want to inspect.
$orders = Order::find()->all();
foreach ($orders as $order) {
    echo $order->user->name;
}

foreach ($db->profileReport()->summary()['relations'] as $relation) {
    printf("%s::%s: %d lazy SQL queries; possible N+1: %s\n",
        $relation['model'], $relation['relation'], $relation['lazy_queries'],
        $relation['possible_n_plus_one'] ? 'yes' : 'no');
}
```

Try `Order::find()->with('user')->all()` and compare the report after `$db->getProfiler()->reset()`. In the [runnable SQLite example](../../examples/09-active-record/05-diagnose-relations.php), 12 lazy queries become one eager query. This is a **suspicion**, not proof: ten or more lazy loads and ten or more SQL attempts for the same model and relation trigger the flag. Review the access pattern before changing it.

The report records property/`getRelation()` lazy loads and `with()` eager loads, not manually executed relation queries. Counts are query attempts within relation loading, including any SQL performed by callbacks in that scope. Nested relation SQL is attributed to the innermost relation. Each database connection keeps its own profile. Profiling is opt-in and in-process; reset it between comparisons. It retains up to 100 model/relation pairs and three SQL samples per pair, and sets `relations_truncated` if the pair limit is reached.

Samples omit bound values and redact recognized literals. SQL identifiers and other text may remain visible, so review reports before sharing them; redaction is not a complete privacy boundary. Profiling does not call AI or send data anywhere.

For a slow query, use `explainAdvice()` for local plan advice or `explainAiContext()` to inspect what would be sent. Only an explicit `explainAiAdvice()` call uses the configured AI provider. See [AI-powered query analysis](23-ai-analysis.md) for setup, privacy, and an end-to-end example.
