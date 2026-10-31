<?php

declare(strict_types=1);

/**
 * Transactional outbox: enqueue an event in the same transaction as a business write,
 * then process it with a custom publisher callback.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;
use tommyknocker\pdodb\outbox\OutboxStatuses;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Transactional outbox (on {$driver}) ===\n\n";

recreateTable($db, 'example_orders', [
    'id' => $db->schema()->primaryKey(),
    'status' => $db->schema()->string(32)->notNull(),
]);

$table = 'example_outbox';
$db->rawQuery('DROP TABLE IF EXISTS ' . $table);

$outbox = new Outbox($db, $table);
foreach ($outbox->getSchemaSql() as $sql) {
    $db->rawQuery($sql);
}

$db->transaction(function () use ($db, $outbox): void {
    $db->find()->table('example_orders')->insert(['status' => 'new']);
    $messageId = $outbox->add('order.created', ['status' => 'new']);
    echo "Enqueued outbox message {$messageId}\n";
});

$publishedTypes = [];
$result = $outbox->worker('example')->runOnce(function (OutboxMessage $message) use (&$publishedTypes): void {
    $publishedTypes[] = $message->type;
    echo "Published {$message->type}: " . json_encode($message->payload, JSON_THROW_ON_ERROR) . "\n";
});

echo "Worker: claimed={$result->claimed}, published={$result->published}\n";

$status = $db->find()->table($table)->select(['status'])->getValue();
echo "Outbox status: {$status}\n";

if ($status !== OutboxStatuses::PUBLISHED || $publishedTypes !== ['order.created']) {
    fwrite(STDERR, "Unexpected outbox result\n");
    exit(1);
}

echo "\nDone.\n";
