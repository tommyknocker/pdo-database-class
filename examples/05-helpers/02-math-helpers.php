<?php
/**
 * Example: Math Helper Functions
 * 
 * Demonstrates mathematical operations and calculations
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Math Helper Functions Example (on $driver) ===\n\n";

// Setup
recreateTable($db, 'measurements', ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'name' => 'TEXT', 'value' => 'REAL', 'reading' => 'INTEGER']);

$db->find()->table('measurements')->insertMulti([
    ['name' => 'Temperature', 'value' => -5.7, 'reading' => 15],
    ['name' => 'Pressure', 'value' => 101.325, 'reading' => 42],
    ['name' => 'Humidity', 'value' => 65.432, 'reading' => 7],
    ['name' => 'Wind Speed', 'value' => -12.3, 'reading' => 23],
]);

echo "✓ Test data inserted\n\n";

// Example 1: ABS - Absolute value
echo "1. ABS - Absolute values...\n";
$results = $db->find()
    ->from('measurements')
    ->select([
        'name',
        'value',
        'absolute' => Db::abs('value')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['name']}: {$row['value']} → |{$row['absolute']}|\n";
}
echo "\n";

// Example 2: ROUND - Rounding numbers
echo "2. ROUND - Rounding to different precisions...\n";
$results = $db->find()
    ->from('measurements')
    ->select([
        'name',
        'value',
        'rounded_0' => Db::round('value', 0),
        'rounded_1' => Db::round('value', 1),
        'rounded_2' => Db::round('value', 2)
    ])
    ->limit(2)
    ->get();

foreach ($results as $row) {
    echo "  • {$row['name']}: {$row['value']}\n";
    echo "    0 decimals: {$row['rounded_0']}\n";
    echo "    1 decimal: {$row['rounded_1']}\n";
    echo "    2 decimals: {$row['rounded_2']}\n";
}
echo "\n";

// Example 3: MOD - Modulo operation
echo "3. MOD - Finding even/odd readings...\n";
$results = $db->find()
    ->from('measurements')
    ->select([
        'name',
        'reading',
        'remainder' => Db::mod('reading', '2')
    ])
    ->get();

foreach ($results as $row) {
    $evenOdd = ($row['remainder'] == 0) ? 'even' : 'odd';
    echo "  • {$row['name']}: reading {$row['reading']} is {$evenOdd} (remainder: {$row['remainder']})\n";
}
echo "\n";

// Example 3b: CEIL and FLOOR
echo "3b. CEIL and FLOOR - Rounding up/down...\n";
$rounding = $db->find()
    ->from('measurements')
    ->select([
        'name',
        'value',
        'ceil_val' => Db::ceil('value'),
        'floor_val' => Db::floor('value')
    ])
    ->limit(2)
    ->get();

foreach ($rounding as $row) {
    echo "  • {$row['name']}: value={$row['value']}, CEIL={$row['ceil_val']}, FLOOR={$row['floor_val']}\n";
}
echo "\n";

// Example 4: GREATEST - Maximum of multiple values
echo "4. GREATEST - Maximum of multiple columns...\n";
$driver = getCurrentDriver($db);
if ($driver === 'sqlsrv') {
    // MSSQL uses different syntax: ALTER TABLE ... ADD ... (without COLUMN keyword)
    $db->rawQuery("ALTER TABLE measurements ADD alt_reading INT DEFAULT 0");
} else {
    $db->rawQuery("ALTER TABLE measurements ADD COLUMN alt_reading INTEGER DEFAULT 0");
}
$db->find()->table('measurements')->where('id', 2, '<=')->update(['alt_reading' => Db::raw('reading + 10')]);
$db->find()->table('measurements')->where('id', 2, '>')->update(['alt_reading' => Db::raw('reading - 5')]);

$results = $db->find()
    ->from('measurements')
    ->select([
        'name',
        'reading',
        'alt_reading',
        'max_reading' => Db::greatest('reading', 'alt_reading')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['name']}: reading={$row['reading']}, alt={$row['alt_reading']}, max={$row['max_reading']}\n";
}
echo "\n";

// Example 5b: POWER, SQRT, EXP, LN/LOG
echo "5b. POWER/SQRT/EXP/LN/LOG - Advanced math...\n";
$driver = getCurrentDriver($db);
if ($driver === 'sqlsrv') {
    // MSSQL uses LOG for natural logarithm (without base) and LOG(base, value) for base logarithm
    // LN is not supported, so we use LOG instead
    $adv = $db->find()
        ->from('measurements')
        ->select([
            'name',
            'reading',
            'pow2' => Db::power('reading', 2),
            'sqrt_abs' => Db::sqrt(Db::abs('value')),
            'exp1' => Db::exp(1),
            'ln_abs' => Db::raw('LOG(ABS(value))'), // MSSQL uses LOG for natural logarithm
            'log10_abs' => Db::raw('LOG(10, ABS(value))') // MSSQL uses LOG(base, value)
        ])
        ->limit(2)
        ->get();
} else {
    $adv = $db->find()
        ->from('measurements')
        ->select([
            'name',
            'reading',
            'pow2' => Db::power('reading', 2),
            'sqrt_abs' => Db::sqrt(Db::abs('value')),
            'exp1' => Db::exp(1),
            'ln_abs' => Db::ln(Db::abs('value')),
            'log10_abs' => Db::log(Db::abs('value'))
        ])
        ->limit(2)
        ->get();
}

foreach ($adv as $row) {
    echo "  • {$row['name']}: pow(reading,2)={$row['pow2']}, sqrt(|value|)={$row['sqrt_abs']}\n";
}
echo "\n";

// Example 5: LEAST - Minimum of multiple values
echo "5. LEAST - Minimum of multiple columns...\n";
$results = $db->find()
    ->from('measurements')
    ->select([
        'name',
        'reading',
        'alt_reading',
        'min_reading' => Db::least('reading', 'alt_reading')
    ])
    ->get();

foreach ($results as $row) {
    echo "  • {$row['name']}: reading={$row['reading']}, alt={$row['alt_reading']}, min={$row['min_reading']}\n";
}
echo "\n";

// Example 10: TRUNC - Truncate numeric values
echo "10. TRUNC - Truncate values without rounding...\n";
$tr = $db->find()
    ->from('measurements')
    ->select([
        'name',
        'value',
        'trunc_0' => Db::trunc('value', 0),
        'trunc_1' => Db::trunc('value', 1)
    ])
    ->limit(2)
    ->get();

foreach ($tr as $row) {
    echo "  • {$row['name']}: {$row['value']} → trunc0={$row['trunc_0']}, trunc1={$row['trunc_1']}\n";
}
echo "\n";

// Example 6: INC and DEC operations (for UPDATE)
echo "6. INC and DEC operations (for UPDATE)...\n";
// Show current values
$before = $db->find()
    ->from('measurements')
    ->select(['name', 'value'])
    ->limit(2)
    ->get();

echo "  Before update:\n";
foreach ($before as $row) {
    echo "  • {$row['name']}: {$row['value']}\n";
}

// Update using INC and DEC
$db->find()->table('measurements')->where('id', 1)->update(['value' => Db::inc(5)]);
$db->find()->table('measurements')->where('id', 2)->update(['value' => Db::dec(2)]);

// Show updated values
$after = $db->find()
    ->from('measurements')
    ->select(['name', 'value'])
    ->limit(2)
    ->get();

echo "  After update:\n";
foreach ($after as $row) {
    echo "  • {$row['name']}: {$row['value']}\n";
}
echo "\n";

// Example 7: Combining math functions
echo "7. Complex calculation - Normalized scores...\n";
$results = $db->find()
    ->from('measurements')
    ->select([
        'name',
        'value',
        'abs_value' => Db::abs('value'),
        'normalized' => Db::round(Db::abs('value'), 1),
        'modulo_10' => Db::mod('reading', 10)
    ])
    ->orderBy(Db::abs('value'), 'DESC')
    ->get();

echo "  Measurements ordered by absolute value:\n";
foreach ($results as $row) {
    echo "  • {$row['name']}: {$row['value']} → abs: {$row['abs_value']}, normalized: {$row['normalized']}, mod 10: {$row['modulo_10']}\n";
}
echo "\n";

// Example 8: Using math in WHERE clause
echo "8. Filtering by math operations...\n";
$filtered = $db->find()
    ->from('measurements')
    ->select(['name', 'value'])
    ->where(Db::abs('value'), 10, '>')
    ->get();

echo "  Measurements with |value| > 10:\n";
foreach ($filtered as $row) {
    echo "  • {$row['name']}: {$row['value']}\n";
}
echo "\n";

// Example 9: Advanced mathematical operations
echo "9. Advanced mathematical operations...\n";
$driver = getCurrentDriver($db);
$rangeCheckFunc = $driver === 'sqlite' ? 'MAX(MIN(value, 100), -100)' : 'GREATEST(LEAST(value, 100), -100)';

$results = $db->find()
    ->from('measurements')
    ->select([
        'name',
        'value',
        'reading',
        'percentage' => Db::round(Db::raw('(value / 100) * 100'), 1),
        'is_even' => Db::mod('reading', 2),
        'range_check' => Db::raw($rangeCheckFunc)
    ])
    ->get();

foreach ($results as $row) {
    $isEven = $row['is_even'] == 0 ? 'even' : 'odd';
    echo "  • {$row['name']}: value={$row['value']}, reading={$row['reading']} ({$isEven})\n";
    echo "    percentage={$row['percentage']}%, range_check={$row['range_check']}\n";
}

echo "\nMath helper functions example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use ABS for absolute values\n";
echo "  • Use ROUND to control precision\n";
echo "  • Use MOD for modulo operations (even/odd, cycles)\n";
echo "  • Use GREATEST/LEAST to compare multiple values\n";
echo "  • Use INC/DEC for increment/decrement operations\n";
echo "  • Combine functions for complex calculations\n";

