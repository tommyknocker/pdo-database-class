<?php

declare(strict_types=1);

/**
 * ActiveRecord Examples.
 *
 * This example demonstrates how to use ActiveRecord pattern for database operations.
 *
 * Usage:
 *   php examples/23-active-record/01-active-record-examples.php
 *   PDODB_DRIVER=mysql php examples/23-active-record/01-active-record-examples.php
 *   PDODB_DRIVER=pgsql php examples/23-active-record/01-active-record-examples.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\orm\Model;

// Define User model
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

// Get database from environment or default to SQLite
$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== ActiveRecord Examples ===\n\n";
echo "Driver: $driver\n\n";

// Create users table based on driver
if ($driver === 'mysql') {
    $db->rawQuery('DROP TABLE IF EXISTS users');
    $db->rawQuery('
        CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            age INT,
            status VARCHAR(20) DEFAULT "active",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ');
} elseif ($driver === 'pgsql') {
    $db->rawQuery('DROP TABLE IF EXISTS users CASCADE');
    $db->rawQuery('
        CREATE TABLE users (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            age INTEGER,
            status VARCHAR(20) DEFAULT \'active\',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
} else { // sqlite
    $db->rawQuery('DROP TABLE IF EXISTS users');
    $db->rawQuery('
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            age INTEGER,
            status TEXT DEFAULT "active",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
}

// Set database connection for User model
User::setDb($db);

// Example 1: Create and save a new user
echo "1. Creating a New User\n";
echo "   -------------------\n";
$user = new User();
$user->name = 'Alice';
$user->email = 'alice@example.com';
$user->age = 30;
$user->status = 'active';

if ($user->save()) {
    echo "   ✓ User created with ID: {$user->id}\n";
    echo "   Name: {$user->name}\n";
    echo "   Email: {$user->email}\n";
    echo '   Is New Record: ' . ($user->getIsNewRecord() ? 'Yes' : 'No') . "\n";
} else {
    echo "   ✗ Failed to create user\n";
}
echo "\n";

// Example 2: Find user by ID
echo "2. Finding User by ID\n";
echo "   -------------------\n";
$foundUser = User::findOne($user->id);
if ($foundUser !== null) {
    echo "   ✓ User found: {$foundUser->name} ({$foundUser->email})\n";
    echo "   ID: {$foundUser->id}\n";
    echo "   Age: {$foundUser->age}\n";
    echo "   Status: {$foundUser->status}\n";
} else {
    echo "   ✗ User not found\n";
}
echo "\n";

// Example 3: Find user by condition
echo "3. Finding User by Condition\n";
echo "   -------------------------\n";
$foundByEmail = User::findOne(['email' => 'alice@example.com']);
if ($foundByEmail !== null) {
    echo "   ✓ User found by email: {$foundByEmail->name}\n";
} else {
    echo "   ✗ User not found\n";
}
echo "\n";

// Example 4: Update user
echo "4. Updating User\n";
echo "   -------------\n";
$foundUser->age = 31;
$foundUser->status = 'verified';
if ($foundUser->save()) {
    echo "   ✓ User updated\n";
    echo '   Dirty attributes: ' . (empty($foundUser->getDirtyAttributes()) ? 'None' : implode(', ', array_keys($foundUser->getDirtyAttributes()))) . "\n";

    // Reload to verify
    $foundUser->refresh();
    echo "   Age after refresh: {$foundUser->age}\n";
    echo "   Status after refresh: {$foundUser->status}\n";
} else {
    echo "   ✗ Failed to update user\n";
}
echo "\n";

// Example 5: Create multiple users
echo "5. Creating Multiple Users\n";
echo "   -----------------------\n";
$users = [];
for ($i = 1; $i <= 3; $i++) {
    $newUser = new User();
    $newUser->name = "User {$i}";
    $newUser->email = "user{$i}@example.com";
    $newUser->age = 20 + $i;
    $newUser->save();
    $users[] = $newUser;
    echo "   ✓ Created user: {$newUser->name} (ID: {$newUser->id})\n";
}
echo "\n";

// Example 6: Find all users
echo "6. Finding All Users\n";
echo "   ------------------\n";
$allUsers = User::findAll([]);
echo '   Total users: ' . count($allUsers) . "\n";
foreach ($allUsers as $u) {
    echo "   - {$u->name} ({$u->email}) - Age: {$u->age}\n";
}
echo "\n";

// Example 7: Using ActiveQuery
echo "7. Using ActiveQuery Builder\n";
echo "   --------------------------\n";
$activeUsers = User::find()
    ->where('status', 'active')
    ->where('age', 25, '>=')
    ->orderBy('age', 'DESC')
    ->all();

echo '   Active users age >= 25: ' . count($activeUsers) . "\n";
foreach ($activeUsers as $u) {
    echo "   - {$u->name} (Age: {$u->age})\n";
}
echo "\n";

// Example 8: Get raw data from ActiveQuery
echo "8. Getting Raw Data from ActiveQuery\n";
echo "   ----------------------------------\n";
$rawData = User::find()
    ->select(['name', 'email'])
    ->where('age', 25, '>')
    ->get();

echo "   Raw data (array of arrays):\n";
foreach ($rawData as $row) {
    echo "   - {$row['name']}: {$row['email']}\n";
}
echo "\n";

// Example 9: Delete user
echo "9. Deleting User\n";
echo "   --------------\n";
$userToDelete = User::findOne($users[0]->id);
if ($userToDelete !== null) {
    $userId = $userToDelete->id;
    $userName = $userToDelete->name;
    if ($userToDelete->delete()) {
        echo "   ✓ User deleted: {$userName} (ID: {$userId})\n";

        // Verify deletion
        $deleted = User::findOne($userId);
        if ($deleted === null) {
            echo "   ✓ User confirmed deleted\n";
        } else {
            echo "   ✗ User still exists\n";
        }
    } else {
        echo "   ✗ Failed to delete user\n";
    }
}
echo "\n";

// Example 10: Dirty attributes tracking
echo "10. Dirty Attributes Tracking\n";
echo "    --------------------------\n";
$testUser = User::findOne(['email' => 'alice@example.com']);
if ($testUser !== null) {
    echo "    Before modification:\n";
    echo '    - Is Dirty: ' . ($testUser->getIsDirty() ? 'Yes' : 'No') . "\n";

    $testUser->age = 35;
    echo "    After modifying age:\n";
    echo '    - Is Dirty: ' . ($testUser->getIsDirty() ? 'Yes' : 'No') . "\n";
    echo '    - Dirty attributes: ' . implode(', ', array_keys($testUser->getDirtyAttributes())) . "\n";

    $testUser->save();
    echo "    After save:\n";
    echo '    - Is Dirty: ' . ($testUser->getIsDirty() ? 'Yes' : 'No') . "\n";
}
echo "\n";

// Example 11: Populate and toArray
echo "11. Populate and toArray\n";
echo "    ---------------------\n";
$newUser = new User();
$newUser->populate([
    'name' => 'Populated User',
    'email' => 'populated@example.com',
    'age' => 40,
]);

$array = $newUser->toArray();
echo "    Model as array:\n";
echo '    ' . json_encode($array, JSON_PRETTY_PRINT) . "\n";
echo "\n";

// Example 12: Model attributes
echo "12. Model Attributes\n";
echo "    -----------------\n";
$attrUser = new User();
$attrUser->name = 'Attribute Test';
$attrUser->email = 'attr@example.com';

echo '    All attributes: ' . json_encode($attrUser->getAttributes()) . "\n";
echo "    Name via magic getter: {$attrUser->name}\n";
echo '    Email isset check: ' . (isset($attrUser->email) ? 'Yes' : 'No') . "\n";

unset($attrUser->email);
echo '    After unset email: ' . (isset($attrUser->email) ? 'Yes' : 'No') . "\n";
echo "\n";

// Example 13: Lifecycle Events
echo "13. Lifecycle Events\n";
echo "    -----------------\n";

// Simple event dispatcher implementation
class SimpleEventDispatcher implements \Psr\EventDispatcher\EventDispatcherInterface
{
    /** @var array<string, array<callable>> */
    protected array $listeners = [];

    public function listen(string $eventClass, callable $listener): void
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

use tommyknocker\pdodb\events\ModelAfterSaveEvent;
use tommyknocker\pdodb\events\ModelBeforeSaveEvent;

$dispatcher = new SimpleEventDispatcher();
$eventCount = 0;

// Listen to beforeSave events
$dispatcher->listen(ModelBeforeSaveEvent::class, function (ModelBeforeSaveEvent $event) use (&$eventCount) {
    $eventCount++;
    $model = $event->getModel();
    echo "    BeforeSave: {$model->name} (new record: " . ($event->isNewRecord() ? 'Yes' : 'No') . ")\n";
});

// Listen to afterSave events
$dispatcher->listen(ModelAfterSaveEvent::class, function (ModelAfterSaveEvent $event) use (&$eventCount) {
    $eventCount++;
    $model = $event->getModel();
    echo "    AfterSave: {$model->name} (was new: " . ($event->isNewRecord() ? 'Yes' : 'No') . ")\n";
});

// Set dispatcher on connection
$queryBuilder = $db->find();
$connection = $queryBuilder->getConnection();
$connection->setEventDispatcher($dispatcher);

// Create user with events
$eventUser = new User();
$eventUser->name = 'EventUser';
$eventUser->email = 'eventuser@example.com';
$eventUser->save();

echo "    Events dispatched: {$eventCount}\n";
echo "\n";

// Clean up
$connection->setEventDispatcher(null);

// Example 14: Validation
echo "14. Validation\n";
echo "    -----------\n";

// Try to save invalid data
$invalidUser = new User();
$invalidUser->email = 'invalid-email'; // Invalid email format
$invalidUser->age = 200; // Out of range

if (!$invalidUser->save()) {
    echo "    Validation failed as expected\n";
    $errors = $invalidUser->getValidationErrors();
    foreach ($errors as $attribute => $messages) {
        foreach ($messages as $message) {
            echo "    - {$attribute}: {$message}\n";
        }
    }
}

// Fix errors and save
$invalidUser->name = 'Valid User';
$invalidUser->email = 'valid@example.com';
$invalidUser->age = 30;

if ($invalidUser->save()) {
    echo "    ✓ User saved after fixing validation errors\n";
    echo "    User ID: {$invalidUser->id}\n";
}

// Validate manually
$testUser = new User();
$testUser->name = 'Test';
$testUser->email = 'test@example.com';
$testUser->age = 25;

if ($testUser->validate()) {
    echo "    ✓ Manual validation passed\n";
} else {
    echo "    ✗ Manual validation failed\n";
    foreach ($testUser->getValidationErrors() as $attribute => $messages) {
        foreach ($messages as $message) {
            echo "    - {$attribute}: {$message}\n";
        }
    }
}

// Check validation errors for specific attribute
$userWithErrors = new User();
$userWithErrors->name = 'A'; // Too short
$userWithErrors->validate();
$nameErrors = $userWithErrors->getValidationErrorsForAttribute('name');
if (!empty($nameErrors)) {
    echo "    Name validation errors: " . implode(', ', $nameErrors) . "\n";
}

echo "\n";

echo "=== All examples completed successfully ===\n";
