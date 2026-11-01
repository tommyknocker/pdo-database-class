<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Base class for AI providers with common functionality.
 */
abstract class BaseAiProvider implements AiProviderInterface
{
    protected AiConfig $config;
    protected string $model = '';
    protected float $temperature = 0.7;
    protected bool $temperatureExplicit = false;
    protected int $maxTokens = 2000;
    protected int $timeout = 30;

    public function __construct(?AiConfig $config = null)
    {
        $this->config = $config ?? new AiConfig();
        $this->initializeDefaults();
        $this->initializeCommonDefaults();
    }

    public function analyzeQuery(string $sql, array $context = []): string
    {
        $this->ensureAvailable();
        return $this->dispatchAnalysis($this->buildSystemPrompt('query'), $this->buildQueryPrompt($sql, $context));
    }

    public function analyzeSchema(array $schema, array $context = []): string
    {
        $this->ensureAvailable();
        return $this->dispatchAnalysis($this->buildSystemPrompt('schema'), $this->buildSchemaPrompt($schema, $context));
    }

    public function suggestOptimizations(array $analysis, array $context = []): string
    {
        $this->ensureAvailable();
        return $this->dispatchAnalysis($this->buildSystemPrompt('optimization'), $this->buildOptimizationPrompt($analysis, $context));
    }

    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new QueryException('AI provider ' . $this->getProviderName() . ' is not available. Check its configuration.', 0);
        }
    }

    protected function requireTemperatureSupport(): void
    {
        if ($this->temperatureExplicit) {
            throw new \InvalidArgumentException('PDOdb does not support an explicit temperature for this model; remove the temperature setting or choose a supported model.');
        }
    }

    protected function dispatchAnalysis(string $systemPrompt, string $userPrompt): string
    {
        throw new \LogicException('AI provider does not implement analysis dispatch');
    }

    protected function buildSystemPrompt(string $type): string
    {
        if ($type === 'query') {
            return QueryAdvicePrompt::system();
        }

        $basePrompt = 'You are an expert database performance analyst. Provide clear, actionable recommendations for database optimization.';

        $typePrompts = [
            'query' => 'Analyze SQL queries and provide optimization suggestions. Focus on index usage, query structure, and performance bottlenecks.',
            'schema' => 'Analyze database schema and provide recommendations for indexes, constraints, and table structure improvements.',
            'optimization' => 'Review existing analysis results and provide additional optimization suggestions. Build upon the existing recommendations.',
        ];

        return $basePrompt . ' ' . ($typePrompts[$type] ?? '');
    }

    /** @param array<string, mixed> $context */
    protected function buildQueryPrompt(string $sql, array $context): string
    {
        return QueryAdvicePrompt::user($sql, $context);
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

    /**
     * Initialize provider-specific defaults.
     */
    abstract protected function initializeDefaults(): void;

    /**
     * Initialize common defaults (timeout) that apply to all providers.
     */
    protected function initializeCommonDefaults(): void
    {
        $providerName = $this->getProviderName();
        $this->temperatureExplicit = $this->config->getProviderSetting($providerName, 'temperature') !== null;
        $timeout = $this->config->getProviderSetting($providerName, 'timeout', 30);
        $this->timeout = (int)$timeout;
    }

    /**
     * Make HTTP request to AI API.
     *
     * @param string $url API endpoint URL
     * @param array<string, mixed> $data Request data
     * @param array<string, string> $headers HTTP headers
     *
     * @return array<string, mixed> Response data
     * @throws QueryException If request fails
     */
    protected function makeRequest(string $url, array $data, array $headers = []): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new QueryException('AI requests need allow_url_fopen=On in php.ini; it is disabled.', 0);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $this->buildHeaders($headers),
                'content' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        http_clear_last_response_headers();
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new QueryException('AI API request failed: unable to connect to provider', 0);
        }

        $headers = http_get_last_response_headers() ?? [];
        $httpCode = $this->getHttpResponseCode($headers);

        if ($httpCode >= 400) {
            throw new QueryException(
                "AI API request failed with HTTP {$httpCode}",
                $httpCode
            );
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new QueryException(
                'Invalid JSON response from AI API',
                0
            );
        }

        return $decoded;
    }

    /**
     * Build HTTP headers string.
     *
     * @param array<string, string> $additionalHeaders Additional headers
     *
     * @return string Headers string
     */
    protected function buildHeaders(array $additionalHeaders = []): string
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
        ], $additionalHeaders);

        $headerStrings = [];
        foreach ($headers as $key => $value) {
            $headerStrings[] = "{$key}: {$value}";
        }

        return implode("\r\n", $headerStrings);
    }

    /**
     * Get HTTP response code from response headers.
     *
     * @param array<int, string> $headers Response headers
     *
     * @return int HTTP status code
     */
    protected function getHttpResponseCode(array $headers): int
    {
        if (empty($headers)) {
            return 0;
        }

        $statusLine = $headers[0];
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * Format context for AI prompt.
     *
     * @param array<string, mixed> $context Context data
     *
     * @return string Formatted context string
     */
    protected function formatContext(array $context): string
    {
        $parts = [];

        if (isset($context['table_schema'])) {
            $context['schema'] = $context['table_schema'];
        }

        if (isset($context['schema']) && !empty($context['schema'])) {
            $schemaJson = json_encode($context['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($schemaJson !== false) {
                $parts[] = "Database Schema:\n" . $schemaJson;
            }
        }

        if (isset($context['dialect'])) {
            $parts[] = 'Database Dialect: ' . $context['dialect'];
        }

        if (isset($context['explain_plan']) && !empty($context['explain_plan'])) {
            $planJson = json_encode($context['explain_plan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($planJson !== false) {
                $parts[] = "Query Execution Plan:\n" . $planJson;
            }
        }

        if (isset($context['table_stats']) && !empty($context['table_stats'])) {
            $statsJson = json_encode($context['table_stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($statsJson !== false) {
                $parts[] = "Table Statistics:\n" . $statsJson;
            }
        }

        if (isset($context['existing_analysis']) && !empty($context['existing_analysis'])) {
            $analysisJson = json_encode($context['existing_analysis'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($analysisJson !== false) {
                $parts[] = "Existing Analysis:\n" . $analysisJson;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Get model name.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Set model name.
     */
    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    /**
     * Get temperature.
     */
    public function getTemperature(): float
    {
        return $this->temperature;
    }

    /**
     * Set temperature.
     */
    public function setTemperature(float $temperature): void
    {
        if (!is_finite($temperature) || $temperature < 0.0 || $temperature > 2.0) {
            throw new \InvalidArgumentException('AI temperature must be between 0 and 2');
        }
        $this->temperature = $temperature;
        $this->temperatureExplicit = true;
    }

    /**
     * Get max tokens.
     */
    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }

    /**
     * Set max tokens.
     */
    public function setMaxTokens(int $maxTokens): void
    {
        $this->maxTokens = max(1, $maxTokens);
    }

    /**
     * Get timeout.
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Set timeout.
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = max(1, $timeout);
    }
}
