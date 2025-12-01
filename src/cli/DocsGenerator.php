<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * Documentation generator for API documentation (OpenAPI/Swagger).
 *
 * Generates OpenAPI specification files from table or model definitions.
 */
class DocsGenerator extends BaseCliCommand
{
    /**
     * Generate API documentation from table or model.
     *
     * @param string|null $tableName Table name
     * @param string|null $modelName Model class name
     * @param string $format Output format (default: openapi)
     * @param string|null $outputPath Output path for documentation file
     * @param PdoDb|null $db Database instance
     * @param bool $force Overwrite existing file
     *
     * @return string Path to created documentation file
     */
    public static function generate(
        ?string $tableName,
        ?string $modelName,
        string $format = 'openapi',
        ?string $outputPath = null,
        ?PdoDb $db = null,
        bool $force = false
    ): string {
        if ($db === null) {
            $db = static::createDatabase();
        }
        $driver = static::getDriverName($db);

        if (getenv('PHPUNIT') === false) {
            echo "PDOdb Documentation Generator\n";
            echo "Database: {$driver}\n";
            echo "Format: {$format}\n\n";
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

        $primaryKey = static::detectPrimaryKey($db, $resolvedTableName);
        $resourceName = static::generateResourceName($resolvedTableName, $modelName);

        // Get output path
        if ($outputPath === null) {
            $outputPath = static::getDocsOutputPath();
        }

        // Generate documentation
        $docsContent = static::generateDocsContent(
            $resourceName,
            $resolvedTableName,
            $modelName,
            $columns,
            $primaryKey,
            $format
        );

        // Determine file extension
        $extension = $format === 'openapi' ? 'yaml' : 'json';
        $filename = $outputPath . '/' . $resourceName . '.' . $extension;

        if (file_exists($filename)) {
            $overwrite = $force ? true : static::readConfirmation('Documentation file already exists. Overwrite?', false);
            if (!$overwrite) {
                static::info('Documentation generation cancelled.');
                exit(0);
            }
        }

        // Ensure directory exists
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filename, $docsContent);

        static::success('Documentation file created: ' . basename($filename));
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
     * Generate resource name from table or model.
     *
     * @param string $tableName Table name
     * @param string|null $modelName Model name
     *
     * @return string
     */
    protected static function generateResourceName(string $tableName, ?string $modelName): string
    {
        if ($modelName !== null) {
            return strtolower($modelName) . '_api';
        }

        return $tableName . '_api';
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
     * Generate documentation content.
     *
     * @param string $resourceName Resource name
     * @param string $tableName Table name
     * @param string|null $modelName Model name
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param array<int, string> $primaryKey Primary key columns
     * @param string $format Output format
     *
     * @return string
     */
    protected static function generateDocsContent(
        string $resourceName,
        string $tableName,
        ?string $modelName,
        array $columns,
        array $primaryKey,
        string $format
    ): string {
        if ($format === 'openapi') {
            return static::generateOpenApiYaml($resourceName, $tableName, $modelName, $columns, $primaryKey);
        }

        return static::generateOpenApiJson($resourceName, $tableName, $modelName, $columns, $primaryKey);
    }

    /**
     * Generate OpenAPI YAML.
     *
     * @param string $resourceName Resource name
     * @param string $tableName Table name
     * @param string|null $modelName Model name
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param array<int, string> $primaryKey Primary key columns
     *
     * @return string
     */
    protected static function generateOpenApiYaml(
        string $resourceName,
        string $tableName,
        ?string $modelName,
        array $columns,
        array $primaryKey
    ): string {
        $schema = static::generateSchema($columns, $primaryKey);
        $isSinglePrimaryKey = count($primaryKey) === 1 && $primaryKey[0] === 'id';
        $idParam = $isSinglePrimaryKey ? 'id' : implode(',', $primaryKey);
        $description = $modelName !== null
            ? "API documentation for {$modelName} model ({$tableName} table)"
            : "API documentation for {$tableName} table";

        $yaml = <<<YAML
openapi: 3.0.0
info:
  title: {$resourceName}
  description: {$description}
  version: 1.0.0
servers:
  - url: http://localhost/api
    description: Development server
paths:
  /{$tableName}:
    get:
      summary: List all {$tableName}
      tags:
        - {$tableName}
      responses:
        '200':
          description: Successful response
          content:
            application/json:
              schema:
                type: array
                items:
                  \$ref: '#/components/schemas/{$resourceName}'
    post:
      summary: Create new {$tableName}
      tags:
        - {$tableName}
      requestBody:
        required: true
        content:
          application/json:
            schema:
              \$ref: '#/components/schemas/{$resourceName}'
      responses:
        '201':
          description: Created
          content:
            application/json:
              schema:
                \$ref: '#/components/schemas/{$resourceName}'
  /{$tableName}/{{$idParam}}:
    get:
      summary: Get {$tableName} by ID
      tags:
        - {$tableName}
      parameters:
        - name: {$idParam}
          in: path
          required: true
          schema:
            type: string
      responses:
        '200':
          description: Successful response
          content:
            application/json:
              schema:
                \$ref: '#/components/schemas/{$resourceName}'
        '404':
          description: Not found
    put:
      summary: Update {$tableName}
      tags:
        - {$tableName}
      parameters:
        - name: {$idParam}
          in: path
          required: true
          schema:
            type: string
      requestBody:
        required: true
        content:
          application/json:
            schema:
              \$ref: '#/components/schemas/{$resourceName}'
      responses:
        '200':
          description: Updated
          content:
            application/json:
              schema:
                \$ref: '#/components/schemas/{$resourceName}'
    delete:
      summary: Delete {$tableName}
      tags:
        - {$tableName}
      parameters:
        - name: {$idParam}
          in: path
          required: true
          schema:
            type: string
      responses:
        '204':
          description: Deleted
components:
  schemas:
    {$resourceName}:
{$schema}

YAML;

        return $yaml;
    }

    /**
     * Generate OpenAPI JSON.
     *
     * @param string $resourceName Resource name
     * @param string $tableName Table name
     * @param string|null $modelName Model name
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param array<int, string> $primaryKey Primary key columns
     *
     * @return string
     */
    protected static function generateOpenApiJson(
        string $resourceName,
        string $tableName,
        ?string $modelName,
        array $columns,
        array $primaryKey
    ): string {
        $schema = static::generateSchemaJson($columns, $primaryKey);
        $isSinglePrimaryKey = count($primaryKey) === 1 && $primaryKey[0] === 'id';
        $idParam = $isSinglePrimaryKey ? 'id' : implode(',', $primaryKey);
        $description = $modelName !== null
            ? "API documentation for {$modelName} model ({$tableName} table)"
            : "API documentation for {$tableName} table";

        $json = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $resourceName,
                'description' => $description,
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => 'http://localhost/api',
                    'description' => 'Development server',
                ],
            ],
            'paths' => [
                "/{$tableName}" => [
                    'get' => [
                        'summary' => "List all {$tableName}",
                        'tags' => [$tableName],
                        'responses' => [
                            '200' => [
                                'description' => 'Successful response',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'array',
                                            'items' => ['$ref' => "#/components/schemas/{$resourceName}"],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'post' => [
                        'summary' => "Create new {$tableName}",
                        'tags' => [$tableName],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => "#/components/schemas/{$resourceName}"],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Created',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => "#/components/schemas/{$resourceName}"],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                "/{$tableName}/{{$idParam}}" => [
                    'get' => [
                        'summary' => "Get {$tableName} by ID",
                        'tags' => [$tableName],
                        'parameters' => [
                            [
                                'name' => $idParam,
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Successful response',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => "#/components/schemas/{$resourceName}"],
                                    ],
                                ],
                            ],
                            '404' => ['description' => 'Not found'],
                        ],
                    ],
                    'put' => [
                        'summary' => "Update {$tableName}",
                        'tags' => [$tableName],
                        'parameters' => [
                            [
                                'name' => $idParam,
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => "#/components/schemas/{$resourceName}"],
                                ],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Updated',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => "#/components/schemas/{$resourceName}"],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'delete' => [
                        'summary' => "Delete {$tableName}",
                        'tags' => [$tableName],
                        'parameters' => [
                            [
                                'name' => $idParam,
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'responses' => [
                            '204' => ['description' => 'Deleted'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    $resourceName => $schema,
                ],
            ],
        ];

        $result = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($result === false) {
            throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }
        return $result;
    }

    /**
     * Generate schema definition in YAML format.
     *
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param array<int, string> $primaryKey Primary key columns
     *
     * @return string
     */
    protected static function generateSchema(array $columns, array $primaryKey): string
    {
        $yaml = "      type: object\n";
        $yaml .= "      properties:\n";

        foreach ($columns as $column) {
            $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? null;
            if ($colName === null) {
                continue;
            }

            $type = static::getOpenApiType($column);
            $nullable = static::isColumnNullable($column);
            $description = $column['Comment'] ?? $column['comment'] ?? '';
            $isPrimaryKey = in_array($colName, $primaryKey, true);

            $yaml .= "        {$colName}:\n";
            $yaml .= "          type: {$type}\n";
            if ($nullable) {
                $yaml .= "          nullable: true\n";
            }
            if ($isPrimaryKey) {
                $yaml .= "          readOnly: true\n";
                if ($description === '') {
                    $description = 'Primary key';
                } else {
                    $description = 'Primary key. ' . $description;
                }
            }
            if ($description !== '') {
                $yaml .= '          description: ' . addslashes($description) . "\n";
            }
        }

        $required = array_filter($columns, fn ($col) => !static::isColumnNullable($col));
        if (!empty($required)) {
            $requiredCols = array_map(fn ($col) => $col['Field'] ?? $col['column_name'] ?? $col['name'], $required);
            $requiredCols = array_filter($requiredCols, fn ($c) => $c !== null);
            if (!empty($requiredCols)) {
                $yaml .= "      required:\n";
                foreach ($requiredCols as $col) {
                    $yaml .= "        - {$col}\n";
                }
            }
        }

        return $yaml;
    }

    /**
     * Generate schema definition in JSON format.
     *
     * @param array<int, array<string, mixed>> $columns Table columns
     * @param array<int, string> $primaryKey Primary key columns
     *
     * @return array<string, mixed>
     */
    protected static function generateSchemaJson(array $columns, array $primaryKey): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
        ];

        $required = [];

        foreach ($columns as $column) {
            $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? null;
            if ($colName === null) {
                continue;
            }

            $type = static::getOpenApiType($column);
            $nullable = static::isColumnNullable($column);
            $description = $column['Comment'] ?? $column['comment'] ?? '';
            $isPrimaryKey = in_array($colName, $primaryKey, true);

            $prop = ['type' => $type];
            if ($nullable) {
                $prop['nullable'] = true;
            }
            if ($isPrimaryKey) {
                $prop['readOnly'] = true;
                if ($description === '') {
                    $description = 'Primary key';
                } else {
                    $description = 'Primary key. ' . $description;
                }
            }
            if ($description !== '') {
                $prop['description'] = $description;
            }

            $schema['properties'][$colName] = $prop;

            if (!$nullable) {
                $required[] = $colName;
            }
        }

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Get OpenAPI type from column definition.
     *
     * @param array<string, mixed> $column Column definition
     *
     * @return string
     */
    protected static function getOpenApiType(array $column): string
    {
        $type = $column['Type'] ?? $column['data_type'] ?? $column['type'] ?? 'string';
        if (!is_string($type)) {
            return 'string';
        }

        $typeLower = strtolower($type);

        if (str_contains($typeLower, 'int')) {
            return 'integer';
        }
        if (str_contains($typeLower, 'float') || str_contains($typeLower, 'double') || str_contains($typeLower, 'decimal')) {
            return 'number';
        }
        if (str_contains($typeLower, 'bool')) {
            return 'boolean';
        }
        if (str_contains($typeLower, 'date') && !str_contains($typeLower, 'time')) {
            return 'string';
        }
        if (str_contains($typeLower, 'time')) {
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
     * Get docs output path.
     *
     * @return string
     */
    protected static function getDocsOutputPath(): string
    {
        $path = getenv('PDODB_DOCS_PATH');
        if ($path && is_dir($path)) {
            return $path;
        }

        $possiblePaths = [
            getcwd() . '/docs/api',
            getcwd() . '/documentation/api',
            getcwd() . '/api-docs',
            __DIR__ . '/../../docs',
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        $defaultPath = getcwd() . '/docs/api';
        if (!is_dir($defaultPath)) {
            mkdir($defaultPath, 0755, true);
        }

        return $defaultPath;
    }
}
