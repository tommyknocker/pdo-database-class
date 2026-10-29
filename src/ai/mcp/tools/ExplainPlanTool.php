<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\QueryInspectionService;
use tommyknocker\pdodb\PdoDb;

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
                'params' => ['type' => ['array', 'object'], 'description' => 'Positional or named SQL parameters'],
                'table' => [
                    'type' => 'string',
                    'description' => 'Table name for context (optional)',
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
        $sql = $arguments['sql'] ?? '';
        if ($sql === '') {
            return ['error' => 'SQL query is required'];
        }

        $tableName = $arguments['table'] ?? null;
        $provider = $arguments['provider'] ?? null;

        try {
            $inspection = (new QueryInspectionService($this->db))->inspect($sql, $arguments['params'] ?? [], $tableName);
            $baseAnalysis = $inspection['analysis'];

            // Then get AI analysis (pass baseAnalysis which includes EXPLAIN plan)
            $aiAnalysis = $this->aiService->analyzeQuery($sql, $tableName, $provider, ['params' => $arguments['params'] ?? []], $baseAnalysis);
            $aiProvider = $this->aiService->getProvider($provider);
            $model = $aiProvider->getModel();

            return [
                'sql' => $sql,
                'base_analysis' => QueryInspectionService::format($baseAnalysis),
                'ai_analysis' => $aiAnalysis,
                'provider' => $aiProvider->getProviderName(),
                'model' => $model,
                'plan_source' => $inspection['source'],
                'warnings' => $inspection['warnings'],
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
