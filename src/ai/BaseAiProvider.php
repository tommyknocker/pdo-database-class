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
    protected int $maxTokens = 2000;
    protected int $timeout = 30;

    public function __construct(?AiConfig $config = null)
    {
        $this->config = $config ?? new AiConfig();
        $this->initializeDefaults();
    }

    /**
     * Initialize provider-specific defaults.
     */
    abstract protected function initializeDefaults(): void;

    /**
     * Make HTTP request to AI API.
     *
     * @param string $url API endpoint URL
     * @param array<string, mixed> $data Request data
     * @param array<string, string> $headers HTTP headers
     *
     * @return array<string, mixed> Response data
     *
     * @throws QueryException If request fails
     */
    protected function makeRequest(string $url, array $data, array $headers = []): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $this->buildHeaders($headers),
                'content' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new QueryException(
                'AI API request failed: ' . ($error['message'] ?? 'Unknown error'),
                0
            );
        }

        $httpCode = $this->getHttpResponseCode($http_response_header ?? []);

        if ($httpCode >= 400) {
            throw new QueryException(
                "AI API request failed with HTTP {$httpCode}: {$response}",
                $httpCode
            );
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new QueryException(
                'Invalid JSON response from AI API: ' . $response,
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

        if (isset($context['schema']) && !empty($context['schema'])) {
            $schemaJson = json_encode($context['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($schemaJson !== false) {
                $parts[] = "Database Schema:\n" . $schemaJson;
            }
        }

        if (isset($context['dialect'])) {
            $parts[] = "Database Dialect: " . $context['dialect'];
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
        $this->temperature = max(0.0, min(2.0, $temperature));
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
}

