<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\ai\AiAnalysisService;

/**
 * MCP tool for suggesting indexes with AI.
 */
class SuggestIndexesTool implements McpToolInterface
{
    public function __construct(
        protected PdoDb $db,
        protected AiAnalysisService $aiService
    ) {
    }

    public function getName(): string
    {
        return 'suggest_indexes';
    }

    public function getDescription(): string
    {
        return 'Get AI-powered index suggestions for a table';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['table'],
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'Table name',
                ],
                'provider' => [
                    'type' => 'string',
                    'description' => 'AI provider (openai, anthropic, google, microsoft, ollama)',
                    'enum' => ['openai', 'anthropic', 'google', 'microsoft', 'ollama'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string|array
    {
        $tableName = $arguments['table'] ?? '';
        if ($tableName === '') {
            return ['error' => 'Table name is required'];
        }

        $provider = $arguments['provider'] ?? null;

        try {
            $schemaInspector = new \tommyknocker\pdodb\cli\SchemaInspector($this->db);
            $schema = [
                'columns' => $this->db->describe($tableName),
                'indexes' => $schemaInspector->getIndexes($tableName),
                'foreign_keys' => $schemaInspector->getForeignKeys($tableName),
            ];

            $context = [
                'schema' => $schema,
            ];

            $suggestions = $this->aiService->analyzeSchema($tableName, $provider);

            return [
                'table' => $tableName,
                'suggestions' => $suggestions,
                'provider' => $provider ?? 'openai',
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}

