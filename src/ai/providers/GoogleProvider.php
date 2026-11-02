<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\AiUsage;
use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Google (Gemini) provider implementation.
 */
class GoogleProvider extends BaseAiProvider
{
    private const string API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent'; // v1beta is the latest supported version
    private const string DEFAULT_MODEL = 'gemini-3.8-flash';
    private const float DEFAULT_TEMPERATURE = 0.7;
    private const int DEFAULT_MAX_TOKENS = 8192;
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

    protected function dispatchAnalysis(string $systemPrompt, string $userPrompt): string
    {
        return $this->callApi($userPrompt, $systemPrompt);
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

        $url = sprintf($this->apiUrl, $this->model);

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

        if (preg_match('/^gemini-(?:3\.[6-9]|[4-9])/', $this->model) === 1) {
            $this->requireTemperatureSupport();
            unset($data[self::REQUEST_KEY_GENERATION_CONFIG]['temperature']);
        }

        $response = $this->makeRequest($url, $data, ['x-goog-api-key' => $apiKey]);

        // Check for error in response
        if (isset($response['error'])) {
            $errorCode = $response['error']['code'] ?? null;
            $errorCode = is_int($errorCode) ? $errorCode : 'unknown';

            throw new QueryException(
                "Google API error ({$errorCode})",
                0
            );
        }

        // Validate response structure
        if (!isset($response[self::RESPONSE_KEY_CANDIDATES])) {
            throw new QueryException(
                'Invalid response format from Google API: missing "candidates" key.',
                0
            );
        }

        if (empty($response[self::RESPONSE_KEY_CANDIDATES])) {
            throw new QueryException(
                'Invalid response format from Google API: empty "candidates" array.',
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
            $message = $reasonMessages[$finishReason] ?? 'Response was stopped for an unknown reason.';

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
            throw new QueryException(
                'Invalid response format from Google API: missing "content" key in candidate.',
                0
            );
        }

        $content = $candidate[self::RESPONSE_KEY_CONTENT];

        if (!isset($content[self::REQUEST_KEY_PARTS]) || empty($content[self::REQUEST_KEY_PARTS])) {
            throw new QueryException(
                'Invalid response format from Google API: missing or empty "parts" array.',
                0
            );
        }

        $part = $content[self::REQUEST_KEY_PARTS][0];

        if (!isset($part[self::REQUEST_KEY_TEXT])) {
            throw new QueryException(
                'Invalid response format from Google API: missing "text" key in part.',
                0
            );
        }

        return (string)$part[self::REQUEST_KEY_TEXT];
    }

    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new QueryException(
                'Google provider is not available. Please configure PDODB_AI_API_KEY environment variable.',
                0
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function extractUsage(array $response): ?AiUsage
    {
        $usage = $response['usageMetadata'] ?? null;

        return is_array($usage)
            ? AiUsage::of($usage['promptTokenCount'] ?? null, $usage['candidatesTokenCount'] ?? null, $usage['totalTokenCount'] ?? null)
            : null;
    }
}
