<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\ai\AiAnalysisService;

/**
 * MCP tool for explaining query plans with AI.
 */
class ExplainPlanTool implements McpToolInterface
{
    public function __construct(
        protected PdoDb $db,
        protected AiAnalysisService $aiService
    ) {
    }

    public function getName(): string
    {
        return 'explain_plan';
    }

    public function getDescription(): string
    {
        return 'Get AI-powered analysis of query execution plan';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['sql'],
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'SQL query to explain',
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
            $result = $this->db->rawQuery($sql)->explainAiAdvice($tableName, $provider);

            return [
                'sql' => $sql,
                'base_analysis' => [
                    'issues' => array_map(function ($issue) {
                        return [
                            'type' => $issue->type ?? '',
                            'severity' => $issue->severity ?? '',
                            'message' => $issue->message ?? '',
                        ];
                    }, $result->baseAnalysis->issues),
                    'recommendations' => array_map(function ($rec) {
                        return [
                            'type' => $rec->type ?? '',
                            'severity' => $rec->severity ?? '',
                            'message' => $rec->message ?? '',
                            'sql' => $rec->sql ?? null,
                        ];
                    }, $result->baseAnalysis->recommendations),
                ],
                'ai_analysis' => $result->aiAnalysis,
                'provider' => $result->provider,
                'model' => $result->model,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}

