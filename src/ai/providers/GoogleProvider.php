<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\AiConfig;
use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Google (Gemini) provider implementation.
 */
class GoogleProvider extends BaseAiProvider
{
    protected string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    protected function initializeDefaults(): void
    {
        $this->model = $this->config->getProviderSetting('google', 'model', 'gemini-pro');
        $this->temperature = (float)$this->config->getProviderSetting('google', 'temperature', 0.7);
        $this->maxTokens = (int)$this->config->getProviderSetting('google', 'max_tokens', 2000);
    }

    public function getProviderName(): string
    {
        return 'google';
    }

    public function isAvailable(): bool
    {
        return $this->config->hasApiKey('google');
    }

    public function analyzeQuery(string $sql, array $context = []): string
    {
        $this->ensureAvailable();

        $prompt = $this->buildQueryPrompt($sql, $context);
        $systemInstruction = $this->buildSystemPrompt('query');

        return $this->callApi($prompt, $systemInstruction);
    }

    public function analyzeSchema(array $schema, array $context = []): string
    {
        $this->ensureAvailable();

        $prompt = $this->buildSchemaPrompt($schema, $context);
        $systemInstruction = $this->buildSystemPrompt('schema');

        return $this->callApi($prompt, $systemInstruction);
    }

    public function suggestOptimizations(array $analysis, array $context = []): string
    {
        $this->ensureAvailable();

        $prompt = $this->buildOptimizationPrompt($analysis, $context);
        $systemInstruction = $this->buildSystemPrompt('optimization');

        return $this->callApi($prompt, $systemInstruction);
    }

    /**
     * Call Google Gemini API.
     */
    protected function callApi(string $prompt, string $systemInstruction): string
    {
        $apiKey = $this->config->getApiKey('google');
        if ($apiKey === null) {
            throw new QueryException('Google API key not configured', 0);
        }

        $url = sprintf($this->apiUrl, $this->model) . '?key=' . urlencode($apiKey);

        $data = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
            'systemInstruction' => [
                'parts' => [
                    [
                        'text' => $systemInstruction,
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $this->temperature,
                'maxOutputTokens' => $this->maxTokens,
            ],
        ];

        $response = $this->makeRequest($url, $data);

        if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            throw new QueryException(
                'Invalid response format from Google API',
                0
            );
        }

        return (string)$response['candidates'][0]['content']['parts'][0]['text'];
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
                'Google provider is not available. Please configure PDODB_AI_GOOGLE_KEY environment variable.',
                0
            );
        }
    }
}

