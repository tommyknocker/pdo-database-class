<?php

/**
 * Example: PSR-14 Event Dispatcher Integration
 *
 * This example demonstrates how to use the PSR-14 Event Dispatcher
 * to listen to database events like query execution, transactions, and errors.
 *
 * Requires: symfony/event-dispatcher or any other PSR-14 compatible implementation
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\events\ConnectionOpenedEvent;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\events\QueryErrorEvent;
use tommyknocker\pdodb\events\TransactionCommittedEvent;
use tommyknocker\pdodb\events\TransactionRolledBackEvent;
use tommyknocker\pdodb\events\TransactionStartedEvent;

// Simple event dispatcher implementation (or use Symfony EventDispatcher)
class SimpleEventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, array<callable>> */
    protected array $listeners = [];

    /**
     * Register a listener for a specific event class.
     *
     * @param string $eventClass Event class name
     * @param callable $listener Listener callback
     */
    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * Dispatch an event.
     *
     * @param object $event
     *
     * @return object
     */
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

// Create event dispatcher
$dispatcher = new SimpleEventDispatcher();

// Listen to connection opened events
$dispatcher->listen(ConnectionOpenedEvent::class, function (ConnectionOpenedEvent $event) {
    echo sprintf(
        "✓ Connection opened: %s (DSN: %s)\n",
        $event->getDriver(),
        substr($event->getDsn(), 0, 50)
    );
});

// Listen to query executed events
$dispatcher->listen(QueryExecutedEvent::class, function (QueryExecutedEvent $event) {
    $sqlPreview = substr($event->getSql(), 0, 60);
    if (strlen($event->getSql()) > 60) {
        $sqlPreview .= '...';
    }
    echo sprintf(
        "  Query executed: %s (%.2f ms, %d rows, driver: %s)\n",
        $sqlPreview,
        $event->getExecutionTime(),
        $event->getRowsAffected(),
        $event->getDriver()
    );
});

// Listen to transaction events
$dispatcher->listen(TransactionStartedEvent::class, function (TransactionStartedEvent $event) {
    echo sprintf("  → Transaction started on %s\n", $event->getDriver());
});

$dispatcher->listen(TransactionCommittedEvent::class, function (TransactionCommittedEvent $event) {
    echo sprintf(
        "  ✓ Transaction committed on %s (duration: %.2f ms)\n",
        $event->getDriver(),
        $event->getDuration()
    );
});

$dispatcher->listen(TransactionRolledBackEvent::class, function (TransactionRolledBackEvent $event) {
    echo sprintf(
        "  ✗ Transaction rolled back on %s (duration: %.2f ms)\n",
        $event->getDriver(),
        $event->getDuration()
    );
});

// Listen to error events
$dispatcher->listen(QueryErrorEvent::class, function (QueryErrorEvent $event) {
    echo sprintf(
        "  ✗ Query error: %s - %s\n",
        substr($event->getSql(), 0, 40),
        $event->getException()->getMessage()
    );
});

// Create database with event dispatcher
$config = getExampleConfig();
$driver = $config['driver'];
unset($config['driver']);

$db = new PdoDb($driver, $config);
$db->setEventDispatcher($dispatcher);

echo "=== PSR-14 Event Dispatcher Example ===\n\n";

// Create table (will trigger connection opened and query executed events)
echo "1. Creating table...\n";
$driver = getCurrentDriver($db);
if ($driver === 'sqlsrv') {
    $autoIncrement = 'INT IDENTITY(1,1) PRIMARY KEY';
    $timestamp = 'DATETIME DEFAULT GETDATE()';
    $varchar = 'NVARCHAR';
} elseif ($driver === 'mysql' || $driver === 'mariadb') {
    $autoIncrement = 'INT AUTO_INCREMENT PRIMARY KEY';
    $timestamp = 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
    $varchar = 'VARCHAR';
} elseif ($driver === 'pgsql') {
    $autoIncrement = 'SERIAL PRIMARY KEY';
    $timestamp = 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
    $varchar = 'VARCHAR';
} else {
    $autoIncrement = 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $timestamp = 'DATETIME DEFAULT CURRENT_TIMESTAMP';
    $varchar = 'TEXT';
}
$db->rawQuery("DROP TABLE IF EXISTS users");
$db->rawQuery("CREATE TABLE users (
    id $autoIncrement,
    name {$varchar}(255) NOT NULL,
    email {$varchar}(255) NOT NULL,
    created_at $timestamp
)");

// Insert data (will trigger query executed events)
echo "\n2. Inserting data...\n";
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
]);

$db->find()->table('users')->insert([
    'name' => 'Bob',
    'email' => 'bob@example.com',
]);

// Transaction example (will trigger transaction events)
echo "\n3. Transaction example...\n";
$db->startTransaction();
try {
    $db->find()->table('users')->insert([
        'name' => 'Charlie',
        'email' => 'charlie@example.com',
    ]);
    $db->commit();
    echo "  Transaction completed successfully.\n";
} catch (Exception $e) {
    $db->rollback();
    echo "  Transaction rolled back.\n";
}

// Query data
echo "\n4. Querying data...\n";
$users = $db->find()->table('users')->get();
echo sprintf("  Found %d users\n", count($users));

// Error example (will trigger query error event)
echo "\n5. Error example...\n";
try {
    $db->rawQuery('SELECT * FROM nonexistent_table');
} catch (Exception $e) {
    // Error event was already dispatched
}

echo "\n=== Example completed ===\n";

