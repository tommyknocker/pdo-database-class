<?php

declare(strict_types=1);

/**
 * Database Monitoring Examples.
 *
 * This example demonstrates how to use the pdodb monitor command
 * to monitor database queries, connections, and performance.
 *
 * Usage:
 *   php examples/11-schema/05-monitoring.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

// Get database configuration
$driver = mb_strtolower(getenv('PDODB_DRIVER') ?: 'sqlite', 'UTF-8');
$config = getExampleConfig();

echo "PDOdb Database Monitoring Examples\n";
echo "===================================\n\n";
echo "Driver: {$driver}\n\n";

// Create database connection
$db = createPdoDbWithErrorHandling($driver, $config);

// Create a test table
$schema = $db->schema();
$schema->dropTableIfExists('monitor_test');
$schema->createTable('monitor_test', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(100),
    'value' => $schema->integer(),
    'created_at' => $schema->datetime(),
]);

echo "✓ Created test table 'monitor_test'\n\n";

// Insert some test data
for ($i = 1; $i <= 10; $i++) {
    $db->find()->table('monitor_test')->insert([
        'name' => "Item {$i}",
        'value' => $i * 10,
        'created_at' => Db::now(), // Use Db::now() helper for better compatibility across dialects
    ]);
}
echo "✓ Inserted 10 test records\n\n";

// Demonstrate monitor commands using CLI
$app = new Application();

echo "1. Monitor Active Queries:\n";
echo "   Command: pdodb monitor queries --format=json\n";
echo "   Output:\n";
ob_start();
$app->run(['pdodb', 'monitor', 'queries', '--format=json']);
$output = ob_get_clean();
echo $output . "\n";

echo "2. Monitor Active Connections:\n";
echo "   Command: pdodb monitor connections --format=json\n";
echo "   Output:\n";
ob_start();
$app->run(['pdodb', 'monitor', 'connections', '--format=json']);
$output = ob_get_clean();
echo $output . "\n";

echo "3. Monitor Slow Queries:\n";
if ($driver === 'sqlite') {
    echo "   Note: For SQLite, profiling must be enabled in application code.\n";
    echo "   This example shows the command, but profiling is not enabled for CLI.\n";
} else {
    echo "   Note: Uses system views (SHOW PROCESSLIST, pg_stat_activity, etc.)\n";
}
echo "   Command: pdodb monitor slow --threshold=1s --limit=5 --format=json\n";
echo "   Output:\n";
ob_start();
$app->run(['pdodb', 'monitor', 'slow', '--threshold=1s', '--limit=5', '--format=json']);
$output = ob_get_clean();
echo $output . "\n";

echo "4. Monitor Query Statistics:\n";
echo "   Note: Requires profiling to be enabled in application code\n";
echo "   Command: pdodb monitor stats --format=json\n";
echo "   Output:\n";
ob_start();
$app->run(['pdodb', 'monitor', 'stats', '--format=json']);
$output = ob_get_clean();
echo $output . "\n";

echo "\n";
echo "Additional Notes:\n";
echo "- Use --watch option for real-time monitoring:\n";
echo "  pdodb monitor queries --watch\n";
echo "  pdodb monitor connections --watch\n";
echo "\n";
echo "- For MySQL/MariaDB/PostgreSQL/MSSQL, monitor commands use system views\n";
echo "- For SQLite, monitor slow/stats require QueryProfiler to be enabled\n";
echo "- Real-time monitoring updates every 2 seconds\n";
echo "- Press Ctrl+C to exit watch mode\n";

// Cleanup
$schema->dropTableIfExists('monitor_test');
echo "\n✓ Cleaned up test table\n";
