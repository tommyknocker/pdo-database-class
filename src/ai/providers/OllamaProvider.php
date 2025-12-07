<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Ollama (local models) provider implementation.
 */
class OllamaProvider extends BaseAiProvider
{
    protected string $apiUrl = '';

    protected function initializeDefaults(): void
    {
        $this->model = $this->config->getProviderSetting('ollama', 'model', 'deepseek-coder:6.7b');
        $this->temperature = (float)$this->config->getProviderSetting('ollama', 'temperature', 0.7);
        $this->maxTokens = (int)$this->config->getProviderSetting('ollama', 'max_tokens', 2000);

        $baseUrl = $this->config->getOllamaUrl();
        $this->apiUrl = rtrim($baseUrl, '/') . '/api/generate';
    }

    public function getProviderName(): string
    {
        return 'ollama';
    }

    public function isAvailable(): bool
    {
        // Ollama doesn't require API key, just check if URL is reachable
        return true;
    }

    public function analyzeQuery(string $sql, array $context = []): string
    {
        $prompt = $this->buildQueryPrompt($sql, $context);
        $systemPrompt = $this->buildSystemPrompt('query');
        $fullPrompt = $systemPrompt . "\n\n" . $prompt;

        return $this->callApi($fullPrompt);
    }

    public function analyzeSchema(array $schema, array $context = []): string
    {
        $prompt = $this->buildSchemaPrompt($schema, $context);
        $systemPrompt = $this->buildSystemPrompt('schema');
        $fullPrompt = $systemPrompt . "\n\n" . $prompt;

        return $this->callApi($fullPrompt);
    }

    public function suggestOptimizations(array $analysis, array $context = []): string
    {
        $prompt = $this->buildOptimizationPrompt($analysis, $context);
        $systemPrompt = $this->buildSystemPrompt('optimization');
        $fullPrompt = $systemPrompt . "\n\n" . $prompt;

        return $this->callApi($fullPrompt);
    }

    /**
     * Call Ollama API.
     */
    protected function callApi(string $prompt): string
    {
        $data = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $this->temperature,
                'num_predict' => $this->maxTokens,
            ],
        ];

        $response = $this->makeRequest($this->apiUrl, $data);

        if (!isset($response['response'])) {
            throw new QueryException(
                'Invalid response format from Ollama API',
                0
            );
        }

        return (string)$response['response'];
    }

    protected function buildSystemPrompt(string $type): string
    {
        $basePrompt = 'You are an expert database performance analyst. Provide clear, actionable recommendations for database optimization.';

        $typePrompts = [
            'query' => 'Analyze SQL queries and provide optimization suggestions. Focus on index usage, query structure, and performance bottlenecks.',
            'schema' => 'Analyze database schema and provide recommendations for indexes, constraints, and table structure improvements.',
            'optimization' => 'Review existing analysis results and provide additional optimization suggestions. Build upon the existing recommendations.',
        ];

        return $basePrompt . ' ' . ($typePrompts[$type] ?? '');
    }

    protected function buildQueryPrompt(string $sql, array $context): string
    {
        $prompt = "Analyze the following SQL query and provide optimization recommendations:\n\n";
        $prompt .= "SQL Query:\n```sql\n{$sql}\n```\n\n";

        if (!empty($context)) {
            $prompt .= $this->formatContext($context);
        }

        $prompt .= "\n\nProvide specific, actionable recommendations including:\n";
        $prompt .= "- Index suggestions\n";
        $prompt .= "- Query structure improvements\n";
        $prompt .= "- Performance bottlenecks\n";
        $prompt .= "- Estimated impact of optimizations";

        return $prompt;
    }

    protected function buildSchemaPrompt(array $schema, array $context): string
    {
        $prompt = "Analyze the following database schema and provide optimization recommendations:\n\n";
        $prompt .= $this->formatContext(array_merge(['schema' => $schema], $context));

        $prompt .= "\n\nProvide specific recommendations for:\n";
        $prompt .= "- Missing indexes\n";
        $prompt .= "- Redundant indexes\n";
        $prompt .= "- Table structure improvements\n";
        $prompt .= "- Foreign key optimizations";

        return $prompt;
    }

    protected function buildOptimizationPrompt(array $analysis, array $context): string
    {
        $prompt = "Review the following database analysis and provide additional optimization suggestions:\n\n";
        $prompt .= $this->formatContext(array_merge(['existing_analysis' => $analysis], $context));

        $prompt .= "\n\nProvide additional recommendations that complement the existing analysis.";

        return $prompt;
    }
}

