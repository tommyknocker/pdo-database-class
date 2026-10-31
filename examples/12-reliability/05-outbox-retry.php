<?php

declare(strict_types=1);

/**
 * Requeue a failed outbox row and process it again (SQLite demo).
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\outbox\OutboxUuid;

$driver = mb_strtolower(getenv('PDODB_DRIVER') ?: 'sqlite', 'UTF-8');
$dbPath = sys_get_temp_dir() . '/pdodb_outbox_retry_example_' . uniqid() . '.sqlite';

putenv('PDODB_DRIVER=sqlite');
putenv('PDODB_PATH=' . $dbPath);
$_ENV['PDODB_DRIVER'] = 'sqlite';
$_ENV['PDODB_PATH'] = $dbPath;

$db = createPdoDbWithErrorHandling('sqlite', ['path' => $dbPath]);

echo "=== Outbox retry (on {$driver}) ===\n\n";

$table = 'example_outbox_retry';
putenv('PDODB_OUTBOX_TABLE=' . $table);
$_ENV['PDODB_OUTBOX_TABLE'] = $table;

$outbox = new Outbox($db, $table);
foreach ($outbox->getSchemaSql() as $sql) {
    $db->rawQuery($sql);
}

$id = OutboxUuid::v4();
$db->find()->table($table)->insert([
    'id' => $id,
    'event_type' => 'retry.demo',
    'payload' => '{}',
    'status' => OutboxStatuses::FAILED,
    'attempts' => 2,
    'available_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'last_error' => 'simulated failure',
]);

$publisher = __DIR__ . '/outbox/publisher.php';
$app = new Application();

$statusRun = $app->run(['pdodb', 'outbox', 'status', '--table=' . $table]);
if ($statusRun !== 0) {
    @unlink($dbPath);
    exit(1);
}

$retryRun = $app->run(['pdodb', 'outbox', 'retry', '--id=' . $id, '--table=' . $table]);
if ($retryRun !== 0) {
    @unlink($dbPath);
    exit(1);
}

$workRun = $app->run([
    'pdodb',
    'outbox',
    'work',
    '--once',
    '--publisher=' . $publisher,
    '--table=' . $table,
]);
if ($workRun !== 0) {
    @unlink($dbPath);
    exit(1);
}

$final = $db->find()->table($table)->where('id', $id)->select(['status'])->getValue();
echo "\nFinal status: {$final}\n";

@unlink($dbPath);

if ($final !== OutboxStatuses::PUBLISHED) {
    fwrite(STDERR, "Expected published status after retry\n");
    exit(1);
}

echo "Done.\n";
