<?php

declare(strict_types=1);

/**
 * Outbox and inbox end to end: two services, a broker that fails and repeats itself.
 *
 *   Shop service     writes an order and its event in ONE transaction (outbox)
 *   Worker           moves events from the outbox to the broker, retrying, one order at a time
 *   Broker           here a small in-memory queue; it is down once and delivers one message twice
 *   Billing service  applies each message exactly once (inbox), whatever the broker does
 *
 * In production the two services have their own databases. One database is used here so the example runs
 * everywhere; the tables are named per service. See documentation/10-cookbook/06-reliable-events-outbox-inbox.md.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\exceptions\OutboxPermanentFailureException;
use tommyknocker\pdodb\exceptions\OutboxRetryableException;
use tommyknocker\pdodb\helpers\Uuid;
use tommyknocker\pdodb\inbox\Inbox;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;
use tommyknocker\pdodb\outbox\OutboxStatuses;

$db = createExampleDb();
$driver = getCurrentDriver($db);
$schema = $db->schema();

echo "=== Outbox and inbox end to end (on {$driver}) ===\n\n";

function check(bool $condition, string $what): void
{
    if (!$condition) {
        fwrite(STDERR, "FAILED: {$what}\n");
        exit(1);
    }
    echo "  ok: {$what}\n";
}

// ---------------------------------------------------------------- the two services' tables
foreach (['billing_charges', 'shop_orders'] as $table) {
    $schema->dropTableIfExists($table);
}
$schema->dropTableIfExists('shop_outbox');
$schema->dropTableIfExists('billing_inbox');

// Orders get an application-assigned id (a UUID): it goes into the event, so it must be known before the insert
$schema->createTable('shop_orders', [
    'id' => $schema->string(36)->notNull()->unique(),
    'status' => $schema->string(32)->notNull(),
    'amount' => $schema->integer()->notNull(),
]);
$schema->createTable('billing_charges', [
    'id' => $schema->primaryKey(),
    'order_id' => $schema->string(36)->notNull(),
    'amount' => $schema->integer()->notNull(),
]);

$outbox = new Outbox($db, 'shop_outbox');
$outbox->ensureSchema();
$inbox = new Inbox($db, 'billing', 'billing_inbox');
$inbox->ensureSchema();

// ---------------------------------------------------------------- the broker (stand-in for RabbitMQ, SQS, Kafka, HTTP...)
$broker = new class () {
    /** @var list<array{id: string, type: string, payload: array<string, mixed>, headers: array<string, mixed>}> */
    public array $queue = [];

    /** How many sends fail before the broker recovers */
    public int $down = 0;

    /** @var list<string> Message types the broker can route */
    public array $routes = ['order.created', 'order.cancelled'];

    public function send(OutboxMessage $message): void
    {
        if ($this->down > 0) {
            $this->down--;

            throw new OutboxRetryableException('broker unavailable');
        }
        if (!in_array($message->type, $this->routes, true)) {
            throw new OutboxPermanentFailureException("no route for {$message->type}");
        }
        $this->queue[] = ['id' => $message->id, 'type' => $message->type, 'payload' => $message->payload, 'headers' => $message->headers];
    }
};

$publish = static function (OutboxMessage $message) use ($broker): void {
    $broker->send($message);
};

// ---------------------------------------------------------------- the billing service
$applied = [];
$consume = static function (array $delivery) use ($db, $inbox, &$applied): bool {
    return $inbox->once($delivery['id'], function () use ($db, $delivery, &$applied): void {
        $orderId = (string)$delivery['payload']['order_id'];
        if ($delivery['type'] === 'order.created') {
            $db->find()->table('billing_charges')->insert(['order_id' => $orderId, 'amount' => (int)$delivery['payload']['amount']]);
        } else {
            $db->find()->table('billing_charges')->where('order_id', $orderId)->delete(); // refund
        }
        $applied[] = $delivery['type'] . ' #' . $orderId;
    });
};

$drain = static function () use ($broker, $consume): array {
    $results = [];
    while ($delivery = array_shift($broker->queue)) {
        $results[] = $consume($delivery);
    }

    return $results;
};

$charged = static fn (): array => array_map('strval', array_column(
    $db->find()->table('billing_charges')->select(['order_id'])->orderBy('order_id')->get(),
    'order_id'
));

// ================================================================ 1. the event is part of the order's transaction
echo "1. The event commits with the order, or not at all\n";

$placeOrder = static function (int $amount, bool $fail = false) use ($db, $outbox): string {
    return $db->transaction(function () use ($db, $outbox, $amount, $fail): string {
        $id = Uuid::v4();
        $db->find()->table('shop_orders')->insert(['id' => $id, 'status' => 'new', 'amount' => $amount]);
        // the partition key keeps the events of one order in order
        $outbox->add('order.created', ['order_id' => $id, 'amount' => $amount], headers: ['source' => 'shop'], partitionKey: "order-{$id}");
        if ($fail) {
            throw new RuntimeException('payment form rejected');
        }

        return $id;
    });
};

$first = $placeOrder(100);
$second = $placeOrder(250);
try {
    $placeOrder(999, fail: true);
} catch (RuntimeException) {
    // the order and its event were rolled back together
}
check(!$db->find()->table('shop_orders')->where('amount', 999)->exists(), 'the rejected order was rolled back and left no order');
check($outbox->countByStatus()->toMap()[OutboxStatuses::PENDING] === 2, 'and no event: two events are waiting (the two real orders)');

$db->transaction(function () use ($db, $outbox, $first): void {
    $db->find()->table('shop_orders')->where('id', $first)->update(['status' => 'cancelled']);
    $outbox->add('order.cancelled', ['order_id' => $first], partitionKey: "order-{$first}");
});

// ================================================================ 2. the broker is down: retry, and keep the order
echo "\n2. The broker is down for the first send\n";

$broker->down = 1;
$worker = $outbox->worker('example-worker', batchSize: 10);

$result = $worker->runOnce($publish);
check($result->scheduledRetry === 1, 'first order: created -> retry scheduled with a delay');
check($result->published === 1, 'second order: created -> published (a different order is not held back)');
$held = $worker->runOnce($publish);
check($held->claimed === 0, 'first order: cancelled waits, so it cannot overtake created');

// Skip the backoff instead of waiting for it
$db->find()->table('shop_outbox')->where('status', OutboxStatuses::PENDING)->update(['available_at' => gmdate('Y-m-d H:i:s', time() - 5)]);

$worker->runOnce($publish);  // created (first order)
$worker->runOnce($publish);  // cancelled (first order), now that created is done
check(array_map(static fn (array $d): string => $d['type'] . ' ' . ($d['payload']['order_id'] === $first ? 'first' : 'second'), $broker->queue) === [
    'order.created second', 'order.created first', 'order.cancelled first',
], 'the broker received the first order in order: created, then cancelled');

// ================================================================ 3. the broker repeats a message
echo "\n3. The broker delivers a message twice\n";

$stale = $broker->queue[1];             // order.created of the first order, already delivered once
$broker->queue[] = $stale;              // at-least-once delivery: it comes back after the cancellation
$results = $drain();
check($results === [true, true, true, false], 'billing applied three messages and recognized the fourth as a repeat');
check($charged() === [$second], 'the first order stays refunded; without the inbox the repeat would have charged it again');

// ================================================================ 4. a message the broker cannot route
echo "\n4. A message that can never be delivered\n";

$db->transaction(function () use ($outbox, $second): void {
    $outbox->add('order.archived', ['order_id' => $second], partitionKey: "order-{$second}");
});
$result = $worker->runOnce($publish);
check($result->markedFailed === 1, 'no route: the message is marked failed, not retried forever');
$failed = $outbox->listMessages(OutboxStatuses::FAILED, 10);
check(count($failed) === 1 && str_contains((string)$failed[0]->lastError, 'no route'), 'the error says why: ' . ($failed[0]->lastError ?? ''));

$broker->routes[] = 'order.archived';  // the fix: the broker learns the route
$outbox->requeueFailed($failed[0]->id);
$result = $worker->runOnce($publish);
check($result->published === 1, 'after the fix and requeue it is published');
$drain();

// ================================================================ 5. where everything ended up
echo "\n5. State\n";

$counts = $outbox->countByStatus()->toMap();
check($counts[OutboxStatuses::PUBLISHED] === 4 && $counts[OutboxStatuses::PENDING] === 0 && $counts[OutboxStatuses::FAILED] === 0, 'outbox: 4 published, nothing waiting, nothing failed');
check($inbox->processed($stale['id']), 'inbox remembers the repeated message id');
check($inbox->purge('30d', dryRun: true) === 0, 'retention: nothing is older than 30 days yet');

echo "\nDone.\n";
