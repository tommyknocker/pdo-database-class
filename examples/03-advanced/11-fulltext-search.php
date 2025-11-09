<?php

/**
 * Full-Text Search Examples
 * 
 * Demonstrates full-text search functionality across MySQL, PostgreSQL, and SQLite
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Full-Text Search Examples ($driver) ===\n\n";

// Create a table for testing full-text search
echo "Setting up test table with full-text index...\n";

$tableDef = match ($driver) {
    'mysql' => [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'title' => 'VARCHAR(255)',
        'content' => 'TEXT',
        'FULLTEXT (title, content)' => ''
    ],
    'pgsql' => [
        'id' => 'SERIAL PRIMARY KEY',
        'title' => 'VARCHAR(255)',
        'content' => 'TEXT',
        'ts_vector' => 'TSVECTOR GENERATED ALWAYS AS (to_tsvector(\'english\', title || \' \' || content)) STORED'
    ],
    'sqlsrv' => [
        'id' => 'INT IDENTITY(1,1) PRIMARY KEY',
        'title' => 'NVARCHAR(255)',
        'content' => 'NTEXT'
    ],
    'sqlite' => [
        'id' => 'INTEGER PRIMARY KEY',
        'title' => 'TEXT',
        'content' => 'TEXT'
    ]
};

$autoIncrement = match ($driver) {
    'pgsql' => 'SERIAL PRIMARY KEY',
    'sqlsrv' => 'INT IDENTITY(1,1) PRIMARY KEY',
    default => 'INT AUTO_INCREMENT PRIMARY KEY'
};

try {
    $db->rawQuery("DROP TABLE IF EXISTS articles");
    
    $sql = getCreateTableSql($db, 'articles', $tableDef);
    $db->rawQuery($sql);
    
    // Create full-text index for SQLite (FTS5)
    if ($driver === 'sqlite') {
        $db->rawQuery("CREATE VIRTUAL TABLE IF NOT EXISTS articles USING fts5(title, content)");
    }
    
    echo "✓ Table created\n\n";
} catch (Exception $e) {
    echo "Note: Full-text indexes may require special setup\n";
    echo "Error: " . $e->getMessage() . "\n";
    
    // Create simple table without full-text index for demonstration
    recreateTable($db, 'articles', [
        'id' => $autoIncrement,
        'title' => 'VARCHAR(255)',
        'content' => 'TEXT'
    ]);
}

// Insert test data
echo "Inserting test data...\n";
$db->find()->table('articles')->insertMulti([
    ['title' => 'PHP Database Tutorial', 'content' => 'Learn how to use PHP with databases'],
    ['title' => 'MySQL Performance Guide', 'content' => 'Optimize your MySQL queries for better performance'],
    ['title' => 'PostgreSQL Best Practices', 'content' => 'Best practices for working with PostgreSQL'],
    ['title' => 'SQLite Mobile Apps', 'content' => 'Building mobile apps with SQLite database'],
    ['title' => 'Database Security', 'content' => 'Secure your database connections and prevent SQL injection'],
]);

echo "✓ Inserted 5 articles\n\n";

// Example 1: Basic full-text search
echo "=== 1. Basic Full-Text Search ===\n";
try {
    $results = $db->find()
        ->from('articles')
        ->where(Db::match('title, content', 'database'))
        ->get();
    
    echo "Results for search 'database': " . count($results) . "\n";
    foreach ($results as $row) {
        echo "- {$row['title']}\n";
    }
} catch (Exception $e) {
    echo "Note: Full-text search not configured. Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Example 2: Full-text search with LIKE as fallback
echo "=== 2. Full-Text Search (fallback to LIKE) ===\n";
$searchTerm = 'performance';
$results = $db->find()
    ->from('articles')
    ->where(Db::like('title', "%{$searchTerm}%"))
    ->orWhere(Db::like('content', "%{$searchTerm}%"))
    ->get();

echo "Results for search '$searchTerm': " . count($results) . "\n";
foreach ($results as $row) {
    echo "- {$row['title']}\n";
}

echo "\n";

// Example 3: Full-text search with LIKE (FulltextMatchValue only works with real FTS indexes)
echo "=== 3. Full-Text Search with LIKE ===\n";
$searchTerm = 'PHP';
$results = $db->find()
    ->from('articles')
    ->select(['id', 'title'])
    ->where(Db::like('title', "%{$searchTerm}%"))
    ->orWhere(Db::like('content', "%{$searchTerm}%"))
    ->orderBy('title', 'DESC')
    ->get();

echo "Results for search '$searchTerm':\n";
foreach ($results as $row) {
    echo "- {$row['title']}\n";
}

echo "\n";

// Example 4: Multiple columns search
echo "=== 4. Multiple Columns Search ===\n";
$searchTerm = 'guide';
$results = $db->find()
    ->from('articles')
    ->where(Db::like('title', "%{$searchTerm}%"))
    ->orWhere(Db::like('content', "%{$searchTerm}%"))
    ->limit(10)
    ->get();

echo "Results for search '$searchTerm': " . count($results) . "\n";
foreach ($results as $row) {
    echo "- {$row['title']}\n";
}

echo "\n";

// Example 5: Case-insensitive search
echo "=== 5. Case-Insensitive Search ===\n";
$searchTerm = 'sql';
$results = $db->find()
    ->from('articles')
    ->where(Db::ilike('title', "%{$searchTerm}%"))
    ->orWhere(Db::ilike('content', "%{$searchTerm}%"))
    ->get();

echo "Results for search '$searchTerm' (case-insensitive): " . count($results) . "\n";
foreach ($results as $row) {
    echo "- {$row['title']}\n";
}

echo "\n";

echo "=== Schema Introspection Examples ===\n";

// Get table structure
echo "1. Table Structure:\n";
$structure = $db->describe('articles');
foreach ($structure as $col) {
    $name = $col['Field'] ?? $col['column_name'] ?? $col['name'] ?? 'unknown';
    $type = $col['Type'] ?? $col['data_type'] ?? $col['type'] ?? 'unknown';
    echo "- $name: $type\n";
}

echo "\n";

// Get indexes
echo "2. Indexes:\n";
try {
    $indexes = $db->find()->from('articles')->indexes();
    foreach ($indexes as $index) {
        $name = $index['Key_name'] ?? $index['index_name'] ?? $index['name'] ?? 'unknown';
        echo "- Index: $name\n";
    }
} catch (Exception $e) {
    echo "- Could not retrieve indexes: " . $e->getMessage() . "\n";
}

echo "\n";

echo "=== All Full-Text Search Examples Completed ===\n";

