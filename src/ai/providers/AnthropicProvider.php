<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Anthropic (Claude) provider implementation.
 */
class AnthropicProvider extends BaseAiProvider
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';
    private const string DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';
    private const float DEFAULT_TEMPERATURE = 0.7;
    private const int DEFAULT_MAX_TOKENS = 2000;
    private const string API_VERSION = '2023-06-01';
    private const string HEADER_API_KEY = 'x-api-key';
    private const string HEADER_VERSION = 'anthropic-version';
    private const string MESSAGE_ROLE_USER = 'user';
    private const string RESPONSE_KEY_CONTENT = 'content';
    private const string RESPONSE_KEY_TEXT = 'text';

    protected string $apiUrl = self::API_URL;

    protected function initializeDefaults(): void
    {
        $this->model = $this->config->getProviderSetting('anthropic', 'model', self::DEFAULT_MODEL);
        $this->temperature = (float)$this->config->getProviderSetting('anthropic', 'temperature', self::DEFAULT_TEMPERATURE);
        $this->maxTokens = (int)$this->config->getProviderSetting('anthropic', 'max_tokens', self::DEFAULT_MAX_TOKENS);
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
                    'role' => self::MESSAGE_ROLE_USER,
                    'content' => $userPrompt,
                ],
            ],
            'temperature' => $this->temperature,
        ];

        $headers = [
            self::HEADER_API_KEY => $apiKey,
            self::HEADER_VERSION => self::API_VERSION,
        ];

        $response = $this->makeRequest($this->apiUrl, $data, $headers);

        if (!isset($response[self::RESPONSE_KEY_CONTENT][0][self::RESPONSE_KEY_TEXT])) {
            throw new QueryException(
                'Invalid response format from Anthropic API',
                0
            );
        }

        return (string)$response[self::RESPONSE_KEY_CONTENT][0][self::RESPONSE_KEY_TEXT];
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
                'Anthropic provider is not available. Please configure PDODB_AI_ANTHROPIC_KEY environment variable.',
                0
            );
        }
    }
}
