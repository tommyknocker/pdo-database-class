<?php
/**
 * Example: Type Helper Functions
 * 
 * Demonstrates type conversion and casting operations using library helpers
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Type Helper Functions Example (on $driver) ===\n\n";

// Setup
$schema = $db->schema();
recreateTable($db, 'data_types', [
    'id' => $schema->primaryKey(),
    'text_value' => $schema->text(),
    'numeric_value' => $schema->decimal(10, 2),
    'date_value' => $schema->text(),
    'boolean_value' => $schema->boolean(),
    'mixed_value' => $schema->text(),
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
$intType = ($driver === 'mysql' || $driver === 'mariadb') ? 'SIGNED' : 'INTEGER';
$realType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'pgsql' ? 'NUMERIC' : ($driver === 'oci' ? 'NUMBER' : 'REAL'));
$textType = ($driver === 'mysql' || $driver === 'mariadb') ? 'CHAR(255)' : (($driver === 'sqlsrv') ? 'NVARCHAR(255)' : ($driver === 'oci' ? 'VARCHAR2(4000)' : 'TEXT'));

// Use COALESCE to handle invalid cast values gracefully
// This demonstrates safe type conversion using library helpers
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'cast_to_int' => Db::coalesce(Db::cast('text_value', $intType), '0'),
        'cast_to_real' => Db::coalesce(Db::cast('text_value', $realType), '0'),
        'cast_to_text' => Db::cast('numeric_value', $textType),
        'cast_mixed_to_real' => Db::coalesce(Db::cast('mixed_value', $realType), '0')
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
$realType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'pgsql' ? 'NUMERIC' : ($driver === 'oci' ? 'NUMBER' : 'REAL'));
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'greatest_numeric' => Db::greatest('numeric_value', '0'),
        'greatest_mixed' => Db::greatest(Db::cast('mixed_value', $realType), 'numeric_value'),
        'greatest_all' => Db::greatest('numeric_value', Db::cast('text_value', $realType), '0')
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
$realType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'pgsql' ? 'NUMERIC' : ($driver === 'oci' ? 'NUMBER' : 'REAL'));
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'least_numeric' => Db::least('numeric_value', '50'),
        'least_mixed' => Db::least(Db::cast('mixed_value', $realType), 'numeric_value'),
        'least_all' => Db::least('numeric_value', Db::cast('text_value', $realType), '100')
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
$castRealType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'oci' ? 'NUMBER' : 'REAL');
if ($driver === 'oci') {
    // Oracle requires TO_CHAR() for CLOB columns before CAST
    $castTextExpr = "CAST(TO_CHAR(\"TEXT_VALUE\") AS $castRealType)";
    $castMixedExpr = "CAST(TO_CHAR(\"MIXED_VALUE\") AS $castRealType)";
    $castDateExpr = "CAST(TO_CHAR(\"MIXED_VALUE\") AS DATE)";
} else {
    $castTextExpr = Db::cast('text_value', $castRealType)->getValue();
    $castMixedExpr = Db::cast('mixed_value', $castRealType)->getValue();
    $castDateExpr = Db::cast('mixed_value', 'DATE')->getValue();
}
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'mixed_value',
        'is_numeric_text' => Db::case([
            ($castTextExpr . ' IS NOT NULL') => '\'Yes\'',
            ($castTextExpr . ' IS NULL') => '\'No\''
        ], '\'Unknown\''),
        'is_numeric_mixed' => Db::case([
            ($castMixedExpr . ' IS NOT NULL') => '\'Yes\'',
            ($castMixedExpr . ' IS NULL') => '\'No\''
        ], '\'Unknown\''),
        'is_date_mixed' => Db::case([
            ($castDateExpr . ' IS NOT NULL') => '\'Yes\'',
            ($castDateExpr . ' IS NULL') => '\'No\''
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
$castRealType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'oci' ? 'NUMBER' : 'REAL');
$results = $db->find()
    ->from('data_types')
    ->where(Db::cast('text_value', $castRealType), 100, '>')
    ->select(['text_value', 'numeric_value'])
    ->get();

echo "  Records where text_value as REAL > 100:\n";
foreach ($results as $row) {
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}\n";
}
echo "\n";

// Example 6: Type conversion in ORDER BY
echo "6. Type conversion in ORDER BY...\n";
$castRealType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'oci' ? 'NUMBER' : 'REAL');
$results = $db->find()
    ->from('data_types')
    ->select(['text_value', 'numeric_value', 'mixed_value'])
    ->orderBy(Db::cast('text_value', $castRealType), 'DESC')
    ->get();

echo "  Records ordered by text_value as REAL (descending):\n";
foreach ($results as $row) {
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}, Mixed: {$row['mixed_value']}\n";
}
echo "\n";

// Example 7: Complex type operations
echo "7. Complex type operations...\n";
$castRealType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'oci' ? 'NUMBER' : 'REAL');
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'safe_numeric' => Db::coalesce(Db::cast('text_value', $castRealType), 'numeric_value', '0'),
        'type_category' => Db::case([
            (($driver === 'oci' ? "CAST(TO_CHAR(\"TEXT_VALUE\") AS $castRealType)" : Db::cast('text_value', $castRealType)->getValue()) . ' IS NOT NULL') => '\'Numeric Text\'',
            'text_value = \'true\' OR text_value = \'false\'' => '\'Boolean Text\'',
            (($driver === 'oci' ? "CAST(TO_CHAR(\"TEXT_VALUE\") AS DATE)" : Db::cast('text_value', 'DATE')->getValue()) . ' IS NOT NULL') => '\'Date Text\''
        ], '\'Other Text\''),
        'converted_sum' => Db::raw('(' . ($driver === 'oci' ? "CAST(TO_CHAR(\"TEXT_VALUE\") AS $castRealType)" : Db::cast('text_value', $castRealType)->getValue()) . ') + (' . ($driver === 'oci' ? "CAST(TO_CHAR(\"MIXED_VALUE\") AS $castRealType)" : Db::cast('mixed_value', $castRealType)->getValue()) . ')')
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
$castRealType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'oci' ? 'NUMBER' : 'REAL');
// Note: AVG/MAX/MIN with TRY_CAST may return NULL for invalid values
// In production, you might want to use COALESCE or filter out NULL values
$stats = $db->find()
    ->from('data_types')
    ->select([
        'total_records' => Db::count(),
        'avg_numeric' => Db::avg('numeric_value'),
        'avg_text_as_numeric' => Db::avg(Db::cast('text_value', $castRealType)),
        'max_mixed_as_numeric' => Db::max(Db::cast('mixed_value', $castRealType)),
        'min_text_as_numeric' => Db::min(Db::cast('text_value', $castRealType))
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
$realType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'pgsql' ? 'NUMERIC' : ($driver === 'oci' ? 'NUMBER' : 'REAL'));
// Use library helpers for range_min and range_max
// For range_span, we need to subtract two expressions
// We'll resolve the helpers to SQL strings for use in Db::raw()
$castTextExpr = Db::cast('text_value', $realType)->getValue();
$castMixedExpr = Db::cast('mixed_value', $realType)->getValue();
// Build GREATEST/LEAST expressions manually for range_span since we need to subtract them
// This demonstrates combining helpers with raw SQL for complex expressions
$greatestExpr = $db->connection->getDialect()->formatGreatest([
    Db::cast('text_value', $realType),
    Db::cast('mixed_value', $realType),
    'numeric_value'
]);
$leastExpr = $db->connection->getDialect()->formatLeast([
    Db::cast('text_value', $realType),
    Db::cast('mixed_value', $realType),
    'numeric_value'
]);
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'range_min' => Db::least(Db::cast('text_value', $realType), Db::cast('mixed_value', $realType), 'numeric_value'),
        'range_max' => Db::greatest(Db::cast('text_value', $realType), Db::cast('mixed_value', $realType), 'numeric_value'),
        'range_span' => Db::raw("({$greatestExpr}) - ({$leastExpr})")
    ])
    ->get();

foreach ($results as $row) {
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}, Mixed: {$row['mixed_value']}\n";
    echo "    Range: {$row['range_min']} to {$row['range_max']} (span: {$row['range_span']})\n";
}
echo "\n";

// Example 10: Type validation
echo "10. Type validation...\n";
$castRealType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'oci' ? 'NUMBER' : 'REAL');
$intType = ($driver === 'mysql' || $driver === 'mariadb') ? 'SIGNED' : 'INTEGER';
// Use COALESCE with CAST to safely check if value can be converted
// COALESCE(CAST(...), NULL) returns NULL if CAST fails (for dialects that use TRY_CAST or safe CAST)
// Then use CASE to categorize based on whether cast succeeded
// This demonstrates combining CAST, COALESCE, and CASE helpers for type validation
$castRealSafe = Db::coalesce(Db::cast('text_value', $castRealType), Db::null());
$castIntSafe = Db::coalesce(Db::cast('text_value', $intType), Db::null());
$castDateSafe = Db::coalesce(Db::cast('mixed_value', 'DATE'), Db::null());
// Resolve expressions to SQL strings for use in CASE conditions
$resolver = new \tommyknocker\pdodb\query\RawValueResolver($db->connection, new \tommyknocker\pdodb\query\ParameterManager());
$castRealSql = $resolver->resolveRawValue($castRealSafe);
$castIntSql = $resolver->resolveRawValue($castIntSafe);
$castDateSql = $resolver->resolveRawValue($castDateSafe);
// Build CASE conditions using resolved SQL expressions
// Note: For checking NULL on expressions, we need to use SQL directly since isNull/isNotNull helpers
// are designed for column names, not expressions. This demonstrates combining helpers with raw SQL.
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'mixed_value',
        'is_valid_numeric' => Db::case([
            ($castRealSql . ' IS NOT NULL AND ' . $castIntSql . ' IS NOT NULL AND ' . $castRealSql . ' = ' . $castIntSql) => '\'Integer\'',
            ($castRealSql . ' IS NOT NULL') => '\'Real\'',
            ($castRealSql . ' IS NULL') => '\'Not Numeric\''
        ], '\'Unknown\''),
        'is_valid_date' => Db::case([
            ($castDateSql . ' IS NOT NULL') => '\'Valid Date\'',
            ($castDateSql . ' IS NULL') => '\'Invalid Date\''
        ], '\'Unknown\'')
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
