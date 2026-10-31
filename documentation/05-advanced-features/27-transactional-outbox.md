# Transactional outbox

Publish domain events reliably: write business data and an outbox row in the **same** database transaction, then process pending rows with your own transport (HTTP, RabbitMQ, Kafka, etc.).

PDOdb handles storage, claiming, retries, multi-worker safety on supported dialects, CLI worker entrypoint, and retention purge. It does **not** ship a message broker adapter — you provide a small PHP publisher file.

## Schema

Create the outbox table in a migration using dialect-specific DDL from `Outbox::getSchemaSql()` or `$db->schema()->getDialect()->buildOutboxSchemaSql('pdodb_outbox')`.

For local bootstrap only, `pdodb outbox work --ensure-schema` can create the table (not recommended for production).

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

| Dialect   | Concurrent workers (`SKIP LOCKED` / `READPAST`) |
|-----------|--------------------------------------------------|
| PostgreSQL | Yes                                             |
| MySQL / MariaDB | Yes (8.0+ / 10.6+)                        |
| SQL Server | Yes                                             |
| SQLite     | Single-worker claim in one transaction          |
| Oracle     | Single-worker claim in one transaction          |

Stale `processing` rows (expired `locked_until`) return to `pending` before the next claim.

## Related

- [CLI tools](21-cli-tools.md#outbox) — full command reference
- `examples/12-reliability/03-transactional-outbox.php` — in-process worker
- `examples/12-reliability/04-outbox-cli.php` — `pdodb outbox work --once`
