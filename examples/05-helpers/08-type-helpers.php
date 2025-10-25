<?php
/**
 * Example: Type Helper Functions
 * 
 * Demonstrates type conversion and casting operations
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Type Helper Functions Example (on $driver) ===\n\n";

// Setup
recreateTable($db, 'data_types', [
    'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    'text_value' => 'TEXT',
    'numeric_value' => 'REAL',
    'date_value' => 'TEXT',
    'boolean_value' => 'BOOLEAN',
    'mixed_value' => 'TEXT'
]);

$db->find()->table('data_types')->insertMulti([
    ['text_value' => '123', 'numeric_value' => 45.67, 'date_value' => '2024-01-15', 'boolean_value' => 1, 'mixed_value' => '42.5'],
    ['text_value' => 'abc', 'numeric_value' => 0, 'date_value' => '2024-02-20', 'boolean_value' => 0, 'mixed_value' => 'hello'],
    ['text_value' => '789.12', 'numeric_value' => 100.0, 'date_value' => '2024-03-10', 'boolean_value' => 1, 'mixed_value' => '2024-12-25'],
    ['text_value' => 'true', 'numeric_value' => -15.5, 'date_value' => 'invalid-date', 'boolean_value' => 0, 'mixed_value' => '0'],
]);

echo "✓ Test data inserted\n\n";

// Example 1: Type casting
echo "1. Type casting...\n";
$driver = getCurrentDriver($db);
$intType = $driver === 'mysql' ? 'SIGNED' : ($driver === 'pgsql' ? 'INTEGER' : 'INTEGER');
$realType = $driver === 'mysql' ? 'DECIMAL(10,2)' : ($driver === 'pgsql' ? 'NUMERIC' : 'REAL');
$textType = $driver === 'mysql' ? 'CHAR(255)' : 'TEXT';

$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'cast_to_int' => $driver === 'pgsql' ? Db::raw('CASE WHEN text_value ~ \'^[0-9]+$\' THEN text_value::INTEGER ELSE 0 END') : Db::cast('text_value', $intType),
        'cast_to_real' => $driver === 'pgsql' ? Db::raw('CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END') : Db::cast('text_value', $realType),
        'cast_to_text' => Db::cast('numeric_value', $textType),
        'cast_mixed_to_real' => $driver === 'pgsql' ? Db::raw('CASE WHEN mixed_value ~ \'^[0-9]+\.?[0-9]*$\' THEN mixed_value::NUMERIC ELSE 0 END') : Db::cast('mixed_value', $realType)
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['text_value']} → int: {$row['cast_to_int']}, real: {$row['cast_to_real']}\n";
    echo "    {$row['numeric_value']} → text: {$row['cast_to_text']}\n";
    echo "    {$row['mixed_value']} → real: {$row['cast_mixed_to_real']}\n";
}
echo "\n";

// Example 2: GREATEST function
echo "2. GREATEST function...\n";
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'greatest_numeric' => Db::greatest('numeric_value', '0'),
        'greatest_mixed' => $driver === 'pgsql' ? Db::raw('GREATEST(CASE WHEN mixed_value ~ \'^[0-9]+\.?[0-9]*$\' THEN mixed_value::NUMERIC ELSE 0 END, numeric_value)') : Db::greatest(Db::cast('mixed_value', 'REAL'), 'numeric_value'),
        'greatest_all' => $driver === 'pgsql' ? Db::raw('GREATEST(numeric_value, CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END, 0)') : Db::greatest('numeric_value', Db::cast('text_value', 'REAL'), '0')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}, Mixed: {$row['mixed_value']}\n";
    echo "    Greatest numeric: {$row['greatest_numeric']}\n";
    echo "    Greatest mixed: {$row['greatest_mixed']}\n";
    echo "    Greatest all: {$row['greatest_all']}\n";
}
echo "\n";

// Example 3: LEAST function
echo "3. LEAST function...\n";
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'least_numeric' => Db::least('numeric_value', '50'),
        'least_mixed' => $driver === 'pgsql' ? Db::raw('LEAST(CASE WHEN mixed_value ~ \'^[0-9]+\.?[0-9]*$\' THEN mixed_value::NUMERIC ELSE 0 END, numeric_value)') : Db::least(Db::cast('mixed_value', 'REAL'), 'numeric_value'),
        'least_all' => $driver === 'pgsql' ? Db::raw('LEAST(numeric_value, CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END, 100)') : Db::least('numeric_value', Db::cast('text_value', 'REAL'), '100')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}, Mixed: {$row['mixed_value']}\n";
    echo "    Least numeric: {$row['least_numeric']}\n";
    echo "    Least mixed: {$row['least_mixed']}\n";
    echo "    Least all: {$row['least_all']}\n";
}
echo "\n";

// Example 4: Type checking with CASE
echo "4. Type checking with CASE...\n";
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'mixed_value',
        'is_numeric_text' => $driver === 'pgsql' ? Db::raw('CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN \'Yes\' ELSE \'No\' END') : Db::case([
            'CAST(text_value AS REAL) IS NOT NULL' => '\'Yes\'',
            'CAST(text_value AS REAL) IS NULL' => '\'No\''
        ], '\'Unknown\''),
        'is_numeric_mixed' => $driver === 'pgsql' ? Db::raw('CASE WHEN mixed_value ~ \'^[0-9]+\.?[0-9]*$\' THEN \'Yes\' ELSE \'No\' END') : Db::case([
            'CAST(mixed_value AS REAL) IS NOT NULL' => '\'Yes\'',
            'CAST(mixed_value AS REAL) IS NULL' => '\'No\''
        ], '\'Unknown\''),
        'is_date_mixed' => $driver === 'pgsql' ? Db::raw('CASE WHEN mixed_value ~ \'^[0-9]{4}-[0-9]{2}-[0-9]{2}$\' THEN \'Yes\' ELSE \'No\' END') : Db::case([
            'DATE(mixed_value) IS NOT NULL' => '\'Yes\'',
            'DATE(mixed_value) IS NULL' => '\'No\''
        ], '\'Unknown\'')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • Text: '{$row['text_value']}' → numeric: {$row['is_numeric_text']}\n";
    echo "    Mixed: '{$row['mixed_value']}' → numeric: {$row['is_numeric_mixed']}, date: {$row['is_date_mixed']}\n";
}
echo "\n";

// Example 5: Type conversion in WHERE clauses
echo "5. Type conversion in WHERE clauses...\n";
$results = $db->find()
    ->from('data_types')
    ->where($driver === 'pgsql' ? Db::raw('CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END') : Db::cast('text_value', 'REAL'), 100, '>')
    ->select(['text_value', 'numeric_value'])
    ->get();

echo "  Records where text_value as REAL > 100:\n";
foreach ($results as $row) {
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}\n";
}
echo "\n";

// Example 6: Type conversion in ORDER BY
echo "6. Type conversion in ORDER BY...\n";
$results = $db->find()
    ->from('data_types')
    ->select(['text_value', 'numeric_value', 'mixed_value'])
    ->orderBy($driver === 'pgsql' ? Db::raw('CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END') : Db::cast('text_value', 'REAL'), 'DESC')
    ->get();

echo "  Records ordered by text_value as REAL (descending):\n";
foreach ($results as $row) {
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}, Mixed: {$row['mixed_value']}\n";
}
echo "\n";

// Example 7: Complex type operations
echo "7. Complex type operations...\n";
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'safe_numeric' => $driver === 'pgsql' ? Db::raw('COALESCE(CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END, numeric_value, 0)') : Db::coalesce(Db::cast('text_value', 'REAL'), 'numeric_value', '0'),
        'type_category' => $driver === 'pgsql' ? Db::raw('CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN \'Numeric Text\' WHEN text_value = \'true\' OR text_value = \'false\' THEN \'Boolean Text\' WHEN text_value ~ \'^[0-9]{4}-[0-9]{2}-[0-9]{2}$\' THEN \'Date Text\' ELSE \'Other Text\' END') : Db::case([
            'CAST(text_value AS REAL) IS NOT NULL' => '"Numeric Text"',
            'text_value = "true" OR text_value = "false"' => '"Boolean Text"',
            'DATE(text_value) IS NOT NULL' => '"Date Text"'
        ], '"Other Text"'),
        'converted_sum' => Db::raw($driver === 'pgsql' ? '(CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END) + (CASE WHEN mixed_value ~ \'^[0-9]+\.?[0-9]*$\' THEN mixed_value::NUMERIC ELSE 0 END)' : 'CAST(text_value AS REAL) + CAST(mixed_value AS REAL)')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • Text: '{$row['text_value']}' ({$row['type_category']})\n";
    echo "    Safe numeric: {$row['safe_numeric']}\n";
    echo "    Converted sum: {$row['converted_sum']}\n";
}
echo "\n";

// Example 8: Type conversion with aggregation
echo "8. Type conversion with aggregation...\n";
$stats = $db->find()
    ->from('data_types')
    ->select([
        'total_records' => Db::count(),
        'avg_numeric' => Db::avg('numeric_value'),
        'avg_text_as_numeric' => $driver === 'pgsql' ? Db::raw('AVG(CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END)') : Db::avg(Db::cast('text_value', 'REAL')),
        'max_mixed_as_numeric' => $driver === 'pgsql' ? Db::raw('MAX(CASE WHEN mixed_value ~ \'^[0-9]+\.?[0-9]*$\' THEN mixed_value::NUMERIC ELSE 0 END)') : Db::max(Db::cast('mixed_value', 'REAL')),
        'min_text_as_numeric' => $driver === 'pgsql' ? Db::raw('MIN(CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END)') : Db::min(Db::cast('text_value', 'REAL'))
    ])
    ->getOne();

echo "  Type conversion statistics:\n";
echo "  • Total records: {$stats['total_records']}\n";
echo "  • Average numeric: " . round($stats['avg_numeric'], 2) . "\n";
echo "  • Average text as numeric: " . round($stats['avg_text_as_numeric'], 2) . "\n";
echo "  • Max mixed as numeric: {$stats['max_mixed_as_numeric']}\n";
echo "  • Min text as numeric: {$stats['min_text_as_numeric']}\n";
echo "\n";

// Example 9: Type conversion with GREATEST/LEAST
echo "9. Type conversion with GREATEST/LEAST...\n";
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'range_min' => Db::raw($driver === 'pgsql' ? 'LEAST(CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END, CASE WHEN mixed_value ~ \'^[0-9]+\.?[0-9]*$\' THEN mixed_value::NUMERIC ELSE 0 END, numeric_value)' : ($driver === 'sqlite' ? 'MIN(CAST(text_value AS REAL), CAST(mixed_value AS REAL), numeric_value)' : 'LEAST(CAST(text_value AS DECIMAL(10,2)), CAST(mixed_value AS DECIMAL(10,2)), numeric_value)')),
        'range_max' => Db::raw($driver === 'pgsql' ? 'GREATEST(CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END, CASE WHEN mixed_value ~ \'^[0-9]+\.?[0-9]*$\' THEN mixed_value::NUMERIC ELSE 0 END, numeric_value)' : ($driver === 'sqlite' ? 'MAX(CAST(text_value AS REAL), CAST(mixed_value AS REAL), numeric_value)' : 'GREATEST(CAST(text_value AS DECIMAL(10,2)), CAST(mixed_value AS DECIMAL(10,2)), numeric_value)')),
        'range_span' => Db::raw($driver === 'pgsql' ? 'GREATEST(CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END, CASE WHEN mixed_value ~ \'^[0-9]+\.?[0-9]*$\' THEN mixed_value::NUMERIC ELSE 0 END, numeric_value) - LEAST(CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN text_value::NUMERIC ELSE 0 END, CASE WHEN mixed_value ~ \'^[0-9]+\.?[0-9]*$\' THEN mixed_value::NUMERIC ELSE 0 END, numeric_value)' : ($driver === 'sqlite' ? 'MAX(CAST(text_value AS REAL), CAST(mixed_value AS REAL), numeric_value) - MIN(CAST(text_value AS REAL), CAST(mixed_value AS REAL), numeric_value)' : 'GREATEST(CAST(text_value AS DECIMAL(10,2)), CAST(mixed_value AS DECIMAL(10,2)), numeric_value) - LEAST(CAST(text_value AS DECIMAL(10,2)), CAST(mixed_value AS DECIMAL(10,2)), numeric_value)'))
    ])
    ->get();

foreach ($results as $row) {
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}, Mixed: {$row['mixed_value']}\n";
    echo "    Range: {$row['range_min']} to {$row['range_max']} (span: {$row['range_span']})\n";
}
echo "\n";

// Example 10: Type validation
echo "10. Type validation...\n";
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'mixed_value',
        'is_valid_numeric' => $driver === 'pgsql' ? Db::raw('CASE WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN \'Integer\' WHEN text_value ~ \'^[0-9]+\.?[0-9]*$\' THEN \'Real\' ELSE \'Not Numeric\' END') : Db::case([
            'CAST(text_value AS REAL) IS NOT NULL AND CAST(text_value AS REAL) = CAST(text_value AS SIGNED)' => '"Integer"',
            'CAST(text_value AS REAL) IS NOT NULL' => '"Real"',
            'CAST(text_value AS REAL) IS NULL' => '"Not Numeric"'
        ], '"Unknown"'),
        'is_valid_date' => $driver === 'pgsql' ? Db::raw('CASE WHEN mixed_value ~ \'^[0-9]{4}-[0-9]{2}-[0-9]{2}$\' THEN \'Valid Date\' ELSE \'Invalid Date\' END') : Db::case([
            'DATE(mixed_value) IS NOT NULL' => '"Valid Date"',
            'DATE(mixed_value) IS NULL' => '"Invalid Date"'
        ], '"Unknown"')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • Text '{$row['text_value']}': {$row['is_valid_numeric']}\n";
    echo "    Mixed '{$row['mixed_value']}': {$row['is_valid_date']}\n";
}

echo "\nType helper functions example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use CAST to convert between data types\n";
echo "  • Use GREATEST to find maximum value from multiple arguments\n";
echo "  • Use LEAST to find minimum value from multiple arguments\n";
echo "  • Type conversion works in SELECT, WHERE, ORDER BY clauses\n";
echo "  • Combine type functions with other helpers for complex operations\n";
echo "  • Use type validation to ensure data integrity\n";
echo "  • Type conversion is useful for data cleaning and analysis\n";
