<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * Test generator for unit/integration tests.
 *
 * Generates PHPUnit test classes for models, tables, or repositories.
 */
class TestGenerator extends BaseCliCommand
{
    /**
     * Generate test class from model, table, or repository.
     *
     * @param string|null $modelName Model class name
     * @param string|null $tableName Table name
     * @param string|null $repositoryName Repository class name
     * @param string $type Test type (unit or integration)
     * @param string|null $namespace Test namespace
     * @param string|null $outputPath Output path for test file
     * @param PdoDb|null $db Database instance
     * @param bool $force Overwrite existing file
     *
     * @return string Path to created test file
     */
    public static function generate(
        ?string $modelName,
        ?string $tableName,
        ?string $repositoryName,
        string $type = 'unit',
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
            echo "PDOdb Test Generator\n";
            echo "Database: {$driver}\n";
        }
        echo "Type: {$type}\n\n";

        // Resolve table name and target class
        $resolvedTableName = null;
        $targetClass = null;
        $targetType = null;

        if ($repositoryName !== null) {
            $targetClass = $repositoryName;
            $targetType = 'repository';
            // Try to resolve table from repository
            $resolvedTableName = static::resolveTableFromRepository($repositoryName, $db);
        } elseif ($modelName !== null) {
            $targetClass = $modelName;
            $targetType = 'model';
            $resolvedTableName = static::resolveTableFromModel($modelName, $db);
        } elseif ($tableName !== null) {
            $resolvedTableName = $tableName;
            $targetClass = static::tableNameToClassName($tableName);
            $targetType = 'table';
        } else {
            static::error('One of --model, --table, or --repository option is required.');
        }

        // Verify table exists if we have it
        if ($resolvedTableName !== null && !$db->schema()->tableExists($resolvedTableName)) {
            throw new QueryException("Table '{$resolvedTableName}' does not exist in the database.");
        }

        // Get table structure if available
        $columns = [];
        $primaryKey = ['id'];
        if ($resolvedTableName !== null) {
            try {
                $columns = $db->describe($resolvedTableName);
                $primaryKey = static::detectPrimaryKey($db, $resolvedTableName);
            } catch (QueryException $e) {
                // Continue without column info
            }
        }

        $testName = $targetClass . 'Test';

        // Get output path
        if ($outputPath === null) {
            $outputPath = static::getTestOutputPath($type);
        }

        // Generate test code
        $testNamespace = $namespace !== null && $namespace !== '' ? $namespace : 'Tests\\' . ucfirst($type);
        $testCode = static::generateTestCode(
            $testName,
            $targetClass,
            $targetType,
            $resolvedTableName,
            $columns,
            $primaryKey,
            $testNamespace,
            $type
        );

        // Write test file
        $filename = $outputPath . '/' . $testName . '.php';
        if (file_exists($filename)) {
            $overwrite = $force ? true : static::readConfirmation('Test file already exists. Overwrite?', false);
            if (!$overwrite) {
                static::info('Test generation cancelled.');
                exit(0);
            }
        }

        // Ensure directory exists
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filename, $testCode);

        static::success('Test file created: ' . basename($filename));
        if (getenv('PHPUNIT') === false) {
            echo "  Path: {$filename}\n";
            if ($resolvedTableName !== null) {
                echo "  Table: {$resolvedTableName}\n";
            }
            echo "  Target: {$targetClass} ({$targetType})\n";
        }

        return $filename;
    }

    /**
     * Resolve table name from model.
     *
     * @param string $modelName Model name
     * @param PdoDb $db Database instance
     *
     * @return string|null
     */
    protected static function resolveTableFromModel(string $modelName, PdoDb $db): ?string
    {
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

        return static::modelNameToTableName($modelName);
    }

    /**
     * Resolve table name from repository.
     *
     * @param string $repositoryName Repository name
     * @param PdoDb $db Database instance
     *
     * @return string|null
     */
    protected static function resolveTableFromRepository(string $repositoryName, PdoDb $db): ?string
    {
        $possibleNamespaces = ['App\\Repositories', 'App\\Repository', 'Repositories', 'Repository'];
        foreach ($possibleNamespaces as $ns) {
            $className = $ns . '\\' . $repositoryName;
            if (class_exists($className)) {
                // Try to find model used by repository
                $reflection = new \ReflectionClass($className);
                $constructor = $reflection->getConstructor();
                if ($constructor !== null) {
                    $params = $constructor->getParameters();
                    foreach ($params as $param) {
                        $type = $param->getType();
                        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                            $modelClass = $type->getName();
                            if (class_exists($modelClass)) {
                                $modelReflection = new \ReflectionClass($modelClass);
                                if ($modelReflection->hasMethod('tableName') && $modelReflection->getMethod('tableName')->isStatic()) {
                                    $tableName = $modelClass::tableName();
                                    if (is_string($tableName)) {
                                        return $tableName;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Try to extract model name from repository name
        if (str_ends_with($repositoryName, 'Repository')) {
            $modelName = substr($repositoryName, 0, -10);
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
     * Convert table name to class name.
     *
     * @param string $tableName Table name
     *
     * @return string
     */
    protected static function tableNameToClassName(string $tableName): string
    {
        $parts = explode('_', $tableName);
        $camelCase = '';
        foreach ($parts as $part) {
            $camelCase .= ucfirst($part);
        }
        if (str_ends_with($camelCase, 's')) {
            $camelCase = substr($camelCase, 0, -1);
        }
        return $camelCase;
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
     * Generate test code.
     *
     * @param string $testName Test class name
     * @param string $targetClass Target class name
     * @param string $targetType Target type (model, repository, table)
     * @param string|null $tableName Table name
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param array<int, string> $primaryKey Primary key columns
     * @param string $namespace Test namespace
     * @param string $type Test type
     *
     * @return string
     */
    protected static function generateTestCode(
        string $testName,
        string $targetClass,
        string $targetType,
        ?string $tableName,
        array $columns,
        array $primaryKey,
        string $namespace,
        string $type
    ): string {
        $isIntegration = $type === 'integration';
        $setupMethod = $isIntegration ? static::generateIntegrationSetup($tableName) : '';
        $teardownMethod = $isIntegration ? static::generateIntegrationTeardown($tableName) : '';
        $testMethods = static::generateTestMethods($targetClass, $targetType, $tableName, $primaryKey, $isIntegration);

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;

/**
 * {$type} tests for {$targetClass} ({$targetType}).
 *
 * Auto-generated by PDOdb Test Generator
 */
class {$testName} extends TestCase
{
    protected ?PdoDb \$db = null;

    /**
     * Set up test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
{$setupMethod}    }

    /**
     * Tear down test environment.
     */
    protected function tearDown(): void
    {
{$teardownMethod}        parent::tearDown();
    }

{$testMethods}
}

PHP;

        return $code;
    }

    /**
     * Generate integration setup method.
     *
     * @param string|null $tableName Table name
     *
     * @return string
     */
    protected static function generateIntegrationSetup(?string $tableName): string
    {
        if ($tableName === null) {
            return "        // TODO: Initialize database connection\n";
        }

        return <<<PHP
        // Initialize database connection
        // TODO: Load database configuration
        // \$this->db = new PdoDb([...]);

        // TODO: Set up test data if needed
        // \$this->db->insert('{$tableName}', [...]);

PHP;
    }

    /**
     * Generate integration teardown method.
     *
     * @param string|null $tableName Table name
     *
     * @return string
     */
    protected static function generateIntegrationTeardown(?string $tableName): string
    {
        if ($tableName === null) {
            return "        // TODO: Clean up test data\n";
        }

        return <<<PHP
        // Clean up test data
        if (\$this->db !== null && '{$tableName}' !== null) {
            // TODO: Clean up test records
            // \$this->db->delete()->from('{$tableName}')->where('test_marker', true)->execute();
        }

PHP;
    }

    /**
     * Generate test methods.
     *
     * @param string $targetClass Target class name
     * @param string $targetType Target type
     * @param string|null $tableName Table name
     * @param array<int, string> $primaryKey Primary key columns
     * @param bool $isIntegration Is integration test
     *
     * @return string
     */
    protected static function generateTestMethods(
        string $targetClass,
        string $targetType,
        ?string $tableName,
        array $primaryKey,
        bool $isIntegration
    ): string {
        $methods = '';

        if ($isIntegration && $tableName !== null) {
            $methods .= <<<PHP
    /**
     * Test basic CRUD operations.
     */
    public function testCrudOperations(): void
    {
        \$this->assertNotNull(\$this->db);

        // Test create
        \$data = [
            // TODO: Add test data
        ];
        \$id = \$this->db->insert('{$tableName}', \$data);
        \$this->assertIsInt(\$id);

        // Test read
        \$record = \$this->db->find()->from('{$tableName}')->where('{$primaryKey[0]}', \$id)->one();
        \$this->assertNotNull(\$record);

        // Test update
        \$updateData = [
            // TODO: Add update data
        ];
        \$this->db->update('{$tableName}')->set(\$updateData)->where('{$primaryKey[0]}', \$id)->execute();

        // Test delete
        \$this->db->delete()->from('{$tableName}')->where('{$primaryKey[0]}', \$id)->execute();
        \$record = \$this->db->find()->from('{$tableName}')->where('{$primaryKey[0]}', \$id)->one();
        \$this->assertNull(\$record);
    }

PHP;
        } else {
            $methods .= <<<PHP
    /**
     * Test instantiation.
     */
    public function testInstantiation(): void
    {
        // TODO: Implement test
        \$this->assertTrue(true);
    }

    /**
     * Test basic functionality.
     */
    public function testBasicFunctionality(): void
    {
        // TODO: Implement test
        \$this->assertTrue(true);
    }

PHP;
        }

        return $methods;
    }

    /**
     * Get test output path.
     *
     * @param string $type Test type
     *
     * @return string
     */
    protected static function getTestOutputPath(string $type): string
    {
        $path = getenv('PDODB_TEST_PATH');
        if ($path && is_dir($path)) {
            return $path;
        }

        $typeDir = ucfirst($type);
        $possiblePaths = [
            getcwd() . "/tests/{$type}",
            getcwd() . "/tests/{$typeDir}",
            getcwd() . '/tests',
            __DIR__ . '/../../tests',
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        $defaultPath = getcwd() . "/tests/{$type}";
        if (!is_dir($defaultPath)) {
            mkdir($defaultPath, 0755, true);
        }

        return $defaultPath;
    }
}
