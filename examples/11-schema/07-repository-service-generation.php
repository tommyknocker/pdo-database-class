<?php

declare(strict_types=1);

/**
 * Repository and Service Generation Examples.
 *
 * This example demonstrates how to use the pdodb repository and service commands
 * to generate repository and service classes.
 *
 * Usage:
 *   php examples/11-schema/07-repository-service-generation.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\PdoDb;

// Get database configuration
$driver = mb_strtolower(getenv('PDODB_DRIVER') ?: 'sqlite', 'UTF-8');
$config = getExampleConfig();

// For SQLite, use a temporary file instead of :memory: so CLI commands use the same database
if ($driver === 'sqlite') {
    $dbFile = sys_get_temp_dir() . '/pdodb_repo_service_example_' . uniqid() . '.sqlite';
    $config['path'] = $dbFile;
    putenv('PDODB_PATH=' . $dbFile);
}

echo "PDOdb Repository and Service Generation Examples\n";
echo "================================================\n\n";
echo "Driver: {$driver}\n\n";

// Set environment variables from config for CLI commands
setEnvFromConfig($config);
putenv('PDODB_NON_INTERACTIVE=1');

// Create database connection
$db = createPdoDbWithErrorHandling($driver, $config);

// Create a test table
$schema = $db->schema();
$schema->dropTableIfExists('example_users');
$schema->createTable('example_users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(100),
    'email' => $schema->string(255)->unique(),
    'status' => $schema->string(20),
    'created_at' => $schema->datetime(),
    'updated_at' => ['type' => 'DATETIME', 'null' => true],
]);

echo "✓ Created test table 'example_users'\n\n";

// Create temporary directories for generated files
$baseDir = sys_get_temp_dir() . '/pdodb_example_generation_' . uniqid();
$repositoriesDir = $baseDir . '/Repositories';
$servicesDir = $baseDir . '/Services';

mkdir($repositoriesDir, 0755, true);
mkdir($servicesDir, 0755, true);

// Set environment variables for output paths
putenv('PDODB_REPOSITORY_PATH=' . $repositoriesDir);
putenv('PDODB_SERVICE_PATH=' . $servicesDir);

echo "Repository Generation Examples\n";
echo "-----------------------------\n\n";

$app = new Application();

// Example 1: Generate UserRepository with default namespace
echo "1. Generating UserRepository...\n";
ob_start();
$code1 = $app->run([
    'pdodb',
    'repository',
    'make',
    'ExampleUserRepository',
    'ExampleUser',
    $repositoriesDir,
    '--namespace=App\\Repositories',
    '--model-namespace=App\\Models',
]);
$out1 = ob_get_clean();
echo $out1 . "\n";

if ($code1 === 0) {
    $repoFile = $repositoriesDir . '/ExampleUserRepository.php';
    if (file_exists($repoFile)) {
        echo "✓ Repository file created: {$repoFile}\n";
        echo "  Contents preview:\n";
        $content = file_get_contents($repoFile);
        $lines = explode("\n", $content);
        foreach (array_slice($lines, 0, 30) as $line) {
            echo "    {$line}\n";
        }
        echo "    ...\n\n";
    }
}

// Example 2: Generate UserService
echo "2. Generating UserService...\n";
ob_start();
$code2 = $app->run([
    'pdodb',
    'service',
    'make',
    'ExampleUserService',
    'ExampleUserRepository',
    $servicesDir,
    '--namespace=App\\Services',
    '--repository-namespace=App\\Repositories',
]);
$out2 = ob_get_clean();
echo $out2 . "\n";

if ($code2 === 0) {
    $serviceFile = $servicesDir . '/ExampleUserService.php';
    if (file_exists($serviceFile)) {
        echo "✓ Service file created: {$serviceFile}\n";
        echo "  Contents preview:\n";
        $content = file_get_contents($serviceFile);
        $lines = explode("\n", $content);
        foreach (array_slice($lines, 0, 40) as $line) {
            echo "    {$line}\n";
        }
        echo "    ...\n\n";
    }
}

// Example 3: Generate with custom namespaces
echo "3. Generating ProductRepository with custom namespaces...\n";

// Create another table for demonstration
$schema->dropTableIfExists('example_products');
$schema->createTable('example_products', [
    'id' => $schema->primaryKey(),
    'name' => $schema->string(200),
    'price' => $schema->decimal(10, 2),
    'stock' => ['type' => 'INT', 'default' => 0],
]);

ob_start();
$code3 = $app->run([
    'pdodb',
    'repository',
    'make',
    'ExampleProductRepository',
    'ExampleProduct',
    $repositoriesDir,
    '--namespace=App\\Data\\Repositories',
    '--model-namespace=App\\Models\\Products',
]);
$out3 = ob_get_clean();
echo $out3 . "\n";

if ($code3 === 0) {
    echo "✓ ProductRepository generated with custom namespaces\n\n";
}

// Example 4: Generate service with auto-detected repository name
echo "4. Generating ProductService (auto-detecting repository name)...\n";
ob_start();
$code4 = $app->run([
    'pdodb',
    'service',
    'make',
    'ExampleProductService',
    'ExampleProductRepository',
    $servicesDir,
    '--namespace=App\\Services',
    '--repository-namespace=App\\Data\\Repositories',
]);
$out4 = ob_get_clean();
echo $out4 . "\n";

if ($code4 === 0) {
    echo "✓ ProductService generated\n\n";
}

// Show summary
echo "Summary\n";
echo "-------\n";
echo "Generated files:\n";
echo "  Repositories:\n";
foreach (glob($repositoriesDir . '/*.php') as $file) {
    echo "    - " . basename($file) . "\n";
}
echo "  Services:\n";
foreach (glob($servicesDir . '/*.php') as $file) {
    echo "    - " . basename($file) . "\n";
}

echo "\n✓ Repository and Service generation examples completed!\n";
echo "\nNote: Generated files are in temporary directory: {$baseDir}\n";
echo "In production, these files would be in your application's directory structure.\n";

// Cleanup (optional - comment out to inspect generated files)
// foreach (glob($repositoriesDir . '/*.php') as $file) {
//     @unlink($file);
// }
// foreach (glob($servicesDir . '/*.php') as $file) {
//     @unlink($file);
// }
// @rmdir($repositoriesDir);
// @rmdir($servicesDir);
// @rmdir($baseDir);

