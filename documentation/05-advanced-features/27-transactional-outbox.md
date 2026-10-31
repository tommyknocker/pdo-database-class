# Transactional outbox

Publish domain events reliably: write business data and an outbox row in the **same** database transaction, then process pending rows in a worker with your own transport (HTTP, RabbitMQ, Kafka, etc.).

PDOdb handles storage, claiming, retries, and multi-worker safety on supported dialects. It does **not** ship a message broker or a long-running CLI worker yet (those come later).

## Schema

Create the outbox table in a migration using dialect-specific DDL from `Outbox::getSchemaSql()` or `$db->schema()->getDialect()->buildOutboxSchemaSql('pdodb_outbox')`.

## Writing events

`Outbox::add()` must run inside an active transaction on the same `PdoDb` instance as your business writes:

```php
use tommyknocker\pdodb\outbox\Outbox;

$outbox = new Outbox($db);

$db->transaction(function () use ($db, $outbox): void {
    $db->find()->table('orders')->insert(['status' => 'new']);
    $outbox->add('order.created', ['order_id' => 123]);
});
```

Nested `transaction()` calls use savepoints, so an inner block can call `add()` without committing the outer unit of work early.

## Processing

Run a worker loop in your application process (cron, queue consumer bootstrap, etc.):

```php
use tommyknocker\pdodb\exceptions\OutboxPermanentFailureException;
use tommyknocker\pdodb\exceptions\OutboxRetryableException;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;

$outbox = new Outbox($db);
$worker = $outbox->worker(workerId: 'api-1', batchSize: 20);

$result = $worker->runOnce(function (OutboxMessage $message): void {
    // Your transport — runs outside any claim transaction
    $ok = publishToBroker($message->type, $message->payload);
    if (!$ok) {
        throw new OutboxRetryableException('Broker unavailable');
    }
});
```

- **Claim** happens in a short transaction (release stale locks, mark row `processing` with `locked_by` / `locked_until`).
- **Publish callback** runs **after** the claim transaction commits.
- **Success / retry / failure** each use a separate short update guarded by the lock token.

Throw `OutboxRetryableException` for transient errors (exponential backoff, capped attempts). Throw `OutboxPermanentFailureException` to mark the row `failed` immediately.

## Dialect notes

| Dialect   | Concurrent workers (`SKIP LOCKED` / `READPAST`) |
|-----------|--------------------------------------------------|
| PostgreSQL | Yes                                             |
| MySQL / MariaDB | Yes (8.0+ / 10.6+)                        |
| SQL Server | Yes                                             |
| SQLite     | Single-worker claim in one transaction          |
| Oracle     | Single-worker claim in one transaction          |

Stale `processing` rows (expired `locked_until`) are returned to `pending` before the next claim.

## Deferred

- `pdodb outbox:work` CLI command
- Built-in broker adapters
- Purge / retention of published rows
