<?php

declare(strict_types=1);

/**
 * Transactional outbox CLI: enqueue an event, then process it with pdodb outbox work --once.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxStatuses;

$driver = mb_strtolower(getenv('PDODB_DRIVER') ?: 'sqlite', 'UTF-8');
$dbPath = sys_get_temp_dir() . '/pdodb_outbox_cli_example_' . uniqid() . '.sqlite';

putenv('PDODB_DRIVER=sqlite');
putenv('PDODB_PATH=' . $dbPath);
$_ENV['PDODB_DRIVER'] = 'sqlite';
$_ENV['PDODB_PATH'] = $dbPath;

$db = createPdoDbWithErrorHandling('sqlite', ['path' => $dbPath]);

echo "=== Outbox CLI (on {$driver}) ===\n\n";

$table = 'example_outbox_cli';
putenv('PDODB_OUTBOX_TABLE=' . $table);
$_ENV['PDODB_OUTBOX_TABLE'] = $table;

$outbox = new Outbox($db, $table);
foreach ($outbox->getSchemaSql() as $sql) {
    $db->rawQuery($sql);
}

$db->transaction(function () use ($outbox): void {
    $outbox->add('cli.demo', ['via' => 'application']);
});

$publisher = __DIR__ . '/outbox/publisher.php';

$app = new Application();
$exitCode = $app->run([
    'pdodb',
    'outbox',
    'work',
    '--once',
    '--publisher=' . $publisher,
    '--table=' . $table,
]);

if ($exitCode !== 0) {
    fwrite(STDERR, "outbox work failed with exit code {$exitCode}\n");
    @unlink($dbPath);
    exit(1);
}

$status = $db->find()->table($table)->select(['status'])->getValue();
echo "\nFinal outbox status: {$status}\n";

@unlink($dbPath);

if ($status !== OutboxStatuses::PUBLISHED) {
    fwrite(STDERR, "Expected published status\n");
    exit(1);
}

echo "Done.\n";
