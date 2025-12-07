<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * OpenAI (ChatGPT) provider implementation.
 */
class OpenAiProvider extends BaseAiProvider
{
    protected string $apiUrl = 'https://api.openai.com/v1/chat/completions';

    protected function initializeDefaults(): void
    {
        $this->model = $this->config->getProviderSetting('openai', 'model', 'gpt-4o-mini');
        $this->temperature = (float)$this->config->getProviderSetting('openai', 'temperature', 0.7);
        $this->maxTokens = (int)$this->config->getProviderSetting('openai', 'max_tokens', 2000);
    }

    public function getProviderName(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return $this->config->hasApiKey('openai');
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
     * Call OpenAI API.
     *
     * @param string $systemPrompt System prompt
     * @param string $userPrompt User prompt
     *
     * @return string AI response
     */
    protected function callApi(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = $this->config->getApiKey('openai');
        if ($apiKey === null) {
            throw new QueryException('OpenAI API key not configured', 0);
        }

        $data = [
            'model' => $this->model,
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
            'Authorization' => "Bearer {$apiKey}",
        ];

        $response = $this->makeRequest($this->apiUrl, $data, $headers);

        if (!isset($response['choices'][0]['message']['content'])) {
            throw new QueryException(
                'Invalid response format from OpenAI API',
                0
            );
        }

        return (string)$response['choices'][0]['message']['content'];
    }

    /**
     * Build system prompt based on analysis type.
     */
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
     * Build prompt for query analysis.
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
        $prompt .= "- Estimated impact of optimizations";

        return $prompt;
    }

    /**
     * Build prompt for schema analysis.
     */
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

    /**
     * Build prompt for optimization suggestions.
     */
    protected function buildOptimizationPrompt(array $analysis, array $context): string
    {
        $prompt = "Review the following database analysis and provide additional optimization suggestions:\n\n";
        $prompt .= $this->formatContext(array_merge(['existing_analysis' => $analysis], $context));

        $prompt .= "\n\nProvide additional recommendations that complement the existing analysis.";

        return $prompt;
    }

    /**
     * Ensure provider is available.
     *
     * @throws QueryException If provider is not available
     */
    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new QueryException(
                'OpenAI provider is not available. Please configure PDODB_AI_OPENAI_KEY environment variable.',
                0
            );
        }
    }
}

