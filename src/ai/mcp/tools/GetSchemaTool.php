<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use tommyknocker\pdodb\PdoDb;

/**
 * MCP tool for getting database schema.
 */
class GetSchemaTool implements McpToolInterface
{
    public function __construct(
        protected PdoDb $db
    ) {
    }

    public function getName(): string
    {
        return 'get_schema';
    }

    public function getDescription(): string
    {
        return 'Get database schema information for a table or all tables';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'Table name (optional, if not provided returns all tables)',
                ],
            ],
        ];
    }

    public function execute(array $arguments): string|array
    {
        $tableName = $arguments['table'] ?? null;

        if ($tableName !== null) {
            $columns = $this->db->describe($tableName);
            $schemaInspector = new \tommyknocker\pdodb\cli\SchemaInspector($this->db);
            $indexes = $schemaInspector->getIndexes($tableName);
            $foreignKeys = $schemaInspector->getForeignKeys($tableName);

            return [
                'table' => $tableName,
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
            ];
        }

        $tables = \tommyknocker\pdodb\cli\TableManager::listTables($this->db);
        $schema = [];
        $schemaInspector = new \tommyknocker\pdodb\cli\SchemaInspector($this->db);

        foreach ($tables as $table) {
            $schema[$table] = [
                'columns' => $this->db->describe($table),
                'indexes' => $schemaInspector->getIndexes($table),
                'foreign_keys' => $schemaInspector->getForeignKeys($table),
            ];
        }

        return $schema;
    }
}

