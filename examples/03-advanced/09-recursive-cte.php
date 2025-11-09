<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;
use tommyknocker\pdodb\PdoDb;

$driverEnv = getenv('PDODB_DRIVER') ?: 'sqlite';
$config = getExampleConfig();
$driver = $config['driver'] ?? $driverEnv; // Use driver from config (converts mssql to sqlsrv)

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
    ->withRecursive('category_tree', function ($q) {
        $q->from('categories')
          ->select(['id', 'name', 'parent_id', Db::raw('0 as level')])
          ->where('parent_id', null, 'IS')
          ->unionAll(function ($r) {
              $r->from('categories c')
                ->select(['c.id', 'c.name', 'c.parent_id', Db::raw('ct.level + 1')])
                ->join('category_tree ct', 'c.parent_id = ct.id');
          });
    }, ['id', 'name', 'parent_id', 'level'])
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

$driverName = $pdoDb->connection->getDriverName();
$pathSelect = ($driverName === 'pgsql') 
    ? Db::raw('name::VARCHAR as path')  // PostgreSQL requires explicit cast for recursive CTE
    : 'name';
    
$results = $pdoDb->find()
    ->withRecursive('emp_hierarchy', function ($q) use ($driverName) {
        $q->from('employees_hierarchy')
          ->select(['id', 'name', 'manager_id', 'salary', Db::raw('0 as level'), 'path' => ($driverName === 'pgsql') ? Db::raw('name::VARCHAR') : 'name'])
          ->where('manager_id', null, 'IS')
          ->unionAll(function ($r) {
              $r->from('employees_hierarchy e')
                ->select([
                    'e.id', 'e.name', 'e.manager_id', 'e.salary', Db::raw('eh.level + 1'),
                    'path' => Db::concat('eh.path', Db::raw("' -> '"), 'e.name')
                ])
                ->join('emp_hierarchy eh', 'e.manager_id = eh.id');
          });
    }, ['id', 'name', 'manager_id', 'salary', 'level', 'path'])
    ->from('emp_hierarchy')
    ->orderBy('level')
    ->orderBy('name')
    ->get();

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
    ->withRecursive('limited_tree', function ($q) {
        $q->from('categories')
          ->select(['id', 'name', 'parent_id', Db::raw('0 as depth')])
          ->where('parent_id', null, 'IS')
          ->unionAll(function ($r) {
              $r->from('categories c')
                ->select(['c.id', 'c.name', 'c.parent_id', Db::raw('lt.depth + 1')])
                ->join('limited_tree lt', 'c.parent_id = lt.id')
                ->where(Db::raw('lt.depth'), 2, '<');
          });
    }, ['id', 'name', 'parent_id', 'depth'])
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
    ->withRecursive('emp_tree', function ($q) {
        $q->from('employees_hierarchy')
          ->select(['id', 'name', 'manager_id', Db::raw('1 as sub_count')])
          ->where('manager_id', null, 'IS NOT')
          ->unionAll(function ($r) {
              $r->from('employees_hierarchy e')
                ->select(['e.id', 'e.name', 'e.manager_id', Db::raw('et.sub_count + 1')])
                ->join('emp_tree et', 'e.id = et.manager_id');
          });
    }, ['id', 'name', 'manager_id', 'sub_count'])
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

