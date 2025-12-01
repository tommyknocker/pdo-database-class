<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * Enum generator for PHP Enum classes from database ENUM columns.
 *
 * Generates PHP Enum classes with values extracted from database ENUM columns.
 */
class EnumGenerator extends BaseCliCommand
{
    /**
     * Generate Enum class from database ENUM column.
     *
     * @param string $tableName Table name
     * @param string $columnName Column name
     * @param string|null $namespace Enum namespace
     * @param string|null $outputPath Output path for enum file
     * @param PdoDb|null $db Database instance
     * @param bool $force Overwrite existing file
     *
     * @return string Path to created enum file
     */
    public static function generate(
        string $tableName,
        string $columnName,
        ?string $namespace = null,
        ?string $outputPath = null,
        ?PdoDb $db = null,
        bool $force = false
    ): string {
        if ($db === null) {
            $db = static::createDatabase();
        }
        $driver = static::getDriverName($db);

        if (getenv('PHPUNIT') === false) {
            echo "PDOdb Enum Generator\n";
            echo "Database: {$driver}\n\n";
        }

        // Verify table exists
        if (!$db->schema()->tableExists($tableName)) {
            throw new QueryException("Table '{$tableName}' does not exist in the database.");
        }

        // Get table structure
        try {
            $columns = $db->describe($tableName);
        } catch (QueryException $e) {
            static::error("Failed to describe table '{$tableName}': " . $e->getMessage());
        }

        // Find the column
        $targetColumn = null;
        foreach ($columns as $column) {
            $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? null;
            if ($colName === $columnName) {
                $targetColumn = $column;
                break;
            }
        }

        if ($targetColumn === null) {
            throw new QueryException("Column '{$columnName}' does not exist in table '{$tableName}'.");
        }

        // Extract ENUM values using dialect-specific method
        $dialect = $db->schema()->getDialect();
        $enumValues = $dialect->extractEnumValues($targetColumn, $db, $tableName, $columnName);
        if (empty($enumValues)) {
            throw new QueryException("Column '{$columnName}' is not an ENUM type or has no values.");
        }

        $enumName = static::generateEnumName($tableName, $columnName);

        // Get output path
        if ($outputPath === null) {
            $outputPath = static::getEnumOutputPath();
        }

        // Generate enum code
        $enumNamespace = $namespace !== null && $namespace !== '' ? $namespace : 'App\\Enums';
        $enumCode = static::generateEnumCode($enumName, $enumValues, $enumNamespace, $tableName, $columnName);

        // Write enum file
        $filename = $outputPath . '/' . $enumName . '.php';
        if (file_exists($filename)) {
            $overwrite = $force ? true : static::readConfirmation('Enum file already exists. Overwrite?', false);
            if (!$overwrite) {
                static::info('Enum generation cancelled.');
                exit(0);
            }
        }

        // Ensure directory exists
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filename, $enumCode);

        static::success('Enum file created: ' . basename($filename));
        if (getenv('PHPUNIT') === false) {
            echo "  Path: {$filename}\n";
            echo "  Table: {$tableName}\n";
            echo "  Column: {$columnName}\n";
            echo '  Values: ' . implode(', ', $enumValues) . "\n";
        }

        return $filename;
    }

    /**
     * Generate enum name from table and column.
     *
     * @param string $tableName Table name
     * @param string $columnName Column name
     *
     * @return string
     */
    protected static function generateEnumName(string $tableName, string $columnName): string
    {
        // Convert table_name_column_name to TableNameColumnNameEnum
        $parts = array_merge(explode('_', $tableName), explode('_', $columnName));
        $camelCase = '';
        foreach ($parts as $part) {
            $camelCase .= ucfirst($part);
        }
        return $camelCase . 'Enum';
    }

    /**
     * Generate enum code.
     *
     * @param string $enumName Enum class name
     * @param array<int, string> $enumValues Enum values
     * @param string $namespace Enum namespace
     * @param string $tableName Table name
     * @param string $columnName Column name
     *
     * @return string
     */
    protected static function generateEnumCode(
        string $enumName,
        array $enumValues,
        string $namespace,
        string $tableName,
        string $columnName
    ): string {
        $cases = '';
        foreach ($enumValues as $value) {
            $caseName = static::valueToCaseName($value);
            $escapedValue = addslashes($value);
            $cases .= "    case {$caseName} = '{$escapedValue}';\n";
        }

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

/**
 * Enum for {$tableName}.{$columnName} column.
 *
 * Auto-generated by PDOdb Enum Generator
 */
enum {$enumName}: string
{
{$cases}
    /**
     * Get all enum values as array.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn(\$case) => \$case->value, self::cases());
    }

    /**
     * Get all enum cases as array.
     *
     * @return array<int, self>
     */
    public static function cases(): array
    {
        return parent::cases();
    }
}

PHP;

        return $code;
    }

    /**
     * Convert enum value to case name.
     *
     * @param string $value Enum value
     *
     * @return string
     */
    protected static function valueToCaseName(string $value): string
    {
        // Convert to uppercase and replace non-alphanumeric with underscore
        $name = strtoupper($value);
        $name = preg_replace('/[^A-Z0-9_]/', '_', $name) ?? '';
        $name = preg_replace('/_+/', '_', $name) ?? '';
        $name = trim($name, '_');

        // If starts with digit, prefix with underscore
        if (ctype_digit($name[0] ?? '')) {
            $name = '_' . $name;
        }

        // If empty, use a default
        if ($name === '') {
            $name = 'VALUE';
        }

        return $name;
    }

    /**
     * Get enum output path.
     *
     * @return string
     */
    protected static function getEnumOutputPath(): string
    {
        $path = getenv('PDODB_ENUM_PATH');
        if ($path && is_dir($path)) {
            return $path;
        }

        $possiblePaths = [
            getcwd() . '/app/Enums',
            getcwd() . '/src/Enums',
            getcwd() . '/enums',
            __DIR__ . '/../../enums',
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        $defaultPath = getcwd() . '/app/Enums';
        if (!is_dir($defaultPath)) {
            mkdir($defaultPath, 0755, true);
        }

        return $defaultPath;
    }
}
