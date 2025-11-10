<?php

/**
 * Export Helpers Examples
 *
 * Demonstrates how to export database results to various formats (JSON, CSV, XML)
 * using the helper methods: Db::toJson(), Db::toCsv(), Db::toXml()
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

// Initialize database connection
$driver = getenv('PDODB_DRIVER') ?: 'sqlite';

$config = match ($driver) {
    'mysql' => require __DIR__ . '/../config.mysql.php',
    'pgsql' => require __DIR__ . '/../config.pgsql.php',
    default => require __DIR__ . '/../config.sqlite.php',
};

$db = new PdoDb($driver, $config);

echo "=== Export Helpers Examples ===\n\n";

// Create and populate test table using fluent API (cross-dialect)
$schema = $db->schema();
$schema->dropTableIfExists('export_test');


$autoIncrement = $driver === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';


$db->rawQuery('
    CREATE TABLE export_test (
        id '. $autoIncrement .',
        name VARCHAR(255),
        email VARCHAR(255),
        age INT,
        city VARCHAR(255)
    )
');

$db->find()->table('export_test')->insertMulti([
    ['name' => 'Alice Johnson', 'email' => 'alice@example.com', 'age' => 30, 'city' => 'New York'],
    ['name' => 'Bob Smith', 'email' => 'bob@example.com', 'age' => 25, 'city' => 'Los Angeles'],
    ['name' => 'Carol Davis', 'email' => 'carol@example.com', 'age' => 28, 'city' => 'Chicago'],
]);

// Get data from database
$data = $db->find()->from('export_test')->get();

echo "Total records: " . count($data) . "\n\n";

// Example 1: Export to JSON
echo "=== 1. JSON Export ===\n";
$json = Db::toJson($data);
echo "Basic JSON export:\n";
echo $json . "\n\n";

// JSON with custom flags
$jsonCompact = Db::toJson($data, 0); // No pretty print
echo "Compact JSON (no pretty print):\n";
echo $jsonCompact . "\n\n";

// Example 2: Export to CSV
echo "=== 2. CSV Export ===\n";
$csv = Db::toCsv($data);
echo "CSV export:\n";
echo $csv . "\n\n";

// CSV with custom delimiter
$csvCustom = Db::toCsv($data, ';');
echo "CSV with semicolon delimiter:\n";
echo $csvCustom . "\n\n";

// Example 3: Export to XML
echo "=== 3. XML Export ===\n";
$xml = Db::toXml($data);
echo "XML export:\n";
echo $xml . "\n\n";

// XML with custom element names
$xmlCustom = Db::toXml($data, 'users', 'user');
echo "XML with custom elements (<users>, <user>):\n";
echo $xmlCustom . "\n\n";

// Example 4: Export specific fields
echo "=== 4. Export Specific Fields ===\n";
$filteredData = $db->find()
    ->from('export_test')
    ->select(['name', 'email'])
    ->get();
    
$jsonFiltered = Db::toJson($filteredData);
echo "JSON export (name and email only):\n";
echo $jsonFiltered . "\n\n";

// Example 5: Export with conditions
echo "=== 5. Export Filtered Data ===\n";
$adults = $db->find()
    ->from('export_test')
    ->where('age', 27, '>')
    ->get();
    
echo "Records with age > 27:\n";
echo Db::toJson($adults) . "\n\n";

// Example 6: Save to file
echo "=== 6. Save to Files ===\n";
$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

file_put_contents($outputDir . '/users.json', Db::toJson($data));
file_put_contents($outputDir . '/users.csv', Db::toCsv($data));
file_put_contents($outputDir . '/users.xml', Db::toXml($data));

echo "Files saved to: $outputDir/\n";
echo "- users.json\n";
echo "- users.csv\n";
echo "- users.xml\n\n";

// Example 7: Export empty result set
echo "=== 7. Export Empty Data ===\n";
$empty = $db->find()->from('export_test')->where('id', 9999)->get();
echo "JSON of empty array: " . Db::toJson($empty) . "\n";
echo "CSV of empty array: '" . Db::toCsv($empty) . "'\n";
echo "XML of empty array: " . Db::toXml($empty) . "\n\n";

// Example 8: Export with nested data
echo "=== 8. Export Complex Data ===\n";
$complexData = [
    [
        'id' => 1,
        'name' => 'Alice',
        'tags' => ['php', 'mysql', 'docker'],
        'metadata' => [
            'location' => 'USA',
            'status' => 'active'
        ]
    ],
    [
        'id' => 2,
        'name' => 'Bob',
        'tags' => ['python', 'postgresql'],
        'metadata' => [
            'location' => 'UK',
            'status' => 'pending'
        ]
    ]
];

$jsonComplex = Db::toJson($complexData);
echo "JSON with nested arrays/objects:\n";
echo $jsonComplex . "\n\n";

echo "=== All Examples Completed ===\n";
echo "Check output/ directory for generated files.\n";


