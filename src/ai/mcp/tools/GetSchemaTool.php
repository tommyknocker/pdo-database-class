<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use tommyknocker\pdodb\ai\mcp\McpConfig;
use tommyknocker\pdodb\ai\mcp\McpMetadataService;
use tommyknocker\pdodb\PdoDb;

/**
 * MCP tool for getting database schema.
 */
class GetSchemaTool implements McpToolInterface
{
    protected McpMetadataService $metadata;
    public function __construct(
        protected PdoDb $db,
        ?McpMetadataService $metadata = null
    ) {
        $this->metadata = $metadata ?? new McpMetadataService($db, new McpConfig());
    }

    public function getName(): string
    {
        return 'get_schema';
    }

    public function getDescription(): string
    {
        return 'Get columns, indexes and visible foreign keys in a selected schema. Omit table to describe visible tables in that schema.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'schema' => ['type' => 'string', 'description' => 'Optional schema name; defaults to the selected metadata schema'],
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
        $schema = $arguments['schema'] ?? null;
        return $tableName === null ? $this->metadata->describeAll($schema) : $this->metadata->describe($tableName, $schema);
    }
}
