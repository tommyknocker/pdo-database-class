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
    // Use CASE to safely check if value can be converted to NUMBER
    // Oracle doesn't have TRY_CAST, so we use REGEXP_LIKE to validate number format first
    // Note: For complex expressions with TO_CHAR inside CASE, we use raw SQL
    // For simple TO_DATE/TO_TIMESTAMP, use Db::toDate() and Db::toTs() helpers
    $castTextExpr = "CASE WHEN REGEXP_LIKE(TO_CHAR(\"TEXT_VALUE\"), '^-?[0-9]+(\\.[0-9]+)?$') THEN CAST(TO_CHAR(\"TEXT_VALUE\") AS $castRealType) ELSE NULL END";
    $castMixedExpr = "CASE WHEN REGEXP_LIKE(TO_CHAR(\"MIXED_VALUE\"), '^-?[0-9]+(\\.[0-9]+)?$') THEN CAST(TO_CHAR(\"MIXED_VALUE\") AS $castRealType) ELSE NULL END";
    // Use CASE to safely check if value can be converted to DATE
    // Oracle doesn't have TRY_CAST, so we use REGEXP_LIKE to validate date format first
    // Then use TO_DATE with exception handling
    $castDateExpr = "CASE WHEN REGEXP_LIKE(TO_CHAR(\"MIXED_VALUE\"), '^[0-9]{4}-[0-9]{2}-[0-9]{2}$') THEN TO_DATE(TO_CHAR(\"MIXED_VALUE\"), 'YYYY-MM-DD') ELSE NULL END";
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
// For Oracle, filter valid numeric values first, then use CAST
if ($driver === 'oci') {
    // Filter rows where text_value can be cast to number, then use CAST
    $results = $db->find()
        ->from('data_types')
        ->whereRaw('REGEXP_LIKE(TO_CHAR("TEXT_VALUE"), \'^-?[0-9]+(\\.[0-9]+)?$\')')
        ->where(Db::cast('text_value', $castRealType), 100, '>')
        ->select(['text_value', 'numeric_value'])
        ->get();
} else {
    $results = $db->find()
        ->from('data_types')
        ->where(Db::cast('text_value', $castRealType), 100, '>')
        ->select(['text_value', 'numeric_value'])
        ->get();
}

echo "  Records where text_value as REAL > 100:\n";
foreach ($results as $row) {
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}\n";
}
echo "\n";

// Example 6: Type conversion in ORDER BY
echo "6. Type conversion in ORDER BY...\n";
$castRealType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'oci' ? 'NUMBER' : 'REAL');
// For Oracle, use COALESCE with CAST to handle invalid values
if ($driver === 'oci') {
    // Use COALESCE to provide default value for invalid casts
    $results = $db->find()
        ->from('data_types')
        ->select(['text_value', 'numeric_value', 'mixed_value'])
        ->orderBy(Db::coalesce(Db::cast('text_value', $castRealType), Db::raw('0')), 'DESC')
        ->get();
} else {
    $results = $db->find()
        ->from('data_types')
        ->select(['text_value', 'numeric_value', 'mixed_value'])
        ->orderBy(Db::cast('text_value', $castRealType), 'DESC')
        ->get();
}

echo "  Records ordered by text_value as REAL (descending):\n";
foreach ($results as $row) {
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}, Mixed: {$row['mixed_value']}\n";
}
echo "\n";

// Example 7: Complex type operations
echo "7. Complex type operations...\n";
$castRealType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'oci' ? 'NUMBER' : 'REAL');
// For Oracle, filter valid numeric values first, then use CAST
if ($driver === 'oci') {
    // Filter rows where both text_value and mixed_value can be cast, then use CAST
    $results = $db->find()
        ->from('data_types')
        ->whereRaw('REGEXP_LIKE(TO_CHAR("TEXT_VALUE"), \'^-?[0-9]+(\\.[0-9]+)?$\')')
        ->whereRaw('REGEXP_LIKE(TO_CHAR("MIXED_VALUE"), \'^-?[0-9]+(\\.[0-9]+)?$\')')
        ->select([
            'text_value',
            'numeric_value',
            'mixed_value',
            'safe_numeric' => Db::coalesce(Db::cast('text_value', $castRealType), Db::raw('TO_NUMBER("NUMERIC_VALUE")'), Db::raw('0')),
            'type_category' => '\'Numeric Text\'',
            'converted_sum' => Db::raw('NVL(' . Db::cast('text_value', $castRealType)->getValue() . ', 0) + NVL(' . Db::cast('mixed_value', $castRealType)->getValue() . ', 0)')
        ])
        ->get();
} else {
    $results = $db->find()
        ->from('data_types')
        ->select([
            'text_value',
            'numeric_value',
            'mixed_value',
            'safe_numeric' => Db::coalesce(Db::cast('text_value', $castRealType), 'numeric_value', Db::raw('0')),
            'type_category' => Db::case([
                (Db::cast('text_value', $castRealType)->getValue() . ' IS NOT NULL') => '\'Numeric Text\'',
                'text_value = \'true\' OR text_value = \'false\'' => '\'Boolean Text\'',
                (Db::cast('text_value', 'DATE')->getValue() . ' IS NOT NULL') => '\'Date Text\''
            ], '\'Other Text\''),
            'converted_sum' => Db::raw('COALESCE(' . Db::cast('text_value', $castRealType)->getValue() . ', 0) + COALESCE(' . Db::cast('mixed_value', $castRealType)->getValue() . ', 0)')
        ])
        ->get();
}

foreach ($results as $row) {
    echo "  • Text: '{$row['text_value']}' ({$row['type_category']})\n";
    echo "    Safe numeric: {$row['safe_numeric']}\n";
    echo "    Converted sum: {$row['converted_sum']}\n";
}
echo "\n";

// Example 8: Type conversion with aggregation
echo "8. Type conversion with aggregation...\n";
$castRealType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'oci' ? 'NUMBER' : 'REAL');
// Note: AVG/MAX/MIN with CAST may return NULL for invalid values
// For Oracle, filter valid numeric values first, then use CAST
if ($driver === 'oci') {
    // Filter rows where values can be cast to number, then use CAST
    $stats = $db->find()
        ->from('data_types')
        ->whereRaw('REGEXP_LIKE(TO_CHAR("TEXT_VALUE"), \'^-?[0-9]+(\\.[0-9]+)?$\')')
        ->whereRaw('REGEXP_LIKE(TO_CHAR("MIXED_VALUE"), \'^-?[0-9]+(\\.[0-9]+)?$\')')
        ->select([
            'total_records' => Db::count(),
            'avg_numeric' => Db::avg('numeric_value'),
            'avg_text_as_numeric' => Db::avg(Db::cast('text_value', $castRealType)),
            'max_mixed_as_numeric' => Db::max(Db::cast('mixed_value', $castRealType)),
            'min_text_as_numeric' => Db::min(Db::cast('text_value', $castRealType))
        ])
        ->getOne();
} else {
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
}

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
// For range_span, we calculate it using the difference between max and min
// This demonstrates using GREATEST and LEAST helpers for finding ranges
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'numeric_value',
        'mixed_value',
        'range_min' => Db::least(Db::cast('text_value', $realType), Db::cast('mixed_value', $realType), 'numeric_value'),
        'range_max' => Db::greatest(Db::cast('text_value', $realType), Db::cast('mixed_value', $realType), 'numeric_value')
    ])
    ->get();

foreach ($results as $row) {
    $rangeSpan = $row['range_max'] !== null && $row['range_min'] !== null 
        ? (float)$row['range_max'] - (float)$row['range_min'] 
        : null;
    echo "  • Text: {$row['text_value']}, Numeric: {$row['numeric_value']}, Mixed: {$row['mixed_value']}\n";
    echo "    Range: {$row['range_min']} to {$row['range_max']}" . ($rangeSpan !== null ? " (span: {$rangeSpan})" : '') . "\n";
}
echo "\n";

// Example 10: Type validation
echo "10. Type validation...\n";
$castRealType = ($driver === 'mysql' || $driver === 'mariadb') ? 'DECIMAL(10,2)' : ($driver === 'oci' ? 'NUMBER' : 'REAL');
$intType = ($driver === 'mysql' || $driver === 'mariadb') ? 'SIGNED' : 'INTEGER';
// Use CASE with regexpLike and helpers to safely check if value can be converted
// This demonstrates combining CAST, COALESCE, CASE, regexpLike, toChar, and toDate helpers
if ($driver === 'oci') {
    // For Oracle, use regexpLike with toChar for safe validation before CAST
    $numericPattern = '^-?[0-9]+(\\.[0-9]+)?$';
    $datePattern = '^[0-9]{4}-[0-9]{2}-[0-9]{2}$';
    // For Oracle, we need to build SQL manually because ToCharValue::getValue() returns empty string
    // and Db::cast() uses getValue() which doesn't work correctly with ToCharValue
    // Use raw SQL to build the CASE expressions properly
    $castRealSafe = Db::raw("CASE WHEN REGEXP_LIKE(TO_CHAR(\"TEXT_VALUE\"), '$numericPattern') THEN CAST(TO_CHAR(\"TEXT_VALUE\") AS $castRealType) ELSE NULL END");
    $castIntSafe = Db::raw("CASE WHEN REGEXP_LIKE(TO_CHAR(\"TEXT_VALUE\"), '$numericPattern') THEN CAST(TO_CHAR(\"TEXT_VALUE\") AS $intType) ELSE NULL END");
    // For date, use regexpLike with toChar and toDate
    $castDateSafe = Db::raw("CASE WHEN REGEXP_LIKE(TO_CHAR(\"MIXED_VALUE\"), '$datePattern') THEN TO_DATE(TO_CHAR(\"MIXED_VALUE\"), 'YYYY-MM-DD') ELSE NULL END");
} else {
    $castRealSafe = Db::coalesce(Db::cast('text_value', $castRealType), Db::null());
    $castIntSafe = Db::coalesce(Db::cast('text_value', $intType), Db::null());
    $castDateSafe = Db::coalesce(Db::cast('mixed_value', 'DATE'), Db::null());
}
// Use RawValue expressions directly in CASE conditions
// Note: Db::case() now accepts RawValue instances in both keys and values
// For checking NULL on expressions, we need to use raw SQL since isNull/isNotNull helpers
// are designed for column names, not expressions
// Use RawValue directly in nested CASE - RawValueResolver will properly resolve nested expressions
$results = $db->find()
    ->from('data_types')
    ->select([
        'text_value',
        'mixed_value',
        // Use nested CASE to check if cast expressions are NULL
        // Use getValue() to get SQL string from RawValue for array keys
        'is_valid_numeric' => Db::case([
            "({$castRealSafe->getValue()}) IS NOT NULL AND ({$castIntSafe->getValue()}) IS NOT NULL AND ({$castRealSafe->getValue()}) = ({$castIntSafe->getValue()})" => '\'Integer\'',
            "({$castRealSafe->getValue()}) IS NOT NULL" => '\'Real\'',
            "({$castRealSafe->getValue()}) IS NULL" => '\'Not Numeric\''
        ], '\'Unknown\''),
        'is_valid_date' => Db::case([
            "({$castDateSafe->getValue()}) IS NOT NULL" => '\'Valid Date\'',
            "({$castDateSafe->getValue()}) IS NULL" => '\'Invalid Date\''
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
