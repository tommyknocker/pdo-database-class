<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\PdoDb;

/**
 * MCP tool for analyzing SQL queries with AI.
 */
class AnalyzeQueryTool implements McpToolInterface
{
    public function __construct(
        protected PdoDb $db,
        protected AiAnalysisService $aiService
    ) {
    }

    public function getName(): string
    {
        return 'analyze_query';
    }

    public function getDescription(): string
    {
        return 'Analyze SQL query with AI and provide optimization recommendations';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['sql'],
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'SQL query to analyze',
                ],
                'table' => [
                    'type' => 'string',
                    'description' => 'Table name for context (optional)',
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
        $sql = $arguments['sql'] ?? '';
        if ($sql === '') {
            return ['error' => 'SQL query is required'];
        }

        $tableName = $arguments['table'] ?? null;
        $provider = $arguments['provider'] ?? null;

        try {
            $analysis = $this->aiService->analyzeQuery($sql, $tableName, $provider);

            return [
                'sql' => $sql,
                'analysis' => $analysis,
                'provider' => $provider ?? 'openai',
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
