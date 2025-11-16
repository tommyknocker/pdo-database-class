<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\cli\Application;

// Example: basic table CLI flows on SQLite temp file
putenv('PDODB_DRIVER=sqlite');
$dbPath = sys_get_temp_dir() . '/pdodb_table_example_' . uniqid() . '.sqlite';
putenv('PDODB_PATH=' . $dbPath);
putenv('PDODB_NON_INTERACTIVE=1');

$app = new Application();
$run = static function (array $argv) use ($app): void {
    echo ">> " . implode(' ', array_slice($argv, 1)) . "\n";
    $app->run($argv);
    echo "\n";
};

$run(['pdodb', 'table', 'create', 'demo', '--columns=id:int,name:string:nullable', '--force']);
$run(['pdodb', 'table', 'info', 'demo']);
$run(['pdodb', 'table', 'columns', 'add', 'demo', 'price', '--type=float']);
$run(['pdodb', 'table', 'indexes', 'add', 'demo', 'idx_demo_name', '--columns=name']);
$run(['pdodb', 'table', 'indexes', 'list', 'demo', '--format=json']);
$run(['pdodb', 'table', 'truncate', 'demo', '--force']);
$run(['pdodb', 'table', 'drop', 'demo', '--force']);

@unlink($dbPath);


