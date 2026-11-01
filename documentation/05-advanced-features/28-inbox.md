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

## Retention

The table grows by one row per delivered message, so clean it up:

```php
$removed = $inbox->purge('30d');       // delete ids processed more than 30 days ago
$wouldRemove = $inbox->purge('30d', true); // dry run: only count
```

From the command line: `vendor/bin/pdodb inbox purge --older-than=30d [--dry-run] [--table=NAME]` (see [CLI tools](21-cli-tools.md#inbox)), which is the form to put in cron.

Deletion runs in batches of 200 rows so a large backlog is not one long statement. The age is a compromise: once an id is purged, a redelivery of that message is handled again. Choose a period longer than the longest time your broker can redeliver (retention of the queue, dead-letter replays, manual replays). The purge applies to every consumer that shares the table.

See [Minimum server versions](27-transactional-outbox.md#minimum-server-versions) for the database versions the inbox needs.
