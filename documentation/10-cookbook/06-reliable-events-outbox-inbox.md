# Reliable events between services: outbox, worker and inbox

How to make "save the order, then tell billing about it" safe when the database, the network and the broker can each fail at any moment. This guide builds the whole chain: the producer writes the event with its data, a worker delivers it, and the consumer applies it once.

Reference pages: [Transactional outbox](../05-advanced-features/27-transactional-outbox.md), [Inbox](../05-advanced-features/28-inbox.md), [CLI tools](../05-advanced-features/21-cli-tools.md). A runnable version of the whole story is in `examples/12-reliability/07-outbox-inbox-end-to-end.php`.

## Table of contents

- [The problem](#the-problem)
- [The pieces](#the-pieces)
- [Step 1: write the event in the same transaction](#step-1-write-the-event-in-the-same-transaction)
- [Step 2: write a publisher](#step-2-write-a-publisher)
- [Step 3: run the worker](#step-3-run-the-worker)
- [Step 4: apply each message once](#step-4-apply-each-message-once)
- [Keeping the events of one entity in order](#keeping-the-events-of-one-entity-in-order)
- [What happens when something fails](#what-happens-when-something-fails)
- [Operating it](#operating-it)
- [Testing your integration](#testing-your-integration)
- [Checklist](#checklist)

## The problem

```php
$db->transaction(function () use ($db) {
    $db->find()->table('orders')->insert([...]);
});
$broker->publish('order.created', $order);   // the process dies here, or the broker is down
```

The order is saved and nobody is told. Swap the two lines and the broker may be told about an order that was then rolled back. Two systems cannot commit together, so one of them has to be the source of truth and the other has to catch up.

The outbox makes the database the source of truth: the event is a row written **in the same transaction** as the order. Either both exist or neither does. A separate worker then delivers the rows to the broker, and keeps trying until it succeeds.

That delivery is **at least once**: a message can arrive twice (the worker published it and died before noting that, or the broker repeated it). The receiving side therefore remembers the ids it has applied, which is the inbox. At-least-once delivery plus a receiver that ignores repeats gives the effect of exactly-once.

## The pieces

```
Service A (producer)                                   Service B (consumer)
┌──────────────────────────┐    ┌──────────────┐    ┌──────────────────────────┐
│ one DB transaction:      │    │              │    │ one DB transaction:      │
│   INSERT orders          │    │   broker /   │    │   INSERT inbox (id)      │
│   INSERT outbox (event)  │    │   webhook /  │    │   apply the change       │
└────────────┬─────────────┘    │   queue      │    └────────────▲─────────────┘
             │                  │              │                 │
        ┌────▼─────┐  publish   │              │   deliver       │
        │  worker  ├───────────►│              ├─────────────────┘
        └──────────┘  (retries) └──────────────┘   (may repeat)
```

| Piece | Lives in | Job |
|-------|----------|-----|
| `Outbox::add()` | service A, inside its transaction | records the event next to the data |
| worker (`pdodb outbox work` or `OutboxWorker`) | service A, separate process | claims rows, calls your publisher, retries, marks the result |
| publisher | service A, your code | sends one message to your broker or HTTP endpoint |
| `Inbox::once()` | service B, inside its transaction | applies a message at most once |

PDOdb does not include a broker client. The publisher is a small function that you write (an example for HTTP is included), so the same outbox works with RabbitMQ, SQS, Kafka, Redis, or a plain webhook.

## Step 1: write the event in the same transaction

Create the outbox table in a migration, with the SQL for the connected database:

```php
use tommyknocker\pdodb\migrations\Migration;
use tommyknocker\pdodb\outbox\Outbox;

final class m20261102_000001_create_outbox extends Migration
{
    public function up(): void
    {
        foreach ((new Outbox($this->db))->getSchemaSql() as $sql) {
            $this->execute($sql);
        }
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('pdodb_outbox');
    }
}
```

For a local script `$outbox->ensureSchema()` creates what is missing. Check the [minimum server versions](../05-advanced-features/27-transactional-outbox.md#minimum-server-versions): the outbox needs `SKIP LOCKED` or an equivalent.

Then write the event with the data it describes:

```php
use tommyknocker\pdodb\helpers\Uuid;
use tommyknocker\pdodb\outbox\Outbox;

$outbox = new Outbox($db);

function placeOrder(PdoDb $db, Outbox $outbox, array $input): string
{
    return $db->transaction(function () use ($db, $outbox, $input): string {
        $orderId = Uuid::v4();
        $db->find()->table('orders')->insert(['id' => $orderId, 'total' => $input['total'], 'status' => 'new']);

        $outbox->add(
            'order.created',                                   // what happened
            ['order_id' => $orderId, 'total' => $input['total']],  // data the consumer needs
            headers: ['trace_id' => $input['trace_id'], 'schema' => 1],
            partitionKey: "order-{$orderId}",                  // events of one order stay in order
        );

        return $orderId;
    });
}
```

Points that matter:

- **`add()` must run inside a transaction** on the same `PdoDb` connection as your writes; it throws otherwise. That is the whole guarantee.
- **Never call the broker inside the transaction.** The transaction can still roll back after the call, and a slow broker would hold your locks.
- **Know the id before the insert.** The event refers to the order, so give the order an application-assigned id (a UUID, ULID, or any key you control) instead of relying on an auto-increment value read back after the insert. `insert()` reports no id for a table whose key you supply (a string or UUID key returns `1` on most databases), and an id you choose makes the event independent of the database.
- **Put what the consumer needs into the payload**, so it does not have to call you back. Put information *about* the message (trace id, tenant, schema version) into `headers`; they are delivered to the publisher as `$message->headers` (up to 4000 bytes as JSON).
- **`delaySeconds`** holds a message back: `add('reminder.due', [...], delaySeconds: 3600)`.
- **Large payloads:** store the data and send its id. The payload is stored as JSON in the outbox table.

An event is only visible to the worker once the transaction commits. If the transaction rolls back, the event never existed.

## Step 2: write a publisher

A publisher is a callable that receives one `OutboxMessage` and either returns (delivered) or throws. Put it in a file that returns the callable, so the CLI can load it:

```php
<?php
// config/outbox-publisher.php
use tommyknocker\pdodb\exceptions\OutboxPermanentFailureException;
use tommyknocker\pdodb\exceptions\OutboxRetryableException;
use tommyknocker\pdodb\outbox\OutboxMessage;

return static function (OutboxMessage $message) use ($channel): void {
    try {
        $channel->basic_publish(new AMQPMessage(json_encode($message->payload), [
            'message_id' => $message->id,                    // the consumer's idempotency key
            'type' => $message->type,
            'application_headers' => new AMQPTable($message->headers),
        ]), 'events', $message->type);
    } catch (AMQPConnectionClosedException|AMQPTimeoutException $e) {
        throw new OutboxRetryableException($e->getMessage(), 0, $e);   // try again later
    } catch (AMQPInvalidArgumentException $e) {
        throw new OutboxPermanentFailureException($e->getMessage(), 0, $e);   // will never work
    }
};
```

(The AMQP classes are illustrative; use whatever your client throws. The shape is what matters.)

What the worker does with the outcome:

| Publisher | Result |
|-----------|--------|
| returns | the message is marked `published` |
| throws `OutboxRetryableException` | retry later with exponential backoff (5 s, 10 s, 20 s ... up to 1 h) |
| throws `OutboxPermanentFailureException` | marked `failed` at once, kept for you to fix and requeue |
| throws anything else | treated like a retryable error |
| attempts reach `--max-attempts` (default 25) | marked `failed` |

Always send **`$message->id`** to the other side and make it the consumer's idempotency key. It is stable across retries and unique per message.

### A ready-made webhook publisher and receiver

`examples/12-reliability/outbox/webhook-publisher.php` posts each message as JSON, signed with an HMAC, with `Idempotency-Key` set to the message id and the message headers sent as `X-Event-*`. It maps the HTTP status to the outcomes above: 2xx is delivered, 408/425/429/5xx and no answer are retried, other 4xx are permanent. `examples/12-reliability/outbox/webhook-receiver.php` is the matching endpoint (see [Step 4](#step-4-apply-each-message-once)).

```bash
WEBHOOK_URL=https://billing.internal/events WEBHOOK_SECRET=change-me \
  vendor/bin/pdodb outbox work --publisher=examples/12-reliability/outbox/webhook-publisher.php
```

Copy the file into your project and adapt it; it is about sixty lines.

## Step 3: run the worker

```bash
# One batch, then exit: for cron or a scheduler
vendor/bin/pdodb outbox work --once --publisher=config/outbox-publisher.php

# A long-running process
vendor/bin/pdodb outbox work --publisher=config/outbox-publisher.php --sleep=2 --max-runtime=3600
```

`--max-runtime` makes the process exit cleanly after that many seconds so a supervisor restarts it (this also guards against slow memory growth). SIGTERM and SIGINT stop the loop after the current batch when the `pcntl` extension is available.

### As a service

systemd:

```ini
[Unit]
Description=Outbox worker
After=network-online.target

[Service]
User=app
WorkingDirectory=/srv/app
EnvironmentFile=/etc/app/outbox.env     # PDODB_* connection settings, WEBHOOK_URL, ...
ExecStart=/usr/bin/php vendor/bin/pdodb outbox work --publisher=config/outbox-publisher.php --max-runtime=3600
Restart=always
RestartSec=2
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```

In containers, run the same command as the container's main process (one container per worker) and let the orchestrator restart it. Scheduled one-shot runs (`--once` from cron or a Kubernetes CronJob) also work when a delay of up to a minute is acceptable.

### Tuning

| Option | Default | Notes |
|--------|---------|-------|
| `--batch` | 10 | messages claimed per round trip; raise it when the publisher is fast |
| `--lock` | 120 | seconds a claim is held; must exceed the time the publisher needs for a whole batch, or a slow batch is claimed again by another worker and delivered twice |
| `--max-attempts` | 25 | after this many tries the message is `failed` |
| `--sleep` | 2 | idle wait when the queue is empty |

You can run **several workers** at once. They never claim the same message, and each message is published by one worker at a time. Run more workers for throughput, not for safety.

### From code

When there is no CLI (a framework console command, a queue consumer), call the worker directly:

```php
$worker = $outbox->worker(workerId: gethostname() . ':' . getmypid(), batchSize: 20);
$publish = require __DIR__ . '/config/outbox-publisher.php';

while (!$shouldStop()) {
    $result = $worker->runOnce($publish);        // claimed, published, scheduledRetry, markedFailed, unconfirmed
    if ($result->claimed === 0) {
        sleep(2);
    }
}
```

`runOnce()` throws if the connection is inside an open transaction: the result of publishing must not be undone by a later rollback.

## Step 4: apply each message once

The consumer keeps one row per message id it has handled. `Inbox::once()` claims the id and runs your handler in **one transaction**:

```php
use tommyknocker\pdodb\inbox\Inbox;

$inbox = new Inbox($db, consumer: 'billing');   // one name per independent handler

function onOrderCreated(PdoDb $db, Inbox $inbox, string $messageId, array $payload): void
{
    $applied = $inbox->once($messageId, function () use ($db, $payload): void {
        $db->find()->table('charges')->insert([
            'order_id' => $payload['order_id'],
            'amount' => $payload['total'],
        ]);
    });

    // $applied is false when this id was handled before: nothing ran, acknowledge the message anyway
}
```

Because the claim and the handler's writes commit together:

- a crash in the middle leaves neither, so the redelivery runs the handler from scratch;
- a repeat finds the id, runs nothing, and returns `false`;
- the handler's database work must use the same `PdoDb` connection.

**Acknowledge the message to the broker after `once()` returns**, whether it returned `true` or `false`. If the process dies before the acknowledgement, the broker redelivers and the inbox absorbs it.

Where the id comes from: the outbox message id, sent by your publisher. With RabbitMQ that is the `message_id` property, with HTTP the `Idempotency-Key` header, with SQS a message attribute. Ids up to 190 characters fit.

### A webhook receiver

`examples/12-reliability/outbox/webhook-receiver.php` shows the HTTP side. The essential part is:

```php
$applied = $inbox->once($_SERVER['HTTP_IDEMPOTENCY_KEY'], function () use ($db, $event): void {
    // apply the change
});
http_response_code(200);             // applied now, or applied before: either way the sender can stop
```

Answer 2xx for both a new message and a repeat, 4xx for a request that is wrong and will stay wrong (the sender then stops retrying and marks it failed), and 5xx or 503 when you could not process it and the sender should try again. Verify the signature before touching the database.

## Keeping the events of one entity in order

Several workers and retries mean messages can overtake each other. When the order matters (an order must be `created` before it is `cancelled`), give them the same **partition key**:

```php
$outbox->add('order.created',   [...], partitionKey: "order-{$id}");
$outbox->add('order.cancelled', [...], partitionKey: "order-{$id}");
```

Messages with the same key are delivered **one at a time, in the order they were added**. `order.cancelled` is not claimed while `order.created` is waiting, being delivered, or waiting for a retry. Messages with different keys (and messages without a key) do not wait for each other.

Limits to know:

- A message that failed for good (`failed`) does not hold its key back. Later messages go on, and a requeued message goes to the front again.
- Order follows the order in which ids were generated. Two overlapping transactions can commit in the opposite order; keep the events of one entity in one transaction, or accept that edge.
- A retry waits out its backoff and later messages of the key wait with it. Throw `OutboxPermanentFailureException` for messages that can never be delivered, so they do not block their key.
- The consumer still has to be idempotent. Ordering stops the sender from reordering; a repeated old message can still arrive after a newer one, which is exactly what the inbox absorbs. (`examples/12-reliability/07-outbox-inbox-end-to-end.php` shows a stale repeat that would charge a refunded order again without it.)

## What happens when something fails

| Failure | Result |
|---------|--------|
| The order transaction rolls back | the event is rolled back with it; nothing is sent |
| The process dies after commit, before any delivery | the row is `pending`; the worker sends it when it runs |
| The broker is down | the publisher throws a retryable error; the message is retried with backoff and keeps its place |
| The worker dies after the broker accepted the message, before it recorded that | the claim expires after `--lock` seconds and the message is delivered again; **the inbox absorbs the repeat** |
| The worker dies mid-batch | its claims expire and other workers take the messages |
| The broker delivers a message twice | the second `Inbox::once()` returns `false` |
| The consumer dies inside its handler | the transaction rolls back; the redelivery runs the handler again |
| A message can never be delivered (no route, 4xx) | marked `failed` and kept; fix the cause, then `pdodb outbox retry` |
| A payload cannot be read (bad JSON, your serializer refuses it) | that message is marked `failed`; the rest of the batch is processed |
| The database restarts or a deadlock occurs | transient errors are retried; the worker does not lose its place |

Nothing is lost, and nothing is applied twice, provided the publisher sends the message id and the consumer uses `Inbox::once()` with it.

## Operating it

### Watch the queue

```bash
vendor/bin/pdodb outbox status                      # pending / processing / published / failed
vendor/bin/pdodb outbox list --status=failed --limit=20
vendor/bin/pdodb outbox status --format=json        # for scripts
```

From code, `$outbox->countByStatus()->toMap()` and `$outbox->listMessages(OutboxStatuses::FAILED, 50)`.

Alert on two things: `failed` greater than zero, and the age of the oldest `pending` message growing (the worker is stopped or cannot keep up):

```sql
SELECT MIN(created_at) FROM pdodb_outbox WHERE status = 'pending';   -- timestamps are UTC
```

For events as they happen, register a PSR-14 dispatcher with `$db->setEventDispatcher(...)`. The outbox sends `OutboxMessageAddedEvent` (inside the producer's transaction, before commit), `OutboxMessagePublishedEvent`, `OutboxMessageRetryEvent` (with the error and the delay) and `OutboxMessageFailedEvent`:

```php
$db->setEventDispatcher(new class implements Psr\EventDispatcher\EventDispatcherInterface {
    public function dispatch(object $event): object
    {
        match (true) {
            $event instanceof OutboxMessageRetryEvent => metrics()->increment('outbox.retry', ['type' => $event->getMessage()->type]),
            $event instanceof OutboxMessageFailedEvent => alert("outbox message failed: {$event->getError()}"),
            default => null,
        };

        return $event;
    }
});
```

`OutboxMessageAddedEvent` fires before the transaction commits and vanishes with a rollback: use it for metrics, not as proof of delivery.

### Fix failures

```bash
vendor/bin/pdodb outbox list --status=failed
# fix the publisher, the route, or the data, then:
vendor/bin/pdodb outbox retry --id=<message id>
vendor/bin/pdodb outbox retry --all-failed --limit=100 --dry-run     # preview a bulk requeue
```

### Keep the tables small

Both tables grow with traffic. Delete what you no longer need on a schedule:

```bash
# producer: delivered messages older than 7 days
vendor/bin/pdodb outbox purge --older-than=7d
# consumer: processed ids older than the longest redelivery you can get
vendor/bin/pdodb inbox purge --older-than=30d
```

The inbox retention is a trade-off: once an id is purged, a redelivery of that message is applied again. Choose a period longer than the longest time your broker can redeliver (queue retention, dead-letter replays, manual replays). `--dry-run` shows what would go.

## Testing your integration

You do not need a broker to test the chain. Replace the publisher with a function that appends to an array and call `$worker->runOnce()`, then feed the array to your consumer, twice if you want to test repeats. `examples/12-reliability/07-outbox-inbox-end-to-end.php` does exactly that and also covers:

- an event that rolls back with its transaction;
- a broker that is down for the first send (retry with the order preserved);
- a message the broker delivers twice;
- a message that can never be routed, then fixed and requeued.

Run it on any supported database:

```bash
PDODB_DRIVER=sqlite php examples/12-reliability/07-outbox-inbox-end-to-end.php
```

In your own tests, skip the retry delay by moving the due time into the past instead of sleeping:

```php
$db->find()->table('pdodb_outbox')->where('status', 'pending')->update(['available_at' => gmdate('Y-m-d H:i:s', time() - 5)]);
```

## Checklist

- [ ] `Outbox::add()` is called inside the same transaction that changes the data, and nothing talks to the broker in that transaction.
- [ ] The publisher sends `$message->id`, and maps your transport's errors to `OutboxRetryableException` or `OutboxPermanentFailureException`.
- [ ] The worker runs under a supervisor (or on a schedule), and `--lock` is longer than a batch takes.
- [ ] The consumer calls `Inbox::once()` with that id and acknowledges after it returns.
- [ ] Events that must stay in order share a `partitionKey`.
- [ ] You watch `failed` and the age of the oldest `pending` message.
- [ ] `outbox purge` and `inbox purge` run on a schedule, and the inbox retention exceeds your broker's redelivery window.
- [ ] You know how to requeue a failed message (`pdodb outbox retry`).

## Related

- [Transactional outbox](../05-advanced-features/27-transactional-outbox.md): schema, options, dialect details
- [Inbox](../05-advanced-features/28-inbox.md)
- [Events](../05-advanced-features/22-events.md)
- [CLI tools](../05-advanced-features/21-cli-tools.md)
- Examples: `examples/12-reliability/03-transactional-outbox.php`, `04-outbox-cli.php`, `05-outbox-retry.php`, `07-outbox-inbox-end-to-end.php`
