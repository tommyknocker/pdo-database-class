<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Yandex Cloud provider implementation.
 * Uses Yandex-specific API format (not OpenAI-compatible).
 *
 * @see https://cloud.yandex.ru/docs/ai-gpt/api-ref/
 */
class YandexProvider extends BaseAiProvider
{
    private const string API_URL = 'https://rest-assistant.api.cloud.yandex.net/v1/responses';
    private const string DEFAULT_MODEL = 'gpt-oss-120b/latest';
    private const float DEFAULT_TEMPERATURE = 0.7;
    private const int DEFAULT_MAX_TOKENS = 4000; // Increased default for Yandex
    private const string HEADER_AUTHORIZATION = 'Authorization';
    private const string HEADER_BEARER_PREFIX = 'Bearer ';
    private const string HEADER_X_FOLDER_ID = 'x-folder-id';
    private const string RESPONSE_KEY_OUTPUT = 'output';
    private const string RESPONSE_KEY_SUMMARY = 'summary';
    private const string RESPONSE_KEY_CONTENT = 'content';
    private const string RESPONSE_KEY_TEXT = 'text';
    private const string MODEL_PREFIX = 'gpt://';

    protected string $apiUrl = self::API_URL;
    protected ?string $folderId = null;

    protected function initializeDefaults(): void
    {
        $this->folderId = $this->config->getProviderSetting('yandex', 'folder_id', null);
        $model = $this->config->getProviderSetting('yandex', 'model', self::DEFAULT_MODEL);
        // Model format: gpt://{folder_id}/{model} or just model name
        if ($this->folderId !== null && !str_starts_with($model, self::MODEL_PREFIX)) {
            $this->model = self::MODEL_PREFIX . $this->folderId . '/' . $model;
        } else {
            $this->model = $model;
        }
        $this->temperature = (float)$this->config->getProviderSetting('yandex', 'temperature', self::DEFAULT_TEMPERATURE);
        $this->maxTokens = (int)$this->config->getProviderSetting('yandex', 'max_tokens', self::DEFAULT_MAX_TOKENS);
    }

    public function getProviderName(): string
    {
        return 'yandex';
    }

    public function isAvailable(): bool
    {
        return $this->config->hasApiKey('yandex') && $this->folderId !== null;
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
     * Call Yandex Cloud API.
     *
     * @param string $systemPrompt System prompt (used as instructions)
     * @param string $userPrompt User prompt (used as input)
     *
     * @return string AI response
     */
    protected function callApi(string $systemPrompt, string $userPrompt): string
    {
        $apiKey = $this->config->getApiKey('yandex');
        if ($apiKey === null) {
            throw new QueryException('Yandex API key not configured', 0);
        }

        if ($this->folderId === null) {
            throw new QueryException('Yandex folder ID not configured', 0);
        }

        // Yandex API uses different format: instructions, input, max_output_tokens
        $data = [
            'model' => $this->model,
            'instructions' => $systemPrompt,
            'input' => $userPrompt,
            'temperature' => $this->temperature,
            'max_output_tokens' => $this->maxTokens,
        ];

        $headers = [
            self::HEADER_AUTHORIZATION => self::HEADER_BEARER_PREFIX . $apiKey,
            self::HEADER_X_FOLDER_ID => $this->folderId,
        ];

        $response = $this->makeRequest($this->apiUrl, $data, $headers);

        // Check for errors
        if (isset($response['error']) && $response['error'] !== '') {
            $error = $response['error'];
            $errorMessage = is_string($error) ? $error : json_encode($error);
            throw new QueryException(
                'Yandex API error: ' . $errorMessage,
                0
            );
        }

        // Check if response was truncated
        $truncated = false;
        $truncatedReason = null;
        if (isset($response['incomplete_details']) && is_array($response['incomplete_details'])) {
            $truncated = ($response['incomplete_details']['valid'] ?? false) === true;
            $truncatedReason = $response['incomplete_details']['reason'] ?? null;
        }

        // Yandex API returns output as an array with summary/content objects containing text
        // Structure: output[0].summary[0].text or output[0].content[0].text
        if (isset($response[self::RESPONSE_KEY_OUTPUT]) && is_array($response[self::RESPONSE_KEY_OUTPUT])) {
            $outputArray = $response[self::RESPONSE_KEY_OUTPUT];
            if (!empty($outputArray) && is_array($outputArray[0])) {
                $firstOutput = $outputArray[0];
                
                // Try content first (for models like aliceai-llm) - this is the correct field
                $textArray = null;
                if (isset($firstOutput[self::RESPONSE_KEY_CONTENT]) && is_array($firstOutput[self::RESPONSE_KEY_CONTENT])) {
                    // Try content (for models like aliceai-llm)
                    $textArray = $firstOutput[self::RESPONSE_KEY_CONTENT];
                } elseif (isset($firstOutput[self::RESPONSE_KEY_SUMMARY]) && is_array($firstOutput[self::RESPONSE_KEY_SUMMARY])) {
                    // Try summary (for some models like gpt-oss-120b) - but may contain prompt, not response
                    $textArray = $firstOutput[self::RESPONSE_KEY_SUMMARY];
                }
                
                if ($textArray !== null) {
                    // Collect all text from all items
                    $textParts = [];
                    foreach ($textArray as $item) {
                        // Handle both object with 'text' field and direct string
                        if (is_string($item)) {
                            $textParts[] = $item;
                        } elseif (is_array($item) && isset($item[self::RESPONSE_KEY_TEXT]) && is_string($item[self::RESPONSE_KEY_TEXT])) {
                            $textParts[] = $item[self::RESPONSE_KEY_TEXT];
                        }
                    }
                    if (!empty($textParts)) {
                        $output = implode('', $textParts);
                        // Filter out prompt-like content (contains instructions or user prompt)
                        // If output looks like a prompt rather than a response, skip it
                        $lowerOutput = strtolower($output);
                        if (str_contains($lowerOutput, 'the user asks') || 
                            str_contains($lowerOutput, 'need to give recommendations') ||
                            str_contains($lowerOutput, 'provide suggestions') ||
                            (str_contains($lowerOutput, 'analyze') && str_contains($lowerOutput, 'query') && strlen($output) < 500)) {
                            // This looks like a prompt, not a response - continue to error
                        } else {
                            if ($truncated && $truncatedReason !== null) {
                                $warning = "\n\n[Note: Response was truncated due to {$truncatedReason}. Consider increasing max_output_tokens.]";
                                return $output . $warning;
                            }
                            return $output;
                        }
                    }
                }
            }
        }

        // If output is missing but response was truncated, provide helpful error
        if ($truncated) {
            $responseJson = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($responseJson)) {
                $responseJson = 'Unable to encode response';
            }
            $responseStr = substr($responseJson, 0, 1000);
            throw new QueryException(
                'Yandex API response was truncated due to max_output_tokens, but output is missing or invalid. ' .
                'Please increase max_output_tokens. Response: ' . $responseStr,
                0
            );
        }

        // If output is missing and not truncated, return detailed error
        $responseJson = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($responseJson)) {
            $responseJson = 'Unable to encode response';
        }
        $responseStr = substr($responseJson, 0, 1000);
        throw new QueryException(
            'Invalid response format from Yandex API: missing or invalid "output" key. Expected structure: output[0].summary[0].text or output[0].content[0].text. Response: ' . $responseStr,
            0
        );
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
     *
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
     * Build prompt for schema analysis.
     *
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
     *
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
                'Yandex provider is not available. Please configure PDODB_AI_YANDEX_KEY and PDODB_AI_YANDEX_FOLDER_ID environment variables.',
                0
            );
        }
    }
}

