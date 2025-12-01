<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * DTO generator for Data Transfer Objects.
 *
 * Generates DTO classes with properties matching table columns.
 */
class DtoGenerator extends BaseCliCommand
{
    /**
     * Generate DTO from table or model.
     *
     * @param string|null $tableName Table name
     * @param string|null $modelName Model class name
     * @param string|null $namespace DTO namespace
     * @param string|null $outputPath Output path for DTO file
     * @param PdoDb|null $db Database instance
     * @param bool $force Overwrite existing file
     *
     * @return string Path to created DTO file
     */
    public static function generate(
        ?string $tableName,
        ?string $modelName,
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
            echo "PDOdb DTO Generator\n";
            echo "Database: {$driver}\n\n";
        }

        // Resolve table name
        $resolvedTableName = static::resolveTableName($tableName, $modelName, $db);
        if ($resolvedTableName === null) {
            static::error('Could not resolve table name. Please provide --table or --model option.');
        }

        // Verify table exists
        if (!$db->schema()->tableExists($resolvedTableName)) {
            throw new QueryException("Table '{$resolvedTableName}' does not exist in the database.");
        }

        // Get table structure
        try {
            $columns = $db->describe($resolvedTableName);
        } catch (QueryException $e) {
            static::error("Failed to describe table '{$resolvedTableName}': " . $e->getMessage());
        }

        $dtoName = static::generateDtoName($resolvedTableName, $modelName);

        // Get output path
        if ($outputPath === null) {
            $outputPath = static::getDtoOutputPath();
        }

        // Generate DTO code
        $dtoNamespace = $namespace !== null && $namespace !== '' ? $namespace : 'App\\DTOs';
        $dtoCode = static::generateDtoCode($dtoName, $resolvedTableName, $columns, $dtoNamespace);

        // Write DTO file
        $filename = $outputPath . '/' . $dtoName . '.php';
        if (file_exists($filename)) {
            $overwrite = $force ? true : static::readConfirmation('DTO file already exists. Overwrite?', false);
            if (!$overwrite) {
                static::info('DTO generation cancelled.');
                exit(0);
            }
        }

        // Ensure directory exists
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filename, $dtoCode);

        static::success('DTO file created: ' . basename($filename));
        if (getenv('PHPUNIT') === false) {
            echo "  Path: {$filename}\n";
            echo "  Table: {$resolvedTableName}\n";
            if ($modelName !== null) {
                echo "  Model: {$modelName}\n";
            }
        }

        return $filename;
    }

    /**
     * Resolve table name from table or model option.
     *
     * @param string|null $tableName Table name
     * @param string|null $modelName Model name
     * @param PdoDb $db Database instance
     *
     * @return string|null
     */
    protected static function resolveTableName(?string $tableName, ?string $modelName, PdoDb $db): ?string
    {
        if ($tableName !== null) {
            return $tableName;
        }

        if ($modelName !== null) {
            // Try to find model class and get table name from it
            $possibleNamespaces = ['App\\Models', 'App\\Entities', 'Models', 'Entities'];
            foreach ($possibleNamespaces as $ns) {
                $className = $ns . '\\' . $modelName;
                if (class_exists($className)) {
                    $reflection = new \ReflectionClass($className);
                    if ($reflection->hasMethod('tableName') && $reflection->getMethod('tableName')->isStatic()) {
                        $tableName = $className::tableName();
                        if (is_string($tableName)) {
                            return $tableName;
                        }
                    }
                }
            }

            // Fallback: convert model name to table name
            return static::modelNameToTableName($modelName);
        }

        return null;
    }

    /**
     * Convert model name to table name.
     *
     * @param string $modelName Model name
     *
     * @return string
     */
    protected static function modelNameToTableName(string $modelName): string
    {
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $modelName) ?: $modelName);
        return $snakeCase . 's';
    }

    /**
     * Generate DTO name from table or model.
     *
     * @param string $tableName Table name
     * @param string|null $modelName Model name
     *
     * @return string
     */
    protected static function generateDtoName(string $tableName, ?string $modelName): string
    {
        if ($modelName !== null) {
            return $modelName . 'DTO';
        }

        // Convert table name to DTO name
        $parts = explode('_', $tableName);
        $camelCase = '';
        foreach ($parts as $part) {
            $camelCase .= ucfirst($part);
        }
        // Remove trailing 's' if plural
        if (str_ends_with($camelCase, 's')) {
            $camelCase = substr($camelCase, 0, -1);
        }
        return $camelCase . 'DTO';
    }

    /**
     * Generate DTO code.
     *
     * @param string $dtoName DTO class name
     * @param string $tableName Table name
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param string $namespace DTO namespace
     *
     * @return string
     */
    protected static function generateDtoCode(
        string $dtoName,
        string $tableName,
        array $columns,
        string $namespace
    ): string {
        $properties = static::generateProperties($columns);
        $constructor = static::generateConstructor($columns);
        $getters = static::generateGetters($columns);
        $toArray = static::generateToArray($columns);

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

/**
 * Data Transfer Object for {$tableName} table.
 *
 * Auto-generated by PDOdb DTO Generator
 */
class {$dtoName}
{
{$properties}
{$constructor}
{$getters}
{$toArray}
}

PHP;

        return $code;
    }

    /**
     * Generate properties from columns.
     *
     * @param array<int, array<string, mixed>> $columns Table columns
     *
     * @return string
     */
    protected static function generateProperties(array $columns): string
    {
        $props = '';
        foreach ($columns as $column) {
            $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? null;
            if ($colName === null) {
                continue;
            }

            $propName = static::columnNameToPropertyName($colName);
            $type = static::getColumnType($column);
            $nullable = static::isColumnNullable($column);

            $typeHint = $nullable ? '?' . $type : $type;
            $docType = $nullable ? $type . '|null' : $type;

            $props .= "    /**\n";
            $props .= "     * @var {$docType}\n";
            $props .= "     */\n";
            $props .= "    protected {$typeHint} \${$propName};\n\n";
        }

        return rtrim($props);
    }

    /**
     * Generate constructor.
     *
     * @param array<int, array<string, mixed>> $columns Table columns
     *
     * @return string
     */
    protected static function generateConstructor(array $columns): string
    {
        $assignments = '';

        foreach ($columns as $column) {
            $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? null;
            if ($colName === null) {
                continue;
            }

            $propName = static::columnNameToPropertyName($colName);
            $assignments .= "        \$this->{$propName} = \$data['{$colName}'] ?? null;\n";
        }

        return <<<PHP

    /**
     * Create DTO instance.
     *
     * @param array<string, mixed> \$data Data array
     */
    public function __construct(array \$data = [])
    {
{$assignments}    }

PHP;
    }

    /**
     * Generate getter methods.
     *
     * @param array<int, array<string, mixed>> $columns Table columns
     *
     * @return string
     */
    protected static function generateGetters(array $columns): string
    {
        $getters = '';

        foreach ($columns as $column) {
            $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? null;
            if ($colName === null) {
                continue;
            }

            $propName = static::columnNameToPropertyName($colName);
            $getterName = 'get' . ucfirst($propName);
            $type = static::getColumnType($column);
            $nullable = static::isColumnNullable($column);
            $docType = $nullable ? $type . '|null' : $type;

            $getters .= <<<PHP

    /**
     * Get {$colName}.
     *
     * @return {$docType}
     */
    public function {$getterName}(): {$docType}
    {
        return \$this->{$propName};
    }

PHP;
        }

        return $getters;
    }

    /**
     * Generate toArray method.
     *
     * @param array<int, array<string, mixed>> $columns Table columns
     *
     * @return string
     */
    protected static function generateToArray(array $columns): string
    {
        $arrayItems = '';
        foreach ($columns as $column) {
            $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? null;
            if ($colName === null) {
                continue;
            }

            $propName = static::columnNameToPropertyName($colName);
            $arrayItems .= "            '{$colName}' => \$this->{$propName},\n";
        }

        return <<<PHP

    /**
     * Convert DTO to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
{$arrayItems}        ];
    }

PHP;
    }

    /**
     * Convert column name to property name.
     *
     * @param string $columnName Column name
     *
     * @return string
     */
    protected static function columnNameToPropertyName(string $columnName): string
    {
        $parts = explode('_', $columnName);
        $camelCase = '';
        foreach ($parts as $part) {
            $camelCase .= ucfirst($part);
        }
        return lcfirst($camelCase);
    }

    /**
     * Get PHP type from column definition.
     *
     * @param array<string, mixed> $column Column definition
     *
     * @return string
     */
    protected static function getColumnType(array $column): string
    {
        $type = $column['Type'] ?? $column['data_type'] ?? $column['type'] ?? 'string';
        if (!is_string($type)) {
            return 'string';
        }

        $typeLower = strtolower($type);

        if (str_contains($typeLower, 'int')) {
            return 'int';
        }
        if (str_contains($typeLower, 'float') || str_contains($typeLower, 'double') || str_contains($typeLower, 'decimal')) {
            return 'float';
        }
        if (str_contains($typeLower, 'bool')) {
            return 'bool';
        }
        if (str_contains($typeLower, 'date') || str_contains($typeLower, 'time')) {
            return 'string';
        }

        return 'string';
    }

    /**
     * Check if column is nullable.
     *
     * @param array<string, mixed> $column Column definition
     *
     * @return bool
     */
    protected static function isColumnNullable(array $column): bool
    {
        $null = $column['Null'] ?? $column['nullable'] ?? $column['is_nullable'] ?? 'NO';
        return is_string($null) && strtoupper($null) === 'YES';
    }

    /**
     * Get DTO output path.
     *
     * @return string
     */
    protected static function getDtoOutputPath(): string
    {
        $path = getenv('PDODB_DTO_PATH');
        if ($path && is_dir($path)) {
            return $path;
        }

        $possiblePaths = [
            getcwd() . '/app/DTOs',
            getcwd() . '/src/DTOs',
            getcwd() . '/dto',
            __DIR__ . '/../../dto',
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        $defaultPath = getcwd() . '/app/DTOs';
        if (!is_dir($defaultPath)) {
            mkdir($defaultPath, 0755, true);
        }

        return $defaultPath;
    }
}
