<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Anthropic (Claude) provider implementation.
 */
class AnthropicProvider extends BaseAiProvider
{
    protected string $apiUrl = 'https://api.anthropic.com/v1/messages';

    protected function initializeDefaults(): void
    {
        $this->model = $this->config->getProviderSetting('anthropic', 'model', 'claude-3-5-sonnet-20241022');
        $this->temperature = (float)$this->config->getProviderSetting('anthropic', 'temperature', 0.7);
        $this->maxTokens = (int)$this->config->getProviderSetting('anthropic', 'max_tokens', 2000);
    }

    public function getProviderName(): string
    {
        return 'anthropic';
    }

    public function isAvailable(): bool
    {
        return $this->config->hasApiKey('anthropic');
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
     * Call Anthropic API.
     */
    protected function callApi(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = $this->config->getApiKey('anthropic');
        if ($apiKey === null) {
            throw new QueryException('Anthropic API key not configured', 0);
        }

        $data = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'temperature' => $this->temperature,
        ];

        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ];

        $response = $this->makeRequest($this->apiUrl, $data, $headers);

        if (!isset($response['content'][0]['text'])) {
            throw new QueryException(
                'Invalid response format from Anthropic API',
                0
            );
        }

        return (string)$response['content'][0]['text'];
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

    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new QueryException(
                'Anthropic provider is not available. Please configure PDODB_AI_ANTHROPIC_KEY environment variable.',
                0
            );
        }
    }
}

