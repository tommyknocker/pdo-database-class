<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Microsoft (Azure OpenAI/Copilot) provider implementation.
 */
class MicrosoftProvider extends BaseAiProvider
{
    protected string $apiUrl = '';
    protected string $apiVersion = '2024-02-15-preview';

    protected function initializeDefaults(): void
    {
        $endpoint = $this->config->getProviderSetting('microsoft', 'endpoint', '');
        $deployment = $this->config->getProviderSetting('microsoft', 'deployment', 'gpt-4');
        $this->model = $deployment;

        if ($endpoint !== '') {
            $this->apiUrl = rtrim($endpoint, '/') . '/openai/deployments/' . urlencode($deployment) . '/chat/completions?api-version=' . $this->apiVersion;
        }

        $this->temperature = (float)$this->config->getProviderSetting('microsoft', 'temperature', 0.7);
        $this->maxTokens = (int)$this->config->getProviderSetting('microsoft', 'max_tokens', 2000);
    }

    public function getProviderName(): string
    {
        return 'microsoft';
    }

    public function isAvailable(): bool
    {
        return $this->config->hasApiKey('microsoft') && $this->apiUrl !== '';
    }

    public function analyzeQuery(string $sql, array $context = []): string
    {
        $this->ensureAvailable();

        $systemPrompt = $this->buildSystemPrompt('query');
        $userPrompt = $this->buildQueryPrompt($sql, $context);

        return $this->callApi($systemPrompt, $userPrompt);
    }

    public function analyzeSchema(array $schema, array $context = []): string
    {
        $this->ensureAvailable();

        $systemPrompt = $this->buildSystemPrompt('schema');
        $userPrompt = $this->buildSchemaPrompt($schema, $context);

        return $this->callApi($systemPrompt, $userPrompt);
    }

    public function suggestOptimizations(array $analysis, array $context = []): string
    {
        $this->ensureAvailable();

        $systemPrompt = $this->buildSystemPrompt('optimization');
        $userPrompt = $this->buildOptimizationPrompt($analysis, $context);

        return $this->callApi($systemPrompt, $userPrompt);
    }

    /**
     * Call Microsoft Azure OpenAI API.
     */
    protected function callApi(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = $this->config->getApiKey('microsoft');
        if ($apiKey === null) {
            throw new QueryException('Microsoft API key not configured', 0);
        }

        if ($this->apiUrl === '') {
            throw new QueryException('Microsoft endpoint not configured', 0);
        }

        $data = [
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
        ];

        $headers = [
            'api-key' => $apiKey,
        ];

        $response = $this->makeRequest($this->apiUrl, $data, $headers);

        if (!isset($response['choices'][0]['message']['content'])) {
            throw new QueryException(
                'Invalid response format from Microsoft API',
                0
            );
        }

        return (string)$response['choices'][0]['message']['content'];
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

    /**
     * @param array<string, mixed> $context
     */
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
        $prompt .= '- Estimated impact of optimizations';

        return $prompt;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $context
     */
    protected function buildSchemaPrompt(array $schema, array $context): string
    {
        $prompt = "Analyze the following database schema and provide optimization recommendations:\n\n";
        $prompt .= $this->formatContext(array_merge(['schema' => $schema], $context));

        $prompt .= "\n\nProvide specific recommendations for:\n";
        $prompt .= "- Missing indexes\n";
        $prompt .= "- Redundant indexes\n";
        $prompt .= "- Table structure improvements\n";
        $prompt .= '- Foreign key optimizations';

        return $prompt;
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $context
     */
    protected function buildOptimizationPrompt(array $analysis, array $context): string
    {
        $prompt = "Review the following database analysis and provide additional optimization suggestions:\n\n";
        $prompt .= $this->formatContext(array_merge(['existing_analysis' => $analysis], $context));

        $prompt .= "\n\nProvide additional recommendations that complement the existing analysis.";

        return $prompt;
    }

    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new QueryException(
                'Microsoft provider is not available. Please configure PDODB_AI_MICROSOFT_KEY and endpoint.',
                0
            );
        }
    }
}
