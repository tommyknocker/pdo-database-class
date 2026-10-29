<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * OpenAI (ChatGPT) provider implementation.
 */
class OpenAiProvider extends BaseAiProvider
{
    private const string API_URL = 'https://api.openai.com/v1/chat/completions';
    private const string DEFAULT_MODEL = 'gpt-4o-mini';
    private const float DEFAULT_TEMPERATURE = 0.7;
    private const int DEFAULT_MAX_TOKENS = 2000;
    private const string HEADER_AUTHORIZATION = 'Authorization';
    private const string HEADER_BEARER_PREFIX = 'Bearer ';
    private const string MESSAGE_ROLE_SYSTEM = 'system';
    private const string MESSAGE_ROLE_USER = 'user';
    private const string RESPONSE_KEY_CHOICES = 'choices';
    private const string RESPONSE_KEY_MESSAGE = 'message';
    private const string RESPONSE_KEY_CONTENT = 'content';

    protected string $apiUrl = self::API_URL;

    protected function initializeDefaults(): void
    {
        $provider = $this->getProviderName();
        $defaultBaseUrl = match ($provider) {
            'openai' => 'https://api.openai.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default => '',
        };
        $baseUrl = (string)$this->config->getProviderSetting($provider, 'base_url', $defaultBaseUrl);
        if ($baseUrl !== '') {
            $parts = parse_url($baseUrl);
            if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                throw new QueryException('AI base_url must be an absolute HTTP(S) URL without credentials, query or fragment', 0);
            }
        }
        // Preserve existing subclasses that override the protected endpoint property.
        if ($provider !== 'openai' || $this->apiUrl === self::API_URL
            || $this->config->getProviderSetting($provider, 'base_url') !== null) {
            $this->apiUrl = $baseUrl === '' ? '' : rtrim($baseUrl, '/') . '/chat/completions';
        }
        $defaultModel = $provider === 'openrouter' ? 'openai/' . self::DEFAULT_MODEL : self::DEFAULT_MODEL;
        $this->model = (string)$this->config->getProviderSetting($provider, 'model', $defaultModel);
        $this->temperature = (float)$this->config->getProviderSetting($provider, 'temperature', self::DEFAULT_TEMPERATURE);
        $this->maxTokens = (int)$this->config->getProviderSetting($provider, 'max_tokens', self::DEFAULT_MAX_TOKENS);
    }

    public function getProviderName(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return $this->apiUrl !== '' && $this->config->hasApiKey($this->getProviderName());
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
        $apiKey = $this->config->getApiKey($this->getProviderName());
        if ($apiKey === null) {
            throw new QueryException('OpenAI API key not configured', 0);
        }

        $data = [
            'model' => $this->model,
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

        $provider = $this->getProviderName();
        $requestOptions = $this->config->getProviderSetting($provider, 'request_options', []);
        if (!is_array($requestOptions) || array_key_exists('model', $requestOptions) || array_key_exists('messages', $requestOptions)
            || isset($requestOptions['stream']) && $requestOptions['stream'] !== false) {
            throw new QueryException('AI request_options must be an array and cannot override model/messages or enable streaming', 0);
        }
        $data = array_filter(array_replace($data, $requestOptions), static fn ($value) => $value !== null);
        $headers = $this->config->getProviderSetting($provider, 'headers', []);
        if (!is_array($headers)) {
            throw new QueryException('AI headers must be an array of strings', 0);
        }
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value) || preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1
                || str_contains($value, "\r") || str_contains($value, "\n")
                || in_array(strtolower($name), ['authorization', 'host', 'content-length', 'content-type'], true)) {
                throw new QueryException('Invalid or reserved AI HTTP header', 0);
            }
        }
        $headers[self::HEADER_AUTHORIZATION] = self::HEADER_BEARER_PREFIX . $apiKey;

        $response = $this->makeRequest($this->apiUrl, $data, $headers);

        $content = $response[self::RESPONSE_KEY_CHOICES][0][self::RESPONSE_KEY_MESSAGE][self::RESPONSE_KEY_CONTENT] ?? null;
        if ((!is_string($content) || trim($content) === '')
            && ($response[self::RESPONSE_KEY_CHOICES][0]['finish_reason'] ?? null) === 'length') {
            throw new QueryException(
                'AI response exhausted its output token limit before producing visible text. Reduce reasoning effort or choose a model that can answer within the configured limit.',
                0
            );
        }
        if (!is_string($content)) {
            throw new QueryException(
                'Invalid response format from OpenAI API',
                0
            );
        }

        return $content;
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
        $prompt .= '- Evidence supporting recommendations and limits of available measurements';

        return $prompt;
    }

    /**
     * Build prompt for schema analysis.
     */
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
     * Build prompt for optimization suggestions.
     */
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

    /**
     * Ensure provider is available.
     *
     * @throws QueryException If provider is not available
     */
    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new QueryException(
                'AI provider ' . $this->getProviderName() . ' is not available. Configure its API key and base_url.',
                0
            );
        }
    }
}
