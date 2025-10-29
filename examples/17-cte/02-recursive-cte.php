<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

$driver = getenv('PDODB_DRIVER') ?: 'sqlite';
$config = getExampleConfig();

echo "=== Recursive CTE Examples ===\n\n";
echo "Database: $driver\n\n";

$pdoDb = createExampleDb($config);

// Create test tables based on driver
if ($driver === 'mysql') {
    $pdoDb->rawQuery('DROP TABLE IF EXISTS categories');
    $pdoDb->rawQuery('DROP TABLE IF EXISTS employees_hierarchy');
    
    $pdoDb->rawQuery('
        CREATE TABLE categories (
            id INT PRIMARY KEY,
            name VARCHAR(100),
            parent_id INT NULL
        )
    ');
    
    $pdoDb->rawQuery('
        CREATE TABLE employees_hierarchy (
            id INT PRIMARY KEY,
            name VARCHAR(100),
            manager_id INT NULL,
            salary DECIMAL(10,2)
        )
    ');
} elseif ($driver === 'pgsql') {
    $pdoDb->rawQuery('DROP TABLE IF EXISTS categories CASCADE');
    $pdoDb->rawQuery('DROP TABLE IF EXISTS employees_hierarchy CASCADE');
    
    $pdoDb->rawQuery('
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100),
            parent_id INTEGER NULL
        )
    ');
    
    $pdoDb->rawQuery('
        CREATE TABLE employees_hierarchy (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100),
            manager_id INTEGER NULL,
            salary DECIMAL(10,2)
        )
    ');
} else {
    $pdoDb->rawQuery('DROP TABLE IF EXISTS categories');
    $pdoDb->rawQuery('DROP TABLE IF EXISTS employees_hierarchy');
    
    $pdoDb->rawQuery('
        CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            name TEXT,
            parent_id INTEGER NULL
        )
    ');
    
    $pdoDb->rawQuery('
        CREATE TABLE employees_hierarchy (
            id INTEGER PRIMARY KEY,
            name TEXT,
            manager_id INTEGER NULL,
            salary REAL
        )
    ');
}

// Insert hierarchical category data
$pdoDb->find()->table('categories')->insertMulti([
    ['id' => 1, 'name' => 'Electronics', 'parent_id' => null],
    ['id' => 2, 'name' => 'Computers', 'parent_id' => 1],
    ['id' => 3, 'name' => 'Laptops', 'parent_id' => 2],
    ['id' => 4, 'name' => 'Desktops', 'parent_id' => 2],
    ['id' => 5, 'name' => 'Mobile', 'parent_id' => 1],
    ['id' => 6, 'name' => 'Smartphones', 'parent_id' => 5],
    ['id' => 7, 'name' => 'Tablets', 'parent_id' => 5],
]);

// Insert employee hierarchy data
$pdoDb->find()->table('employees_hierarchy')->insertMulti([
    ['id' => 1, 'name' => 'CEO', 'manager_id' => null, 'salary' => 200000],
    ['id' => 2, 'name' => 'CTO', 'manager_id' => 1, 'salary' => 150000],
    ['id' => 3, 'name' => 'CFO', 'manager_id' => 1, 'salary' => 150000],
    ['id' => 4, 'name' => 'Dev Manager', 'manager_id' => 2, 'salary' => 120000],
    ['id' => 5, 'name' => 'QA Manager', 'manager_id' => 2, 'salary' => 110000],
    ['id' => 6, 'name' => 'Senior Dev', 'manager_id' => 4, 'salary' => 90000],
    ['id' => 7, 'name' => 'Junior Dev', 'manager_id' => 4, 'salary' => 60000],
    ['id' => 8, 'name' => 'QA Lead', 'manager_id' => 5, 'salary' => 80000],
]);

// Example 1: Recursive CTE - Category hierarchy
echo "1. Recursive CTE - Full category tree from 'Electronics':\n";
$results = $pdoDb->find()
    ->withRecursive('category_tree', Db::raw('
        SELECT id, name, parent_id, 0 as level
        FROM categories
        WHERE parent_id IS NULL
        UNION ALL
        SELECT c.id, c.name, c.parent_id, ct.level + 1
        FROM categories c
        INNER JOIN category_tree ct ON c.parent_id = ct.id
    '), ['id', 'name', 'parent_id', 'level'])
    ->from('category_tree')
    ->orderBy('level')
    ->orderBy('name')
    ->get();

foreach ($results as $category) {
    $indent = str_repeat('  ', (int)$category['level']);
    printf("%s- %s (Level %d)\n", $indent, $category['name'], $category['level']);
}
echo "\n";

// Example 2: Recursive CTE - Employee hierarchy chain
echo "2. Recursive CTE - Management chain from CEO down:\n";

// PostgreSQL requires explicit type casting for concatenated paths
if ($driver === 'pgsql') {
    $results = $pdoDb->find()
        ->withRecursive('emp_hierarchy', Db::raw('
            SELECT id, name, manager_id, salary, 0 as level, CAST(name AS TEXT) as path
            FROM employees_hierarchy
            WHERE manager_id IS NULL
            UNION ALL
            SELECT e.id, e.name, e.manager_id, e.salary, eh.level + 1,
                   CAST(eh.path || \' -> \' || e.name AS TEXT) as path
            FROM employees_hierarchy e
            INNER JOIN emp_hierarchy eh ON e.manager_id = eh.id
        '), ['id', 'name', 'manager_id', 'salary', 'level', 'path'])
        ->from('emp_hierarchy')
        ->orderBy('level')
        ->orderBy('name')
        ->get();
} elseif ($driver === 'mysql') {
    // MySQL uses CONCAT() instead of || operator
    $results = $pdoDb->find()
        ->withRecursive('emp_hierarchy', Db::raw('
            SELECT id, name, manager_id, salary, 0 as level, name as path
            FROM employees_hierarchy
            WHERE manager_id IS NULL
            UNION ALL
            SELECT e.id, e.name, e.manager_id, e.salary, eh.level + 1,
                   CONCAT(eh.path, \' -> \', e.name) as path
            FROM employees_hierarchy e
            INNER JOIN emp_hierarchy eh ON e.manager_id = eh.id
        '), ['id', 'name', 'manager_id', 'salary', 'level', 'path'])
        ->from('emp_hierarchy')
        ->orderBy('level')
        ->orderBy('name')
        ->get();
} else {
    // SQLite uses || operator
    $results = $pdoDb->find()
        ->withRecursive('emp_hierarchy', Db::raw('
            SELECT id, name, manager_id, salary, 0 as level, name as path
            FROM employees_hierarchy
            WHERE manager_id IS NULL
            UNION ALL
            SELECT e.id, e.name, e.manager_id, e.salary, eh.level + 1,
                   eh.path || \' -> \' || e.name as path
            FROM employees_hierarchy e
            INNER JOIN emp_hierarchy eh ON e.manager_id = eh.id
        '), ['id', 'name', 'manager_id', 'salary', 'level', 'path'])
        ->from('emp_hierarchy')
        ->orderBy('level')
        ->orderBy('name')
        ->get();
}

foreach ($results as $employee) {
    printf("Level %d: %s (Salary: $%s)\n  Path: %s\n", 
        $employee['level'],
        $employee['name'],
        number_format((float)$employee['salary'], 2),
        $employee['path']
    );
}
echo "\n";

// Example 3: Recursive CTE with depth limit
echo "3. Recursive CTE - Two levels deep from root:\n";
$results = $pdoDb->find()
    ->withRecursive('limited_tree', Db::raw('
        SELECT id, name, parent_id, 0 as depth
        FROM categories
        WHERE parent_id IS NULL
        UNION ALL
        SELECT c.id, c.name, c.parent_id, lt.depth + 1
        FROM categories c
        INNER JOIN limited_tree lt ON c.parent_id = lt.id
        WHERE lt.depth < 2
    '), ['id', 'name', 'parent_id', 'depth'])
    ->from('limited_tree')
    ->orderBy('depth')
    ->orderBy('name')
    ->get();

foreach ($results as $category) {
    $indent = str_repeat('  ', (int)$category['depth']);
    printf("%s- %s (Depth %d)\n", $indent, $category['name'], $category['depth']);
}
echo "\n";

// Example 4: Recursive CTE - Count subordinates
echo "4. Recursive CTE - Employee count per manager:\n";

// First, get the hierarchy
$hierarchyResults = $pdoDb->find()
    ->withRecursive('emp_tree', Db::raw('
        SELECT id, name, manager_id, 1 as sub_count
        FROM employees_hierarchy
        WHERE manager_id IS NOT NULL
        UNION ALL
        SELECT e.id, e.name, e.manager_id, et.sub_count + 1
        FROM employees_hierarchy e
        INNER JOIN emp_tree et ON e.id = et.manager_id
    '), ['id', 'name', 'manager_id', 'sub_count'])
    ->from('emp_tree')
    ->get();

// Group by manager
$managerCounts = [];
foreach ($hierarchyResults as $row) {
    $id = $row['id'];
    if (!isset($managerCounts[$id])) {
        $managerCounts[$id] = ['name' => $row['name'], 'count' => 0];
    }
    $managerCounts[$id]['count'] = max($managerCounts[$id]['count'], (int)$row['sub_count']);
}

// Get all employees to show everyone
$allEmployees = $pdoDb->find()->table('employees_hierarchy')->get();
foreach ($allEmployees as $emp) {
    $count = $managerCounts[$emp['id']]['count'] ?? 0;
    printf("  - %s: %d direct/indirect subordinate(s)\n", $emp['name'], $count);
}
echo "\n";

echo "=== All examples completed successfully ===\n";

