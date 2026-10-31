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
| `--worker-id=ID` | Prefix for `locked_by` (default hostname) |
| `--table=NAME` | Table name (default `pdodb_outbox`) |
| `--ensure-schema` | Create table if missing (dev only) |

Processing rules:

- **Claim** runs in a short transaction (stale lock release, row → `processing`).
- **Publisher** runs **after** the claim transaction commits.
- **Published / retry / failed** updates use separate short transactions guarded by the lock token.

Throw `OutboxRetryableException` for transient errors (exponential backoff). Throw `OutboxPermanentFailureException` for poison messages.

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

## Dialect notes

Schema creation, enqueue inside a transaction, and a single worker publish are covered for SQLite, MySQL, MariaDB, PostgreSQL, SQL Server, and Oracle.

| Dialect | Claim |
|---------|--------|
| PostgreSQL | `SKIP LOCKED` and `RETURNING` |
| MySQL / MariaDB | `SKIP LOCKED`; each claimed row gets its own lock token |
| SQL Server | `READPAST` and `OUTPUT`; lock duration is bound as an integer |
| SQLite | One transaction for the batch. Lock expiry compares ISO timestamps with `datetime('now')` |
| Oracle | Locks one pending id with `FOR UPDATE SKIP LOCKED`, then tries the next id |

Integration tests use several independent `PdoDb` connections per dialect (MySQL, MariaDB, PostgreSQL, SQL Server, Oracle) to assert that many pending rows are published exactly once under concurrent workers. SQLite is validated with a single connection (one worker per process).

Stale `processing` rows (expired `locked_until`) return to `pending` before the next claim. `runOnce()` throws if its connection is already inside a transaction. A lost lock after publish is counted as `unconfirmed` and is not marked published; the row stays `processing` until the lock expires and another worker sends it again (at least once).

Deadlocks and other retryable transaction errors are retried a few times inside the worker and by `pdodb outbox work`. The command keeps running after a transient error instead of exiting. On Oracle, claim locks the chosen pending row with `FOR UPDATE SKIP LOCKED` before updating it, so a second worker takes a different row.

## Related

- [CLI tools](21-cli-tools.md#outbox) — full command reference
- `examples/12-reliability/03-transactional-outbox.php` — in-process worker
- `examples/12-reliability/04-outbox-cli.php` — `pdodb outbox work --once`
