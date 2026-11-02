# Transactional outbox

Publish domain events reliably: write business data and an outbox row in the **same** database transaction, then process pending rows with your own transport (HTTP, RabbitMQ, Kafka, etc.).

PDOdb handles storage, claiming, retries, multi-worker safety on supported dialects, CLI worker entrypoint, and retention purge. It does **not** ship a message broker adapter — you provide a small PHP publisher file.

## Schema

Create the outbox table in a migration using dialect-specific DDL from `Outbox::getSchemaSql()` or `$db->schema()->getDialect()->buildOutboxSchemaSql('pdodb_outbox')`.

For local bootstrap only, `pdodb outbox work --ensure-schema` can create the table (not recommended for production).

## Writing events

`Outbox::add()` must run inside an active transaction on the same `PdoDb` instance as your business writes. The insert does not call `lastInsertId()`, so a UUID primary key cannot abort the surrounding PostgreSQL transaction.

`OutboxWorker::runOnce()` refuses to start when that connection already has an open transaction. The publish callback must not observe a row that a later rollback can undo.

```php
use tommyknocker\pdodb\outbox\Outbox;

$outbox = new Outbox($db);

$db->transaction(function () use ($db, $outbox): void {
    $db->find()->table('orders')->insert(['status' => 'new']);
    $outbox->add('order.created', ['order_id' => 123]);
});
```

Nested `transaction()` calls use savepoints, so an inner block can call `add()` without committing the outer unit of work early.

To hold a message back, pass a delay in seconds: `$outbox->add('reminder.due', ['id' => 7], delaySeconds: 3600)`. A worker will not claim it before then.

### Headers

Information about a message that is not part of its payload (a trace id, a tenant, a schema version) goes in headers.
The publisher reads it as `$message->headers`:

```php
$outbox->add('order.created', ['order_id' => 123], headers: ['trace_id' => $traceId, 'tenant' => 7]);

// in the publisher
$broker->send($message->type, $message->payload, $message->headers);
```

Header names must be non-empty strings, and the headers must fit in 4000 bytes as JSON.

### Ordering by partition key

Workers claim messages in batches and several workers run at once, so by default there is no order between messages.
Give related messages the same partition key to get one:

```php
$db->transaction(function () use ($outbox, $orderId): void {
    $outbox->add('order.created', [...], partitionKey: "order-{$orderId}");
    $outbox->add('order.paid', [...], partitionKey: "order-{$orderId}");
});
```

Messages with the same key are delivered one at a time, in the order they were added: `order.paid` is not claimed while
`order.created` is waiting, being delivered, or waiting for a retry. Messages with different keys, or none, are not held
back by each other. The key is at most 190 bytes.

What this does not promise:
- A message that failed for good (`failed`) does not hold its key back, so later messages for the key go on. Requeueing
  it puts it first in line again.
- The order is the order in which ids were generated. Two transactions that overlap can commit in the opposite order;
  a message added by the transaction that commits later is delivered after, even if its id is smaller. Keep messages
  of one key in one transaction, or accept that edge.
- A retry waits out its backoff while later messages of the key wait too. Make the publisher fail permanently
  (`OutboxPermanentFailureException`) for messages that can never be delivered.

### Payload serializer

The payload is stored as JSON. To wrap it, for example in an envelope that carries a schema version or an encrypted
body, pass an `OutboxSerializer` (its output must be valid JSON, because the payload column is JSON on PostgreSQL):

```php
$outbox = new Outbox($db, 'pdodb_outbox', new MyEnvelopeSerializer());
```

Use the same serializer for the workers that read the table.

## Events

The outbox sends PSR-14 events to the connection's event dispatcher (see [Events](22-events.md)):

| Event | When |
|---|---|
| `OutboxMessageAddedEvent` | after `add()` inserted the row, still inside the caller's transaction |
| `OutboxMessagePublishedEvent` | after the worker recorded the delivery |
| `OutboxMessageRetryEvent` | after a retry was scheduled (error and delay available) |
| `OutboxMessageFailedEvent` | after the message was marked failed |

`OutboxMessageAddedEvent` fires before the transaction commits: the message does not exist for workers until then and
vanishes on rollback. Use it for metrics and tracing, not as proof that anything was sent.

## Publisher file

Create a PHP file that **returns** a callable. The CLI and your own bootstrap can load it via `OutboxPublisherLoader::load()`:

```php
<?php

use tommyknocker\pdodb\exceptions\OutboxPermanentFailureException;
use tommyknocker\pdodb\exceptions\OutboxRetryableException;
use tommyknocker\pdodb\outbox\OutboxMessage;

return function (OutboxMessage $message): void {
    $ok = publishToBroker($message->type, $message->payload);
    if (!$ok) {
        throw new OutboxRetryableException('Broker unavailable');
    }
};
```

A publisher receives `$message->id`, `type`, `payload`, `attempts`, `headers` and `partitionKey`.

See `examples/12-reliability/outbox/publisher.php` for a minimal example.

## CLI worker

```bash
# Cron: one batch per invocation
vendor/bin/pdodb outbox work --once --publisher=config/outbox-publisher.php

# Long-running process
vendor/bin/pdodb outbox work --publisher=config/outbox-publisher.php --sleep=2

# Environment alternatives
export PDODB_OUTBOX_PUBLISHER=config/outbox-publisher.php
export PDODB_OUTBOX_TABLE=pdodb_outbox
vendor/bin/pdodb outbox work --once
```

Options:

| Option | Purpose |
|--------|---------|
| `--publisher=PATH` | Required unless `PDODB_OUTBOX_PUBLISHER` is set |
| `--once` | Single batch (recommended for cron) |
| `--batch=N` | Rows per batch (default 10) |
| `--lock=N` | Processing lock TTL seconds (default 120) |
| `--max-attempts=N` | Fail permanently after N attempts (default 25) |
| `--sleep=N` | Idle sleep when queue empty (default 2) |
| `--max-runtime=N` | Stop after N seconds, so a supervisor can restart the worker (default: run until stopped) |
| `--worker-id=ID` | Prefix for `locked_by` (default hostname) |
| `--table=NAME` | Table name (default `pdodb_outbox`) |
| `--ensure-schema` | Create table if missing (dev only) |

Processing rules:

- **Claim** takes the whole batch in one short transaction (stale lock release, up to `--batch` rows → `processing`, oldest first). All rows of a batch share one lock token.
- **Publisher** runs **after** the claim transaction commits.
- **Published / retry / failed** updates use separate short transactions guarded by the lock token.

Throw `OutboxRetryableException` for transient errors (exponential backoff). Throw `OutboxPermanentFailureException` for poison messages. Any other exception from the publisher, including a deadlock or other transient database error, schedules a retry with the same backoff instead of leaving the row locked until the lease expires.

A claimed row whose stored payload cannot be decoded into an array is marked `failed` (`last_error` starts with `Unreadable payload`) in the claim transaction and counted in `markedFailed`; it does not block the rest of the batch. The publisher must not leave a transaction open on the outbox connection: the worker throws `OutboxException` before it records the result.

## Purge (retention)

```bash
# Delete published rows older than 7 days
vendor/bin/pdodb outbox purge --older-than=7d

# Preview
vendor/bin/pdodb outbox purge --older-than=7d --dry-run

# Remove old failed rows explicitly
vendor/bin/pdodb outbox purge --older-than=30d --status=failed
```

Only `published` (default) or `failed` may be purged. Pending and processing rows are never deleted by this command.

## Inspect and requeue failed rows

When a message is marked `failed`, fix the publisher or payload and put it back on the queue instead of deleting it.

```bash
# Counts by status
vendor/bin/pdodb outbox status

# Failed rows (ops)
vendor/bin/pdodb outbox list --status=failed --limit=20

# Requeue one failed message, then run the worker again
vendor/bin/pdodb outbox retry --id=11111111-1111-4111-8111-111111111111
vendor/bin/pdodb outbox work --once --publisher=config/outbox-publisher.php

# Requeue many failed rows
vendor/bin/pdodb outbox retry --all-failed --limit=100 --dry-run
```

Programmatic API:

```php
use tommyknocker\pdodb\outbox\OutboxStatuses;

$counts = $outbox->countByStatus();
$failed = $outbox->listMessages(OutboxStatuses::FAILED, 50);
$outbox->requeueFailed($id);
$outbox->requeueAllFailed(limit: 100, resetAttempts: true, dryRun: false);
```

`retry` sets `status` back to `pending`, clears locks and `published_at`, and resets `attempts` unless `--keep-attempts` is passed. `last_error` is kept so you can see the previous failure until the next attempt.

## Programmatic worker

You can call the worker without the CLI:

```php
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;

$outbox = new Outbox($db);
$result = $outbox->worker(workerId: 'api-1', batchSize: 20)->runOnce(
    function (OutboxMessage $message): void {
        // transport
    }
);
```

## Minimum server versions

The outbox claims rows with `SKIP LOCKED` / `READPAST` and the outbox and inbox tables use expression defaults and `RETURNING`, so they need newer servers than the rest of the library:

| Database | Minimum for outbox and inbox | Why |
|----------|------------------------------|-----|
| MySQL | 8.0.13 | `SKIP LOCKED` (8.0.1) and expression defaults such as `DEFAULT (UTC_TIMESTAMP(6))` (8.0.13) |
| MariaDB | 10.6 | `SKIP LOCKED` (expression defaults need only 10.2.1) |
| PostgreSQL | 9.5 | `SKIP LOCKED`; the payload column is `JSONB` (9.4) |
| SQL Server | 2019 | `READPAST` with `OUTPUT` |
| Oracle | 12c | `FOR UPDATE SKIP LOCKED` |
| SQLite | 3.38 | `UPDATE ... RETURNING` needs 3.35; 3.38 is the library baseline |

On MySQL 5.7 and MariaDB before 10.6 the claim statement fails with a syntax error. CI runs MySQL 8.0, MariaDB 11.2, PostgreSQL 16, SQL Server 2022 and Oracle XE 21; the other versions in the table are the documented minimums, not tested ones.

## Dialect notes

Schema creation, enqueue inside a transaction, and a single worker publish are covered for SQLite, MySQL, MariaDB, PostgreSQL, SQL Server, and Oracle.

| Dialect | Claim |
|---------|--------|
| PostgreSQL | One `UPDATE ... RETURNING` over a `SKIP LOCKED` subquery |
| MySQL / MariaDB | One `UPDATE` over a `SKIP LOCKED` subquery, then a read by the batch token |
| SQL Server | One `UPDATE ... OUTPUT` over a `TOP (n)` `READPAST` CTE |
| SQLite | One `UPDATE ... RETURNING` (single writer) |
| Oracle | `FOR UPDATE SKIP LOCKED` cannot be combined with a row limit, so candidate ids are locked one by one inside the batch transaction |

Each dialect supplies its claim as an `OutboxClaimStrategy` returned by `DialectInterface::outboxClaimStrategy()`. Ordering is oldest `available_at` first within a batch; it is not guaranteed across concurrent workers.

Integration tests use several independent `PdoDb` connections per dialect (MySQL, MariaDB, PostgreSQL, SQL Server, Oracle) to assert that many pending rows are published exactly once under concurrent workers. SQLite is validated with a single connection (one worker per process).

All outbox timestamps (`created_at`, `available_at`, `locked_until`, `published_at`) and every lease, backoff and `purge` comparison use the database clock in UTC, never the clock of the PHP host, so workers with skewed clocks cannot steal each other's locks. The columns use native types: `TIMESTAMPTZ` on PostgreSQL, `DATETIME2` on SQL Server, `DATETIME(6)` on MySQL and MariaDB, `TIMESTAMP(6)` on Oracle. SQLite has no datetime type and stores `YYYY-MM-DD HH:MM:SS` text in UTC. Write values only through the library (or in UTC), never with `NOW()`, which follows the session time zone.

Stale `processing` rows (expired `locked_until`) return to `pending` once per claimed batch. A message that is claimed again after its last allowed attempt ended without confirmation (worker crash or lock expiry, `attempts` above `--max-attempts`) is marked `failed` without being published, so a message that crashes workers cannot be redelivered forever. Because `attempts` is counted when a message is claimed, `retry --keep-attempts` on an exhausted message fails it again on the next claim. `runOnce()` throws if its connection is already inside a transaction. A lost lock after publish is counted as `unconfirmed` and is not marked published; the row stays `processing` until the lock expires and another worker sends it again (at least once).

Deadlocks and other retryable transaction errors are retried a few times inside the worker and by `pdodb outbox work`. The command keeps running after a transient error instead of exiting. On Oracle, claim locks the chosen pending row with `FOR UPDATE SKIP LOCKED` before updating it, so a second worker takes a different row.

## Model saves and the inbox

When `Model::transactional()` includes the operation, `save()` and `delete()` already hold a transaction through `afterSave`. Call `Outbox::add()` there, or in the same outer `transaction()` callback. Call `Model::afterCommit()` only for work that must wait until the commit succeeds.

Consumers that need at-least-once delivery to become once-only should record the message id with [Inbox](28-inbox.md).

## Related

- [CLI tools](21-cli-tools.md#outbox) — full command reference
- `examples/12-reliability/03-transactional-outbox.php` — in-process worker
- `examples/12-reliability/04-outbox-cli.php` — `pdodb outbox work --once`
