<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Google (Gemini) provider implementation.
 */
class GoogleProvider extends BaseAiProvider
{
    private const string API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent'; // v1beta is the latest supported version
    private const string DEFAULT_MODEL = 'gemini-2.5-flash';
    private const float DEFAULT_TEMPERATURE = 0.7;
    private const int DEFAULT_MAX_TOKENS = 8192; // Increased default for Gemini 2.5 models (supports up to 65K output tokens)
    private const string URL_PARAM_KEY = '?key=';
    private const string REQUEST_KEY_CONTENTS = 'contents';
    private const string REQUEST_KEY_PARTS = 'parts';
    private const string REQUEST_KEY_TEXT = 'text';
    private const string REQUEST_KEY_SYSTEM_INSTRUCTION = 'systemInstruction';
    private const string REQUEST_KEY_GENERATION_CONFIG = 'generationConfig';
    private const string REQUEST_KEY_MAX_OUTPUT_TOKENS = 'maxOutputTokens';
    private const string RESPONSE_KEY_CANDIDATES = 'candidates';
    private const string RESPONSE_KEY_CONTENT = 'content';

    protected string $apiUrl = self::API_URL;

    protected function initializeDefaults(): void
    {
        $this->model = $this->config->getProviderSetting('google', 'model', self::DEFAULT_MODEL);
        $this->temperature = (float)$this->config->getProviderSetting('google', 'temperature', self::DEFAULT_TEMPERATURE);
        $this->maxTokens = (int)$this->config->getProviderSetting('google', 'max_tokens', self::DEFAULT_MAX_TOKENS);
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

        $url = sprintf($this->apiUrl, $this->model) . self::URL_PARAM_KEY . urlencode($apiKey);

        $data = [
            self::REQUEST_KEY_CONTENTS => [
                [
                    self::REQUEST_KEY_PARTS => [
                        [
                            self::REQUEST_KEY_TEXT => $prompt,
                        ],
                    ],
                ],
            ],
            self::REQUEST_KEY_SYSTEM_INSTRUCTION => [
                self::REQUEST_KEY_PARTS => [
                    [
                        self::REQUEST_KEY_TEXT => $systemInstruction,
                    ],
                ],
            ],
            self::REQUEST_KEY_GENERATION_CONFIG => [
                'temperature' => $this->temperature,
                self::REQUEST_KEY_MAX_OUTPUT_TOKENS => $this->maxTokens,
            ],
        ];

        $response = $this->makeRequest($url, $data);

        // Check for error in response
        if (isset($response['error'])) {
            $errorCode = $response['error']['code'] ?? 'unknown';
            $errorMessage = $response['error']['message'] ?? 'Unknown error';

            throw new QueryException(
                "Google API error ({$errorCode}): {$errorMessage}",
                0
            );
        }

        // Validate response structure
        if (!isset($response[self::RESPONSE_KEY_CANDIDATES])) {
            $responseJson = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $responseStr = is_string($responseJson) ? substr($responseJson, 0, 500) : 'Unable to encode response';

            throw new QueryException(
                'Invalid response format from Google API: missing "candidates" key. Response: ' . $responseStr,
                0
            );
        }

        if (empty($response[self::RESPONSE_KEY_CANDIDATES])) {
            $responseJson = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $responseStr = is_string($responseJson) ? substr($responseJson, 0, 500) : 'Unable to encode response';

            throw new QueryException(
                'Invalid response format from Google API: empty "candidates" array. Response: ' . $responseStr,
                0
            );
        }

        $candidate = $response[self::RESPONSE_KEY_CANDIDATES][0];

        // Check finish reason
        $finishReason = $candidate['finishReason'] ?? null;
        if ($finishReason !== null && $finishReason !== 'STOP') {
            $reasonMessages = [
                'MAX_TOKENS' => 'Response was truncated due to token limit. Consider increasing max_tokens.',
                'SAFETY' => 'Response was blocked due to safety filters.',
                'RECITATION' => 'Response was blocked due to recitation detection.',
                'OTHER' => 'Response was stopped for an unknown reason.',
            ];
            $message = $reasonMessages[$finishReason] ?? "Response was stopped (reason: {$finishReason}).";

            // Try to get partial content if available
            $content = $candidate[self::RESPONSE_KEY_CONTENT] ?? null;
            if ($content !== null && isset($content[self::REQUEST_KEY_PARTS]) && !empty($content[self::REQUEST_KEY_PARTS])) {
                $part = $content[self::REQUEST_KEY_PARTS][0];
                if (isset($part[self::REQUEST_KEY_TEXT])) {
                    // Return partial content with warning
                    return (string)$part[self::REQUEST_KEY_TEXT] . "\n\n[Note: Response was truncated. {$message}]";
                }
            }

            throw new QueryException(
                "Google API response issue: {$message}",
                0
            );
        }

        if (!isset($candidate[self::RESPONSE_KEY_CONTENT])) {
            $responseJson = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $responseStr = is_string($responseJson) ? substr($responseJson, 0, 500) : 'Unable to encode response';

            throw new QueryException(
                'Invalid response format from Google API: missing "content" key in candidate. Response: ' . $responseStr,
                0
            );
        }

        $content = $candidate[self::RESPONSE_KEY_CONTENT];

        if (!isset($content[self::REQUEST_KEY_PARTS]) || empty($content[self::REQUEST_KEY_PARTS])) {
            $responseJson = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $responseStr = is_string($responseJson) ? substr($responseJson, 0, 500) : 'Unable to encode response';

            throw new QueryException(
                'Invalid response format from Google API: missing or empty "parts" array. Response: ' . $responseStr,
                0
            );
        }

        $part = $content[self::REQUEST_KEY_PARTS][0];

        if (!isset($part[self::REQUEST_KEY_TEXT])) {
            $responseJson = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $responseStr = is_string($responseJson) ? substr($responseJson, 0, 500) : 'Unable to encode response';

            throw new QueryException(
                'Invalid response format from Google API: missing "text" key in part. Response: ' . $responseStr,
                0
            );
        }

        return (string)$part[self::REQUEST_KEY_TEXT];
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
                'Google provider is not available. Please configure PDODB_AI_GOOGLE_KEY environment variable.',
                0
            );
        }
    }
}
