<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\mcp\tools;

use tommyknocker\pdodb\ai\AiAnalysisService;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\AiExplainAnalysis;
use tommyknocker\pdodb\query\analysis\ExplainAnalyzer;

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
            // For raw SQL, we need to use ExplainAnalyzer and AiAnalysisService directly
            $connection = $this->db->connection;
            $dialect = $connection->getDialect();
            $pdo = $connection->getPdo();

            // Get base analysis - use QueryBuilder's internal execution engine
            $explainResults = $dialect->executeExplain($pdo, $sql, []);
            $queryBuilder = $this->db->find();
            // Access execution engine via reflection or create new one
            $reflection = new \ReflectionClass($queryBuilder);
            $executionEngineProperty = $reflection->getProperty('executionEngine');
            $executionEngineProperty->setAccessible(true);
            $executionEngine = $executionEngineProperty->getValue($queryBuilder);
            $analyzer = new ExplainAnalyzer($dialect, $executionEngine);
            $baseAnalysis = $analyzer->analyze($explainResults, $tableName);

            // Then get AI analysis
            $aiAnalysis = $this->aiService->analyzeQuery($sql, $tableName, $provider);
            $aiProvider = $this->aiService->getProvider($provider);
            $model = $aiProvider->getModel();

            $result = new AiExplainAnalysis(
                $baseAnalysis,
                $aiAnalysis,
                $aiProvider->getProviderName(),
                $model
            );

            return [
                'sql' => $sql,
                'base_analysis' => [
                    'issues' => array_map(function ($issue) {
                        return [
                            'type' => $issue->type,
                            'severity' => $issue->severity,
                            'description' => $issue->description,
                            'table' => $issue->table ?? null,
                        ];
                    }, $result->baseAnalysis->issues),
                    'recommendations' => array_map(function ($rec) {
                        return [
                            'type' => $rec->type,
                            'severity' => $rec->severity,
                            'message' => $rec->message,
                            'suggestion' => $rec->suggestion ?? null,
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
