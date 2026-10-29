<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\QueryInspectionService;
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
            // Get EXPLAIN plan for better AI analysis
            $explainAnalysis = null;
            $warnings = [];
            $inspection = ['source' => 'unavailable'];

            try {
                $inspection = (new QueryInspectionService($this->db))->inspect($sql, $arguments['params'] ?? [], $tableName);
                $explainAnalysis = $inspection['analysis'];
                $warnings = $inspection['warnings'];
            } catch (\Throwable $e) {
                $warnings[] = 'EXPLAIN could not be collected. This advice is not based on a verified execution plan.';
            }

            $analysis = $this->aiService->analyzeQuery($sql, $tableName, $provider, [], $explainAnalysis);

            return [
                'sql' => $sql,
                'analysis' => $analysis,
                'provider' => $this->aiService->getProvider($provider)->getProviderName(),
                'plan_source' => $inspection['source'],
                'warnings' => $warnings,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
