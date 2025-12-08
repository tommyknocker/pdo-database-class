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
    private const string API_VERSION = '2024-10-21'; // Latest stable version
    private const string DEFAULT_DEPLOYMENT = 'gpt-4';
    private const float DEFAULT_TEMPERATURE = 0.7;
    private const int DEFAULT_MAX_TOKENS = 2000;
    private const string URL_PATH_DEPLOYMENTS = '/openai/deployments/';
    private const string URL_PATH_CHAT_COMPLETIONS = '/chat/completions';
    private const string URL_PARAM_API_VERSION = 'api-version=';
    private const string HEADER_API_KEY = 'api-key';
    private const string MESSAGE_ROLE_SYSTEM = 'system';
    private const string MESSAGE_ROLE_USER = 'user';
    private const string RESPONSE_KEY_CHOICES = 'choices';
    private const string RESPONSE_KEY_MESSAGE = 'message';
    private const string RESPONSE_KEY_CONTENT = 'content';

    protected string $apiUrl = '';
    protected string $apiVersion = self::API_VERSION;

    protected function initializeDefaults(): void
    {
        $endpoint = $this->config->getProviderSetting('microsoft', 'endpoint', '');
        $deployment = $this->config->getProviderSetting('microsoft', 'deployment', self::DEFAULT_DEPLOYMENT);
        $this->model = $deployment;

        if ($endpoint !== '') {
            $this->apiUrl = rtrim($endpoint, '/') . self::URL_PATH_DEPLOYMENTS . urlencode($deployment) . self::URL_PATH_CHAT_COMPLETIONS . '?' . self::URL_PARAM_API_VERSION . $this->apiVersion;
        }

        $this->temperature = (float)$this->config->getProviderSetting('microsoft', 'temperature', self::DEFAULT_TEMPERATURE);
        $this->maxTokens = (int)$this->config->getProviderSetting('microsoft', 'max_tokens', self::DEFAULT_MAX_TOKENS);
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
                    'role' => self::MESSAGE_ROLE_SYSTEM,
                    'content' => $systemPrompt,
                ],
                [
                    'role' => self::MESSAGE_ROLE_USER,
                    'content' => $userPrompt,
                ],
            ],
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
        ];

        $headers = [
            self::HEADER_API_KEY => $apiKey,
        ];

        $response = $this->makeRequest($this->apiUrl, $data, $headers);

        if (!isset($response[self::RESPONSE_KEY_CHOICES][0][self::RESPONSE_KEY_MESSAGE][self::RESPONSE_KEY_CONTENT])) {
            throw new QueryException(
                'Invalid response format from Microsoft API',
                0
            );
        }

        return (string)$response[self::RESPONSE_KEY_CHOICES][0][self::RESPONSE_KEY_MESSAGE][self::RESPONSE_KEY_CONTENT];
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
