<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use Exception;
use ReflectionClass;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * API generator for REST API endpoints/controllers.
 *
 * Generates REST API controller classes with CRUD operations.
 */
class ApiGenerator extends BaseCliCommand
{
    /**
     * Generate API controller from table or model.
     *
     * @param string|null $tableName Table name
     * @param string|null $modelName Model class name
     * @param string $format Output format (default: rest)
     * @param string|null $namespace Controller namespace
     * @param string|null $outputPath Output path for controller file
     * @param PdoDb|null $db Database instance
     * @param bool $force Overwrite existing file
     *
     * @return string Path to created controller file
     */
    public static function generate(
        ?string $tableName,
        ?string $modelName,
        string $format = 'rest',
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
            echo "PDOdb API Generator\n";
            echo "Database: {$driver}\n";
        }
        echo "Format: {$format}\n\n";

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

        $primaryKey = static::detectPrimaryKey($db, $resolvedTableName);
        $controllerName = static::generateControllerName($resolvedTableName, $modelName);

        // Get output path
        if ($outputPath === null) {
            $outputPath = static::getApiOutputPath();
        }

        // Generate controller code
        $controllerNamespace = $namespace !== null && $namespace !== '' ? $namespace : 'App\\Controllers';
        $controllerCode = static::generateControllerCode(
            $controllerName,
            $resolvedTableName,
            $modelName,
            $columns,
            $primaryKey,
            $controllerNamespace,
            $format
        );

        // Write controller file
        $filename = $outputPath . '/' . $controllerName . '.php';
        if (file_exists($filename)) {
            $overwrite = $force ? true : static::readConfirmation('Controller file already exists. Overwrite?', false);
            if (!$overwrite) {
                static::info('API generation cancelled.');
                exit(0);
            }
        }

        // Ensure directory exists
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filename, $controllerCode);

        static::success('API controller file created: ' . basename($filename));
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
                    $reflection = new ReflectionClass($className);
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
     * Generate controller name from table or model.
     *
     * @param string $tableName Table name
     * @param string|null $modelName Model name
     *
     * @return string
     */
    protected static function generateControllerName(string $tableName, ?string $modelName): string
    {
        if ($modelName !== null) {
            return $modelName . 'Controller';
        }

        // Convert table name to controller name
        $parts = explode('_', $tableName);
        $camelCase = '';
        foreach ($parts as $part) {
            $camelCase .= ucfirst($part);
        }
        // Remove trailing 's' if plural
        if (str_ends_with($camelCase, 's')) {
            $camelCase = substr($camelCase, 0, -1);
        }
        return $camelCase . 'Controller';
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
        } catch (Exception $e) {
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
     * Generate controller code.
     *
     * @param string $controllerName Controller class name
     * @param string $tableName Table name
     * @param string|null $modelName Model name
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param array<int, string> $primaryKey Primary key columns
     * @param string $namespace Controller namespace
     * @param string $format Output format
     *
     * @return string
     */
    protected static function generateControllerCode(
        string $controllerName,
        string $tableName,
        ?string $modelName,
        array $columns,
        array $primaryKey,
        string $namespace,
        string $format
    ): string {
        $isSinglePrimaryKey = count($primaryKey) === 1 && $primaryKey[0] === 'id';
        $primaryKeyParam = $isSinglePrimaryKey ? 'int $id' : 'array $ids';
        $primaryKeyVar = $isSinglePrimaryKey ? '$id' : '$ids';
        $storeReturn = $isSinglePrimaryKey
            ? 'return $this->show($id);'
            : '$ids = [\'' . $primaryKey[0] . '\' => $id];' . "\n        return \$this->show(\$ids);";

        $modelUse = '';
        $modelType = '';
        $modelInstantiate = '';
        if ($modelName !== null) {
            $modelNamespace = 'App\\Models';
            $modelUse = "use {$modelNamespace}\\{$modelName};\n";
            $modelType = $modelName . ' ';
            $modelInstantiate = "new {$modelName}(\$this->db)";
        }

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use tommyknocker\pdodb\PdoDb;
{$modelUse}
/**
 * REST API Controller for {$tableName} table.
 *
 * Auto-generated by PDOdb API Generator
 */
class {$controllerName}
{
    protected PdoDb \$db;

    /**
     * Create controller instance.
     *
     * @param PdoDb \$db Database instance
     */
    public function __construct(PdoDb \$db)
    {
        \$this->db = \$db;
    }

    /**
     * List all records.
     *
     * GET /{$tableName}
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(): array
    {
        \$query = \$this->db->find()->from('{$tableName}');
        return \$query->all();
    }

    /**
     * Get single record by ID.
     *
     * GET /{$tableName}/{{$primaryKeyVar}}
     *
     * @param {$primaryKeyParam}
     *
     * @return array<string, mixed>|null
     */
    public function show({$primaryKeyParam}): ?array
    {
        \$query = \$this->db->find()->from('{$tableName}');
        if (is_array({$primaryKeyVar})) {
            foreach ({$primaryKeyVar} as \$key => \$value) {
                \$query->where(\$key, \$value);
            }
        } else {
            \$query->where('{$primaryKey[0]}', {$primaryKeyVar});
        }
        return \$query->one();
    }

    /**
     * Create new record.
     *
     * POST /{$tableName}
     *
     * @param array<string, mixed> \$data Request data
     *
     * @return array<string, mixed>
     */
    public function store(array \$data): array
    {
        \$id = \$this->db->insert('{$tableName}', \$data);
        {$storeReturn}
    }

    /**
     * Update existing record.
     *
     * PUT /{$tableName}/{{$primaryKeyVar}}
     *
     * @param {$primaryKeyParam}
     * @param array<string, mixed> \$data Request data
     *
     * @return array<string, mixed>|null
     */
    public function update({$primaryKeyParam}, array \$data): ?array
    {
        \$query = \$this->db->update('{$tableName}')->set(\$data);
        if (is_array({$primaryKeyVar})) {
            foreach ({$primaryKeyVar} as \$key => \$value) {
                \$query->where(\$key, \$value);
            }
        } else {
            \$query->where('{$primaryKey[0]}', {$primaryKeyVar});
        }
        \$query->execute();
        return \$this->show({$primaryKeyVar});
    }

    /**
     * Delete record.
     *
     * DELETE /{$tableName}/{{$primaryKeyVar}}
     *
     * @param {$primaryKeyParam}
     *
     * @return bool
     */
    public function destroy({$primaryKeyParam}): bool
    {
        \$query = \$this->db->delete()->from('{$tableName}');
        if (is_array({$primaryKeyVar})) {
            foreach ({$primaryKeyVar} as \$key => \$value) {
                \$query->where(\$key, \$value);
            }
        } else {
            \$query->where('{$primaryKey[0]}', {$primaryKeyVar});
        }
        return \$query->execute();
    }
}

PHP;

        return $code;
    }

    /**
     * Get API output path.
     *
     * @return string
     */
    protected static function getApiOutputPath(): string
    {
        $path = getenv('PDODB_API_PATH');
        if ($path && is_dir($path)) {
            return $path;
        }

        $possiblePaths = [
            getcwd() . '/app/Controllers',
            getcwd() . '/src/Controllers',
            getcwd() . '/controllers',
            __DIR__ . '/../../controllers',
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        $defaultPath = getcwd() . '/app/Controllers';
        if (!is_dir($defaultPath)) {
            mkdir($defaultPath, 0755, true);
        }

        return $defaultPath;
    }
}
