<?php
/**
 * Example: Subqueries
 * 
 * Demonstrates subqueries in SELECT, WHERE, and FROM clauses
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Subqueries Example (on $driver) ===\n\n";

// Setup
$schema = $db->schema();
recreateTable($db, 'users', [
    'id' => $schema->primaryKey(),
    'name' => $schema->text(),
    'department' => $schema->text(),
    'salary' => $schema->decimal(10, 2),
    'hire_date' => $schema->text(),
]);

recreateTable($db, 'orders', [
    'id' => $schema->primaryKey(),
    'user_id' => $schema->integer(),
    'amount' => $schema->decimal(10, 2),
    'order_date' => $schema->text(),
    'status' => $schema->text(),
]);

recreateTable($db, 'departments', [
    'id' => $schema->primaryKey(),
    'name' => $schema->text(),
    'budget' => $schema->decimal(10, 2),
    'manager_id' => $schema->integer(),
]);

$db->find()->table('users')->insertMulti([
    ['name' => 'Alice Johnson', 'department' => 'Sales', 'salary' => 50000, 'hire_date' => '2020-01-15'],
    ['name' => 'Bob Smith', 'department' => 'IT', 'salary' => 60000, 'hire_date' => '2019-03-20'],
    ['name' => 'Carol Davis', 'department' => 'Sales', 'salary' => 55000, 'hire_date' => '2021-06-10'],
    ['name' => 'Dave Wilson', 'department' => 'IT', 'salary' => 70000, 'hire_date' => '2018-11-05'],
    ['name' => 'Eve Brown', 'department' => 'HR', 'salary' => 45000, 'hire_date' => '2022-02-28'],
]);

$db->find()->table('orders')->insertMulti([
    ['user_id' => 1, 'amount' => 1000, 'order_date' => '2024-01-15', 'status' => 'completed'],
    ['user_id' => 1, 'amount' => 500, 'order_date' => '2024-02-10', 'status' => 'pending'],
    ['user_id' => 2, 'amount' => 2000, 'order_date' => '2024-01-20', 'status' => 'completed'],
    ['user_id' => 3, 'amount' => 750, 'order_date' => '2024-02-05', 'status' => 'shipped'],
    ['user_id' => 4, 'amount' => 3000, 'order_date' => '2024-01-25', 'status' => 'completed'],
]);

$db->find()->table('departments')->insertMulti([
    ['name' => 'Sales', 'budget' => 100000, 'manager_id' => 1],
    ['name' => 'IT', 'budget' => 150000, 'manager_id' => 2],
    ['name' => 'HR', 'budget' => 80000, 'manager_id' => 5],
]);

echo "✓ Test data inserted\n\n";

// Example 1: Subquery in SELECT
echo "1. Subquery in SELECT...\n";
$results = $db->find()
    ->from('users')
    ->select([
        'name',
        'department',
        'salary',
        'total_orders' => function ($q) {
            $q->from('orders')
              ->select([Db::count()])
              ->where('user_id', 'users.id');
        },
        'total_amount' => function ($q) {
            $q->from('orders')
              ->select([Db::coalesce(Db::sum('amount'), 0)])
              ->where('user_id', 'users.id');
        }
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['department']}): \${$row['salary']}\n";
    echo "    Orders: {$row['total_orders']}, Total: \${$row['total_amount']}\n";
}
echo "\n";

// Example 2: Subquery in WHERE
echo "2. Subquery in WHERE...\n";
$results = $db->find()
    ->from('users')
    ->where('salary', function ($q) {
        $q->from('users')->select([Db::avg('salary')]);
    }, '>')
    ->select(['name', 'salary'])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['name']}: \${$row['salary']} (above average)\n";
}
echo "\n";

// Example 3: Subquery in FROM
echo "3. Subquery in FROM...\n";
// Oracle doesn't support AS keyword for subquery aliases and requires TO_CHAR() for CLOB in ORDER BY
if ($driver === 'oci') {
    $results = $db->rawQuery('
        SELECT department, avg_salary, emp_count 
        FROM (SELECT TO_CHAR(department) as department, AVG(salary) as avg_salary, COUNT(*) as emp_count 
              FROM users GROUP BY TO_CHAR(department)) dept_stats 
        ORDER BY avg_salary DESC
    ');
} else {
    $results = $db->rawQuery('
        SELECT department, avg_salary, emp_count 
        FROM (SELECT department, AVG(salary) as avg_salary, COUNT(*) as emp_count 
              FROM users GROUP BY department) as dept_stats 
        ORDER BY avg_salary DESC
    ');
}

foreach ($results as $row) {
    echo "  • {$row['department']}: \${$row['avg_salary']} average salary, {$row['emp_count']} employees\n";
}
echo "\n";

// Example 4: EXISTS subquery
echo "4. EXISTS subquery...\n";
$results = $db->find()
    ->from('users')
    ->whereExists(function ($q) {
        $q->from('orders')->where('orders.user_id', 'users.id');
    })
    ->select(['name', 'department'])
    ->get();

echo "  Users who have placed orders:\n";
foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['department']})\n";
}
echo "\n";

// Example 5: NOT EXISTS subquery
echo "5. NOT EXISTS subquery...\n";
$results = $db->find()
    ->from('users')
    ->whereNotExists(function ($q) {
        $q->from('orders')->where('orders.user_id', 'users.id');
    })
    ->select(['name', 'department'])
    ->get();

echo "  Users who have NOT placed orders:\n";
foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['department']})\n";
}
echo "\n";

// Example 6: IN subquery
echo "6. IN subquery...\n";
$results = $db->find()
    ->from('users')
    ->whereIn('id', function ($q) {
        $q->from('orders')
          ->select(['user_id'])
          ->where('status', 'completed')
          ->groupBy('user_id');
    })
    ->select(['name', 'department'])
    ->get();

echo "  Users who have completed orders:\n";
foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['department']})\n";
}
echo "\n";

// Example 7: Correlated subquery with aggregation
echo "7. Correlated subquery with aggregation...\n";
$results = $db->find()
    ->from('users')
    ->select([
        'name',
        'department',
        'salary',
        'dept_avg_salary' => function ($q) {
            $q->from('users u2')
              ->select([Db::avg('salary')])
              ->where('u2.department', 'users.department');
        },
        'salary_vs_dept' => Db::raw('(salary - (SELECT AVG(salary) FROM users u2 WHERE u2.department = users.department))')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['department']}): \${$row['salary']}\n";
    echo "    Dept avg: \${$row['dept_avg_salary']}, Difference: \${$row['salary_vs_dept']}\n";
}
echo "\n";

// Example 8: Subquery with JOIN
echo "8. Subquery with JOIN...\n";
// Oracle doesn't support AS keyword for subquery aliases
if ($driver === 'oci') {
    $results = $db->rawQuery('
        SELECT users.name, users.department, order_stats.order_count, order_stats.total_spent
        FROM users
        JOIN (SELECT user_id, COUNT(*) as order_count, SUM(amount) as total_spent 
              FROM orders GROUP BY user_id) order_stats 
        ON order_stats.user_id = users.id
    ');
} else {
    $results = $db->rawQuery('
        SELECT users.name, users.department, order_stats.order_count, order_stats.total_spent
        FROM users
        JOIN (SELECT user_id, COUNT(*) as order_count, SUM(amount) as total_spent 
              FROM orders GROUP BY user_id) as order_stats 
        ON order_stats.user_id = users.id
    ');
}

echo "  Users with order statistics:\n";
foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['department']}): {$row['order_count']} orders, \${$row['total_spent']} total\n";
}
echo "\n";

// Example 9: Multiple subqueries
echo "9. Multiple subqueries...\n";
$results = $db->find()
    ->from('users')
    ->select([
        'name',
        'department',
        'salary',
        'salary_rank' => function ($q) {
            $q->from('users u2')->select([Db::raw('COUNT(*) + 1')])->where('u2.salary', 'users.salary', '>');
        },
        'dept_rank' => function ($q) {
            $q->from('users u2')
              ->select([Db::raw('COUNT(*) + 1')])
              ->where('u2.department', 'users.department')
              ->andWhere('u2.salary', 'users.salary', '>');
        },
        'total_users' => function ($q) {
            $q->from('users')->select([Db::count()]);
        },
        'dept_users' => function ($q) {
            $q->from('users u2')->select([Db::count()])->where('u2.department', 'users.department');
        }
    ])
    ->orderBy('salary', 'DESC')
    ->get();

foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['department']}): \${$row['salary']}\n";
    echo "    Overall rank: {$row['salary_rank']}/{$row['total_users']}, Dept rank: {$row['dept_rank']}/{$row['dept_users']}\n";
}
echo "\n";

// Example 10: Subquery in HAVING
echo "10. Subquery in HAVING...\n";
$results = $db->rawQuery('
    SELECT department, AVG(salary) as avg_salary, COUNT(*) as emp_count
    FROM users
    GROUP BY department
    HAVING AVG(salary) > (SELECT AVG(salary) FROM users)
');

echo "  Departments with above-average salaries:\n";
foreach ($results as $row) {
    echo "  • {$row['department']}: \${$row['avg_salary']} avg salary, {$row['emp_count']} employees\n";
}
echo "\n";

// Example 11: Complex subquery with CASE
echo "11. Complex subquery with CASE...\n";
$results = $db->find()
    ->from('users')
    ->select([
        'name',
        'department',
        'salary',
        'performance_category' => Db::raw('(SELECT CASE 
            WHEN COUNT(*) = 0 THEN \'No Orders\'
            WHEN SUM(amount) > 2000 THEN \'High Performer\'
            WHEN SUM(amount) > 1000 THEN \'Good Performer\'
            ELSE \'Average Performer\'
        END FROM orders WHERE orders.user_id = users.id)'),
        'order_count' => function ($q) {
            $q->from('orders')->select([Db::count()])->where('orders.user_id', 'users.id');
        },
        'total_revenue' => function ($q) {
            $q->from('orders')->select([Db::coalesce(Db::sum('amount'), 0)])->where('orders.user_id', 'users.id');
        }
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['department']}): \${$row['salary']}\n";
    echo "    Performance: {$row['performance_category']}\n";
    echo "    Orders: {$row['order_count']}, Revenue: \${$row['total_revenue']}\n";
}
echo "\n";

// Example 12: Subquery with window functions simulation
echo "12. Subquery with window functions simulation...\n";
$results = $db->find()
    ->from('users')
    ->select([
        'name',
        'department',
        'salary',
        'salary_percentile' => Db::raw('(SELECT COUNT(*) * 100.0 / (SELECT COUNT(*) FROM users) FROM users u2 WHERE u2.salary <= users.salary)'),
        'dept_salary_percentile' => Db::raw('(SELECT COUNT(*) * 100.0 / (SELECT COUNT(*) FROM users u3 WHERE u3.department = users.department) FROM users u2 WHERE u2.department = users.department AND u2.salary <= users.salary)')
    ])
    ->orderBy('salary', 'DESC')
    ->get();

foreach ($results as $row) {
    echo "  • {$row['name']} ({$row['department']}): \${$row['salary']}\n";
    echo "    Overall percentile: " . round($row['salary_percentile'], 1) . "%\n";
    echo "    Dept percentile: " . round($row['dept_salary_percentile'], 1) . "%\n";
}

echo "\nSubqueries example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use subqueries in SELECT for calculated columns\n";
echo "  • Use subqueries in WHERE for complex filtering\n";
echo "  • Use subqueries in FROM for derived tables\n";
echo "  • EXISTS/NOT EXISTS check for record existence\n";
echo "  • IN subqueries filter by multiple values\n";
echo "  • Correlated subqueries reference outer query\n";
echo "  • Combine subqueries with JOINs for complex data\n";
echo "  • Use subqueries in HAVING for group filtering\n";
