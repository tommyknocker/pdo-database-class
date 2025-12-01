<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * Repository generator for repository classes.
 *
 * Generates repository classes with CRUD operations for models.
 */
class RepositoryGenerator extends BaseCliCommand
{
    /**
     * Generate a repository from a model/table.
     *
     * @param string $repositoryName Repository class name
     * @param string|null $modelName Model class name (optional)
     * @param string|null $outputPath Output path for repository file (optional)
     * @param PdoDb|null $db Database instance (optional)
     * @param string|null $namespace Repository namespace (optional)
     * @param string|null $modelNamespace Model namespace (optional)
     * @param bool $force Overwrite existing file
     *
     * @return string Path to created repository file
     */
    public static function generate(
        string $repositoryName,
        ?string $modelName = null,
        ?string $outputPath = null,
        ?PdoDb $db = null,
        ?string $namespace = null,
        ?string $modelNamespace = null,
        bool $force = false
    ): string {
        if ($db === null) {
            $db = static::createDatabase();
        }
        $driver = static::getDriverName($db);

        if (getenv('PHPUNIT') === false) {
            echo "PDOdb Repository Generator\n";
            echo "Database: {$driver}\n\n";
        }

        // Validate repository name
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $repositoryName)) {
            static::error("Invalid repository name: {$repositoryName}. Repository name must start with uppercase letter and contain only alphanumeric characters.");
        }

        // Get model name
        if ($modelName === null) {
            // Auto-detect from repository name (remove "Repository" suffix)
            $modelName = static::repositoryNameToModelName($repositoryName);
            $modelName = static::readInput('Enter model class name', $modelName);
        }

        // Get output path
        if ($outputPath === null) {
            $outputPath = static::getRepositoryOutputPath();
        }

        // Get table name from model
        $tableName = static::modelNameToTableName($modelName);

        // Verify table exists, if not try singular version (RcUser -> rc_user)
        if (!$db->schema()->tableExists($tableName)) {
            // Try singular version
            $singularTable = rtrim($tableName, 's');
            if ($db->schema()->tableExists($singularTable)) {
                $tableName = $singularTable;
            } else {
                throw new QueryException("Table '{$tableName}' does not exist in the database.");
            }
        }

        try {
            $columns = $db->describe($tableName);
        } catch (QueryException $e) {
            static::error("Failed to describe table '{$tableName}': " . $e->getMessage());
        }

        $primaryKey = static::detectPrimaryKey($db, $tableName);

        // Generate repository code
        $repositoryNamespace = $namespace !== null && $namespace !== '' ? strtolower($namespace) : 'app\\repositories';
        $modelNamespaceFinal = $modelNamespace !== null && $modelNamespace !== '' ? strtolower($modelNamespace) : 'app\\models';
        $repositoryCode = static::generateRepositoryCode(
            $repositoryName,
            $modelName,
            $tableName,
            $primaryKey,
            $repositoryNamespace,
            $modelNamespaceFinal
        );

        // Write repository file
        $filename = $outputPath . '/' . $repositoryName . '.php';
        if (file_exists($filename)) {
            $overwrite = $force ? true : static::readConfirmation('Repository file already exists. Overwrite?', false);
            if (!$overwrite) {
                static::info('Repository generation cancelled.');
                exit(0);
            }
        }

        file_put_contents($filename, $repositoryCode);

        static::success('Repository file created: ' . basename($filename));
        if (getenv('PHPUNIT') === false) {
            echo "  Path: {$filename}\n";
            echo "  Model: {$modelNamespaceFinal}\\{$modelName}\n";
            echo "  Table: {$tableName}\n";
        }

        return $filename;
    }

    /**
     * Convert repository name to model name.
     *
     * @param string $repositoryName Repository name (e.g. "UserRepository")
     *
     * @return string Model name (e.g. "User")
     */
    protected static function repositoryNameToModelName(string $repositoryName): string
    {
        // Remove "Repository" suffix if present
        if (str_ends_with($repositoryName, 'Repository')) {
            return substr($repositoryName, 0, -10);
        }
        return $repositoryName;
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
     * Generate repository code.
     *
     * @param string $repositoryName Repository class name
     * @param string $modelName Model class name
     * @param string $tableName Table name
     * @param array<int, string> $primaryKey Primary key columns
     * @param string $repositoryNamespace Repository namespace
     * @param string $modelNamespace Model namespace
     *
     * @return string
     */
    protected static function generateRepositoryCode(
        string $repositoryName,
        string $modelName,
        string $tableName,
        array $primaryKey,
        string $repositoryNamespace,
        string $modelNamespace
    ): string {
        $isSinglePrimaryKey = count($primaryKey) === 1 && $primaryKey[0] === 'id';
        $primaryKeyParam = $isSinglePrimaryKey ? 'int $id' : 'array $ids';
        $primaryKeyVar = $isSinglePrimaryKey ? '$id' : '$ids';

        $primaryKeyWhere = $isSinglePrimaryKey
            ? "\$this->db->find()->from('{$tableName}')->where('id', \$id)"
            : "\$this->db->find()->from('{$tableName}')->where(\$ids)";

        $primaryKeyWhereUpdate = $isSinglePrimaryKey
            ? "\$query->where('id', \$id);"
            : "foreach (\$ids as \$key => \$value) {\n            \$query->where(\$key, \$value);\n        }";

        $primaryKeyWhereDelete = $isSinglePrimaryKey
            ? "\$query->where('id', \$id);"
            : "foreach (\$ids as \$key => \$value) {\n            \$query->where(\$key, \$value);\n        }";

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$repositoryNamespace};

use tommyknocker\pdodb\PdoDb;
use {$modelNamespace}\\{$modelName};

/**
 * Repository class for {$modelName} model.
 *
 * Auto-generated by PDOdb Repository Generator
 */
class {$repositoryName}
{
    protected PdoDb \$db;

    /**
     * Create repository instance.
     *
     * @param PdoDb \$db Database instance
     */
    public function __construct(PdoDb \$db)
    {
        \$this->db = \$db;
    }

    /**
     * Find record by primary key.
     *
     * @param {$primaryKeyParam} Primary key value(s)
     *
     * @return array<string, mixed>|null
     */
    public function findById({$primaryKeyParam}): ?array
    {
        return {$primaryKeyWhere}->getOne();
    }

    /**
     * Find all records.
     *
     * @param array<string, mixed> \$conditions Additional WHERE conditions
     * @param array<string> \$orderBy Order by columns
     * @param int|null \$limit Limit number of records
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array \$conditions = [], array \$orderBy = [], ?int \$limit = null): array
    {
        \$query = \$this->db->find()->from('{$tableName}');

        foreach (\$conditions as \$column => \$value) {
            \$query->where(\$column, \$value);
        }

        foreach (\$orderBy as \$column) {
            \$query->orderBy(\$column);
        }

        if (\$limit !== null) {
            \$query->limit(\$limit);
        }

        return \$query->get();
    }

    /**
     * Find records by condition.
     *
     * @param string \$column Column name
     * @param mixed \$value Value
     *
     * @return array<int, array<string, mixed>>
     */
    public function findBy(string \$column, mixed \$value): array
    {
        return \$this->db->find()
            ->from('{$tableName}')
            ->where(\$column, \$value)
            ->get();
    }

    /**
     * Find one record by condition.
     *
     * @param string \$column Column name
     * @param mixed \$value Value
     *
     * @return array<string, mixed>|null
     */
    public function findOneBy(string \$column, mixed \$value): ?array
    {
        return \$this->db->find()
            ->from('{$tableName}')
            ->where(\$column, \$value)
            ->getOne();
    }

    /**
     * Create new record.
     *
     * @param array<string, mixed> \$data Record data
     *
     * @return int Inserted record ID
     */
    public function create(array \$data): int
    {
        return \$this->db->find()
            ->table('{$tableName}')
            ->insert(\$data);
    }

    /**
     * Update record by primary key.
     *
     * @param {$primaryKeyParam} Primary key value(s)
     * @param array<string, mixed> \$data Update data
     *
     * @return int Number of affected rows
     */
    public function update({$primaryKeyParam}, array \$data): int
    {
        \$query = \$this->db->find()->table('{$tableName}');

        {$primaryKeyWhereUpdate}

        return \$query->update(\$data);
    }

    /**
     * Delete record by primary key.
     *
     * @param {$primaryKeyParam} Primary key value(s)
     *
     * @return int Number of affected rows
     */
    public function delete({$primaryKeyParam}): int
    {
        \$query = \$this->db->find()->table('{$tableName}');

        {$primaryKeyWhereDelete}

        return \$query->delete();
    }

    /**
     * Check if record exists by primary key.
     *
     * @param {$primaryKeyParam} Primary key value(s)
     *
     * @return bool
     */
    public function exists({$primaryKeyParam}): bool
    {
        \$result = \$this->findById({$primaryKeyVar});
        return \$result !== null;
    }

    /**
     * Count records.
     *
     * @param array<string, mixed> \$conditions Additional WHERE conditions
     *
     * @return int
     */
    public function count(array \$conditions = []): int
    {
        \$query = \$this->db->find()->from('{$tableName}');

        foreach (\$conditions as \$column => \$value) {
            \$query->where(\$column, \$value);
        }

        return (int)\$query->select('COUNT(*) as total')->getOne()['total'] ?? 0;
    }
}

PHP;

        return $code;
    }

    /**
     * Get repository output path.
     *
     * @return string
     */
    protected static function getRepositoryOutputPath(): string
    {
        // Check environment variable first
        $path = getenv('PDODB_REPOSITORY_PATH');
        if ($path && is_dir($path)) {
            return $path;
        }

        // Check common locations
        $possiblePaths = [
            getcwd() . '/app/Repositories',
            getcwd() . '/src/Repositories',
            getcwd() . '/repositories',
            __DIR__ . '/../../repositories',
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        // Create default repositories directory
        $defaultPath = getcwd() . '/repositories';
        if (!is_dir($defaultPath)) {
            mkdir($defaultPath, 0755, true);
        }

        return $defaultPath;
    }
}
