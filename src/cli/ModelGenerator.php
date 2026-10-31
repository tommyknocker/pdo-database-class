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
     * @param array<string, string> $relatedModels Referenced table names mapped to model class names
     * @param PdoDb|null $db Database instance (optional, creates new instance if not provided)
     *
     * @return string Path to created model file
     */
    public static function generate(
        string $modelName,
        ?string $tableName = null,
        ?string $outputPath = null,
        ?PdoDb $db = null,
        ?string $namespace = null,
        bool $force = false,
        array $relatedModels = []
    ): string {
        if ($db === null) {
            $db = static::createDatabase();
        }
        $driver = static::getDriverName($db);

        static::cliWriteln('PDOdb Model Generator');
        static::cliWriteln("Database: {$driver}\n");

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

        foreach ($relatedModels as $table => $class) {
            if (!is_string($table) || $table === '' || !is_string($class) || preg_match('/^[A-Z][a-zA-Z0-9]*$/', $class) !== 1) {
                throw new QueryException('Related model mappings must map table names to valid model class names.');
            }
        }

        // Generate model code
        $modelNamespace = $namespace !== null && $namespace !== '' ? $namespace : 'app\\models';
        $relatedUses = static::relatedModelUses($foreignKeys, $modelNamespace, $modelName, $relatedModels);
        $modelCode = static::generateModelCode(
            $modelName,
            $tableName,
            $columns,
            $primaryKey,
            $foreignKeys,
            $modelNamespace,
            $relatedUses,
            $relatedModels
        );

        // Write model file
        $filename = $outputPath . '/' . $modelName . '.php';
        if (file_exists($filename)) {
            $overwrite = $force ? true : static::readConfirmation('Model file already exists. Overwrite?', false);
            if (!$overwrite) {
                static::info('Model generation cancelled.');
                exit(0);
            }
        }

        file_put_contents($filename, $modelCode);

        static::success('Model file created: ' . basename($filename));
        static::cliWriteln("  Path: {$filename}");
        static::cliWriteln("  Table: {$tableName}");
        static::cliWriteln('  Primary key: ' . implode(', ', $primaryKey));

        return $filename;
    }

    /** @return array<string, string> */
    public static function parseRelatedModels(mixed $value): array
    {
        if ($value === null || $value === false) {
            return [];
        }
        if (!is_string($value) || !str_starts_with(ltrim($value), '{')) {
            throw new QueryException('--relation-models must be a JSON object mapping table names to model classes.');
        }

        try {
            $mapping = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new QueryException('Invalid --relation-models JSON.');
        }
        if (!is_array($mapping) || (array_is_list($mapping) && $mapping !== [])) {
            throw new QueryException('--relation-models must be a JSON object mapping table names to model classes.');
        }
        foreach ($mapping as $table => $class) {
            if (!is_string($table) || $table === '' || !is_string($class) || preg_match('/^[A-Z][a-zA-Z0-9]*$/', $class) !== 1) {
                throw new QueryException('Related model mappings must map table names to valid model class names.');
            }
        }
        return $mapping;
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
     * @param array<int, string> $relatedUses Fully-qualified class names to import (excluding this model)
     * @param array<string, string> $relatedModels Referenced table to model class mappings
     *
     * @return string
     */
    protected static function generateModelCode(
        string $modelName,
        string $tableName,
        array $columns,
        array $primaryKey,
        array $foreignKeys,
        string $namespace,
        array $relatedUses = [],
        array $relatedModels = []
    ): string {
        $primaryKeyCode = count($primaryKey) === 1 && $primaryKey[0] === 'id'
            ? "        return ['id'];\n"
            : '        return ' . var_export($primaryKey, true) . ";\n";

        $attributes = static::generateAttributes($columns);
        $relationships = static::generateRelationships($foreignKeys, $namespace, $relatedModels);

        $useLines = "use tommyknocker\\pdodb\\orm\\Model;\n";
        foreach ($relatedUses as $relatedClass) {
            $useLines .= 'use ' . $relatedClass . ";\n";
        }

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$useLines}
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
     * @param array<string, string> $relatedModels Referenced table to model class mappings
     *
     * @return string
     */
    protected static function generateRelationships(array $foreignKeys, string $namespace, array $relatedModels = []): string
    {
        if ($foreignKeys === []) {
            return '';
        }

        $code = "    /**\n";
        $code .= "     * {@inheritDoc}\n";
        $code .= "     */\n";
        $code .= "    public static function relations(): array\n";
        $code .= "    {\n";
        $code .= "        return [\n";

        foreach ($foreignKeys as $foreignKey) {
            $column = static::foreignKeyColumn($foreignKey);
            $refTable = static::foreignKeyReferencedTable($foreignKey);
            if ($column === null || $refTable === null) {
                continue;
            }

            $ownerKey = static::foreignKeyReferencedColumn($foreignKey) ?? 'id';
            $relationName = static::foreignKeyToRelationName($column);
            $relatedModel = $relatedModels[$refTable] ?? static::tableToModelName($refTable);

            $code .= "            '{$relationName}' => [\n";
            $code .= "                'belongsTo',\n";
            $code .= "                'modelClass' => {$relatedModel}::class,\n";
            $code .= "                'foreignKey' => '{$column}',\n";
            $code .= "                'ownerKey' => '{$ownerKey}',\n";
            $code .= "            ],\n";
        }

        $code .= "        ];\n";
        $code .= "    }\n";

        return $code;
    }

    /**
     * @param array<string, mixed> $foreignKey
     */
    protected static function foreignKeyColumn(array $foreignKey): ?string
    {
        $column = $foreignKey['column_name']
            ?? $foreignKey['COLUMN_NAME']
            ?? $foreignKey['from']
            ?? null;

        return is_string($column) && $column !== '' ? $column : null;
    }

    /**
     * @param array<string, mixed> $foreignKey
     */
    protected static function foreignKeyReferencedTable(array $foreignKey): ?string
    {
        $table = $foreignKey['referenced_table_name']
            ?? $foreignKey['REFERENCED_TABLE_NAME']
            ?? $foreignKey['table']
            ?? null;

        return is_string($table) && $table !== '' ? $table : null;
    }

    /**
     * @param array<string, mixed> $foreignKey
     */
    protected static function foreignKeyReferencedColumn(array $foreignKey): ?string
    {
        $column = $foreignKey['referenced_column_name']
            ?? $foreignKey['REFERENCED_COLUMN_NAME']
            ?? $foreignKey['to']
            ?? null;

        return is_string($column) && $column !== '' ? $column : null;
    }

    protected static function foreignKeyToRelationName(string $column): string
    {
        if (str_ends_with($column, '_id')) {
            $base = substr($column, 0, -3);

            return $base !== '' ? $base : $column;
        }

        return $column;
    }

    protected static function tableToModelName(string $table): string
    {
        $parts = explode('_', $table);
        $name = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $name .= ucfirst(mb_strtolower($part, 'UTF-8'));
        }

        if ($name === '') {
            return 'Related';
        }

        $irregular = ['People' => 'Person', 'Men' => 'Man', 'Women' => 'Woman',
            'Children' => 'Child', 'Teeth' => 'Tooth', 'Feet' => 'Foot', 'Mice' => 'Mouse',
            'Geese' => 'Goose', 'Indices' => 'Index'];
        foreach ($irregular as $plural => $singular) {
            if (str_ends_with($name, $plural)) {
                return substr($name, 0, -strlen($plural)) . $singular;
            }
        }
        if (in_array($name, ['News', 'Series', 'Species'], true)) {
            return $name;
        }
        if (preg_match('/[^aeiou]ies$/i', $name) === 1) {
            return substr($name, 0, -3) . 'y';
        }
        if (preg_match('/(sses|shes|ches|xes|zes|statuses|buses)$/i', $name) === 1) {
            return substr($name, 0, -2);
        }
        if (str_ends_with($name, 's') && !preg_match('/(ss|us|is)$/i', $name) && strlen($name) > 1) {
            $name = substr($name, 0, -1);
        }

        return $name;
    }

    /**
     * @param array<int, array<string, mixed>> $foreignKeys
     * @param array<string, string> $relatedModels Referenced table to model class mappings
     *
     * @return array<int, string>
     */
    protected static function relatedModelUses(array $foreignKeys, string $namespace, string $modelName, array $relatedModels = []): array
    {
        $uses = [];
        foreach ($foreignKeys as $foreignKey) {
            $refTable = static::foreignKeyReferencedTable($foreignKey);
            if ($refTable === null) {
                continue;
            }
            $related = $relatedModels[$refTable] ?? static::tableToModelName($refTable);
            if ($related === $modelName) {
                continue;
            }
            $fqn = $namespace . '\\' . $related;
            $uses[$fqn] = $fqn;
        }

        return array_values($uses);
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
