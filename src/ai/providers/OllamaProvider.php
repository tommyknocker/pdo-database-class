<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai\providers;

use tommyknocker\pdodb\ai\AiEndpoint;
use tommyknocker\pdodb\ai\AiUsage;
use tommyknocker\pdodb\ai\BaseAiProvider;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Ollama (local models) provider implementation.
 */
class OllamaProvider extends BaseAiProvider
{
    private const string DEFAULT_MODEL = 'deepseek-coder:6.7b';
    private const float DEFAULT_TEMPERATURE = 0.7;
    private const int DEFAULT_MAX_TOKENS = 2000;
    private const string API_PATH_GENERATE = '/api/generate';
    private const string REQUEST_KEY_MODEL = 'model';
    private const string REQUEST_KEY_PROMPT = 'prompt';
    private const string REQUEST_KEY_STREAM = 'stream';
    private const string REQUEST_KEY_OPTIONS = 'options';
    private const string REQUEST_KEY_NUM_PREDICT = 'num_predict';
    private const string RESPONSE_KEY_RESPONSE = 'response';

    protected string $apiUrl = '';

    protected function initializeDefaults(): void
    {
        $this->model = $this->config->getProviderSetting('ollama', 'model', self::DEFAULT_MODEL);
        $this->temperature = (float)$this->config->getProviderSetting('ollama', 'temperature', self::DEFAULT_TEMPERATURE);
        $this->maxTokens = (int)$this->config->getProviderSetting('ollama', 'max_tokens', self::DEFAULT_MAX_TOKENS);

        $baseUrl = $this->config->getOllamaUrl();
        AiEndpoint::assertValid($baseUrl);
        $this->apiUrl = rtrim($baseUrl, '/') . self::API_PATH_GENERATE;
    }

    public function getProviderName(): string
    {
        return 'ollama';
    }

    public function isAvailable(): bool
    {
        // Ollama doesn't require API key, just check if URL is reachable
        return true;
    }

    protected function dispatchAnalysis(string $systemPrompt, string $userPrompt): string
    {
        return $this->callApi($systemPrompt . "\n\n" . $userPrompt);
    }

    /**
     * Call Ollama API.
     */
    protected function callApi(string $prompt): string
    {
        $data = [
            self::REQUEST_KEY_MODEL => $this->model,
            self::REQUEST_KEY_PROMPT => $prompt,
            self::REQUEST_KEY_STREAM => false,
            self::REQUEST_KEY_OPTIONS => [
                'temperature' => $this->temperature,
                self::REQUEST_KEY_NUM_PREDICT => $this->maxTokens,
            ],
        ];

        $response = $this->makeRequest($this->apiUrl, $data);

        if (!isset($response[self::RESPONSE_KEY_RESPONSE])) {
            throw new QueryException(
                'Invalid response format from Ollama API',
                0
            );
        }

        return (string)$response[self::RESPONSE_KEY_RESPONSE];
    }

    /**
     * {@inheritDoc}
     */
    protected function extractUsage(array $response): ?AiUsage
    {
        return AiUsage::of($response['prompt_eval_count'] ?? null, $response['eval_count'] ?? null);
    }
}
