# Inbox

An outbox delivers a message at least once. The consumer needs its own record of message ids it has already applied. `Inbox` is that record: one row per message id and consumer name.

Create the table from `Inbox::getSchemaSql()` in a migration, or call `ensureSchema()` during local bootstrap.

```php
use tommyknocker\pdodb\inbox\Inbox;

$inbox = new Inbox($db, 'billing');

$applied = $inbox->once($messageId, function (string $id) use ($db): void {
    $db->find()->table('payments')->insert(['message_id' => $id]);
});
```

`once()` inserts the id and then runs the handler in one transaction. The handler's writes therefore commit only when the claim commits. A second delivery hits the primary key, rolls back that savepoint when a transaction is already open, and returns false without running the handler again. `processed()` checks the id without claiming it.

Message ids may be up to 190 characters, so broker ids other than UUIDs fit. `processed_at` is a native datetime column filled by the database clock in UTC (`TIMESTAMPTZ`, `DATETIME2`, `DATETIME(6)`, `TIMESTAMP(6)`; text on SQLite), so it is safe to use for retention cleanup.

Use a distinct consumer name for each independent handler. The same message id can be applied once per consumer.

The duplicate check is the inbox table, not the broker. Keep the handler's database work on the same `PdoDb` connection.
