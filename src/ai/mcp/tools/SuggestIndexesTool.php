<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\PdoDb;

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
                    'description' => 'AI report provider; defaults to the configured provider',
                    'enum' => AiConfig::PROVIDERS,
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
            $suggestions = $this->aiService->analyzeSchema($tableName, $provider);

            return [
                'table' => $tableName,
                'suggestions' => $suggestions,
                'provider' => $this->aiService->getProvider($provider)->getProviderName(),
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
