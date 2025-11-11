<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * Model generator for ActiveRecord models.
 *
 * Generates ActiveRecord model classes from existing database tables.
 */
class ModelGenerator extends BaseCliCommand
{
    /**
     * Generate a model from a database table.
     *
     * @param string $modelName Model class name
     * @param string|null $tableName Table name (optional, will be auto-detected from model name)
     * @param string|null $outputPath Output path for model file (optional)
     * @param PdoDb|null $db Database instance (optional, creates new instance if not provided)
     *
     * @return string Path to created model file
     */
    public static function generate(string $modelName, ?string $tableName = null, ?string $outputPath = null, ?PdoDb $db = null): string
    {
        if ($db === null) {
            $db = static::createDatabase();
        }
        $driver = static::getDriverName($db);

        echo "PDOdb Model Generator\n";
        echo "Database: {$driver}\n\n";

        // Validate model name
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $modelName)) {
            static::error("Invalid model name: {$modelName}. Model name must start with uppercase letter and contain only alphanumeric characters.");
        }

        // Get table name
        if ($tableName === null) {
            // Auto-detect from model name
            $tableName = static::modelNameToTableName($modelName);
            $tableName = static::readInput('Enter table name', $tableName);
        }

        // Verify table exists
        if (!$db->schema()->tableExists($tableName)) {
            throw new QueryException("Table '{$tableName}' does not exist in the database.");
        }

        try {
            // Get table structure
            $columns = $db->describe($tableName);
        } catch (QueryException $e) {
            static::error("Failed to describe table '{$tableName}': " . $e->getMessage());
        }

        // Get output path
        if ($outputPath === null) {
            $outputPath = static::getModelOutputPath();
        }

        $primaryKey = static::detectPrimaryKey($db, $tableName);
        $foreignKeys = static::getForeignKeys($db, $tableName);

        // Generate model code
        $modelCode = static::generateModelCode($modelName, $tableName, $columns, $primaryKey, $foreignKeys);

        // Write model file
        $filename = $outputPath . '/' . $modelName . '.php';
        if (file_exists($filename)) {
            $overwrite = static::readConfirmation('Model file already exists. Overwrite?', false);
            if (!$overwrite) {
                static::info('Model generation cancelled.');
                exit(0);
            }
        }

        file_put_contents($filename, $modelCode);

        static::success('Model file created: ' . basename($filename));
        echo "  Path: {$filename}\n";
        echo "  Table: {$tableName}\n";
        echo '  Primary key: ' . implode(', ', $primaryKey) . "\n";

        return $filename;
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
        // Convert CamelCase to snake_case and pluralize
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $modelName) ?: $modelName);
        return $snakeCase . 's';
    }

    /**
     * Detect primary key columns from table.
     *
     * @param PdoDb $db Database instance
     * @param string $table Table name
     *
     * @return array<int, string>
     */
    protected static function detectPrimaryKey(PdoDb $db, string $table): array
    {
        try {
            $indexes = $db->schema()->getIndexes($table);
            foreach ($indexes as $index) {
                if (($index['is_primary'] ?? false) === true) {
                    $columns = $index['columns'] ?? [];
                    if (!empty($columns)) {
                        return $columns;
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback to column check
        }

        // Fallback: check for 'id' column
        $columns = $db->describe($table);
        foreach ($columns as $column) {
            $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? null;
            $isPrimary = $column['Key'] ?? $column['constraint_type'] ?? null;
            if ($colName === 'id' || (is_string($isPrimary) && stripos($isPrimary, 'PRI') !== false)) {
                return ['id'];
            }
        }

        return ['id'];
    }

    /**
     * Get foreign keys for a table.
     *
     * @param PdoDb $db Database instance
     * @param string $table Table name
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getForeignKeys(PdoDb $db, string $table): array
    {
        try {
            return $db->schema()->getForeignKeys($table);
        } catch (QueryException $e) {
            return [];
        }
    }

    /**
     * Generate model code.
     *
     * @param string $modelName Model class name
     * @param string $tableName Table name
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param array<int, string> $primaryKey Primary key columns
     * @param array<int, array<string, mixed>> $foreignKeys Foreign keys
     *
     * @return string
     */
    protected static function generateModelCode(
        string $modelName,
        string $tableName,
        array $columns,
        array $primaryKey,
        array $foreignKeys
    ): string {
        $namespace = 'App\\Models';
        $primaryKeyCode = count($primaryKey) === 1 && $primaryKey[0] === 'id'
            ? "        return ['id'];\n"
            : '        return ' . var_export($primaryKey, true) . ";\n";

        $attributes = static::generateAttributes($columns);
        $relationships = static::generateRelationships($foreignKeys);

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use tommyknocker\pdodb\orm\Model;

/**
 * Model class for table: {$tableName}
 *
 * Auto-generated by PDOdb Model Generator
 */
class {$modelName} extends Model
{
    /**
     * {@inheritDoc}
     */
    public static function tableName(): string
    {
        return '{$tableName}';
    }

    /**
     * {@inheritDoc}
     */
    public static function primaryKey(): array
    {
{$primaryKeyCode}    }

{$attributes}
{$relationships}
}

PHP;

        return $code;
    }

    /**
     * Generate attributes documentation from columns.
     *
     * @param array<int, array<string, mixed>> $columns Table columns
     *
     * @return string
     */
    protected static function generateAttributes(array $columns): string
    {
        $doc = "    /**\n";
        $doc .= "     * Model attributes.\n";
        $doc .= "     *\n";
        $doc .= "     * @var array<string, mixed>\n";
        $doc .= "     */\n";
        $doc .= "    public array \$attributes = [\n";

        foreach ($columns as $column) {
            $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? null;
            if ($colName !== null) {
                $doc .= "        '{$colName}' => null,\n";
            }
        }

        $doc .= "    ];\n";

        return $doc;
    }

    /**
     * Generate relationships from foreign keys.
     *
     * @param array<int, array<string, mixed>> $foreignKeys Foreign keys
     *
     * @return string
     */
    protected static function generateRelationships(array $foreignKeys): string
    {
        if (empty($foreignKeys)) {
            return '';
        }

        $code = "    /**\n";
        $code .= "     * {@inheritDoc}\n";
        $code .= "     */\n";
        $code .= "    public static function relations(): array\n";
        $code .= "    {\n";
        $code .= "        return [\n";
        $code .= "            // TODO: Define relationships based on foreign keys\n";
        $code .= "        ];\n";
        $code .= "    }\n";

        return $code;
    }

    /**
     * Get model output path.
     *
     * @return string
     */
    protected static function getModelOutputPath(): string
    {
        // Check environment variable first
        $path = getenv('PDODB_MODEL_PATH');
        if ($path && is_dir($path)) {
            return $path;
        }

        // Check common locations
        $possiblePaths = [
            getcwd() . '/app/Models',
            getcwd() . '/src/Models',
            getcwd() . '/models',
            __DIR__ . '/../../models',
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        // Create default models directory
        $defaultPath = getcwd() . '/models';
        if (!is_dir($defaultPath)) {
            mkdir($defaultPath, 0755, true);
        }

        return $defaultPath;
    }
}
